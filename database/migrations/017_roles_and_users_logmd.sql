-- log.md: 5-6 user types with different access (Admin, Order processing, Accounts, Operator, CRM)
-- Add new roles, keep existing entry/view for backward compatibility
INSERT IGNORE INTO roles (name) VALUES
('order_processing'),
('accounts'),
('operator'),
('crm');

-- Sample users for each role (default password: Jld@Passw0rd! – change in production)
SET @pwd = '$2b$10$5sOYJsm5tXCUa7X1K1kcIetJNH8h5jYEAUko5PXtF78ZSFVqs3gT.';

INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'order@jldminerals.com', @pwd, 'Order Processing', r.id, 1 FROM roles r WHERE r.name = 'order_processing' LIMIT 1;
INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'accounts@jldminerals.com', @pwd, 'Accounts', r.id, 1 FROM roles r WHERE r.name = 'accounts' LIMIT 1;
INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'operator@jldminerals.com', @pwd, 'Operator', r.id, 1 FROM roles r WHERE r.name = 'operator' LIMIT 1;
INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'crm@jldminerals.com', @pwd, 'CRM', r.id, 1 FROM roles r WHERE r.name = 'crm' LIMIT 1;
