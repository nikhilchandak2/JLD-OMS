-- CRM pipeline: 7 sequential stages owned by the deal, config-driven exit criteria,
-- immutable transition log, and technical support as an orthogonal queue-routed hold.
--
-- Legacy columns on crm_deals are renamed (not dropped) so the dry-run backfill job can
-- map them: stage -> legacy_funnel_stage, assigned_to -> owner_user_id, lead_id -> legacy_lead_id.
-- Rollback: database/rollback/046_crm_pipeline_7stage.down.sql

-- ---------------------------------------------------------------------------
-- Configurable reason codes for lost / dropped deals
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_deal_reason_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL,
  label VARCHAR(255) NOT NULL,
  applies_to ENUM('lost', 'dropped', 'both') NOT NULL DEFAULT 'both',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reason_code (code),
  INDEX idx_active (is_active, sort_order)
);

INSERT IGNORE INTO crm_deal_reason_codes (code, label, applies_to, sort_order) VALUES
  ('price_too_high', 'Price too high', 'lost', 10),
  ('quality_not_suitable', 'Quality / spec not suitable', 'lost', 20),
  ('competitor_won', 'Competitor won the business', 'lost', 30),
  ('credit_terms_rejected', 'Credit terms not acceptable', 'lost', 40),
  ('logistics_freight', 'Freight / logistics not viable', 'lost', 50),
  ('customer_project_shelved', 'Customer project shelved', 'both', 60),
  ('no_response', 'No response from customer', 'dropped', 70),
  ('duplicate_deal', 'Duplicate deal record', 'dropped', 80),
  ('not_a_fit', 'Not our product range', 'dropped', 90),
  ('other', 'Other (see note)', 'both', 100);

-- ---------------------------------------------------------------------------
-- Exit criteria: CONFIG. The Director can change what is mandatory without a deploy.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_stage_exit_criteria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stage TINYINT NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
  label VARCHAR(255) NOT NULL,
  help_text VARCHAR(255) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stage_field (stage, field_key),
  INDEX idx_stage_active (stage, is_active, sort_order)
);

INSERT IGNORE INTO crm_stage_exit_criteria (stage, field_key, is_mandatory, label, help_text, sort_order) VALUES
  (1, 'source', 1, 'Enquiry source', 'How the enquiry reached us', 10),
  (1, 'party', 1, 'Customer', 'Existing party or a newly created one', 20),
  (1, 'grades', 1, 'Grade(s) enquired for', 'At least one grade code', 30),
  (1, 'indicative_quantity', 1, 'Indicative quantity (tonnes)', NULL, 40),
  (1, 'inquiry_date', 1, 'Enquiry date', NULL, 50),
  (2, 'application_confirmed', 1, 'Application confirmed', 'What the material will be used for', 10),
  (2, 'decision_maker_contact', 1, 'Decision-maker contact identified', 'A contact recorded against this customer', 20),
  (2, 'rough_volume_tonnes_per_month', 1, 'Rough monthly volume (tonnes)', NULL, 30),
  (2, 'order_frequency', 1, 'Order frequency', 'e.g. weekly, monthly', 40),
  (3, 'sample_sent', 1, 'Sample sent', 'A sample record exists for this deal', 10),
  (3, 'sample_grade_batch', 1, 'Grade + batch logged', NULL, 20),
  (3, 'follow_up_date', 1, 'Follow-up date set', NULL, 30),
  (4, 'customer_feedback', 1, 'Customer feedback logged', NULL, 10),
  (4, 'technical_fit', 1, 'Technical fit confirmed or rejected', 'confirmed / rejected', 20),
  (4, 'technical_fit_reason', 1, 'Reason for the technical fit decision', NULL, 30),
  (5, 'quote_grade_code', 1, 'Quoted grade code', NULL, 10),
  (5, 'quote_spec', 1, 'Quoted specification', NULL, 20),
  (5, 'quote_validity_until', 1, 'Quote validity date', NULL, 30),
  (5, 'proposed_terms', 1, 'Proposed commercial terms', NULL, 40),
  (6, 'final_terms_agreed', 1, 'Final terms agreed', NULL, 10),
  (6, 'credit_gate_cleared', 0, 'Credit gate cleared', 'Seam for TASK 3 - not enforced yet', 20),
  (7, 'handoff_packet_transferred', 0, 'Handoff packet transferred to Dispatch', 'Seam for TASK 8 - not enforced yet', 10);

