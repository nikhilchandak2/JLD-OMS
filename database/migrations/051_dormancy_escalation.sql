-- Dormancy detection and escalation engine (TASK 6).
-- Read models refreshed nightly. Never aggregate orders/dispatches on page load.
-- B3: 20 days, uniform. account_tier stays nullable for a later config row.
-- forecast_gap_flag is stored now; TASK 7 populates it.
-- Rollback: database/rollback/051_dormancy_escalation.down.sql

ALTER TABLE parties
  ADD COLUMN account_tier VARCHAR(50) NULL;

ALTER TABLE parties
  ADD INDEX idx_parties_sales_owner (assigned_sales_owner);

ALTER TABLE parties
  ADD INDEX idx_parties_account_tier (account_tier);

ALTER TABLE orders
  ADD INDEX idx_orders_party_date (party_id, order_date);

-- ---------------------------------------------------------------------------
-- Config: one group-wide row at launch. Per-company / per-tier is a config row later.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dormancy_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NULL,
  account_tier VARCHAR(50) NULL,
  days_no_order INT NOT NULL,
  days_no_order_urgent INT NOT NULL,
  days_no_visit INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_dormancy_rules_match (company_id, account_tier, is_active),
  CONSTRAINT fk_dormancy_rules_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

INSERT INTO dormancy_rules (company_id, account_tier, days_no_order, days_no_order_urgent, days_no_visit, is_active)
SELECT NULL, NULL, 20, 20, 20, 1
WHERE NOT EXISTS (
  SELECT 1 FROM dormancy_rules WHERE company_id IS NULL AND account_tier IS NULL
);

-- Daily snapshot. Truncate-and-rebuild for computed_on = today; earlier days kept.
CREATE TABLE IF NOT EXISTS account_dormancy_signals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  company_id INT NULL,
  computed_on DATE NOT NULL,
  days_since_last_order INT NULL,
  last_order_date DATE NULL,
  days_since_last_visit INT NULL,
  last_visit_date DATE NULL,
  severity ENUM('watch','urgent') NOT NULL,
  reason_summary VARCHAR(512) NOT NULL,
  forecast_gap_flag TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dormancy_party_day (party_id, computed_on),
  INDEX idx_dormancy_company_severity (company_id, severity, computed_on),
  INDEX idx_dormancy_severity_day (severity, computed_on),
  CONSTRAINT fk_dormancy_signals_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  CONSTRAINT fk_dormancy_signals_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS escalation_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NULL,
  trigger_type ENUM('dormant_account','unresolved_issue','dispatch_delay','technical_overdue','manual_flag') NOT NULL,
  threshold_days INT NULL,
  escalate_to_role VARCHAR(50) NOT NULL DEFAULT 'admin',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_escalation_rules_type (trigger_type, is_active),
  CONSTRAINT fk_escalation_rules_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

INSERT INTO escalation_rules (company_id, trigger_type, threshold_days, escalate_to_role, is_active)
SELECT NULL, 'dormant_account', 20, 'admin', 1
WHERE NOT EXISTS (SELECT 1 FROM escalation_rules WHERE trigger_type = 'dormant_account' AND company_id IS NULL);

INSERT INTO escalation_rules (company_id, trigger_type, threshold_days, escalate_to_role, is_active)
SELECT NULL, 'unresolved_issue', NULL, 'admin', 1
WHERE NOT EXISTS (SELECT 1 FROM escalation_rules WHERE trigger_type = 'unresolved_issue' AND company_id IS NULL);

INSERT INTO escalation_rules (company_id, trigger_type, threshold_days, escalate_to_role, is_active)
SELECT NULL, 'dispatch_delay', 1, 'admin', 1
WHERE NOT EXISTS (SELECT 1 FROM escalation_rules WHERE trigger_type = 'dispatch_delay' AND company_id IS NULL);

INSERT INTO escalation_rules (company_id, trigger_type, threshold_days, escalate_to_role, is_active)
SELECT NULL, 'technical_overdue', 0, 'admin', 1
WHERE NOT EXISTS (SELECT 1 FROM escalation_rules WHERE trigger_type = 'technical_overdue' AND company_id IS NULL);

INSERT INTO escalation_rules (company_id, trigger_type, threshold_days, escalate_to_role, is_active)
SELECT NULL, 'manual_flag', NULL, 'admin', 1
WHERE NOT EXISTS (SELECT 1 FROM escalation_rules WHERE trigger_type = 'manual_flag' AND company_id IS NULL);

-- source_* and episode_key identify the episode so nightly runs cannot spam.
CREATE TABLE IF NOT EXISTS escalations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NULL,
  party_id INT NOT NULL,
  deal_id INT NULL,
  trigger_type ENUM('dormant_account','unresolved_issue','dispatch_delay','technical_overdue','manual_flag') NOT NULL,
  source_table VARCHAR(64) NULL,
  source_id INT NULL,
  episode_key VARCHAR(64) NOT NULL,
  triggered_on DATE NOT NULL,
  triggered_by ENUM('system','user') NOT NULL,
  triggered_by_user_id INT NULL,
  context_snapshot JSON NOT NULL,
  status ENUM('open','acknowledged','resolved','dismissed') NOT NULL DEFAULT 'open',
  acknowledged_by_user_id INT NULL,
  acknowledged_at DATETIME NULL,
  resolution_note TEXT NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_escalations_inbox (company_id, status, triggered_on),
  INDEX idx_escalations_party (party_id, status),
  INDEX idx_escalations_episode (party_id, trigger_type, episode_key, status),
  INDEX idx_escalations_source (source_table, source_id, status),
  CONSTRAINT fk_escalations_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  CONSTRAINT fk_escalations_deal FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE SET NULL,
  CONSTRAINT fk_escalations_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  CONSTRAINT fk_escalations_triggered_by FOREIGN KEY (triggered_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_escalations_ack_by FOREIGN KEY (acknowledged_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS crm_job_locks (
  job_name VARCHAR(64) PRIMARY KEY,
  locked_at DATETIME NOT NULL,
  locked_by VARCHAR(128) NULL
);

CREATE TABLE IF NOT EXISTS crm_job_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_name VARCHAR(64) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status ENUM('running','ok','failed','skipped') NOT NULL,
  summary JSON NULL,
  error_text TEXT NULL,
  INDEX idx_job_runs_name_started (job_name, started_at)
);
