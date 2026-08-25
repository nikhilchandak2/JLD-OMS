<?php
/**
 * Nightly CRM dormancy + escalation job (TASK 6).
 * Idempotent. Safe to run twice in a day. Overlap is blocked by GET_LOCK and crm_job_locks.
 *
 * Usage: php scripts/crm_nightly.php
 *        php scripts/crm_nightly.php 2026-08-25
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$asOf = $argv[1] ?? null;
if ($asOf !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    fwrite(STDERR, "Usage: php scripts/crm_nightly.php [YYYY-MM-DD]\n");
    exit(1);
}

$job = new App\Services\CrmNightlyJobService();
$result = $job->run($asOf);

echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
if (($result['status'] ?? '') === 'failed') {
    exit(1);
}
