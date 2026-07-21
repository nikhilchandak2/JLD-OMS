-- Fuel Management: monthly vendor reports (Kobelco / JCB / Dumpers)
-- Unified JLD format: machines + per-day fuel / hours / average usage

CREATE TABLE IF NOT EXISTS fuel_report_uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category ENUM('kobelco', 'jcb', 'dumpers') NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  file_type VARCHAR(20) NOT NULL,
  stored_path VARCHAR(500) NULL,
  report_month DATE NULL COMMENT 'First day of report month when detectable',
  uploaded_by INT NULL,
  machines_found INT NOT NULL DEFAULT 0,
  readings_saved INT NOT NULL DEFAULT 0,
  parse_notes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_fuel_uploads_category (category),
  INDEX idx_fuel_uploads_month (report_month)
);

CREATE TABLE IF NOT EXISTS fuel_machines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category ENUM('kobelco', 'jcb', 'dumpers') NOT NULL,
  name VARCHAR(255) NULL,
  serial_no VARCHAR(120) NULL,
  chassis_no VARCHAR(120) NULL,
  identity_key VARCHAR(255) NOT NULL COMMENT 'Normalized unique key within category',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_fuel_machine_identity (category, identity_key),
  INDEX idx_fuel_machines_category (category)
);

CREATE TABLE IF NOT EXISTS fuel_daily_readings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  machine_id INT NOT NULL,
  upload_id INT NULL,
  reading_date DATE NULL,
  fuel_consumed_liters DECIMAL(12, 2) NULL,
  working_hours DECIMAL(10, 2) NULL,
  average_usage DECIMAL(12, 4) NULL,
  extra_json JSON NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (machine_id) REFERENCES fuel_machines(id) ON DELETE CASCADE,
  FOREIGN KEY (upload_id) REFERENCES fuel_report_uploads(id) ON DELETE SET NULL,
  INDEX idx_fuel_readings_machine (machine_id),
  INDEX idx_fuel_readings_date (reading_date),
  INDEX idx_fuel_readings_upload (upload_id)
);
