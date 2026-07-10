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
    private const PRODUCT_HEADERS = ['product', 'item', 'item name', 'product name', 'material', 'description'];
    private const TRUCK_HEADERS = ['trucks', 'no of trucks', 'truck qty', 'truck', 'vehicles', 'qty trucks'];
    private const QTY_HEADERS = ['quantity', 'qty', 'dispatch qty'];
    private const RATE_HEADERS = ['rate', 'rate per mt', 'rate/mt', 'rate per ton', 'product rate', 'price'];
    private const WEIGHT_HEADERS = ['weight', 'net weight', 'loading weight', 'weight mt', 'weight (mt)', 'qty mt', 'quantity mt', 'mt'];
    private const ORDER_HEADERS = ['order no', 'order number', 'order #', 'oms order', 'order id'];
    private const VEHICLE_HEADERS = ['vehicle no', 'vehicle', 'lr no', 'vehicle number'];
    private const AMOUNT_HEADERS = ['amount', 'total', 'invoice amount', 'bill amount'];
    private const COMPANY_HEADERS = ['company', 'company name', 'unit', 'branch'];

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
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            return [
                'invoices' => [],
                'errors' => ['Could not read PDF: ' . $e->getMessage()],
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
        $normalized = preg_replace("/\r\n|\r/", "\n", $text);
        $normalized = preg_replace('/[ \t]+/u', ' ', (string)$normalized);

        if (!preg_match('/TAX\s+INVOICE/i', $normalized)) {
            return [
                'invoices' => [],
                'errors' => ['Not a recognized Busy tax invoice PDF (missing TAX INVOICE header).'],
                'preview' => [],
            ];
        }

        $invoiceNo = null;
        if (preg_match('/Invoice\s+No\.?\s*:\s*(\S+)/i', $normalized, $m)) {
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

        $lineItem = $this->extractGoodsLine($normalized);
        if ($lineItem === null) {
            return [
                'invoices' => [],
                'errors' => ['Could not find goods line (Qty in M.T. and Unit Price) in PDF.'],
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
            'company_name' => $companyName,
            'remarks' => trim(
                "Imported from Busy tax invoice #{$invoiceNo}" .
                ($rawanaNo ? " | Rawana: {$rawanaNo}" : '') .
                ($vehicleNo ? " | Vehicle: {$vehicleNo}" : '')
            ),
        ];

        return [
            'invoices' => [$invoice],
            'errors' => $errors,
            'preview' => [$invoice],
        ];
    }

    /**
     * @return array{product_name: string, loading_weight_tons: float, product_rate: float}|null
     */
    private function extractGoodsLine(string $text): ?array
    {
        // Busy PDF: "1.BALL CLAY C GRADE 25084010 26.170M.T. 270.00 7,066.00" (qty+unit often merged)
        if (preg_match(
            '/\d+\.\s*(.+?)\s+(\d{4,8})\s+([\d,]+\.?\d*)\s*M\.?\s*T\.?\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)/iu',
            $text,
            $m
        )) {
            $productName = trim(preg_replace('/\s+/', ' ', $m[1]));
            $productName = preg_replace('/\s*\(LOOSE\)\s*/iu', '', $productName) ?? $productName;
            $weight = $this->parseNumber($m[3]);
            $rate = $this->parseNumber($m[4]);
            $amount = $this->parseNumber($m[5]);

            if (($rate === null || $rate <= 0) && $amount !== null && $weight !== null && $weight > 0) {
                $rate = round($amount / $weight, 2);
            }

            if ($productName === '' || $weight === null || $weight <= 0 || $rate === null || $rate <= 0) {
                return null;
            }

            return [
                'product_name' => $productName,
                'loading_weight_tons' => $weight,
                'product_rate' => $rate,
            ];
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

            if (preg_match('/Billed\s+to\s*:/i', $trimmed)) {
                $capture = true;
                if (preg_match('/Billed\s+to\s*:\s*(.+?)(?:\s+Shipped\s+to|$)/i', $trimmed, $m)) {
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
    public function parseUpload(string $content, string $extension): array
    {
        $ext = strtolower(ltrim($extension, '.'));
        if ($ext === 'pdf') {
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
     * @return array{invoices: array<int, array<string, mixed>>, errors: string[], preview: array}
     */
    public function parseCsv(string $csvContent): array
    {
        $errors = [];
        $invoices = [];

        if (substr($csvContent, 0, 3) === "\xEF\xBB\xBF") {
            $csvContent = substr($csvContent, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
        if (count($lines) < 2) {
            return [
                'invoices' => [],
                'errors' => ['CSV must have a header row and at least one data row.'],
                'preview' => [],
            ];
        }

        $headerRow = array_map(fn($h) => trim(strtolower((string)$h)), str_getcsv(array_shift($lines)));

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
        $amountCol = $this->findColumn($headerRow, self::AMOUNT_HEADERS);
        $companyCol = $this->findColumn($headerRow, self::COMPANY_HEADERS);

        if ($invoiceCol === null) {
            return [
                'invoices' => [],
                'errors' => ['Could not find Invoice No column. Use headers like "Invoice No", "Bill No", or "Voucher No".'],
                'preview' => [$headerRow],
            ];
        }
        if ($partyCol === null) {
            return [
                'invoices' => [],
                'errors' => ['Could not find Party/Customer column.'],
                'preview' => [$headerRow],
            ];
        }
        if ($productCol === null) {
            return [
                'invoices' => [],
                'errors' => ['Could not find Product/Item column.'],
                'preview' => [$headerRow],
            ];
        }

        $rowNum = 1;
        foreach ($lines as $line) {
            $rowNum++;
            if ($rowNum > self::MAX_ROWS + 1) {
                $errors[] = 'Import stopped: CSV exceeds maximum allowed rows (' . self::MAX_ROWS . ').';
                break;
            }

            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv($line);
            $invoiceNo = trim((string)($row[$invoiceCol] ?? ''));
            $partyName = trim((string)($row[$partyCol] ?? ''));
            $productName = trim((string)($row[$productCol] ?? ''));

            if ($invoiceNo === '' || $partyName === '' || $productName === '') {
                continue;
            }

            $invoiceDate = $dateCol !== null ? $this->normalizeDate(trim((string)($row[$dateCol] ?? ''))) : null;
            if ($invoiceDate === null) {
                $invoiceDate = date('Y-m-d');
            }

            $trucks = 1;
            if ($truckCol !== null) {
                $trucks = max(1, (int)$this->parseNumber($row[$truckCol] ?? '1'));
            } elseif ($qtyCol !== null) {
                $trucks = max(1, (int)$this->parseNumber($row[$qtyCol] ?? '1'));
            }

            $weight = $weightCol !== null ? $this->parseNumber($row[$weightCol] ?? '') : null;
            $rate = $rateCol !== null ? $this->parseNumber($row[$rateCol] ?? '') : null;
            $amount = $amountCol !== null ? $this->parseNumber($row[$amountCol] ?? '') : null;

            if (($rate === null || $rate <= 0) && $amount !== null && $amount > 0 && $weight !== null && $weight > 0) {
                $rate = round($amount / $weight, 2);
            }

            if ($rate === null || $rate <= 0) {
                $errors[] = "Row {$rowNum}: invoice {$invoiceNo} — product rate per ton is required (or provide Amount and Weight).";
                continue;
            }

            $invoices[$invoiceNo] = [
                'invoice_no' => $invoiceNo,
                'invoice_date' => $invoiceDate,
                'party_name' => $partyName,
                'product_name' => $productName,
                'quantity' => $trucks,
                'product_rate' => (float)$rate,
                'loading_weight_tons' => $weight !== null && $weight > 0 ? (float)$weight : null,
                'order_no' => $orderCol !== null ? trim((string)($row[$orderCol] ?? '')) : null,
                'vehicle_no' => $vehicleCol !== null ? trim((string)($row[$vehicleCol] ?? '')) : null,
                'company_name' => $companyCol !== null ? trim((string)($row[$companyCol] ?? '')) : null,
                'remarks' => "Imported from Busy invoice #{$invoiceNo}",
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

    private function findColumn(array $headers, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $index = array_search($candidate, $headers, true);
            if ($index !== false) {
                return $index;
            }
        }
        foreach ($headers as $index => $header) {
            foreach ($candidates as $candidate) {
                if ($header !== '' && str_contains($header, $candidate)) {
                    return $index;
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
        if ($value === '') {
            return null;
        }

        $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'd-M-Y', 'd M Y'];
        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt && $dt->format($format) === $value) {
                return $dt->format('Y-m-d');
            }
        }

        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }
}
