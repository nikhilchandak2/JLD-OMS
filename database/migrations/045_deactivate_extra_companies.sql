-- Keep only the three live trading companies; soft-remove seed/demo and any others.
-- Soft-delete (inactive) so historical orders with those company_ids keep their FK.

UPDATE companies
SET status = 'inactive'
WHERE status = 'active'
  AND code NOT IN (
    'JAICHAND_LAL_DAGA',
    'JLD_MINERALS_PRIVATE_LIMITED',
    'JL_DAGA_MINES_MINERALS'
  );

-- Belt-and-suspenders: ensure the three keepers are active
UPDATE companies
SET status = 'active'
WHERE code IN (
  'JAICHAND_LAL_DAGA',
  'JLD_MINERALS_PRIVATE_LIMITED',
  'JL_DAGA_MINES_MINERALS'
);
