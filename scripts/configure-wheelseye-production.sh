#!/bin/bash
# Production WheelsEye sync setup: remove HTTP cron, install CLI systemd backup.
#
# Usage on server (from app root):
#   sudo bash scripts/configure-wheelseye-production.sh
#
# Env:
#   APP_ROOT=/var/www/oms
#   WHEELSEYE_SYNC_INTERVAL_SECONDS=120   # CLI pull interval (webhook is primary)

set -e

APP_ROOT="${APP_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
INTERVAL="${WHEELSEYE_SYNC_INTERVAL_SECONDS:-120}"

echo "=== WheelsEye production sync setup ==="
echo "App root: ${APP_ROOT}"
echo "CLI interval: ${INTERVAL}s"
echo ""

echo "--- Current crontab (wheelseye-related) ---"
crontab -l 2>/dev/null | grep -iE 'wheelseye|auto_sync_wheelseye|tracking/sync' || echo "(none)"
echo ""

echo "--- Removing HTTP curl sync + duplicate CLI cron lines ---"
TMP="$(mktemp)"
crontab -l 2>/dev/null \
  | grep -v 'oms-wheelseye-sync' \
  | grep -v 'auto_sync_wheelseye.php' \
  | grep -v 'wheelseye-sync-loop.sh' \
  | grep -v 'api/tracking/sync' \
  | grep -v 'wheelseye-cron.log' > "$TMP" || true
crontab "$TMP"
rm -f "$TMP"
echo "Crontab cleaned."
echo ""

echo "--- Installing systemd CLI backup (interval=${INTERVAL}s) ---"
WHEELSEYE_SYNC_INTERVAL_SECONDS="${INTERVAL}" APP_ROOT="${APP_ROOT}" bash "${APP_ROOT}/scripts/install-wheelseye-systemd.sh"
echo ""

echo "--- Recommended .env (add on server if not set) ---"
cat <<'EOF'
WHEELSEYE_ALLOW_HTTP_SYNC=0
WHEELSEYE_ALLOW_LIVE_PAGE_SYNC=0
# Webhook remains primary: https://oms.jldminerals.com/api/gps/webhook
EOF
echo ""

echo "--- Verify ---"
echo "  systemctl status oms-wheelseye-sync --no-pager"
echo "  tail -f ${APP_ROOT}/storage/logs/wheelseye-sync.log"
echo "  curl -I --max-time 15 http://127.0.0.1/login"
echo ""
echo "Done."
