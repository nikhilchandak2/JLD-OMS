-- Transitional handover notes for the new-rep briefing (TASK 9).
-- This table is a temporary knowledge dump while system data is still thin.
-- Review date lives in config/briefing.php. Not a permanent capture surface.
-- Rollback: database/rollback/054_handover_notes.down.sql

CREATE TABLE IF NOT EXISTS party_handover_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  author_user_id INT NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  INDEX idx_handover_party_active (party_id, is_active, created_at),
  CONSTRAINT fk_handover_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  CONSTRAINT fk_handover_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
);
