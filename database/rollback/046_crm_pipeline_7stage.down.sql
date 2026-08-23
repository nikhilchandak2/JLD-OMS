-- Rollback for 046_crm_pipeline_7stage.sql
-- Run with: php scripts/migrate_down.php 046_crm_pipeline_7stage
-- Lives outside database/migrations/ on purpose: scripts/migrate.php applies every *.sql
-- file in that directory on every run, so a down file there would undo itself immediately.
--
-- Restores crm_deals to its migration-012 shape and drops the pipeline tables.
-- Pipeline data (stage events, grades, technical flags, criteria values) is discarded --
-- take a dump before running this if the data matters.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS crm_deal_criteria_values;
DROP TABLE IF EXISTS crm_deal_stage_events;
DROP TABLE IF EXISTS crm_deal_grades;
DROP TABLE IF EXISTS crm_technical_flags;
DROP TABLE IF EXISTS crm_technical_queues;
DROP TABLE IF EXISTS crm_stage_exit_criteria;

SET FOREIGN_KEY_CHECKS = 1;

-- crm_deals: drop the pipeline columns, constraints and indexes
SET @fk := (
  SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals'
    AND COLUMN_NAME = 'lost_reason_code_id' AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(@fk IS NOT NULL, CONCAT('ALTER TABLE crm_deals DROP FOREIGN KEY ', @fk), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk := (
  SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals'
    AND COLUMN_NAME = 'company_id' AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(@fk IS NOT NULL, CONCAT('ALTER TABLE crm_deals DROP FOREIGN KEY ', @fk), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS crm_deal_reason_codes;

ALTER TABLE crm_deals DROP INDEX idx_company_status_stage;
-- The owner FK needs an index on that column; restore migration 012's index first.
ALTER TABLE crm_deals ADD INDEX idx_assigned_to (owner_user_id);
ALTER TABLE crm_deals DROP INDEX idx_owner_status;
ALTER TABLE crm_deals DROP INDEX idx_status_stage;
ALTER TABLE crm_deals DROP INDEX idx_deleted_at;

ALTER TABLE crm_deals DROP COLUMN company_id;
ALTER TABLE crm_deals DROP COLUMN stage;
ALTER TABLE crm_deals DROP COLUMN status;
ALTER TABLE crm_deals DROP COLUMN stage_entered_at;
ALTER TABLE crm_deals DROP COLUMN lost_reason_code_id;
ALTER TABLE crm_deals DROP COLUMN source;
ALTER TABLE crm_deals DROP COLUMN indicative_quantity_tonnes;
ALTER TABLE crm_deals DROP COLUMN inquiry_date;
ALTER TABLE crm_deals DROP COLUMN deleted_at;

SET @has_legacy_stage := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals' AND COLUMN_NAME = 'legacy_funnel_stage'
);
SET @sql := IF(@has_legacy_stage = 1,
  'ALTER TABLE crm_deals RENAME COLUMN legacy_funnel_stage TO stage',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_owner := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals' AND COLUMN_NAME = 'owner_user_id'
);
SET @sql := IF(@has_owner = 1,
  'ALTER TABLE crm_deals RENAME COLUMN owner_user_id TO assigned_to',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_legacy_lead := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals' AND COLUMN_NAME = 'legacy_lead_id'
);
SET @sql := IF(@has_legacy_lead = 1,
  'ALTER TABLE crm_deals RENAME COLUMN legacy_lead_id TO lead_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Restore the original ON DELETE CASCADE on party_id
SET @fk := (
  SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals'
    AND COLUMN_NAME = 'party_id' AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(@fk IS NOT NULL, CONCAT('ALTER TABLE crm_deals DROP FOREIGN KEY ', @fk), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE crm_deals ADD CONSTRAINT fk_crm_deals_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE;

-- Bring back crm_leads under its original name
SET @archive_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_archived_crm_leads'
);
SET @leads_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_leads'
);
SET @sql := IF(@archive_exists = 1 AND @leads_exists = 0,
  'RENAME TABLE _archived_crm_leads TO crm_leads',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @lead_fk_exists := (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals'
    AND COLUMN_NAME = 'lead_id' AND REFERENCED_TABLE_NAME = 'crm_leads'
);
SET @leads_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_leads'
);
SET @sql := IF(@lead_fk_exists = 0 AND @leads_exists = 1,
  'ALTER TABLE crm_deals ADD CONSTRAINT fk_crm_deals_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
