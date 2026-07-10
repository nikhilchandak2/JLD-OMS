-- Order quantity in trucks or weight (MT); default 40 MT per truck for planning
ALTER TABLE orders
  ADD COLUMN order_qty_mode VARCHAR(10) NOT NULL DEFAULT 'trucks' COMMENT 'trucks or weight' AFTER order_qty_trucks,
  ADD COLUMN order_weight_tons DECIMAL(12,3) NULL AFTER order_qty_mode,
  ADD COLUMN tons_per_truck DECIMAL(8,2) NOT NULL DEFAULT 40.00 AFTER order_weight_tons;

UPDATE orders
SET order_weight_tons = ROUND(order_qty_trucks * 40, 3),
    tons_per_truck = 40
WHERE order_weight_tons IS NULL;
