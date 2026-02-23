-- Trip stoppages: when vehicle stopped and for how long (WheelsEye-style detail)
-- Migration: 006_trip_stoppages.sql

ALTER TABLE vehicle_trips
ADD COLUMN stoppage_count INT NULL DEFAULT NULL COMMENT 'Number of stoppages in this trip',
ADD COLUMN total_stoppage_minutes DECIMAL(8, 2) NULL DEFAULT NULL COMMENT 'Total duration of all stoppages in minutes';

CREATE TABLE IF NOT EXISTS trip_stoppages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trip_id INT NOT NULL,
    start_time DATETIME NOT NULL COMMENT 'When vehicle stopped',
    end_time DATETIME NOT NULL COMMENT 'When vehicle started again',
    duration_minutes DECIMAL(8, 2) NOT NULL,
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES vehicle_trips(id) ON DELETE CASCADE,
    INDEX idx_trip_id (trip_id)
);
