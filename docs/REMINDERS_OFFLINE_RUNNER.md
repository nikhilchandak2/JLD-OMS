# Offline Reminders Runner (Accountant PC)

This mode lets **OMS (server)** accept a CSV upload and create a *reminders job*, then an **offline Windows PC** (with BusyPayBot installed) picks up the job and runs reminders locally.

## 1) Server setup (`oms.jldminerals.com`)

In the server project `.env` (example path: `/var/www/oms/.env`) set a secret:

```env
REMINDERS_RUNNER_KEY=replace-with-a-long-random-secret
```

Deploy the latest OMS code (so the new endpoints exist), then restart PHP-FPM + nginx.

## 2) How it works

- Accounts/Admin uploads CSV on **Administration → Reminders**
- OMS stores the CSV in `storage/reminders_uploads/` and creates a job in `storage/reminders_jobs/`
- Offline runner polls `GET /api/reminders/jobs/next` with header `X-Runner-Key`
- Runner downloads the CSV, runs BusyPayBot, then posts output back to OMS
- Reminders page polls job status and shows output when completed

## 3) Runner setup (Windows accountant PC)

### 3.1 Requirements

- Python installed (same environment you use to run BusyPayBot)
- BusyPayBot folders present, e.g.:
  - `C:\BusyPayBot\JLD Minerals Private Limited\main.py`
  - `C:\BusyPayBot\Jaichand Lal Daga\main.py`

### 3.2 Run the runner

From the OMS repo folder on the PC:

```powershell
$env:OMS_BASE_URL = "https://oms.jldminerals.com"
$env:REMINDERS_RUNNER_KEY = "replace-with-the-same-secret-as-server"
$env:RUNNER_ID = "accounts-pc-1"
# Optional:
# $env:COMPANY = "jld_minerals"   # or "jaichand" (leave empty to accept any)

python .\scripts\reminders_offline_runner.py
```

Keep it running (Task Scheduler recommended) so jobs are processed automatically.

## 4) Security notes

- `REMINDERS_RUNNER_KEY` is effectively a password. Keep it private.
- If you need multiple PCs, give each one a different `RUNNER_ID`.

