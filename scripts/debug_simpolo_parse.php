<?php
require __DIR__ . '/../vendor/autoload.php';

$path = $argv[1] ?? '';
$parser = new Smalot\PdfParser\Parser();
$text = trim($parser->parseFile($path)->getText());
$text = preg_replace('/[\x{00A0}\x{202F}\x{2007}]/u', ' ', $text) ?? $text;
$normalized = preg_replace('/[ \t]+/u', ' ', preg_replace("/\r\n|\r/", "\n", $text) ?? $text);

$qtyUnit = '(?:M\.?\s*T\.?|MT|M\s*TON|TON(?:NE)?S?|MTS)';
$patterns = [
    'with_hsn_mt' => '/\d+\.?\s*(.+?)\s+(\d{4,8})\s+([\d,]+\.?\d*)\s*' . $qtyUnit . '\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)/iu',
    'no_unit' => '/\d+\.?\s*(.+?)\s+(\d{4,8})\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)/iu',
];

foreach ($patterns as $name => $pattern) {
    if (preg_match($pattern, $normalized, $m)) {
        echo "MATCH $name\n";
        print_r($m);
    } else {
        echo "NO MATCH $name\n";
    }
}

// goods section
if (preg_match('/Description\s+of\s+Goods(.+?)(?:Grand\s+Total|Add\s*:\s*CGST|Add\s*:\s*IGST)/is', $text, $m)) {
    echo "\nGOODS SECTION:\n" . $m[1] . "\n";
}
