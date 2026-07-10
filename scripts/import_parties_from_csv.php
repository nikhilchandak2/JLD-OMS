<?php
/**
 * Bulk-import parties from CSV (name + GST, optional email).
 *
 * Usage: php scripts/import_parties_from_csv.php path/to/parties.csv
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\PartyImportService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$csvPath = $argv[1] ?? null;
if ($csvPath === null || !is_file($csvPath)) {
    fwrite(STDERR, "Usage: php scripts/import_parties_from_csv.php path/to/parties.csv\n");
    exit(1);
}

$content = file_get_contents($csvPath);
if ($content === false) {
    fwrite(STDERR, "Could not read CSV.\n");
    exit(1);
}

$result = (new PartyImportService())->importFromCsv($content);

echo 'DB: ' . ($_ENV['DB_NAME'] ?? '') . PHP_EOL;
echo "Created: {$result['created']}, updated: {$result['updated']}, skipped: {$result['skipped']}" . PHP_EOL;

if (!empty($result['errors'])) {
    echo PHP_EOL . 'Errors / warnings:' . PHP_EOL;
    foreach ($result['errors'] as $err) {
        echo "  - {$err}" . PHP_EOL;
    }
}

if (!$result['success']) {
    exit(1);
}
