-- BRD-aligned CRM: party extensions, samples/trials, receivables.

ALTER TABLE parties
  ADD COLUMN region VARCHAR(100) DEFAULT NULL COMMENT 'e.g. Morbi, Export',
  ADD COLUMN product_category VARCHAR(100) DEFAULT NULL COMMENT 'tiles, sanitary, tableware',
  ADD COLUMN production_capacity VARCHAR(255) DEFAULT NULL,
  ADD COLUMN factory_locations TEXT DEFAULT NULL,
  ADD COLUMN credit_limit DECIMAL(15,2) DEFAULT NULL,
  ADD COLUMN payment_terms_days INT DEFAULT NULL COMMENT 'e.g. 90, 180',
  ADD COLUMN technical_notes TEXT DEFAULT NULL COMMENT 'Body formulation, clay requirements';

CREATE TABLE IF NOT EXISTS crm_samples (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  deal_id INT DEFAULT NULL,
  sample_type VARCHAR(100) DEFAULT NULL,
  quantity_sent VARCHAR(100) DEFAULT NULL,
  request_date DATE DEFAULT NULL,
  dispatch_date DATE DEFAULT NULL,
  trial_date DATE DEFAULT NULL,
  status VARCHAR(50) DEFAULT 'sample_sent' COMMENT 'sample_sent, trial_scheduled, trial_successful, trial_failed, trial_retesting',
  outcome VARCHAR(255) DEFAULT NULL,
  technical_feedback TEXT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_party_id (party_id),
  INDEX idx_deal_id (deal_id),
  INDEX idx_status (status)
);

CREATE TABLE IF NOT EXISTS crm_sample_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sample_id INT NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sample_id) REFERENCES crm_samples(id) ON DELETE CASCADE,
  INDEX idx_sample_id (sample_id)
);

CREATE TABLE IF NOT EXISTS crm_receivable_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  entry_type VARCHAR(20) NOT NULL COMMENT 'invoice, payment, adjustment',
  amount DECIMAL(15,2) NOT NULL,
  entry_date DATE NOT NULL,
  reference VARCHAR(255) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_party_id (party_id),
  INDEX idx_entry_date (entry_date)
);
