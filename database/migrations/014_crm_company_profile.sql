ALTER TABLE parties
  ADD COLUMN products_introduced TEXT DEFAULT NULL COMMENT 'Our products introduced: Ball Clay, Kaolin, Feldspar, etc.',
  ADD COLUMN monthly_consumption VARCHAR(255) DEFAULT NULL COMMENT 'e.g. 50 MT, 100 trucks',
  ADD COLUMN year_of_association INT DEFAULT NULL COMMENT 'Year we started business with them',
  ADD COLUMN order_frequency VARCHAR(50) DEFAULT NULL COMMENT 'regular, occasional, trial',
  ADD COLUMN last_order_date DATE DEFAULT NULL,
  ADD COLUMN last_visit_date DATE DEFAULT NULL,
  ADD COLUMN payment_track VARCHAR(50) DEFAULT NULL COMMENT 'good, delayed, overdue, na',
  ADD COLUMN target_volume VARCHAR(255) DEFAULT NULL COMMENT 'Sales target for this account',
  ADD COLUMN next_followup_date DATE DEFAULT NULL,
  ADD COLUMN assigned_sales_owner INT DEFAULT NULL COMMENT 'User ID of sales owner',
  ADD COLUMN number_of_plants INT DEFAULT NULL,
  ADD COLUMN general_notes TEXT DEFAULT NULL;
