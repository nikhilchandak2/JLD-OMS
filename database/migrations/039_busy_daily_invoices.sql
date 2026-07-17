-- Daily Busy invoice upload ledger: every CSV/PDF invoice row, mapped or not.
-- Unmapped rows surface invoices with no matching portal order.

CREATE TABLE IF NOT EXISTS busy_daily_invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(100) NOT NULL,
  invoice_date DATE NOT NULL,
  party_name VARCHAR(255) NOT NULL,
  product_name VARCHAR(255) NULL,
  product_rate DECIMAL(14,2) NULL,
  quantity_trucks INT NOT NULL DEFAULT 1,
  loading_weight_tons DECIMAL(10,3) NULL,
  vehicle_no VARCHAR(50) NULL,
  rawana_no VARCHAR(100) NULL,
  eway_bill_no VARCHAR(100) NULL,
  order_no_from_invoice VARCHAR(100) NULL,
  company_id INT NULL,
  order_id INT NULL,
  dispatch_id INT NULL,
  mapping_status ENUM('mapped', 'unmapped', 'error') NOT NULL DEFAULT 'unmapped',
  error_message TEXT NULL,
  uploaded_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_busy_daily_invoice_no (invoice_no),
  KEY idx_busy_daily_invoice_date (invoice_date),
  KEY idx_busy_daily_mapping_status (mapping_status),
  KEY idx_busy_daily_company (company_id),
  CONSTRAINT fk_bdi_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  CONSTRAINT fk_bdi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_bdi_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatches(id) ON DELETE SET NULL,
  CONSTRAINT fk_bdi_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
