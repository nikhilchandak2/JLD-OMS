-- Rollback 048_credit_gate.

UPDATE crm_stage_exit_criteria
SET is_mandatory = 0,
    help_text = 'Seam for TASK 3 - not enforced yet'
WHERE field_key = 'credit_gate_cleared';

ALTER TABLE orders DROP COLUMN credit_override_request_id;
ALTER TABLE orders DROP COLUMN credit_gate_status;

DROP TABLE IF EXISTS credit_override_events;
DROP TABLE IF EXISTS credit_override_requests;
DROP TABLE IF EXISTS credit_policy_tiers;
