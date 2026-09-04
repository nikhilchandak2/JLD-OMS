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

-- Live trading companies are inserted in later migrations (033, 044, 045).
-- Do not seed demo/fake JLD* legal entities here.

-- Add company_id to orders table (nullable first)
ALTER TABLE orders ADD COLUMN company_id INT NULL AFTER id;

-- Point any pre-company orders at the first company row if one already exists
UPDATE orders
SET company_id = (SELECT id FROM companies ORDER BY id ASC LIMIT 1)
WHERE company_id IS NULL
  AND EXISTS (SELECT 1 FROM companies);

ALTER TABLE orders
MODIFY COLUMN company_id INT NOT NULL,
ADD CONSTRAINT fk_orders_company FOREIGN KEY (company_id) REFERENCES companies(id);
