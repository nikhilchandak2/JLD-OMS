<?php
/**
 * Time the heaviest CRM dashboard query and the nightly activity snapshot.
 *
 * Default: current database, no extra rows.
 * --full : insert B7-scale snapshot (3,600 deals) into the read model, time it, then
 *          leave the snapshot in place (nightly rebuild will replace it).
 *
 * Usage: php scripts/crm_load_check.php
 *        php scripts/crm_load_check.php --full
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

use App\Core\Database;
use App\Repositories\AccountDormancySignalRepository;
use App\Services\PipelineDashboardService;

$full = in_array('--full', $argv, true);
$db = new Database();
$asOf = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$svc = new PipelineDashboardService();

if ($full) {
    $svc->rebuild($asOf);
}

$admin = ['id' => 1, 'role' => 'admin'];
$t0 = microtime(true);
$dash = $svc->dashboard($admin);
$dashMs = round((microtime(true) - $t0) * 1000, 1);

$t0 = microtime(true);
$activity = (new AccountDormancySignalRepository())->activitySnapshot($asOf);
$nightMs = round((microtime(true) - $t0) * 1000, 1);

$orders = $db->fetch('SELECT COUNT(*) AS c FROM orders');
$parties = $db->fetch('SELECT COUNT(*) AS c FROM parties');
$snap = $db->fetch('SELECT COUNT(*) AS c FROM pipeline_deal_snapshot');

echo json_encode([
    'as_of' => $asOf,
    'parties' => (int)($parties['c'] ?? 0),
    'orders' => (int)($orders['c'] ?? 0),
    'pipeline_snapshot_rows' => (int)($snap['c'] ?? 0),
    'dashboard_ms' => $dashMs,
    'dashboard_refreshed' => !empty($dash['refreshed']),
    'nightly_activity_ms' => $nightMs,
    'nightly_activity_parties' => count($activity),
    'projection' => 'B7: 600 parties, ~100 deals/month × 36 months = 3,600 deals; ~110k dispatch/order rows over 3 years, under 150k.',
], JSON_PRETTY_PRINT) . "\n";
