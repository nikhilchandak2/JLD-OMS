# CRM rollback plan (TASK 11)

Disable a feature without a code deploy where a config row or flag exists. Otherwise revert the numbered migration. Run `php scripts/run_migration.php` only forward; rollbacks are the `.down.sql` files applied by hand on a maintenance window.

| Feature | Toggle (no schema drop) | Migration revert |
|---|---|---|
| 7-stage deals / technical flags | Stop capturing; existing rows stay. Exit criteria rows in `crm_stage_exit_criteria` can be deactivated. | `database/rollback/046_crm_pipeline_7stage.down.sql` |
| Credit gate | Do not capture orders through `/api/credit/capture`. Tier rows live in `credit_policy_tiers`. | `database/rollback/048_credit_gate.down.sql` |
| Account context (contacts / competitors / issues) | Stop writing; views show empty states. | `database/rollback/049_account_context.down.sql` |
| Visit log / overdue follow-ups | Stop logging. Overdue list empties. | `database/rollback/050_crm_visits.down.sql` |
| Dormancy + escalations | Remove or `is_active=0` the `dormancy_rules` / `escalation_rules` rows. Comment the nightly cron. | `database/rollback/051_dormancy_escalation.down.sql` |
| Monthly forecast | Do not open a period. Worksheet is empty. | `database/rollback/052_forecasts.down.sql` |
| Handoff packets | Stage 6 exit criterion `handoff_packet_transferred` can be turned off in `crm_stage_exit_criteria`. | `database/rollback/053_handoff_packets.down.sql` |
| New-rep briefing / handover notes | Stop adding notes. Briefing still composes from other tables. Review date: `config/briefing.php`. | `database/rollback/054_handover_notes.down.sql` |
| Pipeline dashboards | Stall days: `config/pipeline_dashboard.php`. Hide `/crm/pipeline` by removing the nav link, or skip `PipelineDashboardService::rebuild` in the nightly job. | `database/rollback/055_pipeline_dashboard.down.sql` |
| Data feeds | Mark feeds inactive (`PUT /api/data-feeds/{id}`). | `database/rollback/047_data_feeds.down.sql` |

Nightly job: `php scripts/crm_nightly.php`. Overlap is locked. To disable all nightly CRM rebuilds, remove the scheduled task.

Always take a DB dump before applying a `.down.sql`.
