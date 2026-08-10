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

$alreadyMigrated = $pdo->query("SHOW TABLES LIKE 'orders'")->fetch() !== false;

if (!$alreadyMigrated) {
    fwrite(STDOUT, "Preparing test schema '{$testName}'...\n");
    foreach (['scripts/migrate.php', 'scripts/seed.php'] as $script) {
        // variables_order=EGPCS so the child script sees these overrides in $_ENV, which is
        // where the app reads its database settings from.
        $command = sprintf(
            'DB_HOST=%s DB_NAME=%s DB_USER=%s DB_PASS=%s %s -d variables_order=EGPCS %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($testName),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/' . $script)
        );
        exec($command, $output, $status);
        if ($status !== 0) {
            fwrite(STDERR, "Failed to run {$script}:\n" . implode("\n", $output) . "\n");
            exit(1);
        }
    }
}
