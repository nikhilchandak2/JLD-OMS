-- New team roles for order-to-dispatch workflow:
-- sales (order creation), dispatch (dispatch dashboard), marketing (visit requests), technical (visit execution)
INSERT IGNORE INTO roles (name) VALUES
('sales'),
('dispatch'),
('marketing'),
('technical');

-- Sample users for each role (default password: Jld@Passw0rd! - change in production)
SET @pwd = '$2b$10$5sOYJsm5tXCUa7X1K1kcIetJNH8h5jYEAUko5PXtF78ZSFVqs3gT.';

INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'sales@jldminerals.com', @pwd, 'Sales Team', r.id, 1 FROM roles r WHERE r.name = 'sales' LIMIT 1;
INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'dispatch@jldminerals.com', @pwd, 'Dispatch Team', r.id, 1 FROM roles r WHERE r.name = 'dispatch' LIMIT 1;
INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'marketing@jldminerals.com', @pwd, 'Marketing Team', r.id, 1 FROM roles r WHERE r.name = 'marketing' LIMIT 1;
INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'technical@jldminerals.com', @pwd, 'Technical Team', r.id, 1 FROM roles r WHERE r.name = 'technical' LIMIT 1;
