-- Stores the credit-limit increment requested during admin approval.

ALTER TABLE credit_approval_requests
  ADD COLUMN credit_limit_increase DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER credit_limit,
  ADD COLUMN new_credit_limit DECIMAL(14,2) NULL AFTER credit_limit_increase;

