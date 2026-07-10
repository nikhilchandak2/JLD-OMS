<?php
/**
 * Fresh production setup: real companies, products, and parties from Parties.csv.
 *
 * Usage: php scripts/fresh_production_setup.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== Step 1: Companies + products ===" . PHP_EOL;
passthru('php ' . escapeshellarg(__DIR__ . '/reset_companies_products.php'), $code1);
if ($code1 !== 0) {
    exit($code1);
}

echo PHP_EOL . "=== Step 2: Import parties from CSV ===" . PHP_EOL;
$csvPath = $argv[1] ?? (__DIR__ . '/../Parties.csv');
if (!is_file($csvPath)) {
    fwrite(STDERR, "Parties CSV not found: {$csvPath}\n");
    fwrite(STDERR, "Usage: php scripts/fresh_production_setup.php path/to/parties.csv\n");
    exit(1);
}
passthru('php ' . escapeshellarg(__DIR__ . '/import_parties_from_csv.php') . ' ' . escapeshellarg($csvPath), $code2);
if ($code2 !== 0) {
    exit($code2);
}

echo PHP_EOL . "=== Fresh setup complete ===" . PHP_EOL;
echo "Log out and log back in to refresh the company switcher." . PHP_EOL;
