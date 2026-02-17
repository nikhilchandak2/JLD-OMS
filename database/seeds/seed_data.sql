-- Order Processing System Seed Data

-- Insert roles
INSERT INTO roles (name) VALUES 
('entry'),
('view'), 
('admin');

-- Insert sample users (passwords are hashed version of 'Passw0rd!')
-- Password hash for 'Passw0rd!' using bcrypt
INSERT INTO users (email, password_hash, name, role_id, is_active) VALUES
('admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 3, 1),
('entry@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Entry User', 1, 1),
('view@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'View User', 2, 1);

-- Insert sample products
INSERT INTO products (code, name) VALUES
('PROD-A', 'Product A - Cement'),
('PROD-B', 'Product B - Steel'),
('PROD-C', 'Product C - Aggregate');

-- Insert sample parties
INSERT INTO parties (name, contact_person, phone, email, address) VALUES
('ABC Construction Ltd', 'John Smith', '+1-555-0101', 'john@abcconstruction.com', '123 Main St, City A'),
('XYZ Builders Inc', 'Jane Doe', '+1-555-0102', 'jane@xyzbuilders.com', '456 Oak Ave, City B'),
('Global Infrastructure', 'Mike Johnson', '+1-555-0103', 'mike@globalinfra.com', '789 Pine Rd, City C'),
('Metro Developers', 'Sarah Wilson', '+1-555-0104', 'sarah@metrodev.com', '321 Elm St, City D'),
('Prime Construction Co', 'David Brown', '+1-555-0105', 'david@primeconstruction.com', '654 Maple Dr, City E');

-- Insert sample orders (spanning last 6 months for analytics)
INSERT INTO orders (order_no, order_date, product_id, order_qty_trucks, party_id, created_by, status) VALUES
-- Orders from 6 months ago
('ORD-2024040001', '2024-04-15', 1, 50, 1, 2, 'completed'),
('ORD-2024040002', '2024-04-20', 2, 30, 2, 2, 'completed'),
('ORD-2024040003', '2024-04-25', 3, 25, 3, 2, 'partial'),

-- Orders from 5 months ago  
('ORD-2024050001', '2024-05-10', 1, 40, 2, 2, 'completed'),
('ORD-2024050002', '2024-05-15', 2, 35, 4, 2, 'partial'),

-- Orders from 4 months ago
('ORD-2024060001', '2024-06-05', 3, 60, 1, 2, 'completed'),
('ORD-2024060002', '2024-06-12', 1, 45, 5, 2, 'partial'),

-- Orders from 3 months ago
('ORD-2024070001', '2024-07-08', 2, 55, 3, 2, 'completed'),
('ORD-2024070002', '2024-07-18', 3, 40, 4, 2, 'partial'),

-- Orders from 2 months ago
('ORD-2024080001', '2024-08-03', 1, 70, 2, 2, 'completed'),
('ORD-2024080002', '2024-08-15', 2, 50, 1, 2, 'partial'),

-- Orders from 1 month ago
('ORD-2024090001', '2024-09-05', 3, 65, 5, 2, 'completed'),
('ORD-2024090002', '2024-09-20', 1, 45, 3, 2, 'partial'),

-- Recent orders (current month)
('ORD-2024100001', '2024-10-01', 2, 80, 4, 2, 'pending'),
('ORD-2024100002', '2024-10-01', 1, 35, 1, 2, 'pending');

-- Insert sample dispatches
INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by) VALUES
-- Dispatches for completed orders (full quantity)
(1, '2024-04-16', 25, 'TRK-001', 'First batch delivery', 2),
(1, '2024-04-18', 25, 'TRK-002', 'Final batch delivery', 2),

(2, '2024-04-21', 30, 'TRK-003', 'Complete delivery', 2),

(4, '2024-05-11', 20, 'TRK-004', 'Partial delivery', 2),
(4, '2024-05-13', 20, 'TRK-005', 'Final delivery', 2),

(6, '2024-06-06', 30, 'TRK-006', 'First batch', 2),
(6, '2024-06-08', 30, 'TRK-007', 'Final batch', 2),

(8, '2024-07-09', 55, 'TRK-008', 'Complete delivery', 2),

(10, '2024-08-04', 35, 'TRK-009', 'First batch', 2),
(10, '2024-08-06', 35, 'TRK-010', 'Final batch', 2),

(12, '2024-09-06', 65, 'TRK-011', 'Complete delivery', 2),

-- Partial dispatches for orders with 'partial' status
(3, '2024-04-26', 15, 'TRK-012', 'Partial delivery', 2),
(5, '2024-05-16', 20, 'TRK-013', 'Partial delivery', 2),
(7, '2024-06-13', 25, 'TRK-014', 'Partial delivery', 2),
(9, '2024-07-19', 25, 'TRK-015', 'Partial delivery', 2),
(11, '2024-08-16', 30, 'TRK-016', 'Partial delivery', 2),
(13, '2024-09-21', 25, 'TRK-017', 'Partial delivery', 2);


