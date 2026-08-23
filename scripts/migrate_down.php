<?php
/**
 * Rollback runner: applies one file from database/rollback/.
 *
 * Usage: php scripts/migrate_down.php 046_crm_pipeline_7stage
 *
 * Unlike scripts/migrate.php this never runs a whole directory: a rollback is always an
 * explicit, named, one-file operation.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$name = $argv[1] ?? '';
if ($name === '') {
    fwrite(STDERR, "Usage: php scripts/migrate_down.php <migration_name>\n");
    fwrite(STDERR, "Available:\n");
    foreach (glob(__DIR__ . '/../database/rollback/*.down.sql') ?: [] as $file) {
        fwrite(STDERR, '  ' . str_replace('.down.sql', '', basename($file)) . "\n");
    }
    exit(1);
}

$path = __DIR__ . '/../database/rollback/' . basename($name) . '.down.sql';
if (!is_file($path)) {
    fwrite(STDERR, "No rollback file for '{$name}' (looked for {$path})\n");
    exit(1);
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

    $sql = (string)file_get_contents($path);
    $sql = preg_replace('~/\*[\s\S]*?\*/~', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

    $statements = array_filter(array_map('trim', explode(';', $sql)), static fn($s) => $s !== '');

    echo "--- rolling back {$name} ---\n";
    foreach ($statements as $statement) {
        try {
            $result = $pdo->query($statement);
            if ($result instanceof PDOStatement) {
                $result->fetchAll();
                $result->closeCursor();
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // A rollback run against a partially-applied schema must be able to finish.
            $isIgnorable =
                stripos($msg, "check that column/key exists") !== false ||
                stripos($msg, "check that it exists") !== false ||
                stripos($msg, "Can't DROP") !== false ||
                stripos($msg, 'Unknown column') !== false ||
                stripos($msg, 'already exists') !== false ||
                stripos($msg, 'Duplicate') !== false;

            if ($isIgnorable) {
                echo "  skipped (already reverted): " . substr(preg_replace('/\s+/', ' ', $statement), 0, 80) . "\n";
                continue;
            }

            throw $e;
        }
    }

    echo "✓ {$name} rolled back\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Rollback failed: ' . $e->getMessage() . "\n");
    exit(1);
}
