# CRM operator runbook (TASK 11)

One page. Times are IST.

## Daily feed upload

1. Open **CRM → Data feeds** (`/data-feeds`).
2. Upload the day’s ledger and dispatch files (`/data-feeds/upload`). Business date = the file’s day, not today if you are catching up.
3. Wait for **validate**, then **promote**. Promotion is what the rest of CRM reads. A parked run is not live.
4. If the same file is uploaded twice, the system refuses or asks you to confirm supersede. Do not promote a duplicate by habit.

## Upload failed

- **Validate errors:** download rejections, fix the file, upload again with a new run. Do not edit rows in the database.
- **Unreadable file / wrong sheet:** use the template from `/api/data-feeds/template/{feedKey}`.
- **Promote blocked:** another run for that feed+date is already live. Supersede only if Director confirms the previous file was wrong.
- Nightly dormancy/forecast/pipeline still use the last **promoted** day. A failed morning upload means yesterday’s as-of until you succeed.

## Unmatched parties

1. Open **Unmatched** (`/data-feeds/unmatched`).
2. Each unmatched name is a source identifier the feed could not attach to a `parties` row.
3. **Create alias:** map the identifier to the correct party. Next promote (or the same run if still open) will attach.
4. Do not invent a second party for a spelling variant. Alias it.

## Credit tiers

Tiers live in `credit_policy_tiers` (Director / admin). Changing a band does not rewrite history; new evaluations use the new row. Headroom on a deal is as-of the last promoted ledger, never a live Busy balance.

## Dormancy thresholds

Edit `dormancy_rules` (days without an order / visit). One group-wide row at launch. Then run `php scripts/crm_nightly.php` (or wait for tonight). A visit does not clear dormancy; only an order does. Escalations freeze context at trigger time — acknowledging is not the same as resolving.

## Pipeline snapshot

`/crm/pipeline` is last night’s rebuild. Advancing a deal today will not move the bars until the job runs. Stall days: `config/pipeline_dashboard.php`.

## Demo / training data

`php scripts/seed_crm_demo.php --yes` inserts labelled Demo parties and deals. Do not run on the live company database.

## Load check

`php scripts/crm_load_check.php` times the dashboard and the nightly activity query. Full 3-year volume: add `--full`.
