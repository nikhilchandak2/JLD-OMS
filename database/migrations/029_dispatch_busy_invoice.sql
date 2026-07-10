-- Link dispatches to Busy sales invoices for idempotent import/update
ALTER TABLE dispatches
  ADD COLUMN busy_invoice_no VARCHAR(100) NULL AFTER loading_weight_tons;

CREATE UNIQUE INDEX idx_dispatches_busy_invoice_no ON dispatches (busy_invoice_no);
