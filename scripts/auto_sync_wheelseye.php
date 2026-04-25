<?php
/**
 * Cron-friendly WheelsEye auto sync runner.
 *
 * Recommended schedule:
 *   */5 * * * * /usr/bin/php /var/www/tracking/scripts/auto_sync_wheelseye.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\WheelsEyeApiService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$startedAt = microtime(true);
$timestamp = date('Y-m-d H:i:s');

try {
    $service = new WheelsEyeApiService();
    $result = $service->syncCurrentLocations();
    $result['last_run'] = $timestamp;
    $result['runner'] = 'scripts/auto_sync_wheelseye.php';
    $result['duration_ms'] = (int)round((microtime(true) - $startedAt) * 1000);

    $storageDir = dirname(__DIR__) . '/storage';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }

    @file_put_contents(
        $storageDir . '/last_tracking_sync.json',
        json_encode($result, JSON_PRETTY_PRINT)
    );

    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($result['success'] ?? false) ? 0 : 1);
} catch (\Throwable $e) {
    $error = [
        'success' => false,
        'message' => $e->getMessage(),
        'synced' => 0,
        'skipped' => 0,
        'fuel_saved' => 0,
        'fuel_missing' => 0,
        'trip_count_delta' => [
            'total' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'cancelled' => 0,
        ],
        'errors' => [],
        'last_run' => $timestamp,
        'runner' => 'scripts/auto_sync_wheelseye.php',
        'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
    ];

    $storageDir = dirname(__DIR__) . '/storage';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }
    @file_put_contents(
        $storageDir . '/last_tracking_sync.json',
        json_encode($error, JSON_PRETTY_PRINT)
    );

    fwrite(STDERR, json_encode($error, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
