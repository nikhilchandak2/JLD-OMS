#!/usr/bin/env python3
"""
Offline Reminders Runner (Windows accountant PC)

Polls OMS for pending reminders jobs, downloads CSV, runs BusyPayBot locally,
then posts output back to OMS.

Required environment variables:
  OMS_BASE_URL=https://oms.jldminerals.com
  REMINDERS_RUNNER_KEY=... (must match server .env)

Optional:
  RUNNER_ID=pc-accounts-1
  COMPANY=jld_minerals|jaichand| (empty = accept any)
  SCRIPT_JLD_MINERALS=C:/BusyPayBot/JLD Minerals Private Limited/main.py
  SCRIPT_JAICHAND=C:/BusyPayBot/Jaichand Lal Daga/main.py
  PYTHON_BIN=python
  POLL_SECONDS=5
"""

import json
import os
import socket
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from urllib.request import Request, urlopen


def env(name: str, default: str = "") -> str:
    return os.environ.get(name, default).strip()

def maybe_force_ipv4():
    """
    Some networks prefer IPv6/NAT64 for DNS and urllib can hang.
    If FORCE_IPV4=1, we filter DNS results to IPv4 only while keeping the hostname
    (so TLS certificate verification still matches).
    """
    if env("FORCE_IPV4") != "1":
        return
    orig_getaddrinfo = socket.getaddrinfo

    def ipv4_only_getaddrinfo(host, port, family=0, type=0, proto=0, flags=0):
        res = orig_getaddrinfo(host, port, family, type, proto, flags)
        v4 = [r for r in res if r[0] == socket.AF_INET]
        return v4 if v4 else res

    socket.getaddrinfo = ipv4_only_getaddrinfo


def http_json(method: str, url: str, runner_key: str, payload=None):
    data = None
    headers = {"X-Runner-Key": runner_key}
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = Request(url, data=data, method=method, headers=headers)
    with urlopen(req, timeout=60) as r:
        raw = r.read().decode("utf-8", errors="replace")
        return json.loads(raw)


def http_download(url: str, runner_key: str, dest: Path):
    req = Request(url, method="GET", headers={"X-Runner-Key": runner_key})
    with urlopen(req, timeout=60) as r:
        dest.write_bytes(r.read())


def choose_script(company: str) -> str:
    if company == "jaichand":
        return env("SCRIPT_JAICHAND", r"C:/BusyPayBot/Jaichand Lal Daga/main.py")
    return env("SCRIPT_JLD_MINERALS", r"C:/BusyPayBot/JLD Minerals Private Limited/main.py")


def run_job(company: str, csv_path: Path) -> tuple[int, str]:
    script = choose_script(company)
    python_bin = env("PYTHON_BIN", "python")
    # -X utf8 forces UTF-8 mode on Windows as well.
    cmd = [python_bin, "-X", "utf8", "-u", script, str(csv_path)]
    # Force UTF-8 so BusyPayBot's unicode status symbols don't crash on Windows cp1252 consoles.
    run_env = os.environ.copy()
    run_env.setdefault("PYTHONUTF8", "1")
    run_env.setdefault("PYTHONIOENCODING", "utf-8")
    timeout_s = int(env("JOB_TIMEOUT_SECONDS", "900") or "900")
    try:
        show_console = env("SHOW_CONSOLE") == "1" and os.name == "nt"

        if show_console:
            # Make BusyPayBot visible while still collecting logs to post back to OMS.
            # We write output to a temp file and open a new console window for the process.
            log_path = csv_path.parent / "busypaybot_run.log"
            with open(log_path, "w", encoding="utf-8", errors="replace") as logf:
                creationflags = getattr(subprocess, "CREATE_NEW_CONSOLE", 0)
                proc = subprocess.Popen(
                    cmd,
                    cwd=str(Path(script).parent),
                    env=run_env,
                    stdout=logf,
                    stderr=logf,
                    creationflags=creationflags,
                )
                proc.wait(timeout=timeout_s)
            output = log_path.read_text(encoding="utf-8", errors="replace").strip()
            return proc.returncode or 0, (output or "(no output)")

        proc = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            cwd=str(Path(script).parent),
            env=run_env,
            timeout=timeout_s,
        )
        out = (proc.stdout or "").strip()
        err = (proc.stderr or "").strip()
        combined = out + ("\n\n" + err if err else "")
        return proc.returncode, (combined or "(no output)")
    except subprocess.TimeoutExpired as e:
        out = (e.stdout or "").strip() if isinstance(e.stdout, str) else ""
        err = (e.stderr or "").strip() if isinstance(e.stderr, str) else ""
        combined = out + ("\n\n" + err if err else "")
        msg = f"ERROR: BusyPayBot timed out after {timeout_s}s.\n\n{combined}".strip()
        return 124, msg


def main():
    base = env("OMS_BASE_URL")
    key = env("REMINDERS_RUNNER_KEY")
    if not base or not key:
        print("Missing OMS_BASE_URL or REMINDERS_RUNNER_KEY in environment.", file=sys.stderr)
        return 2

    maybe_force_ipv4()

    runner_id = env("RUNNER_ID", "pc-runner")
    company_filter = env("COMPANY")
    poll = int(env("POLL_SECONDS", "5") or "5")

    try:
        while True:
            job_id = None
            try:
                url = f"{base.rstrip('/')}/api/reminders/jobs/next?runner_id={runner_id}"
                if company_filter:
                    url += f"&company={company_filter}"
                res = http_json("GET", url, key)
                job = res.get("job")
                if not job:
                    time.sleep(poll)
                    continue

                job_id = job["id"]
                company = job.get("company") or ""
                download_url = f"{base.rstrip('/')}{job['download_url']}"

                with tempfile.TemporaryDirectory(prefix="oms_reminders_") as td:
                    csv_file = Path(td) / f"{job_id}.csv"
                    http_download(download_url, key, csv_file)
                    exit_code, output = run_job(company, csv_file)

                complete_url = f"{base.rstrip('/')}/api/reminders/jobs/{job_id}/complete"
                http_json(
                    "POST",
                    complete_url,
                    key,
                    payload={"exit_code": exit_code, "success": exit_code == 0, "output": output},
                )

            except Exception as e:
                print(f"[runner] error: {e}", file=sys.stderr)
                if job_id:
                    try:
                        complete_url = f"{base.rstrip('/')}/api/reminders/jobs/{job_id}/complete"
                        http_json(
                            "POST",
                            complete_url,
                            key,
                            payload={"exit_code": 1, "success": False, "output": f"Runner error: {e}"},
                        )
                    except Exception:
                        pass
                time.sleep(poll)
    except KeyboardInterrupt:
        print("[runner] stopped by user (Ctrl+C). Any running job may stay 'running' on OMS until stale timeout.", file=sys.stderr)
        return 130


if __name__ == "__main__":
    raise SystemExit(main())