-- ---------------------------------------------------------------------------
-- Technical queues (CONFIG, seeded) and queue-routed technical flags
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_technical_queues (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT DEFAULT NULL,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_queue_name (name),
  INDEX idx_active (is_active),
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
);

INSERT IGNORE INTO crm_technical_queues (company_id, name, is_active) VALUES
  (NULL, 'Technical Support', 1),
  (NULL, 'Lab / Testing', 1);

CREATE TABLE IF NOT EXISTS crm_technical_flags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  deal_id INT DEFAULT NULL,
  party_id INT NOT NULL,
  raised_from_stage TINYINT DEFAULT NULL,
  raised_by_user_id INT DEFAULT NULL,
  nature_of_query TEXT NOT NULL,
  routed_to_queue_id INT NOT NULL,
  claimed_by_user_id INT DEFAULT NULL,
  claimed_at DATETIME DEFAULT NULL,
  expected_turnaround_at DATETIME DEFAULT NULL,
  status ENUM('open', 'claimed', 'resolved', 'cancelled') NOT NULL DEFAULT 'open',
  resolution_type ENUM('remote_answer', 'site_visit') DEFAULT NULL,
  resolution_note TEXT DEFAULT NULL,
  resolved_at DATETIME DEFAULT NULL,
  resolved_by_user_id INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_queue_status (routed_to_queue_id, status),
  INDEX idx_deal_status (deal_id, status),
  INDEX idx_status_turnaround (status, expected_turnaround_at),
  INDEX idx_party_status (party_id, status),
  FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE RESTRICT,
  FOREIGN KEY (routed_to_queue_id) REFERENCES crm_technical_queues(id) ON DELETE RESTRICT,
  FOREIGN KEY (raised_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (claimed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------------
-- crm_deals: legacy column renames, then the pipeline columns
-- ---------------------------------------------------------------------------
SET @has_varchar_stage := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals'
    AND COLUMN_NAME = 'stage' AND DATA_TYPE = 'varchar'
);
SET @sql := IF(@has_varchar_stage = 1,
  'ALTER TABLE crm_deals CHANGE COLUMN stage legacy_funnel_stage VARCHAR(50) DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_assigned_to := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals' AND COLUMN_NAME = 'assigned_to'
);
SET @sql := IF(@has_assigned_to = 1,
  'ALTER TABLE crm_deals CHANGE COLUMN assigned_to owner_user_id INT DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @lead_fk := (
  SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals'
    AND COLUMN_NAME = 'lead_id' AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(@lead_fk IS NOT NULL,
  CONCAT('ALTER TABLE crm_deals DROP FOREIGN KEY ', @lead_fk),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_lead_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals' AND COLUMN_NAME = 'lead_id'
);
SET @sql := IF(@has_lead_id = 1,
  'ALTER TABLE crm_deals CHANGE COLUMN lead_id legacy_lead_id INT DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The legacy funnel value is only meaningful for rows that predate this migration, so new
-- deals must not inherit the old default.
ALTER TABLE crm_deals MODIFY COLUMN legacy_funnel_stage VARCHAR(50) DEFAULT NULL;

ALTER TABLE crm_deals ADD COLUMN company_id INT DEFAULT NULL AFTER party_id;
ALTER TABLE crm_deals ADD COLUMN stage TINYINT NOT NULL DEFAULT 1 AFTER title;
ALTER TABLE crm_deals ADD COLUMN status ENUM('active', 'won', 'lost', 'dropped') NOT NULL DEFAULT 'active' AFTER stage;
ALTER TABLE crm_deals ADD COLUMN stage_entered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status;
ALTER TABLE crm_deals ADD COLUMN lost_reason_code_id INT DEFAULT NULL AFTER status;
ALTER TABLE crm_deals ADD COLUMN source ENUM('call', 'whatsapp', 'email', 'exhibition', 'referral', 'walk_in', 'other') NOT NULL DEFAULT 'other' AFTER stage_entered_at;
ALTER TABLE crm_deals ADD COLUMN indicative_quantity_tonnes DECIMAL(12,3) DEFAULT NULL AFTER source;
ALTER TABLE crm_deals ADD COLUMN inquiry_date DATE DEFAULT NULL AFTER indicative_quantity_tonnes;
ALTER TABLE crm_deals ADD COLUMN deleted_at DATETIME DEFAULT NULL;

UPDATE crm_deals SET inquiry_date = DATE(created_at) WHERE inquiry_date IS NULL;
ALTER TABLE crm_deals MODIFY COLUMN inquiry_date DATE NOT NULL;

-- A deal must not disappear because a party row was removed: business records soft-delete (I12).
SET @party_fk := (
  SELECT rc.CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS rc
  JOIN information_schema.KEY_COLUMN_USAGE kcu
    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
  WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = 'crm_deals'
    AND kcu.COLUMN_NAME = 'party_id' AND rc.DELETE_RULE = 'CASCADE'
  LIMIT 1
);
SET @sql := IF(@party_fk IS NOT NULL,
  CONCAT('ALTER TABLE crm_deals DROP FOREIGN KEY ', @party_fk),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @party_fk_exists := (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals'
    AND COLUMN_NAME = 'party_id' AND REFERENCED_TABLE_NAME = 'parties'
);
SET @sql := IF(@party_fk_exists = 0,
  'ALTER TABLE crm_deals ADD CONSTRAINT fk_crm_deals_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE crm_deals ADD CONSTRAINT fk_crm_deals_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL;
ALTER TABLE crm_deals ADD CONSTRAINT fk_crm_deals_reason FOREIGN KEY (lost_reason_code_id) REFERENCES crm_deal_reason_codes(id) ON DELETE RESTRICT;
ALTER TABLE crm_deals ADD INDEX idx_company_status_stage (company_id, status, stage);
ALTER TABLE crm_deals ADD INDEX idx_owner_status (owner_user_id, status);
ALTER TABLE crm_deals ADD INDEX idx_status_stage (status, stage);
ALTER TABLE crm_deals ADD INDEX idx_deleted_at (deleted_at);

-- ---------------------------------------------------------------------------
-- Grades on a deal
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_deal_grades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  deal_id INT NOT NULL,
  grade_code VARCHAR(64) NOT NULL,
  indicative_qty_tonnes DECIMAL(12,3) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_deal_grade (deal_id, grade_code),
  FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------------
-- Append-only transition log. Never updated, never deleted.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_deal_stage_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  deal_id INT NOT NULL,
  from_stage TINYINT DEFAULT NULL,
  to_stage TINYINT DEFAULT NULL,
  from_status ENUM('active', 'won', 'lost', 'dropped') DEFAULT NULL,
  to_status ENUM('active', 'won', 'lost', 'dropped') DEFAULT NULL,
  reason_code_id INT DEFAULT NULL,
  reason_note TEXT DEFAULT NULL,
  exit_criteria_snapshot JSON DEFAULT NULL,
  actor_user_id INT DEFAULT NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_deal_occurred (deal_id, occurred_at),
  FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE CASCADE,
  FOREIGN KEY (reason_code_id) REFERENCES crm_deal_reason_codes(id) ON DELETE RESTRICT,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------------
-- Values captured against config-driven exit criteria that are not derivable
-- from an existing record (feedback text, agreed terms, quote spec, ...).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_deal_criteria_values (
  id INT AUTO_INCREMENT PRIMARY KEY,
  deal_id INT NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  value_text TEXT DEFAULT NULL,
  updated_by_user_id INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_deal_field (deal_id, field_key),
  FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------------
-- Retire crm_leads: one writable entity means "sales opportunity" (I14).
-- Renamed, not dropped, so any surviving write path fails loudly.
-- ---------------------------------------------------------------------------
SET @leads_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_leads'
);
SET @archive_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_archived_crm_leads'
);
SET @sql := IF(@leads_exists = 1 AND @archive_exists = 0,
  'RENAME TABLE crm_leads TO _archived_crm_leads',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Migration 012 re-creates crm_leads on every run of the migration script. Once the archive
-- exists, that recreated shell is an empty duplicate entity: remove it (only when empty).
SET @leads_rows := 1;
SET @sql := IF(@leads_exists = 1 AND @archive_exists = 1,
  'SELECT COUNT(*) INTO @leads_rows FROM crm_leads',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@leads_exists = 1 AND @archive_exists = 1 AND @leads_rows = 0,
  'DROP TABLE crm_leads',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
