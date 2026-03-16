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

    $stripSqlComments = static function (string $sql): string {
        // Remove /* ... */ block comments
        $sql = preg_replace('~/\\*[\\s\\S]*?\\*/~', '', $sql) ?? $sql;
        // Remove full-line -- comments
        $sql = preg_replace('/^\\s*--.*$/m', '', $sql) ?? $sql;
        return $sql;
    };
    
    // Read and execute seed file
    $seedFile = __DIR__ . '/../database/seeds/seed_data.sql';
    
    if (!file_exists($seedFile)) {
        throw new Exception("Seed file not found: {$seedFile}");
    }
    
    $sql = file_get_contents($seedFile);
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $stripSqlComments($sql))),
        function($stmt) {
            return !empty($stmt);
        }
    );
    
    foreach ($statements as $statement) {
        if (trim($statement)) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $isIgnorable =
                    stripos($msg, 'Duplicate') !== false ||
                    stripos($msg, 'duplicate entry') !== false;
                if (!$isIgnorable) {
                    throw $e;
                }
            }
        }
    }
    
    echo "Seeding completed successfully!\n";
    echo "\nDefault user credentials:\n";
    echo "Admin: admin@example.com / Passw0rd!\n";
    echo "Entry: entry@example.com / Passw0rd!\n";
    echo "View: view@example.com / Passw0rd!\n";
    
} catch (Exception $e) {
    echo "Seeding failed: " . $e->getMessage() . "\n";
    exit(1);
}


