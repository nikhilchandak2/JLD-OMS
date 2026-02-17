<?php
/**
 * Clear Demo Data Script
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

try {
    echo "Clearing demo data...\n";
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    $pdo->beginTransaction();
    
    // Clear in correct order due to foreign key constraints
    echo "Clearing dispatches...\n";
    $pdo->exec("DELETE FROM dispatches");
    
    echo "Clearing orders...\n";
    $pdo->exec("DELETE FROM orders");
    
    echo "Clearing parties...\n";
    $pdo->exec("DELETE FROM parties");
    
    echo "Clearing products...\n";
    $pdo->exec("DELETE FROM products");
    
    // Keep users and roles
    echo "Keeping users and roles intact...\n";
    
    // Reset auto-increment counters
    $pdo->exec("ALTER TABLE dispatches AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE orders AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE parties AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE products AUTO_INCREMENT = 1");
    
    $pdo->commit();
    
    echo "Demo data cleared successfully!\n";
    echo "You can now add your own parties and products.\n";
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    echo "Error clearing data: " . $e->getMessage() . "\n";
    exit(1);
}
?>



