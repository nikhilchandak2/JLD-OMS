-- TDS reports (Accounts): Busy Supply Outward Vouchers classified by Price slab + Material Centre

CREATE TABLE IF NOT EXISTS tds_uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  original_filename VARCHAR(255) NOT NULL,
  file_type VARCHAR(20) NOT NULL,
  period_label VARCHAR(120) NULL,
  period_from DATE NULL,
  period_to DATE NULL,
  rows_imported INT NOT NULL DEFAULT 0,
  uploaded_by INT NULL,
  parse_notes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_tds_uploads_created (created_at),
  INDEX idx_tds_uploads_period (period_from, period_to)
);

CREATE TABLE IF NOT EXISTS tds_voucher_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  upload_id INT NOT NULL,
  voucher_date DATE NULL,
  voucher_date_raw VARCHAR(40) NULL,
  voucher_no VARCHAR(80) NULL,
  particulars VARCHAR(255) NULL,
  item_details VARCHAR(255) NULL,
  material_centre VARCHAR(255) NOT NULL,
  qty DECIMAL(14, 3) NOT NULL DEFAULT 0,
  unit VARCHAR(40) NULL,
  price DECIMAL(14, 4) NOT NULL DEFAULT 0,
  amount DECIMAL(16, 2) NOT NULL DEFAULT 0,
  price_band ENUM('below_1000', '1000_1500', '1500_2000', '2000_plus') NOT NULL,

  INDEX idx_tds_lines_upload (upload_id),
  INDEX idx_tds_lines_centre (material_centre),
  INDEX idx_tds_lines_band (price_band),
  INDEX idx_tds_lines_date (voucher_date),
  CONSTRAINT fk_tds_lines_upload FOREIGN KEY (upload_id) REFERENCES tds_uploads(id) ON DELETE CASCADE
);
