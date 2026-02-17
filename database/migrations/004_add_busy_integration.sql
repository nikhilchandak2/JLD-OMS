-- Add Busy integration webhook logging table

CREATE TABLE IF NOT EXISTS busy_webhook_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(100),
    webhook_data JSON,
    status ENUM('received', 'processing', 'success', 'error') DEFAULT 'received',
    error_message TEXT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_invoice_no (invoice_no),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- Add system user for automated processes
INSERT IGNORE INTO users (name, email, password_hash, role_id, created_at) 
SELECT 'System Integration', 'system@jldminerals.com', '$2y$10$dummy_hash_for_system_user', r.id, NOW()
FROM roles r WHERE r.name = 'admin' LIMIT 1;
