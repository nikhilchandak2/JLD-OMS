-- Credit approval workflow for over-credit orders
-- Creates a request record per order when party is over its credit limit.

CREATE TABLE IF NOT EXISTS credit_approval_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL UNIQUE,
  party_id INT NOT NULL,
  outstanding DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
  requested_by INT NOT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(500) NULL,

  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (party_id) REFERENCES parties(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (decided_by) REFERENCES users(id),

  INDEX idx_status (status),
  INDEX idx_party_id (party_id),
  INDEX idx_requested_at (requested_at)
);

