<?php
/**
 * Run a single migration by number (e.g. 006).
 * Usage: php scripts/run_migration.php 006
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$num = $argv[1] ?? null;
if (!$num || !preg_match('/^\d{3}$/', $num)) {
    echo "Usage: php run_migration.php <NNN>\nExample: php run_migration.php 006\n";
    exit(1);
}

$migrationFile = __DIR__ . "/../database/migrations/{$num}_*.sql";
$files = glob($migrationFile);
if (empty($files)) {
    echo "No migration found for {$num}.\n";
    exit(1);
}
$migrationFile = $files[0];

use App\Core\Database;

try {
    echo "Running migration: " . basename($migrationFile) . "\n";
    $database = new Database();
    $pdo = $database->getConnection();
    $dbName = $_ENV['DB_NAME'] ?? 'order_processing';
    echo "Using database: " . $dbName . "\n";
    $sql = file_get_contents($migrationFile);

    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function ($stmt) {
            return trim($stmt) !== '';
        }
    );

    foreach ($statements as $i => $statement) {
        $statement = trim($statement);
        // Strip leading comment lines so "-\- ...\nCREATE TABLE" is executed as "CREATE TABLE"
        $statement = preg_replace('/^(\s*--[^\n]*\n)+/m', '', $statement);
        $statement = trim($statement);
        if ($statement === '') continue;
        echo "  [" . ($i + 1) . "] " . substr($statement, 0, 55) . "...\n";
        try {
            $pdo->exec($statement);
        } catch (Exception $e) {
            echo "Migration failed at statement " . ($i + 1) . ": " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
