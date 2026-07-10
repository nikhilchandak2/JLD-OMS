-- Party-level credit requests (hard credit block at order creation).
-- Orders are no longer created when a party is over its credit limit; instead sales raises
-- a party-level credit request (max 2 per party per calendar month). order_id becomes optional.

-- order_id was NOT NULL UNIQUE with an FK; relax it so requests can exist without an order.
ALTER TABLE credit_approval_requests DROP FOREIGN KEY credit_approval_requests_ibfk_1;
ALTER TABLE credit_approval_requests DROP INDEX order_id;
ALTER TABLE credit_approval_requests MODIFY order_id INT NULL;
ALTER TABLE credit_approval_requests
  ADD CONSTRAINT fk_credit_requests_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL;

ALTER TABLE credit_approval_requests
  ADD COLUMN requested_limit_increase DECIMAL(14,2) NULL AFTER credit_limit,
  ADD COLUMN reason VARCHAR(500) NULL AFTER requested_limit_increase;

ALTER TABLE credit_approval_requests
  ADD INDEX idx_party_requested_at (party_id, requested_at);
