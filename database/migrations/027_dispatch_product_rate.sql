-- Product rate entered by dispatch team when recording a dispatch
ALTER TABLE dispatches
  ADD COLUMN product_rate DECIMAL(14,2) NULL AFTER dispatch_qty_trucks;
