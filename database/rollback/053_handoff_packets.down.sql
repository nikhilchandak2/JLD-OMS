-- Rollback 053_handoff_packets.

UPDATE crm_stage_exit_criteria
SET stage = 7,
    is_mandatory = 0,
    sort_order = 10,
    help_text = 'Seam for TASK 8 - not enforced yet'
WHERE field_key = 'handoff_packet_transferred';

DROP TABLE IF EXISTS handoff_packets;
