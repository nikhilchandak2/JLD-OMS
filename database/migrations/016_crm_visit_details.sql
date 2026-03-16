-- log.md: Visit details tab – description, follow-up notes, samples provided (product + price)
ALTER TABLE parties
  ADD COLUMN visit_description TEXT DEFAULT NULL COMMENT 'Visit description',
  ADD COLUMN followup_notes TEXT DEFAULT NULL COMMENT 'Follow-up notes',
  ADD COLUMN visit_samples_provided JSON DEFAULT NULL COMMENT 'Array of {product, price} for samples provided to client';
