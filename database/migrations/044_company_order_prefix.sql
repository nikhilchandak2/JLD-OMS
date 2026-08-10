-- Per-company order number prefixes (e.g. JLDMPL-0001)

CREATE TABLE IF NOT EXISTS migration_flags (
    name VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE companies
    ADD COLUMN order_prefix VARCHAR(20) NULL AFTER code;

-- Known live companies (one row each; skip if prefix already taken)
UPDATE companies
SET order_prefix = 'JLDMM'
WHERE order_prefix IS NULL
  AND id = (
    SELECT id FROM (
      SELECT id FROM companies
      WHERE code = 'JL_DAGA_MINES_MINERALS'
         OR name LIKE 'J L daga Mines%'
         OR name LIKE 'JL Daga Mines%'
         OR name LIKE 'J.L. Daga Mines%'
      ORDER BY id ASC
      LIMIT 1
    ) t
  );

UPDATE companies
SET order_prefix = 'JLDMPL'
WHERE order_prefix IS NULL
  AND id = (
    SELECT id FROM (
      SELECT id FROM companies
      WHERE code IN ('JLD_MINERALS_PRIVATE_LIMITED', 'JLD001')
         OR name LIKE 'JLD Minerals Private%'
         OR name LIKE 'JLD Minerals Pvt%'
      ORDER BY
        CASE WHEN code = 'JLD_MINERALS_PRIVATE_LIMITED' THEN 0
             WHEN name LIKE 'JLD Minerals Private%' THEN 1
             ELSE 2 END,
        id ASC
      LIMIT 1
    ) t
  );

UPDATE companies
SET order_prefix = 'JLD'
WHERE order_prefix IS NULL
  AND id = (
    SELECT id FROM (
      SELECT id FROM companies
      WHERE code LIKE '%JAICHAND%'
         OR name LIKE 'Jaichand Lal Daga%'
         OR name = 'Jaichand Lal Daga'
      ORDER BY id ASC
      LIMIT 1
    ) t
  );
