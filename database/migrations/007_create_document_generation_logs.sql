-- Document Generation Logs Table
-- Migration: 007_create_document_generation_logs.sql

CREATE TABLE IF NOT EXISTS document_generation_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    dispatch_ids TEXT, -- JSON array of dispatch IDs
    document_type VARCHAR(100) NOT NULL,
    generated_file_path VARCHAR(500) NOT NULL,
    generated_by INT,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES users(id),
    INDEX idx_order_id (order_id),
    INDEX idx_document_type (document_type),
    INDEX idx_generated_at (generated_at)
);
