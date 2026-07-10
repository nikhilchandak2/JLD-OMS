<?php
require __DIR__ . '/../vendor/autoload.php';

$path = $argv[1] ?? '';
$parser = new Smalot\PdfParser\Parser();
$pdf = $parser->parseFile($path);
echo "=== RAW TEXT ===\n";
echo $pdf->getText();
echo "\n=== END ===\n";
