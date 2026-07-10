#!/bin/bash
# Install WheelsEye pull sync on a schedule (server-side cron, CLI only).
#
# Prefer systemd instead: sudo bash scripts/configure-wheelseye-production.sh
# Do NOT use HTTP curl to /api/tracking/sync — it blocks PHP-FPM.
#
# Optional env overrides:
#   APP_ROOT=/var/www/oms
#   PHP_BIN=/usr/bin/php
#   CRON_SCHEDULE="* * * * *"              # cron time mask (default: every minute)
#   CRON_SCHEDULE="* 7-18 * * *"           # every minute, 07:00-18:59 only
#   SYNC_INTERVAL_SECONDS=30               # 30 or 60 (default: 30)
#
# Note: standard cron has 1-minute resolution. For 30s we install two lines:
#   at :00 and at :30 each minute (sleep 30 on the second line).

set -e

APP_ROOT="${APP_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
CRON_SCHEDULE="${CRON_SCHEDULE:-* * * * *}"
SYNC_INTERVAL_SECONDS="${SYNC_INTERVAL_SECONDS:-30}"
SCRIPT="${APP_ROOT}/scripts/auto_sync_wheelseye.php"
LOG_FILE="${APP_ROOT}/storage/logs/wheelseye-cron.log"
LOCK_FILE="/tmp/wheelseye-sync.lock"

if [[ ! -f "$SCRIPT" ]]; then
  echo "ERROR: Missing $SCRIPT"
  exit 1
fi

if [[ "$SYNC_INTERVAL_SECONDS" != "30" && "$SYNC_INTERVAL_SECONDS" != "60" ]]; then
  echo "ERROR: SYNC_INTERVAL_SECONDS must be 30 or 60 (got: $SYNC_INTERVAL_SECONDS)"
  exit 1
fi

mkdir -p "${APP_ROOT}/storage/logs"
chown -R www-data:www-data "${APP_ROOT}/storage" 2>/dev/null || true

MARKER="# oms-wheelseye-sync"
RUN_CMD="flock -n ${LOCK_FILE} ${PHP_BIN} ${SCRIPT} >> ${LOG_FILE} 2>&1 ${MARKER}"

TMP="$(mktemp)"
crontab -l 2>/dev/null \
  | grep -v "${MARKER}" \
  | grep -v "oms-wheelseye-sync-1min" \
  | grep -v "oms-wheelseye-sync-30s" \
  | grep -v "auto_sync_wheelseye.php" > "$TMP" || true

if [[ "$SYNC_INTERVAL_SECONDS" == "30" ]]; then
  echo "${CRON_SCHEDULE} ${RUN_CMD}" >> "$TMP"
  echo "${CRON_SCHEDULE} sleep 30; ${RUN_CMD}" >> "$TMP"
else
  echo "${CRON_SCHEDULE} ${RUN_CMD}" >> "$TMP"
fi

crontab "$TMP"
rm -f "$TMP"

echo "Installed WheelsEye sync (every ${SYNC_INTERVAL_SECONDS}s):"
crontab -l | grep "${MARKER}" || true
echo ""
echo "Verify:"
echo "  tail -f ${LOG_FILE}"
echo "  cat ${APP_ROOT}/storage/last_tracking_sync.json"
echo ""
echo "Revert to 1 minute: SYNC_INTERVAL_SECONDS=60 sudo bash scripts/install-wheelseye-cron.sh"
