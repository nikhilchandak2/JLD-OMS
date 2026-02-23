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
    $sql = file_get_contents($migrationFile);

    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function ($stmt) {
            return !empty($stmt) && !preg_match('/^\s*--/', $stmt);
        }
    );

    $pdo->beginTransaction();
    foreach ($statements as $statement) {
        if (trim($statement)) {
            echo "  " . substr(trim($statement), 0, 60) . "...\n";
            $pdo->exec($statement);
        }
    }
    $pdo->commit();
    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
