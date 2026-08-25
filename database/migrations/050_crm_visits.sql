-- Sales visit logging (TASK 5). Distinct from visit_requests (technical team jobs).
-- next_planned_touchpoint is required unless no_followup_needed is set with a reason.
-- Rollback: database/rollback/050_crm_visits.down.sql

CREATE TABLE IF NOT EXISTS crm_visits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  deal_id INT NULL,
  visited_by_user_id INT NULL,
  visit_date DATE NOT NULL,
  purpose TEXT NULL,
  outcome TEXT NULL,
  next_planned_touchpoint DATE NULL,
  next_action TEXT NULL,
  no_followup_needed TINYINT(1) NOT NULL DEFAULT 0,
  no_followup_reason TEXT NULL,
  logged_via ENUM('web','mobile','voice') NOT NULL DEFAULT 'web',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_visits_party_date (party_id, visit_date),
  INDEX idx_visits_owner_date (visited_by_user_id, visit_date),
  INDEX idx_visits_overdue (visited_by_user_id, next_planned_touchpoint),
  INDEX idx_visits_touchpoint (next_planned_touchpoint),
  CONSTRAINT fk_crm_visits_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_visits_deal FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_visits_visitor FOREIGN KEY (visited_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_visit_followup CHECK (
    (next_planned_touchpoint IS NOT NULL AND no_followup_needed = 0)
    OR (no_followup_needed = 1 AND no_followup_reason IS NOT NULL)
  )
);

CREATE TABLE IF NOT EXISTS crm_visit_contacts (
  visit_id INT NOT NULL,
  contact_id INT NOT NULL,
  PRIMARY KEY (visit_id, contact_id),
  INDEX idx_visit_contacts_contact (contact_id),
  CONSTRAINT fk_visit_contacts_visit FOREIGN KEY (visit_id) REFERENCES crm_visits(id) ON DELETE CASCADE,
  CONSTRAINT fk_visit_contacts_contact FOREIGN KEY (contact_id) REFERENCES crm_contacts(id) ON DELETE CASCADE
);
