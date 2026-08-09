-- Order Processing System Seed Data

-- Insert roles
INSERT IGNORE INTO roles (name) VALUES 
('entry'),
('view'), 
('admin');

-- Insert sample users (passwords are hashed version of 'Jld@Passw0rd!')
-- Role is resolved by name: role ids are not stable, because migration 017 also inserts roles.
SET @seed_pwd = '$2b$10$5sOYJsm5tXCUa7X1K1kcIetJNH8h5jYEAUko5PXtF78ZSFVqs3gT.';

INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'admin@jldminerals.com', @seed_pwd, 'System Administrator', r.id, 1 FROM roles r WHERE r.name = 'admin' LIMIT 1;
INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'entry@jldminerals.com', @seed_pwd, 'Entry User', r.id, 1 FROM roles r WHERE r.name = 'entry' LIMIT 1;
INSERT IGNORE INTO users (email, password_hash, name, role_id, is_active)
SELECT 'view@jldminerals.com', @seed_pwd, 'View User', r.id, 1 FROM roles r WHERE r.name = 'view' LIMIT 1;

-- Repair role assignments if an earlier seed run used the hard-coded ids.
UPDATE users u JOIN roles r ON r.name = 'admin' SET u.role_id = r.id WHERE u.email = 'admin@jldminerals.com';
UPDATE users u JOIN roles r ON r.name = 'entry' SET u.role_id = r.id WHERE u.email = 'entry@jldminerals.com';
UPDATE users u JOIN roles r ON r.name = 'view' SET u.role_id = r.id WHERE u.email = 'view@jldminerals.com';

-- Insert sample products
INSERT IGNORE INTO products (code, name) VALUES
('PROD-A', 'Product A - Cement'),
('PROD-B', 'Product B - Steel'),
('PROD-C', 'Product C - Aggregate');

-- Insert sample parties
INSERT INTO parties (name, contact_person, phone, email, address)
SELECT 'ABC Construction Ltd', 'John Smith', '+1-555-0101', 'john@abcconstruction.com', '123 Main St, City A' FROM (SELECT 1) t
WHERE NOT EXISTS (SELECT 1 FROM parties p WHERE p.name = 'ABC Construction Ltd');

INSERT INTO parties (name, contact_person, phone, email, address)
SELECT 'XYZ Builders Inc', 'Jane Doe', '+1-555-0102', 'jane@xyzbuilders.com', '456 Oak Ave, City B' FROM (SELECT 1) t
WHERE NOT EXISTS (SELECT 1 FROM parties p WHERE p.name = 'XYZ Builders Inc');

INSERT INTO parties (name, contact_person, phone, email, address)
SELECT 'Global Infrastructure', 'Mike Johnson', '+1-555-0103', 'mike@globalinfra.com', '789 Pine Rd, City C' FROM (SELECT 1) t
WHERE NOT EXISTS (SELECT 1 FROM parties p WHERE p.name = 'Global Infrastructure');

INSERT INTO parties (name, contact_person, phone, email, address)
SELECT 'Metro Developers', 'Sarah Wilson', '+1-555-0104', 'sarah@metrodev.com', '321 Elm St, City D' FROM (SELECT 1) t
WHERE NOT EXISTS (SELECT 1 FROM parties p WHERE p.name = 'Metro Developers');

INSERT INTO parties (name, contact_person, phone, email, address)
SELECT 'Prime Construction Co', 'David Brown', '+1-555-0105', 'david@primeconstruction.com', '654 Maple Dr, City E' FROM (SELECT 1) t
WHERE NOT EXISTS (SELECT 1 FROM parties p WHERE p.name = 'Prime Construction Co');

-- Insert sample orders (spanning last 6 months for analytics)
SET @seed_entry_user = (SELECT id FROM users WHERE email = 'entry@jldminerals.com');

INSERT IGNORE INTO orders (company_id, order_no, order_date, product_id, order_qty_trucks, party_id, created_by, status) VALUES
-- Orders from 6 months ago
(1, 'ORD-2024040001', '2024-04-15', 1, 50, 1, @seed_entry_user, 'completed'),
(1, 'ORD-2024040002', '2024-04-20', 2, 30, 2, @seed_entry_user, 'completed'),
(1, 'ORD-2024040003', '2024-04-25', 3, 25, 3, @seed_entry_user, 'partial'),

-- Orders from 5 months ago  
(1, 'ORD-2024050001', '2024-05-10', 1, 40, 2, @seed_entry_user, 'completed'),
(1, 'ORD-2024050002', '2024-05-15', 2, 35, 4, @seed_entry_user, 'partial'),

-- Orders from 4 months ago
(1, 'ORD-2024060001', '2024-06-05', 3, 60, 1, @seed_entry_user, 'completed'),
(1, 'ORD-2024060002', '2024-06-12', 1, 45, 5, @seed_entry_user, 'partial'),

-- Orders from 3 months ago
(1, 'ORD-2024070001', '2024-07-08', 2, 55, 3, @seed_entry_user, 'completed'),
(1, 'ORD-2024070002', '2024-07-18', 3, 40, 4, @seed_entry_user, 'partial'),

-- Orders from 2 months ago
(1, 'ORD-2024080001', '2024-08-03', 1, 70, 2, @seed_entry_user, 'completed'),
(1, 'ORD-2024080002', '2024-08-15', 2, 50, 1, @seed_entry_user, 'partial'),

-- Orders from 1 month ago
(1, 'ORD-2024090001', '2024-09-05', 3, 65, 5, @seed_entry_user, 'completed'),
(1, 'ORD-2024090002', '2024-09-20', 1, 45, 3, @seed_entry_user, 'partial'),

-- Recent orders (current month)
(1, 'ORD-2024100001', '2024-10-01', 2, 80, 4, @seed_entry_user, 'pending'),
(1, 'ORD-2024100002', '2024-10-01', 1, 35, 1, @seed_entry_user, 'pending');

-- Insert sample dispatches
-- Dispatches for completed orders (full quantity)

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-04-16', 25, 'TRK-001', 'First batch delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024040001'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-001');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-04-18', 25, 'TRK-002', 'Final batch delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024040001'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-002');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-04-21', 30, 'TRK-003', 'Complete delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024040002'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-003');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-05-11', 20, 'TRK-004', 'Partial delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024050001'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-004');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-05-13', 20, 'TRK-005', 'Final delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024050001'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-005');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-06-06', 30, 'TRK-006', 'First batch', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024060001'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-006');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-06-08', 30, 'TRK-007', 'Final batch', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024060001'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-007');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-07-09', 55, 'TRK-008', 'Complete delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024070001'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-008');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-08-04', 35, 'TRK-009', 'First batch', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024080001'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-009');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-08-06', 35, 'TRK-010', 'Final batch', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024080001'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-010');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-09-06', 65, 'TRK-011', 'Complete delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024090001'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-011');

-- Partial dispatches for orders with 'partial' status

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-04-26', 15, 'TRK-012', 'Partial delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024040003'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-012');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-05-16', 20, 'TRK-013', 'Partial delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024050002'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-013');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-06-13', 25, 'TRK-014', 'Partial delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024060002'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-014');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-07-19', 25, 'TRK-015', 'Partial delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024070002'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-015');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-08-16', 30, 'TRK-016', 'Partial delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024080002'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-016');

INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
SELECT o.id, '2024-09-21', 25, 'TRK-017', 'Partial delivery', @seed_entry_user FROM orders o
WHERE o.order_no = 'ORD-2024090002'
  AND NOT EXISTS (SELECT 1 FROM dispatches d WHERE d.vehicle_no = 'TRK-017');


