<?php
/**
 * Verify Busy PDF invoice import prerequisites on the server.
 * Usage: php scripts/check_busy_pdf_setup.php [optional-invoice.pdf]
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Services\BusyInvoiceImportService;
use Smalot\PdfParser\Parser as PdfParser;

$checks = [
    'php_version' => PHP_VERSION,
    'pdf_parser_class' => class_exists(PdfParser::class),
    'pdf_parser_vendor' => is_dir(__DIR__ . '/../vendor/smalot/pdfparser'),
    'busy_import_service' => class_exists(BusyInvoiceImportService::class),
];

$sample = $argv[1] ?? '';
if ($sample !== '' && is_readable($sample)) {
    $service = new BusyInvoiceImportService();
    $checks['sample_file'] = basename($sample);
    $checks['sample_parse'] = $service->parsePdfFile($sample);
}

$ok = $checks['pdf_parser_class'] && $checks['pdf_parser_vendor'] && $checks['busy_import_service'];
$checks['ok'] = $ok;
$checks['message'] = $ok
    ? 'Busy PDF import prerequisites look good.'
    : 'PDF import is not ready. Run: composer install --no-dev';

echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 1);
