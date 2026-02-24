<?php
/**
 * One-off: create excavating_machines and dumper_assignments.
 * Run from app root: php scripts/run_migration_008_once.php
 * Ensures we use the same .env as the app (DB_NAME=order_processing_prod).
 */

require_once __DIR__ . '/../vendor/autoload.php';

$baseDir = realpath(__DIR__ . '/..');
$envPath = $baseDir . '/.env';
if (!is_file($envPath)) {
    echo "ERROR: .env not found at {$envPath}. Create it with DB_NAME=order_processing_prod\n";
    exit(1);
}
$dotenv = Dotenv\Dotenv::createImmutable($baseDir);
$dotenv->load();

$dbName = $_ENV['DB_NAME'] ?? 'order_processing';
echo "Using database: {$dbName}\n";
if ($dbName !== 'order_processing_prod') {
    echo "WARNING: Expected DB_NAME=order_processing_prod. Fix .env and run again.\n";
}

use App\Core\Database;

$pdo = (new Database())->getConnection();

$sqls = [
    "CREATE TABLE IF NOT EXISTS excavating_machines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        mine_name VARCHAR(150) NOT NULL,
        sort_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active (is_active)
    )",
    "CREATE TABLE IF NOT EXISTS dumper_assignments (
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
    )",
    "INSERT IGNORE INTO excavating_machines (id, name, mine_name, sort_order) VALUES
    (1, 'Machine 1', 'Mine 1', 1),
    (2, 'Machine 2', 'Mine 2', 2),
    (3, 'Machine 3', 'Mine 3', 3),
    (4, 'Machine 4', 'Mine 4', 4),
    (5, 'Machine 5', 'Mine 5', 5)",
];

foreach ($sqls as $i => $sql) {
    echo "Executing step " . ($i + 1) . "... ";
    try {
        $pdo->exec($sql);
        echo "OK\n";
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "Done. Tables excavating_machines and dumper_assignments are ready.\n";
