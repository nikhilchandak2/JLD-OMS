<?php
/**
 * Database Migration Script
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

try {
    echo "Starting database migration...\n";
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Read and execute migration file
    $migrationFile = __DIR__ . '/../database/migrations/001_create_tables.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: {$migrationFile}");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^\s*--/', $stmt);
        }
    );
    
    $pdo->beginTransaction();
    
    foreach ($statements as $statement) {
        if (trim($statement)) {
            echo "Executing: " . substr(trim($statement), 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }
    
    $pdo->commit();
    
    echo "Migration completed successfully!\n";
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}


