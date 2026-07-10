-- Truck loading weight from kanta parchi (weighbridge); entered after dispatch is recorded
ALTER TABLE dispatches
  ADD COLUMN loading_weight_tons DECIMAL(10,3) NULL AFTER product_rate;
