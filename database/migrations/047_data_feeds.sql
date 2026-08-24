-- Daily batch ingest for dispatch and ledger files.
-- Manual upload only (B1). Nothing in this schema implies a live Busy/API feed.
-- Rollback: database/rollback/047_data_feeds.down.sql

-- ---------------------------------------------------------------------------
-- CONFIG: one row per feed per legal entity. Owner and deadline are editable
-- without a deploy.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS data_feeds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  feed_key ENUM('dispatch_day_file', 'ledger') NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  owner_user_id INT NULL,
  deadline_local_time TIME NOT NULL DEFAULT '09:00:00',
  company_id INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_feed_company (feed_key, company_id),
  INDEX idx_feed_active (feed_key, is_active),
  CONSTRAINT fk_data_feeds_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_data_feeds_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------------
-- One attempt to ingest a file for a feed + company + business date.
-- UNIQUE (feed_key, company_id, business_date, file_hash) makes a byte-identical
-- re-upload a no-op rather than a duplicate insert.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS data_feed_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  feed_key ENUM('dispatch_day_file', 'ledger') NOT NULL,
  company_id INT NOT NULL,
  business_date DATE NOT NULL,
  uploaded_by_user_id INT NULL,
  uploaded_at DATETIME NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  status ENUM('uploaded', 'validating', 'validated', 'promoting', 'completed', 'failed', 'superseded') NOT NULL DEFAULT 'uploaded',
  rows_total INT NOT NULL DEFAULT 0,
  rows_accepted INT NOT NULL DEFAULT 0,
  rows_rejected INT NOT NULL DEFAULT 0,
  as_of DATETIME NULL,
  error_summary TEXT NULL,
  replaces_run_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_feed_run_hash (feed_key, company_id, business_date, file_hash),
  INDEX idx_feed_company_date (feed_key, company_id, business_date),
  INDEX idx_feed_status (feed_key, company_id, status),
  CONSTRAINT fk_data_feed_runs_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_data_feed_runs_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_data_feed_runs_replaces FOREIGN KEY (replaces_run_id) REFERENCES data_feed_runs(id) ON DELETE SET NULL
);

-- Staging rows. Promoted atomically after validation + operator confirm.
CREATE TABLE IF NOT EXISTS data_feed_rows (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  row_number INT NOT NULL,
  raw JSON NOT NULL,
  status ENUM('pending', 'valid', 'rejected', 'promoted') NOT NULL DEFAULT 'pending',
  rejection_reason VARCHAR(255) NULL,
  resolved_party_id INT NULL,
  INDEX idx_run_status (run_id, status),
  CONSTRAINT fk_data_feed_rows_run FOREIGN KEY (run_id) REFERENCES data_feed_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_data_feed_rows_party FOREIGN KEY (resolved_party_id) REFERENCES parties(id) ON DELETE SET NULL
);

-- Learned name/code mappings. Unmatched parties are never auto-created.
CREATE TABLE IF NOT EXISTS party_source_aliases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source_system ENUM('busy', 'dispatch') NOT NULL,
  source_identifier VARCHAR(255) NOT NULL,
  party_id INT NOT NULL,
  confidence ENUM('exact', 'manual') NOT NULL DEFAULT 'manual',
  created_by_user_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_source_identifier (source_system, source_identifier),
  INDEX idx_alias_party (party_id),
  CONSTRAINT fk_party_aliases_party FOREIGN KEY (party_id) REFERENCES parties(id),
  CONSTRAINT fk_party_aliases_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Live snapshot written only on successful promote of a ledger run.
CREATE TABLE IF NOT EXISTS ledger_outstanding (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  company_id INT NOT NULL,
  party_id INT NOT NULL,
  business_date DATE NOT NULL,
  outstanding_amount DECIMAL(14,2) NOT NULL,
  invoice_no VARCHAR(255) NULL,
  invoice_date DATE NULL,
  as_of DATETIME NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ledger_company_date (company_id, business_date),
  INDEX idx_ledger_party (party_id, business_date),
  INDEX idx_ledger_run (run_id),
  INDEX idx_ledger_invoice (company_id, invoice_no),
  CONSTRAINT fk_ledger_outstanding_run FOREIGN KEY (run_id) REFERENCES data_feed_runs(id),
  CONSTRAINT fk_ledger_outstanding_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_ledger_outstanding_party FOREIGN KEY (party_id) REFERENCES parties(id)
);

-- Live rows written only on successful promote of a dispatch day file.
CREATE TABLE IF NOT EXISTS dispatch_day_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  company_id INT NOT NULL,
  party_id INT NOT NULL,
  business_date DATE NOT NULL,
  grade_code VARCHAR(64) NOT NULL,
  quantity_tonnes DECIMAL(12,3) NOT NULL,
  vehicle_no VARCHAR(64) NULL,
  destination VARCHAR(255) NULL,
  invoice_no VARCHAR(255) NULL,
  as_of DATETIME NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dispatch_company_date (company_id, business_date),
  INDEX idx_dispatch_party (party_id, business_date),
  INDEX idx_dispatch_run (run_id),
  CONSTRAINT fk_dispatch_day_run FOREIGN KEY (run_id) REFERENCES data_feed_runs(id),
  CONSTRAINT fk_dispatch_day_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_dispatch_day_party FOREIGN KEY (party_id) REFERENCES parties(id)
);

-- Prevent overlapping promote of the same run (cron + locking table, no queue).
CREATE TABLE IF NOT EXISTS data_feed_locks (
  run_id INT PRIMARY KEY,
  locked_by_user_id INT NULL,
  locked_at DATETIME NOT NULL,
  CONSTRAINT fk_data_feed_locks_run FOREIGN KEY (run_id) REFERENCES data_feed_runs(id) ON DELETE CASCADE
);

-- Seed one ledger feed and one dispatch feed per company. Deadline and owner
-- are config — changeable later from the dashboard with no deploy.
INSERT INTO data_feeds (feed_key, display_name, owner_user_id, deadline_local_time, company_id, is_active)
SELECT 'ledger', CONCAT(c.name, ' — Ledger (Busy)'), NULL, '09:00:00', c.id, 1
FROM companies c
WHERE NOT EXISTS (
  SELECT 1 FROM data_feeds df WHERE df.feed_key = 'ledger' AND df.company_id = c.id
);

INSERT INTO data_feeds (feed_key, display_name, owner_user_id, deadline_local_time, company_id, is_active)
SELECT 'dispatch_day_file', CONCAT(c.name, ' — Dispatch day file'), NULL, '18:00:00', c.id, 1
FROM companies c
WHERE NOT EXISTS (
  SELECT 1 FROM data_feeds df WHERE df.feed_key = 'dispatch_day_file' AND df.company_id = c.id
);
