-- Add invoice date range on Busy upload history (for filtering by invoice date, e.g. 1 Jul).
-- App also adds these via BusyInvoiceUploadRepository::ensureSchema().

SET @col_from := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'busy_invoice_uploads'
    AND COLUMN_NAME = 'invoice_date_from'
);
SET @sql_from := IF(@col_from = 0,
  'ALTER TABLE busy_invoice_uploads ADD COLUMN invoice_date_from DATE NULL AFTER company_id',
  'SELECT 1'
);
PREPARE stmt_from FROM @sql_from;
EXECUTE stmt_from;
DEALLOCATE PREPARE stmt_from;

SET @col_to := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'busy_invoice_uploads'
    AND COLUMN_NAME = 'invoice_date_to'
);
SET @sql_to := IF(@col_to = 0,
  'ALTER TABLE busy_invoice_uploads ADD COLUMN invoice_date_to DATE NULL AFTER invoice_date_from',
  'SELECT 1'
);
PREPARE stmt_to FROM @sql_to;
EXECUTE stmt_to;
DEALLOCATE PREPARE stmt_to;
