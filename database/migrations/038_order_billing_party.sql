-- Bill-to party: invoice may be raised in a different party's name than delivery party
ALTER TABLE orders
  ADD COLUMN bill_to_other_party TINYINT(1) NOT NULL DEFAULT 0 AFTER party_id,
  ADD COLUMN billing_party_id INT NULL AFTER bill_to_other_party,
  ADD INDEX idx_orders_billing_party_id (billing_party_id),
  ADD CONSTRAINT fk_orders_billing_party
    FOREIGN KEY (billing_party_id) REFERENCES parties(id) ON DELETE SET NULL;
