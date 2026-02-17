-- GPS and Fuel Tracking System Migration
-- Migration: 005_add_gps_fuel_tracking.sql

-- GPS Devices Table
CREATE TABLE IF NOT EXISTS gps_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(100) UNIQUE NOT NULL COMMENT 'IMEI or unique device identifier',
    imei VARCHAR(50),
    device_type VARCHAR(50) DEFAULT 'wheelseye',
    status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
    last_seen DATETIME NULL,
    battery_level INT NULL COMMENT 'Battery percentage 0-100',
    signal_strength INT NULL COMMENT 'Signal strength 0-100',
    firmware_version VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_device_id (device_id),
    INDEX idx_imei (imei),
    INDEX idx_status (status)
);

-- Fuel Sensors Table
CREATE TABLE IF NOT EXISTS fuel_sensors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sensor_id VARCHAR(100) UNIQUE NOT NULL COMMENT 'Unique sensor identifier',
    sensor_type VARCHAR(50) DEFAULT 'ultrasonic',
    status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
    calibration_factor DECIMAL(10, 4) DEFAULT 1.0000,
    tank_capacity_liters DECIMAL(10, 2) NULL,
    last_seen DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sensor_id (sensor_id),
    INDEX idx_status (status)
);

-- Vehicles Table
CREATE TABLE IF NOT EXISTS vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_number VARCHAR(100) UNIQUE NOT NULL,
    vehicle_type ENUM('dumper', 'excavator', 'loader', 'truck', 'other') DEFAULT 'dumper',
    make VARCHAR(100),
    model VARCHAR(100),
    year INT,
    registration_number VARCHAR(100),
    gps_device_id INT NULL,
    fuel_sensor_id INT NULL,
    status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (gps_device_id) REFERENCES gps_devices(id) ON DELETE SET NULL,
    FOREIGN KEY (fuel_sensor_id) REFERENCES fuel_sensors(id) ON DELETE SET NULL,
    INDEX idx_vehicle_number (vehicle_number),
    INDEX idx_gps_device_id (gps_device_id),
    INDEX idx_fuel_sensor_id (fuel_sensor_id),
    INDEX idx_status (status)
);

-- GPS Tracking Data Table
CREATE TABLE IF NOT EXISTS gps_tracking_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    device_id VARCHAR(100) NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    altitude DECIMAL(10, 2) NULL,
    speed DECIMAL(8, 2) NULL COMMENT 'Speed in km/h',
    heading DECIMAL(5, 2) NULL COMMENT 'Heading in degrees 0-360',
    accuracy DECIMAL(8, 2) NULL COMMENT 'Accuracy in meters',
    satellite_count INT NULL,
    timestamp DATETIME NOT NULL,
    ignition_status TINYINT(1) NULL COMMENT '0=off, 1=on',
    movement_status ENUM('stationary', 'moving', 'idle') DEFAULT 'stationary',
    odometer DECIMAL(12, 2) NULL COMMENT 'Odometer reading in km',
    raw_data JSON NULL COMMENT 'Store original webhook data',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_vehicle_id (vehicle_id),
    INDEX idx_device_id (device_id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_location (latitude, longitude),
    INDEX idx_vehicle_timestamp (vehicle_id, timestamp)
);

-- Fuel Reading Data Table
CREATE TABLE IF NOT EXISTS fuel_reading_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    sensor_id VARCHAR(100) NOT NULL,
    fuel_level DECIMAL(8, 2) NOT NULL COMMENT 'Fuel level in liters',
    fuel_percentage DECIMAL(5, 2) NULL COMMENT 'Fuel percentage 0-100',
    temperature DECIMAL(5, 2) NULL COMMENT 'Temperature in Celsius',
    voltage DECIMAL(5, 2) NULL,
    timestamp DATETIME NOT NULL,
    raw_data JSON NULL COMMENT 'Store original webhook data',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_vehicle_id (vehicle_id),
    INDEX idx_sensor_id (sensor_id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_vehicle_timestamp (vehicle_id, timestamp)
);

-- Geofences Table
CREATE TABLE IF NOT EXISTS geofences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    geofence_type ENUM('pit', 'stockpile', 'other') NOT NULL,
    material_type VARCHAR(100) NULL COMMENT 'For stockpiles: ball_clay_1st_grade, ball_clay_2nd_grade, ball_clay_3rd_grade, overburden, etc.',
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    radius_meters DECIMAL(8, 2) NOT NULL COMMENT 'Radius in meters',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (geofence_type),
    INDEX idx_location (latitude, longitude),
    INDEX idx_active (is_active)
);

-- Geofence Events Table (for tracking entry/exit)
CREATE TABLE IF NOT EXISTS geofence_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    geofence_id INT NOT NULL,
    event_type ENUM('entry', 'exit') NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    timestamp DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (geofence_id) REFERENCES geofences(id) ON DELETE CASCADE,
    INDEX idx_vehicle_id (vehicle_id),
    INDEX idx_geofence_id (geofence_id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_vehicle_geofence (vehicle_id, geofence_id, timestamp)
);

-- Vehicle Trips Table
CREATE TABLE IF NOT EXISTS vehicle_trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    trip_type ENUM('pit_to_stockpile', 'other') DEFAULT 'pit_to_stockpile',
    source_geofence_id INT NULL COMMENT 'Pit geofence',
    destination_geofence_id INT NULL COMMENT 'Stockpile geofence',
    material_type VARCHAR(100) NULL COMMENT 'Material type for this trip',
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    start_latitude DECIMAL(10, 8) NOT NULL,
    start_longitude DECIMAL(11, 8) NOT NULL,
    end_latitude DECIMAL(10, 8) NULL,
    end_longitude DECIMAL(11, 8) NULL,
    distance_km DECIMAL(10, 2) NULL,
    duration_minutes INT NULL,
    fuel_consumed_liters DECIMAL(8, 2) NULL,
    fuel_start_liters DECIMAL(8, 2) NULL,
    fuel_end_liters DECIMAL(8, 2) NULL,
    status ENUM('in_progress', 'completed', 'cancelled') DEFAULT 'in_progress',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (source_geofence_id) REFERENCES geofences(id) ON DELETE SET NULL,
    FOREIGN KEY (destination_geofence_id) REFERENCES geofences(id) ON DELETE SET NULL,
    INDEX idx_vehicle_id (vehicle_id),
    INDEX idx_start_time (start_time),
    INDEX idx_status (status),
    INDEX idx_trip_type (trip_type),
    INDEX idx_material_type (material_type)
);

-- Fuel Alerts Table
CREATE TABLE IF NOT EXISTS fuel_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    alert_type ENUM('low_fuel', 'fuel_theft', 'rapid_consumption', 'sensor_fault') NOT NULL,
    fuel_level DECIMAL(8, 2) NULL,
    fuel_percentage DECIMAL(5, 2) NULL,
    message TEXT,
    is_resolved TINYINT(1) DEFAULT 0,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_vehicle_id (vehicle_id),
    INDEX idx_alert_type (alert_type),
    INDEX idx_is_resolved (is_resolved),
    INDEX idx_created_at (created_at)
);

-- Vehicle Maintenance Table
CREATE TABLE IF NOT EXISTS vehicle_maintenance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    maintenance_type ENUM('scheduled', 'repair', 'inspection', 'other') NOT NULL,
    description TEXT,
    cost DECIMAL(10, 2) NULL,
    maintenance_date DATE NOT NULL,
    next_maintenance_date DATE NULL,
    odometer_reading DECIMAL(12, 2) NULL,
    performed_by VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_vehicle_id (vehicle_id),
    INDEX idx_maintenance_date (maintenance_date)
);
