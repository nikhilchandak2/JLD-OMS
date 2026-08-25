-- Relationship mapping, competitive intelligence, and account context (TASK 4).
-- Competitor rows are append-with-current-flag, never overwritten.
-- Rollback: database/rollback/049_account_context.down.sql
--
-- parties.relation_with_purchase, relation_with_internal_team, probability_of_conversion
-- are the old 1-5 star scores these structured fields supersede. They are NOT dropped.

-- ---------------------------------------------------------------------------
-- crm_contacts: institutional knowledge a new rep will not have
-- New columns are nullable or defaulted so existing rows stay readable.
-- ---------------------------------------------------------------------------
ALTER TABLE crm_contacts
  ADD COLUMN influence_level ENUM('decision_maker','technical_gatekeeper','end_user','blocker','unknown') NOT NULL DEFAULT 'unknown';

ALTER TABLE crm_contacts
  ADD COLUMN relationship_strength ENUM('strong','neutral','cold','unknown') NOT NULL DEFAULT 'unknown';

ALTER TABLE crm_contacts
  ADD COLUMN introduced_by_user_id INT NULL;

ALTER TABLE crm_contacts
  ADD COLUMN introduced_on DATE NULL;

ALTER TABLE crm_contacts
  ADD COLUMN preferred_channel ENUM('call','whatsapp','email','in_person') NULL;

ALTER TABLE crm_contacts
  ADD COLUMN preferred_language VARCHAR(50) NULL;

ALTER TABLE crm_contacts
  ADD COLUMN context_notes TEXT NULL;

ALTER TABLE crm_contacts
  ADD CONSTRAINT fk_crm_contacts_introduced_by
    FOREIGN KEY (introduced_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE crm_contacts
  ADD FULLTEXT INDEX ft_crm_contacts_name (name);

-- ---------------------------------------------------------------------------
-- Competitor positions: history is the signal. is_current marks the latest.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_competitor_positions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  competitor_name VARCHAR(255) NOT NULL,
  grade_code VARCHAR(64) NULL,
  application VARCHAR(255) NULL,
  estimated_share_pct TINYINT NULL,
  reason_code ENUM('price','relationship','spec_fit','logistics','payment_terms','other') NOT NULL DEFAULT 'other',
  reason_note TEXT NULL,
  intelligence_type ENUM('factual','reported','estimated') NOT NULL,
  recorded_by_user_id INT NULL,
  recorded_at DATETIME NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_competitor_party_current (party_id, is_current),
  INDEX idx_competitor_party_name (party_id, competitor_name),
  FULLTEXT INDEX ft_competitor_name (competitor_name),
  CONSTRAINT fk_competitor_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  CONSTRAINT fk_competitor_recorded_by FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------------
-- Account context: 1:1 with party
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_account_context (
  party_id INT NOT NULL,
  production_capacity_note VARCHAR(255) NULL,
  seasonality_note TEXT NULL,
  updated_by_user_id INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (party_id),
  CONSTRAINT fk_account_context_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  CONSTRAINT fk_account_context_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------------
-- Account issues: visible on the deal and later the briefing (TASK 9).
-- resolution_window_days drives escalation in TASK 6.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_account_issues (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  deal_id INT NULL,
  issue_type ENUM('quality_complaint','delivery_failure','commercial','other') NOT NULL DEFAULT 'other',
  raised_on DATE NOT NULL,
  description TEXT NOT NULL,
  resolution_window_days INT NOT NULL DEFAULT 7,
  status ENUM('open','resolved','escalated') NOT NULL DEFAULT 'open',
  resolved_on DATE NULL,
  resolution_note TEXT NULL,
  raised_by_user_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_issue_party_status (party_id, status),
  INDEX idx_issue_status_raised (status, raised_on),
  INDEX idx_issue_deal (deal_id),
  FULLTEXT INDEX ft_issue_description (description),
  CONSTRAINT fk_issue_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
  CONSTRAINT fk_issue_deal FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE SET NULL,
  CONSTRAINT fk_issue_raised_by FOREIGN KEY (raised_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
