-- Rollback 055_pipeline_dashboard.

DROP TABLE IF EXISTS pipeline_time_in_stage_facts;
DROP TABLE IF EXISTS pipeline_deal_snapshot_grades;
DROP TABLE IF EXISTS pipeline_deal_snapshot;
