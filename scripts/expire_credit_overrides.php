<?php
/**
 * Expire overdue credit override requests (pending / call_requested past expires_at).
 * Run daily from cron. Window is config/credit_gate.php expire_after_days (IST).
 *
 * Usage: php scripts/expire_credit_overrides.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$service = new App\Services\CreditOverrideService();
$count = $service->expireOverdue(['id' => null, 'role' => 'system']);

echo "Expired {$count} credit override(s).\n";
