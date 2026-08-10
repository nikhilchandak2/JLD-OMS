<?php
/**
 * Test runner. Test-database creation, migration and seeding live in tests/bootstrap.php,
 * so this is a thin wrapper and `./vendor/bin/phpunit` behaves identically.
 */

$root = dirname(__DIR__);
$phpunit = $root . '/vendor/bin/phpunit';

if (!file_exists($phpunit)) {
    echo "PHPUnit not found. Please run 'composer install' first.\n";
    exit(1);
}

$command = sprintf(
    '%s %s --configuration %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($phpunit),
    escapeshellarg($root . '/phpunit.xml')
);

passthru($command, $returnCode);

exit($returnCode);
