<?php
/**
 * Setup script for Order Processing System
 * This script runs database migrations and seeds initial data
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "=== Order Processing System Setup ===\n\n";

try {
    $database = new Database();
    $connection = $database->getConnection();
    
    echo "✓ Database connection established\n";
    
    // Run migrations
    echo "\n--- Running Migrations ---\n";
    
    $migrationFiles = [
        '001_create_tables.sql',
        '002_add_companies.sql',
        '004_add_busy_integration.sql',
        '005_add_gps_fuel_tracking.sql'
    ];
    
    foreach ($migrationFiles as $migrationFile) {
        $migrationPath = __DIR__ . '/../database/migrations/' . $migrationFile;
        
        if (!file_exists($migrationPath)) {
            echo "⚠ Migration file not found: {$migrationFile}\n";
            continue;
        }
        
        echo "Running {$migrationFile}...\n";
        
        $sql = file_get_contents($migrationPath);
        
        // Split by semicolon and execute each statement
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($stmt) => !empty($stmt) && !preg_match('/^\s*--/', $stmt)
        );
        
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                try {
                    $connection->exec($statement);
                } catch (PDOException $e) {
                    // Ignore "table already exists" errors
                    if (strpos($e->getMessage(), 'already exists') === false && 
                        strpos($e->getMessage(), 'Duplicate') === false) {
                        echo "  ⚠ Warning: " . $e->getMessage() . "\n";
                    }
                }
            }
        }
        
        echo "  ✓ {$migrationFile} completed\n";
    }
    
    // Run seeds
    echo "\n--- Running Seeds ---\n";
    
    $seedFile = __DIR__ . '/../database/seeds/seed_data.sql';
    
    if (file_exists($seedFile)) {
        echo "Running seed_data.sql...\n";
        
        $sql = file_get_contents($seedFile);
        
        // Split by semicolon and execute each statement
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($stmt) => !empty($stmt) && !preg_match('/^\s*--/', $stmt)
        );
        
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                try {
                    $connection->exec($statement);
                } catch (PDOException $e) {
                    // Ignore duplicate entry errors
                    if (strpos($e->getMessage(), 'Duplicate') === false) {
                        echo "  ⚠ Warning: " . $e->getMessage() . "\n";
                    }
                }
            }
        }
        
        echo "  ✓ Seed data loaded\n";
    } else {
        echo "  ⚠ Seed file not found: seed_data.sql\n";
    }
    
    echo "\n=== Setup Complete ===\n";
    echo "You can now access the application at: http://localhost:8000\n";
    echo "Default login credentials should be set in seed_data.sql\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
