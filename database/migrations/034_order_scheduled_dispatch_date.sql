-- Optional target dispatch date on order creation (non-recurring schedule hint)
ALTER TABLE orders
  ADD COLUMN scheduled_dispatch_date DATE NULL AFTER order_date,
  ADD INDEX idx_orders_scheduled_dispatch_date (scheduled_dispatch_date);
