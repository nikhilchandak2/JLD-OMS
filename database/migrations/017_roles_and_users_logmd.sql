-- log.md: 5-6 user types with different access (Admin, Order processing, Accounts, Operator, CRM)
-- Add new roles, keep existing entry/view for backward compatibility
INSERT IGNORE INTO roles (name) VALUES
('order_processing'),
('accounts'),
('operator'),
('crm');

-- Sample users for each role (default password: Passw0rd! – change in production)
SET @pwd = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'order@example.com', @pwd, 'Order Processing', r.id, 1 FROM roles r WHERE r.name = 'order_processing' LIMIT 1;
INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'accounts@example.com', @pwd, 'Accounts', r.id, 1 FROM roles r WHERE r.name = 'accounts' LIMIT 1;
INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'operator@example.com', @pwd, 'Operator', r.id, 1 FROM roles r WHERE r.name = 'operator' LIMIT 1;
INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'crm@example.com', @pwd, 'CRM', r.id, 1 FROM roles r WHERE r.name = 'crm' LIMIT 1;
