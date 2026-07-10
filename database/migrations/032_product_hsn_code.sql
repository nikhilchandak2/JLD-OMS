-- HSN code on products (from product master CSV import)
ALTER TABLE products
  ADD COLUMN hsn_code VARCHAR(20) NULL AFTER name,
  ADD INDEX idx_products_hsn_code (hsn_code);
