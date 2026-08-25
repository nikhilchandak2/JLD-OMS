<?php
/**
 * PHPUnit bootstrap: point the suite at <DB_NAME>_test and make sure that schema exists.
 * Credentials come from .env, so the suite runs with the same setup as the app.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?? 'localhost';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$baseName = $_ENV['DB_NAME'] ?? 'order_processing';
$testName = str_ends_with($baseName, '_test') ? $baseName : $baseName . '_test';

foreach (['DB_HOST' => $host, 'DB_USER' => $user, 'DB_PASS' => $pass, 'DB_NAME' => $testName] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

try {
    $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot reach MySQL at {$host} as {$user}: {$e->getMessage()}\n");
    fwrite(STDERR, "Set DB_HOST/DB_USER/DB_PASS in .env before running the test suite.\n");
    exit(1);
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$testName}` CHARACTER SET utf8mb4");
$pdo->exec("USE `{$testName}`");

$applySqlFile = static function (PDO $pdo, string $path): void {
    $sql = (string)file_get_contents($path);
    $sql = preg_replace('~/\*[\s\S]*?\*/~', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        try {
            $result = $pdo->query($statement);
            if ($result instanceof PDOStatement) {
                $result->fetchAll();
                $result->closeCursor();
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $ignorable =
                stripos($msg, 'already exists') !== false ||
                stripos($msg, 'Duplicate') !== false ||
                stripos($msg, 'check that column/key exists') !== false ||
                stripos($msg, 'check that it exists') !== false ||
                stripos($msg, "Can't DROP") !== false ||
                stripos($msg, 'Unknown column') !== false ||
                stripos($msg, '1072') !== false ||
                stripos($msg, 'Key column') !== false ||
                stripos($msg, 'RENAME COLUMN') !== false ||
                stripos($msg, 'prepared statement') !== false;
            if (!$ignorable) {
                fwrite(STDERR, "Migration {$path} failed: {$msg}\n");
                throw $e;
            }
        }
    }
};

$alreadyMigrated = $pdo->query("SHOW TABLES LIKE 'orders'")->fetch() !== false;
$migrationsDir = dirname(__DIR__) . '/database/migrations';

if (!$alreadyMigrated) {
    fwrite(STDOUT, "Preparing test schema '{$testName}'...\n");
    $files = glob($migrationsDir . '/*.sql') ?: [];
    sort($files, SORT_NATURAL);
    foreach ($files as $file) {
        $applySqlFile($pdo, $file);
    }
}

$ownerCol = $pdo->query(
    "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_deals' AND COLUMN_NAME = 'owner_user_id'"
)->fetch();
if ((int)($ownerCol['c'] ?? 0) === 0) {
    $applySqlFile($pdo, $migrationsDir . '/046_crm_pipeline_7stage.sql');
}

$incremental = [
    'data_feeds' => '047_data_feeds.sql',
    'credit_policy_tiers' => '048_credit_gate.sql',
    'crm_competitor_positions' => '049_account_context.sql',
    'crm_visits' => '050_crm_visits.sql',
    'dormancy_rules' => '051_dormancy_escalation.sql',
    'forecast_periods' => '052_forecasts.sql',
    'handoff_packets' => '053_handoff_packets.sql',
    'party_handover_notes' => '054_handover_notes.sql',
    'pipeline_deal_snapshot' => '055_pipeline_dashboard.sql',
];
foreach ($incremental as $marker => $file) {
    $missing = $pdo->query("SHOW TABLES LIKE '{$marker}'")->fetch() === false;
    if (!$missing && $marker === 'credit_policy_tiers') {
        $count = $pdo->query("SELECT COUNT(*) AS c FROM credit_policy_tiers")->fetch();
        $missing = ((int)($count['c'] ?? 0) === 0);
    }
    if ($missing) {
        $applySqlFile($pdo, $migrationsDir . '/' . $file);
    }
}
