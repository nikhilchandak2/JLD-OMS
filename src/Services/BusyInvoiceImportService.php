<?php

namespace App\Services;

use Smalot\PdfParser\Parser as PdfParser;

/**
 * Parse Busy sales-invoice CSV exports and tax-invoice PDFs into normalized rows for dispatch import.
 */
class BusyInvoiceImportService
{
    private const MAX_ROWS = 5000;

    private const INVOICE_HEADERS = ['invoice no', 'invoice #', 'bill no', 'voucher no', 'inv no', 'invoice number', 'invoice'];
    private const DATE_HEADERS = ['invoice date', 'bill date', 'date', 'voucher date'];
    private const PARTY_HEADERS = ['party name', 'customer', 'party', 'customer name', 'account name', 'buyer', 'consignee'];
    private const PRODUCT_HEADERS = ['item name', 'product name', 'item', 'product', 'material', 'description'];
    private const TRUCK_HEADERS = ['no of trucks', 'truck qty', 'qty trucks', 'trucks', 'vehicles'];
    private const QTY_HEADERS = ['quantity', 'qty', 'dispatch qty'];
    private const RATE_HEADERS = ['item rate', 'rate per mt', 'rate/mt', 'rate per ton', 'product rate', 'rate', 'price'];
    private const WEIGHT_HEADERS = ['loading weight', 'net weight', 'weight mt', 'weight (mt)', 'qty mt', 'quantity mt', 'weight', 'mt'];
    private const ORDER_HEADERS = ['order no', 'order number', 'order #', 'oms order', 'order id'];
    private const VEHICLE_HEADERS = ['truck no.', 'truck no', 'truck number', 'vehicle no', 'vehicle number', 'vehicle', 'lr no'];
    private const RAWANA_HEADERS = ['rawana no', 'rawana number', 'rawana'];
    private const EWAY_HEADERS = ['e-way bill', 'eway bill', 'e way bill', 'eway bill no', 'e-way bill no', 'ewb no'];
    private const AMOUNT_HEADERS = ['amount', 'invoice amount', 'bill amount'];
    private const COMPANY_HEADERS = ['company', 'company name', 'unit', 'branch'];
    private const MC_HEADERS = ['mc name', 'mine name', 'mine', 'mc'];
    /** Minimum header markers for Busy Supply Outward Register format. */
    private const SUPPLY_OUTWARD_MARKERS = ['party name', 'rawana no', 'truck no', 'invoice', 'item', 'item rate', 'qty'];

