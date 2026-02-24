#!/bin/bash
# Run this on the server (e.g. after SSH) from the app root.
# Usage: ./scripts/deploy-server.sh   or   bash scripts/deploy-server.sh

set -e
cd "$(dirname "$0")/.."

echo "=== Deploy: pull latest ==="
git pull

echo "=== Deploy: composer install ==="
composer install --no-dev --optimize-autoloader

echo "=== Deploy: run migrations (006, 008) ==="
php scripts/run_migration.php 006 2>/dev/null || true
php scripts/run_migration.php 008 2>/dev/null || true

echo "=== Deploy done. ==="
