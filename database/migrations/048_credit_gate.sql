-- Three-tier credit gate, override snapshots, and a visible pending-director
-- state on orders. Rollback: database/rollback/048_credit_gate.down.sql
--
-- Director is sole approver (the admin role). required_approver_count is 1
-- everywhere so a second approver can be added later without a migration.

-- ---------------------------------------------------------------------------
-- CONFIG: per company, per tier. Changing a row changes routing with no deploy.
-- threshold_amount is retained but unused (B2: percentage ceiling only).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS credit_policy_tiers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  tier TINYINT NOT NULL,
  threshold_type ENUM('percentage', 'absolute', 'either') NOT NULL DEFAULT 'percentage',
  threshold_percentage DECIMAL(5,2) NULL,
  threshold_amount DECIMAL(14,2) NULL,
  routing ENUM('auto', 'passive_queue', 'realtime_push') NOT NULL,
  allows_provisional_proceed TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_credit_policy_company_tier (company_id, tier),
  INDEX idx_credit_policy_active (company_id, is_active),
  CONSTRAINT fk_credit_policy_company FOREIGN KEY (company_id) REFERENCES companies(id)
);

-- ---------------------------------------------------------------------------
-- One override per deal or order (exactly one of deal_id / order_id).
-- Snapshot columns are the data the decision was made against. Never re-read live.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS credit_override_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  deal_id INT NULL,
  order_id INT NULL,
  party_id INT NOT NULL,
  requested_by_user_id INT NULL,
  requested_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  tier TINYINT NOT NULL,
  credit_limit_snapshot DECIMAL(14,2) NULL,
  outstanding_snapshot DECIMAL(14,2) NOT NULL,
  outstanding_breakdown JSON NOT NULL,
  ledger_as_of DATETIME NULL,
  incomplete_feed_entities JSON NULL,
  proposed_order_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  computed_overage DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  rep_reason TEXT NOT NULL,
  status ENUM(
    'pending',
    'approved',
    'approved_with_modified_limit',
    'rejected',
    'call_requested',
    'withdrawn',
    'expired'
  ) NOT NULL DEFAULT 'pending',
  required_approver_count TINYINT NOT NULL DEFAULT 1,
  decided_by_user_id INT NULL,
  decided_at DATETIME NULL,
  modified_limit_value DECIMAL(14,2) NULL,
  decision_note TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_credit_override_queue (company_id, status, tier),
  INDEX idx_credit_override_party (party_id, requested_at),
  INDEX idx_credit_override_requester (requested_by_user_id, status),
  INDEX idx_credit_override_deal (deal_id),
  INDEX idx_credit_override_order (order_id),
  INDEX idx_credit_override_expires (status, expires_at),
  CONSTRAINT fk_credit_override_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_credit_override_party FOREIGN KEY (party_id) REFERENCES parties(id),
  CONSTRAINT fk_credit_override_deal FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE SET NULL,
  CONSTRAINT fk_credit_override_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_credit_override_requester FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_credit_override_decider FOREIGN KEY (decided_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS credit_override_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  from_status VARCHAR(40) NULL,
  to_status VARCHAR(40) NOT NULL,
  actor_user_id INT NULL,
  note TEXT NULL,
  occurred_at DATETIME NOT NULL,
  INDEX idx_credit_override_events_request (request_id, occurred_at),
  CONSTRAINT fk_credit_override_events_request FOREIGN KEY (request_id) REFERENCES credit_override_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_credit_override_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Visible pending-director / blocked state on the order. No FK back to the
-- request table (circular with order_id on the request); the service keeps both sides in sync.
ALTER TABLE orders
  ADD COLUMN credit_gate_status ENUM('cleared', 'pending_director', 'blocked') NOT NULL DEFAULT 'cleared';

ALTER TABLE orders
  ADD COLUMN credit_override_request_id INT NULL;

ALTER TABLE orders
  ADD INDEX idx_orders_credit_gate (credit_gate_status);

-- Stage 6 → 7 now requires the credit gate (TASK 1 left this optional).
UPDATE crm_stage_exit_criteria
SET is_mandatory = 1,
    help_text = 'Cleared automatically within limit. Tier 2 may proceed pending Director confirmation. Tier 3 waits for a decision.'
WHERE field_key = 'credit_gate_cleared';

-- Seed the three-tier policy for every legal entity (B2: 10% ceiling on Tier 2).
INSERT INTO credit_policy_tiers (company_id, tier, threshold_type, threshold_percentage, threshold_amount, routing, allows_provisional_proceed, is_active)
SELECT c.id, 1, 'percentage', NULL, NULL, 'auto', 0, 1
FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM credit_policy_tiers t WHERE t.company_id = c.id AND t.tier = 1);

INSERT INTO credit_policy_tiers (company_id, tier, threshold_type, threshold_percentage, threshold_amount, routing, allows_provisional_proceed, is_active)
SELECT c.id, 2, 'percentage', 10.00, NULL, 'passive_queue', 1, 1
FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM credit_policy_tiers t WHERE t.company_id = c.id AND t.tier = 2);

INSERT INTO credit_policy_tiers (company_id, tier, threshold_type, threshold_percentage, threshold_amount, routing, allows_provisional_proceed, is_active)
SELECT c.id, 3, 'percentage', NULL, NULL, 'realtime_push', 0, 1
FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM credit_policy_tiers t WHERE t.company_id = c.id AND t.tier = 3);
