<?php
/**
 * Simulate Busy invoice upload parsing (same path as BusyIntegrationController).
 * Usage: php scripts/test_busy_upload_flow.php <invoice.pdf|invoice.csv>
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Services\BusyInvoiceImportService;

$path = $argv[1] ?? '';
if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php scripts/test_busy_upload_flow.php <file>\n");
    exit(1);
}

$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Could not read file\n");
    exit(1);
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (strncmp($content, '%PDF-', 5) === 0) {
    $ext = 'pdf';
}

echo "Extension: {$ext}\n";
echo "PDF magic: " . (strncmp($content, '%PDF-', 5) === 0 ? 'yes' : 'no') . "\n";
echo "PdfParser class: " . (class_exists(\Smalot\PdfParser\Parser::class) ? 'yes' : 'NO — run composer install') . "\n\n";

$service = new BusyInvoiceImportService();
$result = $service->parseUpload($content, $ext, $path);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit(empty($result['invoices']) ? 1 : 0);
