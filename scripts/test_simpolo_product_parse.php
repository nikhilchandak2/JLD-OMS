<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\BusyInvoiceImportService;

$sample = <<<'TEXT'
TAX INVOICE
JAICHAND LAL DAGA
Invoice No.    : 838
Dated          : 11-07-2026
Billed to :
Simpolo Vitrified Pvt Ltd
S.N.Description of Goods         HSN/SAC Code       Qty.Unit          Price   Amount(`)
  1.P2 609 P2 609 P4 604 P4 UBC-71 (PROCESSED) 69072200 26.170M.T. 270.00 7,066.00
Grand Total    26.170 M.T.              `     7,420.00
TEXT;

$service = new BusyInvoiceImportService();
$result = $service->parsePdfText($sample);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
