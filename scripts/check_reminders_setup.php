<?php
/**
 * Run on the server to verify reminders .env and script paths:
 *   php scripts/check_reminders_setup.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($root);
$dotenv->safeLoad();

$keys = [
    'REMINDERS_SCRIPT_JLD_MINERALS',
    'REMINDERS_SCRIPT_JAICHAND',
    'REMINDERS_SCRIPT',
    'PYTHON_PATH',
];

echo "Project root: {$root}\n";
echo "PHP user: " . (function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : get_current_user()) . "\n";
echo 'open_basedir: ' . (ini_get('open_basedir') ?: '(none)') . "\n\n";

$paths = [];
foreach ($keys as $key) {
    $v = $_ENV[$key] ?? getenv($key);
    echo "{$key}=" . ($v === false || $v === '' ? '(not set)' : $v) . "\n";
    if (is_string($v) && $v !== '') {
        $paths[] = $v;
    }
}

$bundled = $root . '/scripts/send_reminders.py';
$paths[] = $bundled;

echo "\nPath checks:\n";
foreach (array_unique($paths) as $path) {
    $exists = is_file($path);
    $readable = $exists && is_readable($path);
    echo ($exists ? 'OK  ' : 'MISS') . '  ' . $path;
    if ($exists && !$readable) {
        echo '  (not readable by PHP)';
    }
    echo "\n";
}
