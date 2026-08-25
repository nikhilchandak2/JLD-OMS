-- Nightly pipeline dashboard read model (TASK 10).
-- Truncate-and-rebuild. Views read these tables, not live aggregates.
-- Rollback: database/rollback/055_pipeline_dashboard.down.sql

CREATE TABLE IF NOT EXISTS pipeline_deal_snapshot (
  as_of DATE NOT NULL,
  deal_id INT NOT NULL,
  stage TINYINT NOT NULL,
  status VARCHAR(20) NOT NULL,
  owner_user_id INT NULL,
  owner_name VARCHAR(255) NULL,
  party_id INT NOT NULL,
  party_name VARCHAR(255) NOT NULL,
  title VARCHAR(255) NOT NULL,
  indicative_value DECIMAL(15,2) NULL,
  inquiry_date DATE NULL,
  stage_entered_at DATETIME NULL,
  PRIMARY KEY (as_of, deal_id),
  INDEX idx_pipeline_snapshot_stage (as_of, status, stage),
  INDEX idx_pipeline_snapshot_owner (as_of, owner_user_id, status),
  INDEX idx_pipeline_snapshot_inquiry (as_of, inquiry_date, status)
);

CREATE TABLE IF NOT EXISTS pipeline_deal_snapshot_grades (
  as_of DATE NOT NULL,
  deal_id INT NOT NULL,
  grade_code VARCHAR(50) NOT NULL,
  PRIMARY KEY (as_of, deal_id, grade_code),
  INDEX idx_pipeline_snapshot_grade (as_of, grade_code, deal_id)
);

CREATE TABLE IF NOT EXISTS pipeline_time_in_stage_facts (
  as_of DATE NOT NULL,
  deal_id INT NOT NULL,
  stage TINYINT NOT NULL,
  status VARCHAR(20) NOT NULL,
  owner_user_id INT NULL,
  owner_name VARCHAR(255) NULL,
  party_id INT NOT NULL,
  party_name VARCHAR(255) NOT NULL,
  title VARCHAR(255) NOT NULL,
  inquiry_date DATE NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  lifetime_seconds INT NOT NULL DEFAULT 0,
  hold_seconds INT NOT NULL DEFAULT 0,
  current_dwell_seconds INT NULL,
  current_hold_seconds INT NULL,
  PRIMARY KEY (as_of, deal_id, stage),
  INDEX idx_pipeline_tis_current (as_of, is_current, status, stage),
  INDEX idx_pipeline_tis_owner (as_of, owner_user_id, is_current, status)
);
