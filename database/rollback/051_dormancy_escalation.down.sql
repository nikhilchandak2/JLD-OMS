-- Rollback 051_dormancy_escalation.

DROP TABLE IF EXISTS crm_job_runs;
DROP TABLE IF EXISTS crm_job_locks;
DROP TABLE IF EXISTS escalations;
DROP TABLE IF EXISTS escalation_rules;
DROP TABLE IF EXISTS account_dormancy_signals;
DROP TABLE IF EXISTS dormancy_rules;

ALTER TABLE orders DROP INDEX idx_orders_party_date;
ALTER TABLE parties DROP INDEX idx_parties_account_tier;
ALTER TABLE parties DROP INDEX idx_parties_sales_owner;
ALTER TABLE parties DROP COLUMN account_tier;
