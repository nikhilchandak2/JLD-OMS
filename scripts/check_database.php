<?php
/**
 * Print which database the app uses and row counts for key tables.
 * Run on server: php scripts/check_database.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'order_processing';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

echo "App .env database: {$dbName} @ {$host}\n\n";

try {
    $pdo = new PDO(
        "mysql:host={$host};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "MySQL databases matching 'order':\n";
    $dbs = $pdo->query("SHOW DATABASES LIKE '%order%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($dbs === []) {
        echo "  (none found)\n";
    }
    foreach ($dbs as $db) {
        echo "  - {$db}\n";
    }
    echo "\n";

    $tables = ['companies', 'orders', 'parties', 'dispatches', 'products', 'users'];

    foreach ($dbs as $db) {
        echo "=== {$db} ===\n";
        $pdo->exec("USE `{$db}`");
        foreach ($tables as $table) {
            try {
                $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                echo "  {$table}: {$count}\n";
            } catch (PDOException $e) {
                echo "  {$table}: (missing)\n";
            }
        }
        echo "\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
