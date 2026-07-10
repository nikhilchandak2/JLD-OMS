#!/bin/bash
# Long-running WheelsEye pull loop (used by systemd). Do not run twice alongside cron.
#
# Env:
#   APP_ROOT                         — app directory (default: parent of scripts/)
#   PHP_BIN                          — default /usr/bin/php
#   WHEELSEYE_SYNC_INTERVAL_SECONDS  — sleep between runs (default: 120)

APP_ROOT="${APP_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
INTERVAL="${WHEELSEYE_SYNC_INTERVAL_SECONDS:-120}"
SCRIPT="${APP_ROOT}/scripts/auto_sync_wheelseye.php"
LOG_FILE="${APP_ROOT}/storage/logs/wheelseye-cron.log"
LOCK_FILE="/tmp/wheelseye-sync.lock"

mkdir -p "${APP_ROOT}/storage/logs"

if [[ ! -f "$SCRIPT" ]]; then
  echo "ERROR: Missing $SCRIPT" >&2
  exit 1
fi

if ! [[ "$INTERVAL" =~ ^[0-9]+$ ]] || [[ "$INTERVAL" -lt 1 ]]; then
  echo "ERROR: WHEELSEYE_SYNC_INTERVAL_SECONDS must be a positive integer (got: $INTERVAL)" >&2
  exit 1
fi

echo "WheelsEye sync loop started (interval=${INTERVAL}s, app=${APP_ROOT})" >&2

while true; do
  flock -n "$LOCK_FILE" "$PHP_BIN" "$SCRIPT" >> "$LOG_FILE" 2>&1 || true
  sleep "$INTERVAL"
done
