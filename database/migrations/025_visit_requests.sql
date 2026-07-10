-- Visit requests: marketing team raises a request for the technical team to visit a client.
-- Lifecycle: pending -> accepted (technical takes it) -> scheduled -> completed / cancelled.

CREATE TABLE IF NOT EXISTS visit_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  requested_by INT NOT NULL,
  assigned_to INT NULL,
  purpose VARCHAR(500) NOT NULL,
  preferred_date DATE NULL,
  priority ENUM('normal', 'urgent') NOT NULL DEFAULT 'normal',
  status ENUM('pending', 'accepted', 'scheduled', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  scheduled_date DATE NULL,
  visit_outcome VARCHAR(1000) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (party_id) REFERENCES parties(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (assigned_to) REFERENCES users(id),

  INDEX idx_status (status),
  INDEX idx_party (party_id),
  INDEX idx_assigned (assigned_to)
);
