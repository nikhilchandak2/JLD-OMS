-- Add companies table and update orders table
-- Migration: 002_add_companies.sql

-- Create companies table
CREATE TABLE IF NOT EXISTS companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(255),
    contact_person VARCHAR(255),
    gst_number VARCHAR(50),
    pan_number VARCHAR(20),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_companies_status (status),
    INDEX idx_companies_code (code)
);

-- Insert sample JLD Minerals companies first
INSERT INTO companies (name, code, address, phone, email, contact_person, gst_number, pan_number, status) VALUES
('JLD Minerals Pvt Ltd', 'JLD001', 'Main Office, Industrial Area, City', '+91-9876543210', 'info@jldminerals.com', 'John Doe', '27ABCDE1234F1Z5', 'ABCDE1234F', 'active'),
('JLD Mining Operations', 'JLD002', 'Mining Site 1, District ABC', '+91-9876543211', 'mining@jldminerals.com', 'Jane Smith', '27ABCDE1234F2Z6', 'ABCDE1234G', 'active'),
('JLD Logistics Ltd', 'JLD003', 'Transport Hub, Highway Road', '+91-9876543212', 'logistics@jldminerals.com', 'Mike Johnson', '27ABCDE1234F3Z7', 'ABCDE1234H', 'active'),
('JLD Processing Unit', 'JLD004', 'Processing Plant, Industrial Zone', '+91-9876543213', 'processing@jldminerals.com', 'Sarah Wilson', '27ABCDE1234F4Z8', 'ABCDE1234I', 'active'),
('JLD Exports International', 'JLD005', 'Export Terminal, Port Area', '+91-9876543214', 'exports@jldminerals.com', 'David Brown', '27ABCDE1234F5Z9', 'ABCDE1234J', 'active');

-- Add company_id to orders table (nullable first)
ALTER TABLE orders ADD COLUMN company_id INT NULL AFTER id;

-- Update existing orders to have company_id = 1 (JLD Minerals Pvt Ltd)
UPDATE orders SET company_id = 1 WHERE company_id IS NULL;

-- Now make company_id NOT NULL and add foreign key
ALTER TABLE orders 
MODIFY COLUMN company_id INT NOT NULL,
ADD CONSTRAINT fk_orders_company FOREIGN KEY (company_id) REFERENCES companies(id);
