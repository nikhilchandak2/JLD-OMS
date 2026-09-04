-- Soft-remove the five demo/fake legal entities seeded in 002.
-- Historical orders keep company_id; the UI only lists status = active.

UPDATE companies
SET status = 'inactive'
WHERE name IN (
  'JLD Exports International',
  'JLD Logistics Ltd',
  'JLD Minerals Pvt Ltd',
  'JLD Mining Operations',
  'JLD Processing Unit'
);

UPDATE data_feeds
SET is_active = 0
WHERE company_id IN (
  SELECT id FROM companies
  WHERE name IN (
    'JLD Exports International',
    'JLD Logistics Ltd',
    'JLD Minerals Pvt Ltd',
    'JLD Mining Operations',
    'JLD Processing Unit'
  )
);

INSERT IGNORE INTO migration_flags (name) VALUES ('056_deactivate_demo_companies');
