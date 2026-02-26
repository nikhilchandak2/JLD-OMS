-- Export Documents (Nepal) module - separate from OMS orders/dispatches/tracking.
-- Used only for Nepal export: Commercial Invoice, Tax Invoice, Packing List generation.

CREATE TABLE IF NOT EXISTS export_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reference_no VARCHAR(100) NOT NULL COMMENT 'e.g. NEPAL/EXP-045',
  buyer_po_no VARCHAR(200) DEFAULT NULL,
  buyer_po_date DATE DEFAULT NULL,
  consignee VARCHAR(500) DEFAULT NULL COMMENT 'e.g. TO THE ORDER OF HIMALAYAN BANK LTD',
  notify_applicant VARCHAR(500) DEFAULT NULL,
  pan_no VARCHAR(50) DEFAULT NULL,
  exim_code VARCHAR(50) DEFAULT NULL,
  lc_number VARCHAR(100) DEFAULT NULL,
  lc_issue_date DATE DEFAULT NULL,
  harmonic_code VARCHAR(50) DEFAULT NULL,
  country_origin VARCHAR(100) DEFAULT 'INDIAN ORIGIN',
  customs_entry VARCHAR(255) DEFAULT NULL,
  payment_terms VARCHAR(255) DEFAULT NULL,
  delivery_terms VARCHAR(255) DEFAULT NULL,
  product_description TEXT DEFAULT NULL,
  packaging VARCHAR(255) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_reference (reference_no),
  INDEX idx_created (created_at)
);
