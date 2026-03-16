-- log.md: 5-stage funnel, industry/tile subtype, ratings, value calculation, current supplier

ALTER TABLE parties
  ADD COLUMN funnel_stage VARCHAR(50) DEFAULT NULL COMMENT 'sampling, technical_support, re_sampling, trial_order, closed',
  ADD COLUMN industry_type VARCHAR(50) DEFAULT NULL COMMENT 'Tiles, Sanitaryware, Tableware, Refractory, Glaze',
  ADD COLUMN tiles_subtype VARCHAR(100) DEFAULT NULL COMMENT 'Slab, Double Charge, GVT, Nano, Full Body, etc.',
  ADD COLUMN monthly_consumption_ton DECIMAL(12,2) DEFAULT NULL COMMENT 'Numeric for value calculation',
  ADD COLUMN avg_price_per_ton DECIMAL(12,2) DEFAULT NULL COMMENT 'Avg price/ton for funnel value',
  ADD COLUMN current_supplier_details TEXT DEFAULT NULL,
  ADD COLUMN relation_with_purchase TINYINT DEFAULT NULL COMMENT '1-5 star rating',
  ADD COLUMN relation_with_internal_team TINYINT DEFAULT NULL COMMENT '1-5 star rating',
  ADD COLUMN probability_of_conversion TINYINT DEFAULT NULL COMMENT '1-5 star rating';
