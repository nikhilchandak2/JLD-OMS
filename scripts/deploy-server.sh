#!/bin/bash
# Run this on the server (e.g. after SSH) from the app root.
# Usage: ./scripts/deploy-server.sh   or   bash scripts/deploy-server.sh

set -e
cd "$(dirname "$0")/.."
APP_ROOT="$(pwd)"

echo "=== Deploy: git safe.directory ==="
git config --global --add safe.directory "$APP_ROOT" 2>/dev/null || true

echo "=== Deploy: pull latest ==="
git pull origin main

echo "=== Deploy: composer install ==="
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader

echo "=== Deploy: run all migrations ==="
php scripts/migrate.php

echo "=== Deploy: verify Busy PDF parser ==="
php scripts/check_busy_pdf_setup.php || {
  echo "WARNING: Busy PDF parser check failed. Invoice PDF upload may not work until composer install succeeds."
}

echo "=== Deploy: permissions ==="
chown -R www-data:www-data "$APP_ROOT" 2>/dev/null || true

echo "=== Deploy done. ==="
