#!/bin/bash
# Install systemd loop for WheelsEye pull (default: every 120 seconds when webhook is primary).
# Removes wheelseye cron lines to avoid double sync.
#
# Usage on server:
#   sudo bash scripts/install-wheelseye-systemd.sh
#
# Env:
#   APP_ROOT=/var/www/oms
#   WHEELSEYE_SYNC_INTERVAL_SECONDS=120    # default: 120s (webhook primary); use 30 if webhook gaps
#   SYSTEMD_SERVICE_USER=root            # or www-data
#   SYSTEMD_UNINSTALL=1                  # remove service and stop loop

set -e

APP_ROOT="${APP_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
INTERVAL="${WHEELSEYE_SYNC_INTERVAL_SECONDS:-120}"
SERVICE_USER="${SYSTEMD_SERVICE_USER:-root}"
UNIT_NAME="oms-wheelseye-sync.service"
UNIT_PATH="/etc/systemd/system/${UNIT_NAME}"
LOOP_SCRIPT="${APP_ROOT}/scripts/wheelseye-sync-loop.sh"
MARKER="oms-wheelseye-sync"

remove_cron_entries() {
  local tmp
  tmp="$(mktemp)"
  crontab -l 2>/dev/null \
    | grep -v "${MARKER}" \
    | grep -v "oms-wheelseye-sync-1min" \
    | grep -v "oms-wheelseye-sync-30s" \
    | grep -v "auto_sync_wheelseye.php" \
    | grep -v "wheelseye-sync-loop.sh" \
    | grep -v "api/tracking/sync" > "$tmp" || true
  crontab "$tmp"
  rm -f "$tmp"
}

if [[ "${SYSTEMD_UNINSTALL:-}" == "1" ]]; then
  systemctl stop "$UNIT_NAME" 2>/dev/null || true
  systemctl disable "$UNIT_NAME" 2>/dev/null || true
  rm -f "$UNIT_PATH"
  systemctl daemon-reload
  echo "Removed ${UNIT_NAME}. Cron was not restored — run install-wheelseye-cron.sh if you want cron back."
  exit 0
fi

if [[ ! -f "$LOOP_SCRIPT" ]]; then
  echo "ERROR: Missing $LOOP_SCRIPT"
  exit 1
fi

chmod +x "$LOOP_SCRIPT"
mkdir -p "${APP_ROOT}/storage/logs"
chown -R www-data:www-data "${APP_ROOT}/storage" 2>/dev/null || true

cat > "$UNIT_PATH" <<EOF
[Unit]
Description=OMS WheelsEye GPS pull sync loop
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=${SERVICE_USER}
WorkingDirectory=${APP_ROOT}
Environment=APP_ROOT=${APP_ROOT}
Environment=WHEELSEYE_SYNC_INTERVAL_SECONDS=${INTERVAL}
ExecStart=${LOOP_SCRIPT}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

remove_cron_entries

systemctl daemon-reload
systemctl enable "$UNIT_NAME"
systemctl restart "$UNIT_NAME"

echo "Installed ${UNIT_NAME} (interval=${INTERVAL}s, user=${SERVICE_USER})"
echo ""
systemctl status "$UNIT_NAME" --no-pager -l || true
echo ""
echo "Logs:"
echo "  journalctl -u ${UNIT_NAME} -f"
echo "  tail -f ${APP_ROOT}/storage/logs/wheelseye-cron.log"
echo ""
echo "Change interval: WHEELSEYE_SYNC_INTERVAL_SECONDS=30 sudo bash scripts/install-wheelseye-systemd.sh"
echo "Uninstall: SYSTEMD_UNINSTALL=1 sudo bash scripts/install-wheelseye-systemd.sh"
