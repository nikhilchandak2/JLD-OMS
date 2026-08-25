-- Rollback 049_account_context.

DROP TABLE IF EXISTS crm_account_issues;
DROP TABLE IF EXISTS crm_account_context;
DROP TABLE IF EXISTS crm_competitor_positions;

ALTER TABLE crm_contacts DROP INDEX ft_crm_contacts_name;
ALTER TABLE crm_contacts DROP FOREIGN KEY fk_crm_contacts_introduced_by;
ALTER TABLE crm_contacts DROP COLUMN context_notes;
ALTER TABLE crm_contacts DROP COLUMN preferred_language;
ALTER TABLE crm_contacts DROP COLUMN preferred_channel;
ALTER TABLE crm_contacts DROP COLUMN introduced_on;
ALTER TABLE crm_contacts DROP COLUMN introduced_by_user_id;
ALTER TABLE crm_contacts DROP COLUMN relationship_strength;
ALTER TABLE crm_contacts DROP COLUMN influence_level;
