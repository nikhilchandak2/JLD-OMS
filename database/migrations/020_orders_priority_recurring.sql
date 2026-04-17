-- Columns required by OrderService/OrderRepository for recurring orders and priority
-- (priority + recurring delivery scheduling)

ALTER TABLE orders
  ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  ADD COLUMN is_recurring TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN delivery_frequency_days INT NULL DEFAULT NULL,
  ADD COLUMN trucks_per_delivery INT NULL DEFAULT NULL,
  ADD COLUMN total_deliveries INT NULL DEFAULT NULL;

