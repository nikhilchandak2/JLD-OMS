<?php
/**
 * Test Runner Script
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

echo "Order Processing System - Test Runner\n";
echo "====================================\n\n";

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Check if test database exists and create if needed
try {
    echo "Setting up test database...\n";
    
    $testDbName = $_ENV['DB_NAME'] . '_test';
    
    // Connect to MySQL without database
    $dsn = "mysql:host={$_ENV['DB_HOST']};charset=utf8mb4";
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
    
    // Create test database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$testDbName}`");
    echo "Test database '{$testDbName}' ready.\n";
    
    // Switch to test database
    $pdo->exec("USE `{$testDbName}`");
    
    // Run migrations on test database
    echo "Running migrations on test database...\n";
    
    $migrationFile = __DIR__ . '/../database/migrations/001_create_tables.sql';
    $sql = file_get_contents($migrationFile);
    
    // Split and execute SQL statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^\s*--/', $stmt);
        }
    );
    
    foreach ($statements as $statement) {
        if (trim($statement)) {
            $pdo->exec($statement);
        }
    }
    
    // Run seed data
    echo "Seeding test database...\n";
    
    $seedFile = __DIR__ . '/../database/seeds/seed_data.sql';
    $sql = file_get_contents($seedFile);
    
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^\s*--/', $stmt);
        }
    );
    
    foreach ($statements as $statement) {
        if (trim($statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo "Test database setup complete.\n\n";
    
} catch (Exception $e) {
    echo "Error setting up test database: " . $e->getMessage() . "\n";
    exit(1);
}

// Set environment variable for tests
$_ENV['DB_NAME'] = $testDbName;

// Run PHPUnit tests
echo "Running tests...\n";
echo "================\n\n";

$phpunitPath = __DIR__ . '/../vendor/bin/phpunit';
if (!file_exists($phpunitPath)) {
    echo "PHPUnit not found. Please run 'composer install' first.\n";
    exit(1);
}

// Execute PHPUnit
$command = "php {$phpunitPath} --configuration phpunit.xml";
$output = [];
$returnCode = 0;

exec($command, $output, $returnCode);

foreach ($output as $line) {
    echo $line . "\n";
}

if ($returnCode === 0) {
    echo "\n✅ All tests passed!\n";
} else {
    echo "\n❌ Some tests failed.\n";
}

exit($returnCode);




