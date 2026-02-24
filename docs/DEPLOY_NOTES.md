# Deploy notes

## Composer

- **Do not run Composer as root.** On the server, run `composer install` as the app user (e.g. `www-data` or your deploy user). If you must use root, create a dedicated user for running composer.
- **Lock file out of date:** If you see `Required package "phpoffice/phpword" is not present in the lock file`, run once (as app user, in project root):
  ```bash
  composer update --no-dev
  ```
  Then commit and push the updated `composer.lock` so future deploys use it. Or run `composer update` locally (where Composer is installed) and commit the new `composer.lock`.

## After git pull

```bash
composer install --no-dev --optimize-autoloader
# Run any new migrations, e.g.:
# php scripts/run_migration.php 006
```
