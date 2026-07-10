-- Scheduled deliveries for recurring orders (used by OrderService + ScheduledDeliveryRepository)

CREATE TABLE IF NOT EXISTS scheduled_deliveries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  delivery_sequence INT NOT NULL,
  scheduled_date DATE NOT NULL,
  trucks_quantity INT NOT NULL,
  status ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  actual_delivery_date DATE NULL,
  notes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_order (order_id),
  INDEX idx_scheduled_date (scheduled_date),
  INDEX idx_status (status)
);
