#!/bin/bash
# Run this on the server (e.g. after SSH) from the app root.
# Usage: ./scripts/deploy-server.sh   or   bash scripts/deploy-server.sh

set -e
cd "$(dirname "$0")/.."

echo "=== Deploy: pull latest ==="
git pull

echo "=== Deploy: composer install ==="
composer install --no-dev --optimize-autoloader

echo "=== Deploy: run migrations ==="
for n in 006 007 008 009 010 011 012 013 018 019 020; do
  php scripts/run_migration.php $n 2>/dev/null || true
done

echo "=== Deploy done. ==="
