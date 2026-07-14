-- Rejected / transferred truck workflow (party rejection → credit note or transfer to another party)

ALTER TABLE dispatches
  ADD COLUMN status ENUM('active', 'rejected', 'transferred') NOT NULL DEFAULT 'active' AFTER dispatch_qty_trucks,
  ADD COLUMN rejection_reason TEXT NULL AFTER status,
  ADD COLUMN transferred_to_dispatch_id INT NULL AFTER rejection_reason,
  ADD COLUMN source_dispatch_id INT NULL AFTER transferred_to_dispatch_id;

CREATE INDEX idx_dispatches_status ON dispatches (status);

CREATE TABLE IF NOT EXISTS dispatch_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source_dispatch_id INT NOT NULL,
  target_dispatch_id INT NULL,
  source_order_id INT NOT NULL,
  target_order_id INT NULL,
  source_party_id INT NOT NULL,
  target_party_id INT NULL,
  trucks_transferred INT NOT NULL DEFAULT 1,
  weight_tons DECIMAL(10,3) NULL,
  action_type ENUM('transfer', 'credit_note', 'replacement') NOT NULL,
  reason TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dt_source_dispatch FOREIGN KEY (source_dispatch_id) REFERENCES dispatches(id),
  CONSTRAINT fk_dt_target_dispatch FOREIGN KEY (target_dispatch_id) REFERENCES dispatches(id),
  CONSTRAINT fk_dt_source_order FOREIGN KEY (source_order_id) REFERENCES orders(id),
  CONSTRAINT fk_dt_target_order FOREIGN KEY (target_order_id) REFERENCES orders(id),
  CONSTRAINT fk_dt_source_party FOREIGN KEY (source_party_id) REFERENCES parties(id),
  CONSTRAINT fk_dt_target_party FOREIGN KEY (target_party_id) REFERENCES parties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS credit_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  dispatch_id INT NULL,
  order_id INT NULL,
  busy_credit_note_no VARCHAR(100) NULL,
  original_invoice_no VARCHAR(100) NULL,
  amount DECIMAL(14,2) NOT NULL,
  weight_tons DECIMAL(10,3) NULL,
  rate_per_ton DECIMAL(14,2) NULL,
  note_date DATE NOT NULL,
  reason TEXT NULL,
  status ENUM('draft', 'posted') NOT NULL DEFAULT 'posted',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_credit_notes_busy_no (busy_credit_note_no),
  CONSTRAINT fk_cn_party FOREIGN KEY (party_id) REFERENCES parties(id),
  CONSTRAINT fk_cn_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatches(id),
  CONSTRAINT fk_cn_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
