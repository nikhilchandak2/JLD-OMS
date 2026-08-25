-- Handoff packets (TASK 8): Sales to Dispatch and Dispatch to Accounts.
-- Versioned JSON payloads. Immutable once acknowledged. Amendments insert a
-- new row and set superseded_by_packet_id. Rollback: database/rollback/053_handoff_packets.down.sql
--
-- Stage 6 to 7 now requires a valid Sales to Dispatch packet (TASK 1 left this optional).

CREATE TABLE IF NOT EXISTS handoff_packets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  packet_type ENUM('sales_to_dispatch', 'dispatch_to_accounts') NOT NULL,
  deal_id INT NULL,
  order_id INT NULL,
  dispatch_id INT NULL,
  schema_version SMALLINT NOT NULL,
  payload JSON NOT NULL,
  supersession_reason VARCHAR(500) NULL,
  created_by_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acknowledged_by_user_id INT NULL,
  acknowledged_at DATETIME NULL,
  superseded_by_packet_id INT NULL,
  INDEX idx_handoff_type_ack (packet_type, acknowledged_at),
  INDEX idx_handoff_deal (deal_id),
  INDEX idx_handoff_order (order_id),
  INDEX idx_handoff_dispatch (dispatch_id),
  INDEX idx_handoff_current (packet_type, deal_id, superseded_by_packet_id),
  CONSTRAINT fk_handoff_deal FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE SET NULL,
  CONSTRAINT fk_handoff_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_handoff_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatches(id) ON DELETE SET NULL,
  CONSTRAINT fk_handoff_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_handoff_acked_by FOREIGN KEY (acknowledged_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_handoff_superseded_by FOREIGN KEY (superseded_by_packet_id) REFERENCES handoff_packets(id) ON DELETE SET NULL
);

UPDATE crm_stage_exit_criteria
SET stage = 6,
    is_mandatory = 1,
    sort_order = 30,
    help_text = 'A valid Sales to Dispatch packet must exist before Dispatch sees the order. Receiving teams do not re-type these fields.'
WHERE field_key = 'handoff_packet_transferred';