    /**
     * Parse a Busy tax-invoice PDF (Jaichand Lal Daga / JLD Minerals format).
     *
     * @return array{invoices: array<int, array<string, mixed>>, errors: string[], preview: array}
     */
    public function parsePdfFile(string $filePath): array
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($filePath);
            $text = trim($pdf->getText());
        } catch (\Throwable $e) {
            return [
                'invoices' => [],
                'errors' => ['Could not read PDF: ' . $e->getMessage()],
                'preview' => [],
            ];
        }

        if ($text === '') {
            return [
                'invoices' => [],
                'errors' => [
                    'Could not extract text from this PDF. Re-export the invoice from Busy as PDF (not a photo, scan, or screenshot).',
                ],
                'preview' => [],
            ];
        }

        return $this->parsePdfText($text);
    }

    /**
     * @return array{invoices: array<int, array<string, mixed>>, errors: string[], preview: array}
     */
    public function parsePdfText(string $text): array
    {
        $errors = [];
        $text = preg_replace('/[\x{00A0}\x{202F}\x{2007}]/u', ' ', $text) ?? $text;
        $normalized = $this->normalizePdfText($text);

        if (!preg_match('/TAX\s+INVOICE|Tax\s+Invoice/i', $normalized)) {
            return [
                'invoices' => [],
                'errors' => ['Not a recognized Busy tax invoice PDF (missing TAX INVOICE header).'],
                'preview' => [],
            ];
        }

        $invoiceNo = null;
        if (preg_match('/(?:Invoice|Inv\.?|Bill)\s+No\.?\s*:\s*(\S+)/i', $normalized, $m)) {
            $invoiceNo = trim($m[1]);
        }
        if ($invoiceNo === null || $invoiceNo === '') {
            return [
                'invoices' => [],
                'errors' => ['Could not find Invoice No. in PDF.'],
                'preview' => [],
            ];
        }

        $invoiceDate = date('Y-m-d');
        if (preg_match('/Dated\s*:\s*(\d{2}-\d{2}-\d{4})/i', $normalized, $m)) {
            $parsed = $this->normalizeDate($m[1]);
            if ($parsed !== null) {
                $invoiceDate = $parsed;
            }
        }

        $vehicleNo = null;
        if (preg_match('/Vehicle\s+No\.?\s*:\s*(\S+)/i', $normalized, $m)) {
            $vehicleNo = trim($m[1]);
        }

        $rawanaNo = null;
        if (preg_match('/Rawana\s+No\.?\s*:\s*(\S+)/i', $normalized, $m)) {
            $rawanaNo = trim($m[1]);
        }

        $ewayBillNo = null;
        if (preg_match('/E[-\s]?Way\s+Bill\s+No\.?\s*:\s*(\S+)/i', $normalized, $m)) {
            $ewayBillNo = trim($m[1]);
        } elseif (preg_match('/EWB\s+No\.?\s*:\s*(\S+)/i', $normalized, $m)) {
            $ewayBillNo = trim($m[1]);
        } elseif (preg_match('/E[-\s]?Way\s+Bill\s*#?\s*(\d{12})/i', $normalized, $m)) {
            $ewayBillNo = trim($m[1]);
        }

        $orderNo = null;
        if (preg_match('/PURCHASE\s+ORDER\s*:\s*([^\n\r]+)/i', $text, $m)) {
            $candidate = trim($m[1]);
            if (
                $candidate !== ''
                && strlen($candidate) >= 3
                && !preg_match('/^(Transport|LOCAL|NIL|NA|N\/A|-)/i', $candidate)
            ) {
                $orderNo = $candidate;
            }
        }

        $companyName = null;
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*TAX\s+INVOICE\s*$/i', trim($line))) {
                for ($j = $i + 1; $j < count($lines); $j++) {
                    $candidate = trim(preg_replace('/\s+/', ' ', $lines[$j]));
                    if ($candidate !== '' && !preg_match('/^\d/', $candidate)) {
                        $companyName = $candidate;
                        break 2;
                    }
                }
            }
        }

        $partyName = $this->extractPartyName($text);
        if ($partyName === null) {
            return [
                'invoices' => [],
                'errors' => ['Could not find Billed to / party name in PDF.'],
                'preview' => [],
            ];
        }

        $goodsSection = $this->extractGoodsSectionText($text);
        $goodsCollapsed = null;
        if ($goodsSection !== null) {
            $goodsCollapsed = preg_replace('/\s+/u', ' ', trim($goodsSection)) ?? trim($goodsSection);
        }

        $lineItem = null;
        if ($goodsCollapsed !== null) {
            $lineItem = $this->extractGoodsLine($goodsCollapsed, $normalized);
        }
        if ($lineItem === null) {
            $lineItem = $this->extractGoodsLine($normalized, $normalized);
        }
        if ($lineItem === null) {
            $lineItem = $this->extractGoodsLine($text, $normalized);
        }
        if ($lineItem === null && $goodsCollapsed !== null) {
            $lineItem = $this->extractGoodsLineFromComponents($goodsSection, $goodsCollapsed);
        }
        if ($lineItem === null && $goodsCollapsed !== null) {
            $lineItem = $this->extractGoodsLineFromGrandTotal($text, $goodsCollapsed);
        }
        if ($lineItem !== null && $this->looksLikeInvalidProductName($lineItem['product_name'])) {
            $betterName = $this->extractProductNameFromText($normalized);
            if ($betterName !== null) {
                $lineItem['product_name'] = $betterName;
            }
        }
        if ($lineItem === null) {
            $details = ['Could not find goods line (Qty in M.T. and Unit Price) in PDF.'];
            if (filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $snippet = $this->extractGoodsSectionText($text);
                if ($snippet !== null) {
                    $details[] = 'Extracted goods section: ' . mb_substr(preg_replace('/\s+/', ' ', $snippet) ?? $snippet, 0, 300);
                }
            }
            return [
                'invoices' => [],
                'errors' => $details,
                'preview' => [],
            ];
        }

        $invoice = [
            'invoice_no' => $invoiceNo,
            'invoice_date' => $invoiceDate,
            'party_name' => $partyName,
            'product_name' => $lineItem['product_name'],
            'quantity' => 1,
            'product_rate' => $lineItem['product_rate'],
            'loading_weight_tons' => $lineItem['loading_weight_tons'],
            'order_no' => $orderNo,
            'vehicle_no' => $vehicleNo,
            'rawana_no' => $rawanaNo,
            'eway_bill_no' => $ewayBillNo,
            'company_name' => $companyName,
            'remarks' => trim(
                "Imported from Busy tax invoice #{$invoiceNo}" .
                ($ewayBillNo ? " | E-way Bill: {$ewayBillNo}" : ($rawanaNo ? " | Rawana: {$rawanaNo}" : '')) .
                ($vehicleNo ? " | Vehicle: {$vehicleNo}" : '')
            ),
        ];

        return [
            'invoices' => [$invoice],
            'errors' => $errors,
            'preview' => [$invoice],
        ];
    }

    private function normalizePdfText(string $text): string
    {
        $text = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
        $text = preg_replace('/[\x{00A0}\x{202F}\x{2007}]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function qtyUnitPattern(): string
    {
        return '(?:M\.?\s*T\.?\.?|MTS|MT\b|M\s*TON|TON(?:NE)?S?)';
    }

    private function extractGoodsSectionText(string $text): ?string
    {
        if (!preg_match(
            '/Description\s+of\s+Goods(.+?)(?:Grand\s+Total|Add\s*:\s*(?:CGST|SGST|IGST)|HSN\/SAC\s+Tax\s+Rate)/is',
            $text,
            $m
        )) {
            return null;
        }

        $section = trim($m[1]);
        return $section !== '' ? $section : null;
    }

    /**
     * @return array{product_name: string, loading_weight_tons: float, product_rate: float}|null
     */
    private function extractGoodsLine(string $text, ?string $fullText = null): ?array
    {
        $qtyUnit = $this->qtyUnitPattern();
        $patterns = [
            // Busy PDF: "1.UBC-71 (PROCESSED) 25084090 40.74MTS 5,325.00 2,16,940.50"
            '/\d+\.?\s*(.+?)\s+(\d{4,8})\s+([\d,]+\.?\d*)\s*' . $qtyUnit . '\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)/iu',
            // Qty and unit separated: "26.170 M.T." / "40.74 MTS"
            '/\d+\.?\s*(.+?)\s+(\d{4,8})\s+([\d,]+\.?\d*)\s+' . $qtyUnit . '\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)/iu',
            // Without unit suffix on qty (header already says M.T./MTS)
            '/\d+\.?\s*(.+?)\s+(\d{4,8})\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)/iu',
            // Without HSN code
            '/\d+\.?\s*(.+?)\s+([\d,]+\.?\d*)\s*' . $qtyUnit . '\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)/iu',
        ];

        foreach ($patterns as $index => $pattern) {
            if (!preg_match($pattern, $text, $m)) {
                continue;
            }

            if (in_array($index, [3], true)) {
                $productName = trim(preg_replace('/\s+/', ' ', $m[1]));
                $weight = $this->parseNumber($m[2]);
                $rate = $this->parseNumber($m[3]);
                $amount = $this->parseNumber($m[4]);
            } elseif ($index === 2) {
                $productName = trim(preg_replace('/\s+/', ' ', $m[1]));
                $weight = $this->parseNumber($m[3]);
                $rate = $this->parseNumber($m[4]);
                $amount = $this->parseNumber($m[5]);
                if ($weight === null || $weight <= 0 || $weight > 1000) {
                    continue;
                }
            } else {
                $productName = trim(preg_replace('/\s+/', ' ', $m[1]));
                $weight = $this->parseNumber($m[3]);
                $rate = $this->parseNumber($m[4]);
                $amount = $this->parseNumber($m[5]);
            }

            $result = $this->buildGoodsLineResult($productName, $weight, $rate, $amount, $fullText);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return array{product_name: string, loading_weight_tons: float, product_rate: float}|null
     */
    private function extractGoodsLineFromComponents(string $text, string $scopeText): ?array
    {
        $qtyUnit = $this->qtyUnitPattern();
        $productName = null;
        if (preg_match('/\d+\.?\s*([A-Za-z][A-Za-z0-9 \-\/\(\)]+?)(?:\s+\d{4,8}\b|\s+[\d,]+\.?\d*\s*' . $qtyUnit . ')/iu', $scopeText, $m)) {
            $productName = trim(preg_replace('/\s+/', ' ', $m[1]));
            $productName = preg_replace('/\s*\(LOOSE\)\s*/iu', '', $productName) ?? $productName;
        }

        $weight = null;
        if (preg_match('/([\d,]+\.?\d*)\s*' . $qtyUnit . '/iu', $scopeText, $m)) {
            $weight = $this->parseNumber($m[1]);
        }

        $rate = null;
        $amount = null;
        if (preg_match('/' . $qtyUnit . '\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)/iu', $scopeText, $m)) {
            $rate = $this->parseNumber($m[1]);
            $amount = $this->parseNumber($m[2]);
        }

        if ($amount === null && preg_match('/Taxable\s+Amt\.?\s*([\d,]+\.?\d*)/iu', $text, $m)) {
            $amount = $this->parseNumber($m[1]);
        }

        return $this->buildGoodsLineResult($productName ?? '', $weight, $rate, $amount, $scopeText);
    }

    /**
     * @return array{product_name: string, loading_weight_tons: float, product_rate: float}|null
     */
    private function extractGoodsLineFromGrandTotal(string $text, string $scopeText): ?array
    {
        $qtyUnit = $this->qtyUnitPattern();
        $weight = null;
        if (preg_match('/Grand\s+Total\s+([\d,]+\.?\d*)\s*' . $qtyUnit . '/iu', $scopeText, $m)) {
            $weight = $this->parseNumber($m[1]);
        }

        $productName = null;
        if (preg_match('/\d+\.?\s*([A-Za-z][A-Za-z0-9 \-\/\(\)]+?)\s+\d{4,8}/iu', $scopeText, $m)) {
            $productName = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        $amount = null;
        if (preg_match('/Grand\s+Total\s+[\d,]+\.?\d*\s*' . $qtyUnit . '\s*[`\']?\s*([\d,]+\.?\d*)/iu', $scopeText, $m)) {
            $amount = $this->parseNumber($m[1]);
        }
        if ($amount === null && preg_match('/\b(\d{4,8})\s+\d+%\s+([\d,]+\.?\d*)/u', $text, $m)) {
            $amount = $this->parseNumber($m[2]);
        }

        $rate = null;
        if ($amount !== null && $weight !== null && $weight > 0) {
            $taxable = $amount;
            if (preg_match('/\b\d{4,8}\s+\d+%\s+([\d,]+\.?\d*)/u', $text, $taxMatch)) {
                $taxable = $this->parseNumber($taxMatch[1]) ?? $taxable;
            }
            if ($taxable > 0) {
                $rate = round($taxable / $weight, 2);
            }
        }

        return $this->buildGoodsLineResult($productName ?? '', $weight, $rate, $amount, $scopeText);
    }

    /**
     * @return array{product_name: string, loading_weight_tons: float, product_rate: float}|null
     */
    private function buildGoodsLineResult(string $productName, ?float $weight, ?float $rate, ?float $amount, ?string $fullText = null): ?array
    {
        $productName = $this->refineProductName($productName, $fullText);

        if (($rate === null || $rate <= 0) && $amount !== null && $weight !== null && $weight > 0) {
            $rate = round($amount / $weight, 2);
        }

        if ($productName === '' || $this->looksLikeInvalidProductName($productName) || $weight === null || $weight <= 0 || $rate === null || $rate <= 0) {
            return null;
        }

        if (!$this->looksLikePlausibleGoodsValues($weight, $rate, $amount)) {
            return null;
        }

        return [
            'product_name' => $productName,
            'loading_weight_tons' => $weight,
            'product_rate' => $rate,
        ];
    }

    private function looksLikePlausibleGoodsValues(float $weight, float $rate, ?float $amount): bool
    {
        if ($weight <= 0 || $weight > 500) {
            return false;
        }
        if ($rate <= 0 || $rate > 50000) {
            return false;
        }
        if ($amount !== null && $amount > 0) {
            $impliedRate = $amount / $weight;
            $tolerance = max(5.0, $rate * 0.05);
            if (abs($impliedRate - $rate) > $tolerance) {
                return false;
            }
        }
        return true;
    }

    private function cleanProductName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $name = preg_replace('/\bP\d+\s+\d+\b/iu', '', $name) ?? $name;
        $name = preg_replace('/\bP\d+\b/iu', '', $name) ?? $name;
        $name = preg_replace('/\s*\(LOOSE\)\s*/iu', '', $name) ?? $name;
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function refineProductName(string $productName, ?string $fullText = null): string
    {
        if ($fullText !== null) {
            $fromText = $this->extractProductNameFromText($fullText);
            if ($fromText !== null) {
                return $fromText;
            }
        }

        $productName = trim(preg_replace('/\s+/', ' ', $productName));
        $productName = preg_replace('/^\d+\.?\s*/', '', $productName) ?? $productName;
        return $this->cleanProductName($productName);
    }

    private function looksLikeInvalidProductName(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || strlen($name) < 3) {
            return true;
        }

        if (preg_match('/^(?:P\d+\s+\d+\s*)+$/iu', $name)) {
            return true;
        }

        $withoutDims = $this->cleanProductName($name);
        if ($withoutDims === '' || preg_match('/^(?:P\d+\s+\d+\s*)+$/iu', $withoutDims)) {
            return true;
        }

        return false;
    }

    private function extractProductNameFromText(string $text): ?string
    {
        $patterns = [
            '/\b(UBC-\d+(?:\s*\([^)]+\))?)/iu',
            '/\b(BALL\s+CLAY[^0-9\n]{0,40})/iu',
            '/\d+\.?\s*(?:P\d+\s+\d+\s*)*([A-Z][A-Za-z0-9 \-\/]+(?:\([^)]+\))?)\s+\d{4,8}\b/iu',
            '/Description\s+of\s+Goods.*?(\d+\.?\s*[A-Z][A-Za-z0-9 \-\/\(\)]+)\s+\d{4,8}/isu',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $text, $m)) {
                continue;
            }

            $candidate = $this->cleanProductName(trim($m[1]));
            if (!$this->looksLikeInvalidProductName($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractPartyName(string $text): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $capture = false;
        foreach ($lines as $line) {
            $trimmed = trim(preg_replace('/[ \t]+/', ' ', $line));
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/(?:Billed\s+to|Bill\s+To|Buyer)\s*:/i', $trimmed)) {
                $capture = true;
                if (preg_match('/(?:Billed\s+to|Bill\s+To|Buyer)\s*:\s*(.+?)(?:\s+Shipped\s+to|$)/i', $trimmed, $m)) {
                    $name = trim($m[1]);
                    if ($name !== '' && !preg_match('/^Shipped/i', $name)) {
                        return $this->cleanPartyName($name);
                    }
                }
                continue;
            }

            if ($capture) {
                if (preg_match('/^(Shipped\s+to|State\s*:|GSTIN|LNT\s+ROAD|IRN\s*:|S\.N\.|Description)/i', $trimmed)) {
                    break;
                }
                if (preg_match('/^(JAICHAND|JLD\s+MINERALS|TAX\s+INVOICE)/i', $trimmed)) {
                    continue;
                }
                if (preg_match('/^\d{6}$/', $trimmed)) {
                    break;
                }
                // "Gargi Industries    Gargi Industries" on one line
                if (preg_match('/^(.+?)\s{2,}(.+?)$/u', $trimmed, $m) && strcasecmp(trim($m[1]), trim($m[2])) === 0) {
                    return $this->cleanPartyName(trim($m[1]));
                }
                return $this->cleanPartyName($trimmed);
            }
        }

        return null;
    }

    private function cleanPartyName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (preg_match('/^(.+?)\s+\1$/u', $name, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^(.+?)\s+(LNT\s+ROAD|BIKANER)/i', $name, $m)) {
            return trim($m[1]);
        }
        return $name;
    }

    /**
     * @return array{invoices: array<int, array<string, mixed>>, errors: string[], preview: array}
     */
    public function parseUpload(string $content, string $extension, ?string $uploadedFilePath = null): array
    {
        $ext = strtolower(ltrim($extension, '.'));
        $isPdf = strncmp($content, '%PDF-', 5) === 0;

        if ($isPdf || $ext === 'pdf') {
            if (!class_exists(PdfParser::class)) {
                return [
                    'invoices' => [],
                    'errors' => [
                        'PDF support is not installed on the server. Run composer install on the server and redeploy.',
                    ],
                    'preview' => [],
                ];
            }

            if ($uploadedFilePath !== null && is_readable($uploadedFilePath)) {
                return $this->parsePdfFile($uploadedFilePath);
            }

            $tmp = tempnam(sys_get_temp_dir(), 'busy_inv_');
            if ($tmp === false) {
                return ['invoices' => [], 'errors' => ['Could not create temp file for PDF.'], 'preview' => []];
            }
            file_put_contents($tmp, $content);
            $result = $this->parsePdfFile($tmp);
            @unlink($tmp);
            return $result;
        }

        return $this->parseCsv($content);
    }

    /**
     * Parse Busy CSV / Supply Outward Register (tab or comma separated).
     *
     * Expected Supply Outward columns:
     * Party Name | Date | Rawana No | Truck No. | Invoice | Item | Item Rate | Qty | MC Name
     * (Qty = loading weight in MT; each row is one truck / one invoice)
     *
     * @return array{invoices: array<int, array<string, mixed>>, errors: string[], preview: array}
     */
    public function parseCsv(string $csvContent): array
    {
        $errors = [];
        $invoices = [];

        if (substr($csvContent, 0, 3) === "\xEF\xBB\xBF") {
            $csvContent = substr($csvContent, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];
        if (count($lines) < 2) {
            return [
                'invoices' => [],
                'errors' => ['CSV must have a header row and at least one data row.'],
                'preview' => [],
            ];
        }

        $delimiter = $this->detectDelimiter($lines);
        $located = $this->locateHeaderRow($lines, $delimiter);
        if ($located === null) {
            return [
                'invoices' => [],
                'errors' => [
                    'Could not find CSV header row. Expected Busy Supply Outward Register columns: '
                    . 'Party Name, Date, Rawana No, Truck No., Invoice, Item, Item Rate, Qty, MC Name.',
                ],
                'preview' => [],
            ];
        }

        [$headerIndex, $headerRow] = $located;
        $dataLines = array_slice($lines, $headerIndex + 1);

        $invoiceCol = $this->findColumn($headerRow, self::INVOICE_HEADERS);
        $dateCol = $this->findColumn($headerRow, self::DATE_HEADERS);
        $partyCol = $this->findColumn($headerRow, self::PARTY_HEADERS);
        $productCol = $this->findColumn($headerRow, self::PRODUCT_HEADERS);
        $rateCol = $this->findColumn($headerRow, self::RATE_HEADERS);
        $weightCol = $this->findColumn($headerRow, self::WEIGHT_HEADERS);
        $truckCol = $this->findColumn($headerRow, self::TRUCK_HEADERS);
        $qtyCol = $this->findColumn($headerRow, self::QTY_HEADERS);
        $orderCol = $this->findColumn($headerRow, self::ORDER_HEADERS);
        $vehicleCol = $this->findColumn($headerRow, self::VEHICLE_HEADERS);
        $rawanaCol = $this->findColumn($headerRow, self::RAWANA_HEADERS);
        $ewayCol = $this->findColumn($headerRow, self::EWAY_HEADERS);
        $amountCol = $this->findColumn($headerRow, self::AMOUNT_HEADERS);
        $companyCol = $this->findColumn($headerRow, self::COMPANY_HEADERS);
        $mcCol = $this->findColumn($headerRow, self::MC_HEADERS);

        $isSupplyOutward = $this->isSupplyOutwardRegister($headerRow);

        // Supply Outward Register: Qty = weight (MT), Truck No. = vehicle, 1 row = 1 truck
        if ($isSupplyOutward && $weightCol === null && $qtyCol !== null) {
            $weightCol = $qtyCol;
            $qtyCol = null;
        }

        if ($invoiceCol === null) {
            return [
                'invoices' => [],
                'errors' => ['Could not find Invoice column. Use "Invoice", "Invoice No", "Bill No", or "Voucher No".'],
                'preview' => [$headerRow],
            ];
        }
        if ($partyCol === null) {
            return [
                'invoices' => [],
                'errors' => ['Could not find Party Name column.'],
                'preview' => [$headerRow],
            ];
        }
        if ($productCol === null) {
            return [
                'invoices' => [],
                'errors' => ['Could not find Item/Product column.'],
                'preview' => [$headerRow],
            ];
        }

        $rowNum = $headerIndex;
        foreach ($dataLines as $line) {
            $rowNum++;
            if (($rowNum - $headerIndex) > self::MAX_ROWS) {
                $errors[] = 'Import stopped: CSV exceeds maximum allowed rows (' . self::MAX_ROWS . ').';
                break;
            }

            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv($line, $delimiter);
            $invoiceNo = trim((string)($row[$invoiceCol] ?? ''));
            $partyName = trim((string)($row[$partyCol] ?? ''));
            $productName = trim((string)($row[$productCol] ?? ''));

            // Skip title / total footer rows
            if ($this->isCsvNoiseRow($invoiceNo, $partyName, $productName, $row)) {
                continue;
            }

            if ($invoiceNo === '' || $partyName === '' || $productName === '') {
                continue;
            }

            $invoiceDate = $dateCol !== null ? $this->normalizeDate(trim((string)($row[$dateCol] ?? ''))) : null;
            if ($invoiceDate === null) {
                $invoiceDate = date('Y-m-d');
            }

            $weight = $weightCol !== null ? $this->parseNumber($row[$weightCol] ?? '') : null;
            $rate = $rateCol !== null ? $this->parseNumber($row[$rateCol] ?? '') : null;
            $amount = $amountCol !== null ? $this->parseNumber($row[$amountCol] ?? '') : null;

            $trucks = 1;
            if ($isSupplyOutward) {
                $trucks = 1;
            } elseif ($truckCol !== null) {
                $trucks = max(1, (int)($this->parseNumber($row[$truckCol] ?? '1') ?? 1));
            } elseif ($qtyCol !== null && $weightCol === null) {
                // Legacy CSV where Qty means truck count (not weight)
                $trucks = max(1, (int)($this->parseNumber($row[$qtyCol] ?? '1') ?? 1));
            } elseif ($qtyCol !== null && $weightCol !== null && $qtyCol !== $weightCol) {
                $trucks = max(1, (int)($this->parseNumber($row[$qtyCol] ?? '1') ?? 1));
            }

            if (($rate === null || $rate <= 0) && $amount !== null && $amount > 0 && $weight !== null && $weight > 0) {
                $rate = round($amount / $weight, 2);
            }

            if ($rate === null || $rate <= 0) {
                $errors[] = "Row {$rowNum}: invoice {$invoiceNo} — Item Rate (₹/MT) is required.";
                continue;
            }

            $mcName = $mcCol !== null ? trim((string)($row[$mcCol] ?? '')) : '';
            $remarks = "Imported from Busy invoice #{$invoiceNo}";
            if ($mcName !== '') {
                $remarks .= " | MC: {$mcName}";
            }

            $invoices[$invoiceNo] = [
                'invoice_no' => $invoiceNo,
                'invoice_date' => $invoiceDate,
                'party_name' => $partyName,
                'product_name' => $productName,
                'quantity' => $trucks,
                'product_rate' => (float)$rate,
                'loading_weight_tons' => $weight !== null && $weight > 0 ? (float)$weight : null,
                'order_no' => $orderCol !== null ? (trim((string)($row[$orderCol] ?? '')) ?: null) : null,
                'vehicle_no' => $vehicleCol !== null ? (trim((string)($row[$vehicleCol] ?? '')) ?: null) : null,
                'rawana_no' => $rawanaCol !== null ? (trim((string)($row[$rawanaCol] ?? '')) ?: null) : null,
                'eway_bill_no' => $ewayCol !== null ? (trim((string)($row[$ewayCol] ?? '')) ?: null) : null,
                'company_name' => $companyCol !== null ? (trim((string)($row[$companyCol] ?? '')) ?: null) : null,
                'mc_name' => $mcName !== '' ? $mcName : null,
                'remarks' => $remarks,
            ];
        }

        if (empty($invoices) && empty($errors)) {
            $errors[] = 'No valid invoice rows found in CSV.';
        }

        return [
            'invoices' => array_values($invoices),
            'errors' => $errors,
            'preview' => array_slice(array_values($invoices), 0, 5),
        ];
    }

    /**
     * @param list<string> $lines
     */
    private function detectDelimiter(array $lines): string
    {
        foreach (array_slice($lines, 0, 15) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $tabs = substr_count($line, "\t");
            $commas = substr_count($line, ',');
            if ($tabs >= 3 && $tabs >= $commas) {
                return "\t";
            }
            if ($commas >= 3) {
                return ',';
            }
        }
        return ',';
    }

    /**
     * Skip Busy title rows ("JAICHAND…", "Supply Outward Register") and find the real header.
     *
     * @param list<string> $lines
     * @return array{0: int, 1: list<string>}|null
     */
    private function locateHeaderRow(array $lines, string $delimiter): ?array
    {
        $best = null;
        $bestScore = 0;

        foreach ($lines as $index => $line) {
            if ($index > 30) {
                break;
            }
            if (trim($line) === '') {
                continue;
            }

            $cells = array_map(
                fn($h) => $this->normalizeHeaderCell((string)$h),
                str_getcsv($line, $delimiter)
            );
            $score = $this->scoreHeaderRow($cells);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$index, $cells];
            }
        }

        // Need at least Party + Invoice + Item (or Product)
        return $bestScore >= 3 ? $best : null;
    }

    /**
     * @param list<string> $headers
     */
    private function scoreHeaderRow(array $headers): int
    {
        $score = 0;
        if ($this->findColumn($headers, self::PARTY_HEADERS) !== null) {
            $score++;
        }
        if ($this->findColumn($headers, self::INVOICE_HEADERS) !== null) {
            $score++;
        }
        if ($this->findColumn($headers, self::PRODUCT_HEADERS) !== null) {
            $score++;
        }
        if ($this->findColumn($headers, self::DATE_HEADERS) !== null) {
            $score++;
        }
        if ($this->findColumn($headers, self::RATE_HEADERS) !== null) {
            $score++;
        }
        if ($this->findColumn($headers, self::RAWANA_HEADERS) !== null) {
            $score++;
        }
        if ($this->findColumn($headers, self::VEHICLE_HEADERS) !== null) {
            $score++;
        }
        return $score;
    }

    /**
     * @param list<string> $headers
     */
    private function isSupplyOutwardRegister(array $headers): bool
    {
        $hits = 0;
        foreach (self::SUPPLY_OUTWARD_MARKERS as $marker) {
            foreach ($headers as $header) {
                if ($header === $marker || str_starts_with($header, $marker)) {
                    $hits++;
                    break;
                }
            }
        }
        return $hits >= 5;
    }

    /**
     * @param list<string> $row
     */
    private function isCsvNoiseRow(string $invoiceNo, string $partyName, string $productName, array $row): bool
    {
        $joined = strtolower(trim(implode(' ', $row)));
        if ($joined === '') {
            return true;
        }
        if (str_contains($joined, 'supply outward')) {
            return true;
        }
        if (str_starts_with($joined, 'voucher series')) {
            return true;
        }
        if (preg_match('/\btotal\b/', $joined) && $invoiceNo === '' && $partyName === '') {
            return true;
        }
        // Title-only rows without invoice number
        if ($invoiceNo === '' && $productName === '' && !preg_match('/\d/', $partyName)) {
            return true;
        }
        return false;
    }

    private function normalizeHeaderCell(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;
        return $header;
    }

    private function findColumn(array $headers, array $candidates): ?int
    {
        $normalized = array_map([$this, 'normalizeHeaderCell'], $headers);

        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeHeaderCell($candidate);
            $index = array_search($candidate, $normalized, true);
            if ($index !== false) {
                return (int)$index;
            }
        }

        // Prefer longer candidates first for fuzzy contains (e.g. "truck no" before "truck")
        $sorted = $candidates;
        usort($sorted, static fn($a, $b) => strlen((string)$b) <=> strlen((string)$a));

        foreach ($normalized as $index => $header) {
            if ($header === '') {
                continue;
            }
            foreach ($sorted as $candidate) {
                $candidate = $this->normalizeHeaderCell((string)$candidate);
                if ($candidate !== '' && str_contains($header, $candidate)) {
                    return (int)$index;
                }
            }
        }
        return null;
    }

    private function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', (string)$value);
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }
        return (float)$clean;
    }

    private function normalizeDate(string $value): ?string
    {
        return \App\Support\IndianDate::toStorage($value);
    }
}
