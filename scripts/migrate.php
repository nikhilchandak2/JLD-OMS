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
    echo "Starting database migrations...\n\n";

    $database = new Database();
    $pdo = $database->getConnection();

    $stripSqlComments = static function (string $sql): string {
        // Remove /* ... */ block comments
        $sql = preg_replace('~/\\*[\\s\\S]*?\\*/~', '', $sql) ?? $sql;
        // Remove full-line -- comments
        $sql = preg_replace('/^\\s*--.*$/m', '', $sql) ?? $sql;
        return $sql;
    };

    $migrationsDir = realpath(__DIR__ . '/../database/migrations');
    if (!$migrationsDir || !is_dir($migrationsDir)) {
        throw new Exception("Migrations directory not found: " . (__DIR__ . '/../database/migrations'));
    }

    $migrationFiles = glob($migrationsDir . '/*.sql') ?: [];
    sort($migrationFiles, SORT_NATURAL);

    if (empty($migrationFiles)) {
        throw new Exception("No migration files found in: {$migrationsDir}");
    }

    foreach ($migrationFiles as $migrationFile) {
        $base = basename($migrationFile);
        echo "--- {$base} ---\n";

        $sql = file_get_contents($migrationFile);
        if ($sql === false) {
            throw new Exception("Failed to read migration file: {$migrationFile}");
        }

        $sql = $stripSqlComments($sql);

        // Split SQL into individual statements (simple splitter; keep migrations free of stored procedures)
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            static function ($stmt) {
                return $stmt !== '';
            }
        );

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                $msg = $e->getMessage();

                // Idempotency: ignore harmless "already exists" / "duplicate" errors,
                // plus re-run errors from DROP INDEX / DROP FOREIGN KEY on already-migrated schemas
                $isIgnorable =
                    stripos($msg, 'already exists') !== false ||
                    stripos($msg, 'Duplicate') !== false ||
                    stripos($msg, 'duplicate entry') !== false ||
                    stripos($msg, 'Duplicate column name') !== false ||
                    stripos($msg, 'check that column/key exists') !== false ||
                    stripos($msg, 'check that it exists') !== false ||
                    stripos($msg, "Can't DROP") !== false;

                if ($isIgnorable) {
                    continue;
                }

                throw $e;
            }
        }

        echo "✓ {$base} applied\n\n";
    }

    echo "All migrations completed.\n";

    $renumber = __DIR__ . '/renumber_orders_by_company_prefix.php';
    if (is_file($renumber)) {
        echo "\n--- order prefix renumber ---\n";
        passthru('php ' . escapeshellarg($renumber), $renumberCode);
        if ($renumberCode !== 0) {
            throw new Exception("Order renumber script failed with code {$renumberCode}");
        }
    }

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}


