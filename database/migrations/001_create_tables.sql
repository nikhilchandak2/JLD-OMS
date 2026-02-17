-- Order Processing System Database Schema

-- Create roles table
CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) UNIQUE NOT NULL COMMENT 'entry, view, admin',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Create users table
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(200) NOT NULL,
  role_id INT NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  failed_login_attempts INT DEFAULT 0,
  locked_until DATETIME NULL,
  password_reset_token VARCHAR(255) NULL,
  password_reset_expires DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id),
  INDEX idx_email (email),
  INDEX idx_role_id (role_id)
);

-- Create parties table
CREATE TABLE parties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  contact_person VARCHAR(255),
  phone VARCHAR(50),
  email VARCHAR(255),
  address TEXT,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_name (name)
);

-- Create products table
CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) UNIQUE,
  name VARCHAR(255) NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_code (code),
  INDEX idx_name (name)
);

-- Create orders table
CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(50) UNIQUE NOT NULL,
  order_date DATE NOT NULL,
  product_id INT NOT NULL,
  order_qty_trucks INT NOT NULL CHECK (order_qty_trucks > 0),
  party_id INT NOT NULL,
  status ENUM('pending', 'partial', 'completed') DEFAULT 'pending',
  created_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (party_id) REFERENCES parties(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_order_no (order_no),
  INDEX idx_order_date (order_date),
  INDEX idx_party_id (party_id),
  INDEX idx_product_id (product_id),
  INDEX idx_status (status)
);

-- Create dispatches table
CREATE TABLE dispatches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  dispatch_date DATE NOT NULL,
  dispatch_qty_trucks INT NOT NULL CHECK (dispatch_qty_trucks > 0),
  vehicle_no VARCHAR(100),
  remarks TEXT,
  dispatched_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (dispatched_by) REFERENCES users(id),
  INDEX idx_order_id (order_id),
  INDEX idx_dispatch_date (dispatch_date)
);

-- Create sessions table for session management
CREATE TABLE sessions (
  id VARCHAR(128) PRIMARY KEY,
  user_id INT,
  data TEXT,
  expires_at DATETIME NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_expires_at (expires_at),
  INDEX idx_user_id (user_id)
);

-- Create audit log table for tracking changes
CREATE TABLE audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  table_name VARCHAR(100) NOT NULL,
  record_id INT NOT NULL,
  action ENUM('CREATE', 'UPDATE', 'DELETE') NOT NULL,
  old_values JSON,
  new_values JSON,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_table_record (table_name, record_id),
  INDEX idx_user_id (user_id),
  INDEX idx_created_at (created_at)
);


