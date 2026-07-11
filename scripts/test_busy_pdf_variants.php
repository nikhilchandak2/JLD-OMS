<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\BusyInvoiceImportService;

$path = $argv[1] ?? '';
if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php scripts/test_busy_pdf_variants.php <invoice.pdf>\n");
    exit(1);
}

$parser = new Smalot\PdfParser\Parser();
$text = trim($parser->parseFile($path)->getText());
$service = new BusyInvoiceImportService();

$variants = [
    'original' => $text,
    'split_qty_unit' => str_replace('26.170M.T.', "26.170\nM.T.", $text),
    'split_line' => preg_replace(
        '/\s+1\.BALL CLAY C GRADE\s+25084010\s+26\.170M\.T\.\s+270\.00\s+7,066\.00/u',
        "\n  1.BALL CLAY C GRADE            25084010\n         26.170 M.T.         270.00    7,066.00",
        $text
    ),
    'no_mt_suffix' => str_replace('26.170M.T.', '26.170', $text),
    'nbsp' => str_replace(' ', "\xC2\xA0", $text),
    'multiline_rows' => preg_replace(
        '/\s+1\.BALL CLAY C GRADE\s+25084010\s+26\.170M\.T\.\s+270\.00\s+7,066\.00/u',
        "\n  1.BALL CLAY C GRADE\n  25084010\n  26.170\n  270.00\n  7,066.00",
        $text
    ),
];

foreach ($variants as $name => $variantText) {
    $result = (new ReflectionClass($service))->getMethod('parsePdfText')->invoke($service, $variantText);
    $ok = !empty($result['invoices']);
    echo str_pad($name, 16) . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        echo '  ' . implode('; ', $result['errors']) . PHP_EOL;
    } else {
        $inv = $result['invoices'][0];
        echo '  ' . $inv['product_name'] . ' | ' . $inv['loading_weight_tons'] . ' MT @ ' . $inv['product_rate'] . PHP_EOL;
    }
}
