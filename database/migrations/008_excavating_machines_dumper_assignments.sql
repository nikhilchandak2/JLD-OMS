-- Excavating machines (one per mine) and daily dumper assignments
-- 5 machines, 4-5 dumpers per machine; assignments can change daily

CREATE TABLE IF NOT EXISTS excavating_machines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL COMMENT 'Machine name or identifier',
    mine_name VARCHAR(150) NOT NULL COMMENT 'Mine / location name',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
);

CREATE TABLE IF NOT EXISTS dumper_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_date DATE NOT NULL,
    excavating_machine_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_vehicle_per_day (assignment_date, vehicle_id),
    FOREIGN KEY (excavating_machine_id) REFERENCES excavating_machines(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_date (assignment_date),
    INDEX idx_machine_date (excavating_machine_id, assignment_date)
);

-- Seed 5 excavating machines (one per mine)
INSERT IGNORE INTO excavating_machines (id, name, mine_name, sort_order) VALUES
(1, 'Machine 1', 'Mine 1', 1),
(2, 'Machine 2', 'Mine 2', 2),
(3, 'Machine 3', 'Mine 3', 3),
(4, 'Machine 4', 'Mine 4', 4),
(5, 'Machine 5', 'Mine 5', 5);
