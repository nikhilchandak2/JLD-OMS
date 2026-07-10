<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\BusyInvoiceImportService;

$path = $argv[1] ?? '';
if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php scripts/test_busy_pdf_parse.php <invoice.pdf>\n");
    exit(1);
}

$service = new BusyInvoiceImportService();
$result = $service->parsePdfFile($path);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
