-- CRM Tasks (sales-owner assigned task panel)
-- Created per requirement: each sales owner can view their tasks; admin can assign tasks.
CREATE TABLE IF NOT EXISTS crm_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  party_id INT DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending' COMMENT 'pending, completed',
  assigned_to INT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_assigned_to (assigned_to),
  INDEX idx_status (status),
  INDEX idx_due_date (due_date)
);

