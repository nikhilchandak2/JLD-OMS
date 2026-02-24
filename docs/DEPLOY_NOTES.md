# Deploy notes

## Composer

- **Do not run Composer as root.** On the server, run `composer install` as the app user (e.g. `www-data` or your deploy user). If you must use root, create a dedicated user for running composer.
- **Install unzip on the server** so Composer can unpack archives correctly and avoid "invalid reports of corrupted archives":
  ```bash
  sudo apt-get update && sudo apt-get install -y unzip   # Debian/Ubuntu
  ```
- **Lock file out of date:** If you see `Required package "phpoffice/phpword" is not present in the lock file`, run once (as app user, in project root):
  ```bash
  composer update --no-dev
  ```
  Then commit and push the updated `composer.lock` so future deploys use it.
- **"The .git directory is missing"** when upgrading packages: Composer will ask "Would you like to try reinstalling the package instead [yes]?" — answer **yes** (or just press Enter). It will remove and reinstall the package; the deploy will complete. To avoid this on future pulls, you can do a clean install once: `rm -rf vendor && composer install --no-dev --optimize-autoloader`.

## Database migrations

Migrations must run against the **same database** as the app (e.g. `order_processing_prod`). Ensure `.env` in the app root has `DB_NAME=order_processing_prod` and run from the **app root**:

```bash
cd /var/www/tracking   # or your app root
php scripts/run_migration.php 008
```

If you see "Table ... doesn't exist" after running 008, the script may have used a different DB (e.g. default `order_processing`). Run the one-off script; it prints which database it uses:

```bash
php scripts/run_migration_008_once.php
```

You should see `Using database: order_processing_prod`. If you see another name, fix `.env` (set `DB_NAME=order_processing_prod`) and run again.

## After git pull

```bash
composer install --no-dev --optimize-autoloader
php scripts/run_migration.php 008   # if not yet run
```
