<?php
/**
 * Database Seeding Script
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

try {
    echo "Starting database seeding...\n";
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Read and execute seed file
    $seedFile = __DIR__ . '/../database/seeds/seed_data.sql';
    
    if (!file_exists($seedFile)) {
        throw new Exception("Seed file not found: {$seedFile}");
    }
    
    $sql = file_get_contents($seedFile);
    
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
    
    echo "Seeding completed successfully!\n";
    echo "\nDefault user credentials:\n";
    echo "Admin: admin@example.com / Passw0rd!\n";
    echo "Entry: entry@example.com / Passw0rd!\n";
    echo "View: view@example.com / Passw0rd!\n";
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    echo "Seeding failed: " . $e->getMessage() . "\n";
    exit(1);
}


