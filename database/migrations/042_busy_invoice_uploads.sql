-- Busy invoice CSV/PDF upload history (file-level batches).
-- Safe to re-run: CREATE IF NOT EXISTS; column add is best-effort via app ensureSchema too.

CREATE TABLE IF NOT EXISTS busy_invoice_uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  original_filename VARCHAR(255) NOT NULL,
  file_type VARCHAR(20) NOT NULL DEFAULT 'csv',
  stored_path VARCHAR(500) NULL,
  file_size INT NULL,
  company_id INT NULL,
  invoice_count INT NOT NULL DEFAULT 0,
  mapped_count INT NOT NULL DEFAULT 0,
  unmapped_count INT NOT NULL DEFAULT 0,
  failed_count INT NOT NULL DEFAULT 0,
  status ENUM('processed', 'partial', 'failed', 'legacy') NOT NULL DEFAULT 'processed',
  parse_notes TEXT NULL,
  uploaded_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_busy_uploads_created (created_at),
  INDEX idx_busy_uploads_status (status),
  INDEX idx_busy_uploads_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link daily ledger rows to upload batch (ignore error if column already exists)
-- App also adds this via BusyInvoiceUploadRepository::ensureSchema()
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'busy_daily_invoices'
    AND COLUMN_NAME = 'upload_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE busy_daily_invoices ADD COLUMN upload_id INT NULL AFTER uploaded_by, ADD INDEX idx_busy_daily_upload (upload_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
