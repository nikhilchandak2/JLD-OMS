-- CRM module: contacts, leads, deals, activities.
-- Parties are used as customer accounts (party_id references parties.id).

CREATE TABLE IF NOT EXISTS crm_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  role VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  is_primary TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  INDEX idx_party_id (party_id)
);

CREATE TABLE IF NOT EXISTS crm_leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  company_name VARCHAR(255) DEFAULT NULL,
  contact_name VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  source VARCHAR(100) DEFAULT NULL COMMENT 'e.g. website, referral',
  value DECIMAL(15,2) DEFAULT NULL,
  stage VARCHAR(50) DEFAULT 'new' COMMENT 'new, contacted, qualified, converted, lost',
  party_id INT DEFAULT NULL COMMENT 'set when linked to existing party',
  assigned_to INT DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_stage (stage),
  INDEX idx_party_id (party_id),
  INDEX idx_assigned_to (assigned_to)
);

CREATE TABLE IF NOT EXISTS crm_deals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  lead_id INT DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  value DECIMAL(15,2) DEFAULT NULL,
  stage VARCHAR(50) DEFAULT 'qualified' COMMENT 'qualified, proposal, negotiation, won, lost',
  expected_close_date DATE DEFAULT NULL,
  assigned_to INT DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_party_id (party_id),
  INDEX idx_stage (stage),
  INDEX idx_lead_id (lead_id)
);

CREATE TABLE IF NOT EXISTS crm_activities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  deal_id INT DEFAULT NULL,
  contact_id INT DEFAULT NULL,
  type VARCHAR(50) NOT NULL COMMENT 'call, meeting, note, email',
  subject VARCHAR(500) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  activity_date DATETIME NOT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE SET NULL,
  FOREIGN KEY (contact_id) REFERENCES crm_contacts(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_party_id (party_id),
  INDEX idx_deal_id (deal_id),
  INDEX idx_activity_date (activity_date)
);
