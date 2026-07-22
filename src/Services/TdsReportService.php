<?php

namespace App\Services;

use App\Repositories\TdsReportRepository;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Parses Busy "List of Supply Outward Vouchers" and classifies rows by Price slab.
 *
 * Cumulative price slabs (overlapping — a high price counts in every matching slab):
 * - above_1000 : Price >= 1000 (no upper limit)
 * - above_1500 : Price >= 1500 (no upper limit)
 * - above_2000 : Price >= 2000 (no upper limit)
 */
class TdsReportService
{
    public const BAND_LABELS = [
        'above_1000' => '≥ 1000 (all above)',
        'above_1500' => '≥ 1500 (all above)',
        'above_2000' => '≥ 2000 (all above)',
    ];

    /**
     * Fixed TDS A layout centres (display name + Busy aliases + Dressing rate).
     * Screening rate = 100, D. Dressing rate = 150 (same as sample "TDS A.xlsx").
     *
     * @var list<array{name:string,aliases:list<string>,dressing_rate:int}>
     */
    private const TDS_A_CENTRES = [
        ['name' => 'Inda', 'aliases' => ['inda', 'indo ka bala', 'indoka bala'], 'dressing_rate' => 225],
        ['name' => 'Guda', 'aliases' => ['guda'], 'dressing_rate' => 225],
        ['name' => 'Kotari', 'aliases' => ['kotari', 'kotri'], 'dressing_rate' => 225],
        ['name' => 'Motawata', 'aliases' => ['motawata'], 'dressing_rate' => 225],
        ['name' => 'Nal ML No 16/2006', 'aliases' => ['nal ml no 16/2006', 'nal ml 16/2006', 'nal ml no. 16/2006'], 'dressing_rate' => 350],
        ['name' => 'Nal ML No 31/2018', 'aliases' => ['nal ml no 31/2018', 'nal ml 31/2018', 'nal ml no. 31/2018'], 'dressing_rate' => 350],
        ['name' => 'Nal ML No 32/2018', 'aliases' => ['nal ml no 32/2018', 'nal ml 32/2018', 'nal ml no. 32/2018'], 'dressing_rate' => 350],
        ['name' => 'Nal ML No 34/2018', 'aliases' => ['nal ml no 34/2018', 'nal ml 34/2018', 'nal ml no. 34/2018'], 'dressing_rate' => 350],
        ['name' => 'Sarah Bhiyani', 'aliases' => ['sarah bhiyani', 'sarahbiyani', 'sarah biyana'], 'dressing_rate' => 350],
        ['name' => 'Export JJN-1', 'aliases' => ['export jjn-1', 'jjn-1'], 'dressing_rate' => 350],
        ['name' => 'Export JN-2', 'aliases' => ['export jn-2', 'jn-2'], 'dressing_rate' => 350],
    ];

    private const RATE_SCREENING = 100;
    private const RATE_D_DRESSING = 150;

    private const REQUIRED_HEADERS = [
        'date' => ['date'],
        'voucher_no' => ['vch/bill no', 'vch bill no', 'bill no', 'voucher no', 'vch no'],
        'particulars' => ['particulars', 'party', 'party name', 'customer'],
        'item_details' => ['item details', 'item', 'product', 'item name'],
        'material_centre' => ['material centre', 'material center', 'mc', 'godown', 'centre', 'center'],
        'qty' => ['qty.', 'qty', 'quantity'],
        'unit' => ['unit', 'uom'],
        'price' => ['price', 'rate'],
        'amount' => ['amount', 'value', 'total'],
    ];

    private TdsReportRepository $repository;

    public function __construct(?TdsReportRepository $repository = null)
    {
        $this->repository = $repository ?? new TdsReportRepository();
    }

    /**
     * @return array{
     *   success:bool,upload_id:?int,rows_imported:int,period_label:?string,
     *   errors:string[],summary:array,band_totals:array
     * }
     */
    public function import(string $tmpPath, string $originalFilename, string $extension, ?int $uploadedBy): array
    {
        $ext = strtolower(ltrim($extension, '.'));
        if (!in_array($ext, ['xlsx', 'xls', 'ods', 'csv'], true)) {
            return $this->fail(['Unsupported file type. Upload Excel (.xlsx/.xls) or CSV.']);
        }

        try {
            if ($ext === 'csv') {
                $content = file_get_contents($tmpPath);
                if ($content === false) {
                    return $this->fail(['Could not read uploaded CSV.']);
                }
                $matrix = $this->parseDelimitedText($content);
            } else {
                $matrix = $this->parseSpreadsheet($tmpPath);
            }
        } catch (\Throwable $e) {
            error_log('TDS parse failed: ' . $e->getMessage());
            return $this->fail(['Failed to parse file: ' . $e->getMessage()]);
        }

        $headerInfo = $this->findHeaderRow($matrix);
        if ($headerInfo === null) {
            return $this->fail([
                'Could not find required columns (Date, Material Centre, Price, Amount). '
                . 'Upload a Busy "List of Supply Outward Vouchers" export.',
            ]);
        }

        [$headerRowIndex, $map] = $headerInfo;
        $period = $this->detectPeriod($matrix, $headerRowIndex);
        $lines = [];
        $skipped = 0;

        for ($i = $headerRowIndex + 1; $i < count($matrix); $i++) {
            $row = $matrix[$i];
            $parsed = $this->parseDataRow($row, $map);
            if ($parsed === null) {
                $skipped++;
                continue;
            }
            $lines[] = $parsed;
        }

        if ($lines === []) {
            return $this->fail(['No voucher lines found under the header row.']);
        }

        $uploadId = $this->repository->createUpload(
            $originalFilename,
            $ext,
            $period['label'],
            $period['from'],
            $period['to'],
            $uploadedBy
        );

        $imported = $this->repository->insertLines($uploadId, $lines);
        $notes = $skipped > 0 ? "Skipped {$skipped} empty/total rows." : null;
        $this->repository->updateUploadStats($uploadId, $imported, $notes);

        $summary = $this->repository->summaryByMaterialCentre($uploadId);
        $bandTotals = $this->repository->bandTotals($uploadId);

        return [
            'success' => true,
            'upload_id' => $uploadId,
            'rows_imported' => $imported,
            'period_label' => $period['label'],
            'errors' => $notes ? [$notes] : [],
            'summary' => $summary,
            'band_totals' => $bandTotals,
        ];
    }

    /**
     * Stored exclusive band (legacy column). Summaries use cumulative price thresholds instead.
     */
    public static function classifyPrice(float $price): string
    {
        if ($price >= 2000) {
            return '2000_plus';
        }
        if ($price >= 1500) {
            return '1500_2000';
        }
        if ($price >= 1000) {
            return '1000_1500';
        }
        return 'below_1000';
    }

    /** Human-readable list of cumulative slabs a price qualifies for. */
    public static function slabLabelsForPrice(float $price): string
    {
        $labels = [];
        if ($price >= 1000) {
            $labels[] = '≥ 1000';
        }
        if ($price >= 1500) {
            $labels[] = '≥ 1500';
        }
        if ($price >= 2000) {
            $labels[] = '≥ 2000';
        }
        return $labels === [] ? '< 1000' : implode(', ', $labels);
    }

    public function buildExportSpreadsheet(int $uploadId): Spreadsheet
    {
        $upload = $this->repository->findUpload($uploadId);
        if (!$upload) {
            throw new \RuntimeException('Upload not found');
        }

        $summary = $this->repository->summaryByMaterialCentre($uploadId);
        $lines = $this->repository->listLines($uploadId, null, null, 20000, 0);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Order Processing JLD')
            ->setTitle('TDS Report')
            ->setDescription('TDS A format — Material Centre qty by price slab');

        // Primary sheet matches sample "TDS A.xlsx"
        $this->writeTdsAFormatSheet($spreadsheet->getActiveSheet(), $upload, $summary);
        // Audit detail kept as second sheet
        $this->writeDetailSheet($spreadsheet->createSheet(), $upload, $lines);

        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    public function exportToTempFile(int $uploadId): array
    {
        $spreadsheet = $this->buildExportSpreadsheet($uploadId);
        $upload = $this->repository->findUpload($uploadId);
        $period = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($upload['period_label'] ?? 'TDS'));
        $filename = 'TDS_A_' . ($period !== '' ? $period : $uploadId) . '_' . date('Ymd_His') . '.xlsx';

        $dir = sys_get_temp_dir();
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return ['path' => $path, 'filename' => $filename];
    }

    /**
     * Export layout matching sample "TDS A.xlsx":
     *
     * Title | Material Centre (merged 3 rows) | Activity | Rate | Qty | Slab | Rate×Qty
     * Each centre has:
     *   Screening   / 100 / qty(≥1000) / >1000.00 / F=C*D
     *   Dressing    / 225|350 / qty(≥1500) / >1500.00 / F=C*D
     *   D. Dressing / 150 / qty(≥2000) / >2000.00 / F=C*D
     */
    private function writeTdsAFormatSheet($sheet, array $upload, array $summary): void
    {
        $sheet->setTitle('TDS A');

        $qtyByDisplay = $this->mapSummaryToTdsACentres($summary);

        $title = $this->buildTdsATitle($upload);
        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1:F1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A1:F1')->applyFromArray([
            'borders' => [
                'outline' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        $sheet->getColumnDimension('A')->setWidth(27.3);
        $sheet->getColumnDimension('B')->setWidth(17);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(16.6);
        $sheet->getColumnDimension('E')->setWidth(12.9);
        $sheet->getColumnDimension('F')->setWidth(14.3);

        $row = 2;
        $screeningQtyTotal = 0.0;
        $dressingQtyTotal = 0.0;
        $dDressingQtyTotal = 0.0;
        $screeningAmtTotal = 0.0;
        $dressingAmtTotal = 0.0;
        $dDressingAmtTotal = 0.0;
        $grandAmt = 0.0;

        foreach ($qtyByDisplay as $centre) {
            $startRow = $row;
            $qty1000 = (float)$centre['above_1000_qty'];
            $qty1500 = (float)$centre['above_1500_qty'];
            $qty2000 = (float)$centre['above_2000_qty'];
            $dressingRate = (int)$centre['dressing_rate'];

            $amt1000 = self::RATE_SCREENING * $qty1000;
            $amt1500 = $dressingRate * $qty1500;
            $amt2000 = self::RATE_D_DRESSING * $qty2000;

            // Screening — >1000
            $sheet->setCellValue('A' . $row, $centre['name']);
            $sheet->setCellValue('B' . $row, 'Screening');
            $sheet->setCellValue('C' . $row, self::RATE_SCREENING);
            $sheet->setCellValue('D' . $row, $qty1000);
            $sheet->setCellValue('E' . $row, '>1000.00');
            $sheet->setCellValue('F' . $row, $amt1000);
            $row++;

            // Dressing — >1500
            $sheet->setCellValue('B' . $row, 'Dressing');
            $sheet->setCellValue('C' . $row, $dressingRate);
            $sheet->setCellValue('D' . $row, $qty1500);
            $sheet->setCellValue('E' . $row, '>1500.00');
            $sheet->setCellValue('F' . $row, $amt1500);
            $row++;

            // D. Dressing — >2000
            $sheet->setCellValue('B' . $row, 'D. Dressing');
            $sheet->setCellValue('C' . $row, self::RATE_D_DRESSING);
            $sheet->setCellValue('D' . $row, $qty2000);
            $sheet->setCellValue('E' . $row, '>2000.00');
            $sheet->setCellValue('F' . $row, $amt2000);
            $endRow = $row;
            $row++;

            $sheet->mergeCells('A' . $startRow . ':A' . $endRow);
            $sheet->getStyle('A' . $startRow)->getAlignment()
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $screeningQtyTotal += $qty1000;
            $dressingQtyTotal += $qty1500;
            $dDressingQtyTotal += $qty2000;
            $screeningAmtTotal += $amt1000;
            $dressingAmtTotal += $amt1500;
            $dDressingAmtTotal += $amt2000;
            $grandAmt += $amt1000 + $amt1500 + $amt2000;
        }

        $dataEndRow = $row - 1;
        if ($dataEndRow >= 2) {
            $sheet->getStyle('D2:D' . $dataEndRow)->getNumberFormat()->setFormatCode('0.000');
            $sheet->getStyle('F2:F' . $dataEndRow)->getNumberFormat()->setFormatCode('0.00');

            // Thin grid borders for the Material Centre block (same as sample TDS A)
            $thin = ['borderStyle' => Border::BORDER_THIN];
            $sheet->getStyle('A2:F' . $dataEndRow)->applyFromArray([
                'borders' => [
                    'allBorders' => $thin,
                ],
            ]);
        }

        // Blank row then grand TDS figures (sample F36 / F37)
        $row++;
        $grandRow = $row;
        $sheet->setCellValue('F' . $row, $grandAmt);
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('0.00');
        $row++;
        $pctRow = $row;
        $sheet->setCellValue('F' . $row, round($grandAmt / 100, 2));
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('0.00');
        $thin = ['borderStyle' => Border::BORDER_THIN];
        $sheet->getStyle('F' . $grandRow . ':F' . $pctRow)->applyFromArray([
            'borders' => [
                'allBorders' => $thin,
            ],
        ]);
        $row += 2;

        // Totals block (sample rows 39–41) — no borders in sample
        $totals = [
            ['Total Screening', $screeningQtyTotal, $screeningAmtTotal],
            ['Total Dressing', $dressingQtyTotal, $dressingAmtTotal],
            ['Total D. Dressing', $dDressingQtyTotal, $dDressingAmtTotal],
        ];
        foreach ($totals as $t) {
            $sheet->setCellValue('B' . $row, $t[0]);
            $sheet->mergeCells('B' . $row . ':C' . $row);
            $sheet->setCellValue('D' . $row, $t[1]);
            $sheet->setCellValue('E' . $row, $t[2]);
            $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('0.000');
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('B' . $row)->getFont()->setBold(true);
            $row++;
        }
    }

    /**
     * Build ordered centre list for TDS A sheet, merging Busy Material Centre names via aliases.
     * Known template centres always appear (qty 0 if missing). Extra Busy centres are appended.
     *
     * @param list<array<string,mixed>> $summary
     * @return list<array{name:string,dressing_rate:int,above_1000_qty:float,above_1500_qty:float,above_2000_qty:float}>
     */
    private function mapSummaryToTdsACentres(array $summary): array
    {
        $byNorm = [];
        foreach ($summary as $row) {
            $norm = $this->normalizeCentreKey((string)$row['material_centre']);
            $byNorm[$norm] = $row;
        }

        $used = [];
        $out = [];

        foreach (self::TDS_A_CENTRES as $centre) {
            $matched = null;
            foreach ($centre['aliases'] as $alias) {
                $key = $this->normalizeCentreKey($alias);
                if (isset($byNorm[$key])) {
                    $matched = $byNorm[$key];
                    $used[$key] = true;
                    break;
                }
            }
            // Also try display name itself
            if ($matched === null) {
                $key = $this->normalizeCentreKey($centre['name']);
                if (isset($byNorm[$key])) {
                    $matched = $byNorm[$key];
                    $used[$key] = true;
                }
            }

            $out[] = [
                'name' => $centre['name'],
                'dressing_rate' => $centre['dressing_rate'],
                'above_1000_qty' => (float)($matched['above_1000_qty'] ?? 0),
                'above_1500_qty' => (float)($matched['above_1500_qty'] ?? 0),
                'above_2000_qty' => (float)($matched['above_2000_qty'] ?? 0),
            ];
        }

        // Append unmapped Busy centres (e.g. Transfer) so qty is not lost
        foreach ($summary as $row) {
            $key = $this->normalizeCentreKey((string)$row['material_centre']);
            if (isset($used[$key])) {
                continue;
            }
            $out[] = [
                'name' => (string)$row['material_centre'],
                'dressing_rate' => 225,
                'above_1000_qty' => (float)$row['above_1000_qty'],
                'above_1500_qty' => (float)$row['above_1500_qty'],
                'above_2000_qty' => (float)$row['above_2000_qty'],
            ];
        }

        return $out;
    }

    private function normalizeCentreKey(string $name): string
    {
        $name = strtolower(trim($name));
        $name = str_replace(['\\', '.', '_'], ['/', '', ' '], $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return $name;
    }

    private function buildTdsATitle(array $upload): string
    {
        $from = $upload['period_from'] ?? null;
        if (is_string($from) && $from !== '') {
            $ts = strtotime($from);
            if ($ts) {
                $month = date('F', $ts);
                $year = (int)date('Y', $ts);
                $monthNum = (int)date('n', $ts);
                // Indian FY label: Apr 2026 → 2026-27; Jan 2027 → 2026-27
                $fyStart = $monthNum >= 4 ? $year : $year - 1;
                $fyEnd = substr((string)($fyStart + 1), -2);
                return 'JLD ' . $month . ' ' . $fyStart . '-' . $fyEnd;
            }
        }

        $label = trim((string)($upload['period_label'] ?? ''));
        if ($label !== '') {
            return 'JLD ' . $label;
        }

        return 'JLD TDS';
    }

    private function writeDetailSheet($sheet, array $upload, array $lines): void
    {
        $sheet->setTitle('Voucher Detail');
        $sheet->setCellValue('A1', 'TDS Voucher Detail — ' . ($upload['period_label'] ?? ''));
        $sheet->mergeCells('A1:J1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $headers = [
            'Date', 'Vch/Bill No', 'Particulars', 'Item Details', 'Material Centre',
            'Qty', 'Unit', 'Price', 'Amount', 'Qualifying Slabs',
        ];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '3', $h);
        }
        $sheet->getStyle('A3:J3')->getFont()->setBold(true);
        $sheet->getStyle('A3:J3')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E8EEF7');

        $row = 4;
        foreach ($lines as $line) {
            $price = (float)$line['price'];
            $sheet->setCellValue('A' . $row, $line['voucher_date'] ?? $line['voucher_date_raw']);
            $sheet->setCellValue('B' . $row, $line['voucher_no']);
            $sheet->setCellValue('C' . $row, $line['particulars']);
            $sheet->setCellValue('D' . $row, $line['item_details']);
            $sheet->setCellValue('E' . $row, $line['material_centre']);
            $sheet->setCellValue('F' . $row, (float)$line['qty']);
            $sheet->setCellValue('G' . $row, $line['unit']);
            $sheet->setCellValue('H' . $row, $price);
            $sheet->setCellValue('I' . $row, (float)$line['amount']);
            $sheet->setCellValue('J' . $row, self::slabLabelsForPrice($price));
            $row++;
        }

        if ($row > 4) {
            $sheet->getStyle('F4:I' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        }
        foreach (range(1, 10) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }

    private function parseSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        return $sheet->toArray(null, true, true, false);
    }

    private function parseDelimitedText(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
        }
        return $rows;
    }

    /**
     * @param list<list<mixed>> $matrix
     * @return array{0:int,1:array<string,int>}|null
     */
    private function findHeaderRow(array $matrix): ?array
    {
        $scanLimit = min(30, count($matrix));
        for ($i = 0; $i < $scanLimit; $i++) {
            $normalized = [];
            foreach ($matrix[$i] as $col => $val) {
                $key = $this->normalizeHeader((string)$val);
                if ($key !== '') {
                    $normalized[$key] = (int)$col;
                }
            }
            if ($normalized === []) {
                continue;
            }

            $map = [];
            foreach (self::REQUIRED_HEADERS as $field => $aliases) {
                foreach ($aliases as $alias) {
                    if (isset($normalized[$alias])) {
                        $map[$field] = $normalized[$alias];
                        break;
                    }
                }
            }

            if (isset($map['material_centre'], $map['price'], $map['amount'], $map['qty'])) {
                return [$i, $map];
            }
        }
        return null;
    }

    private function normalizeHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(["\xc2\xa0", "\n", "\r", "\t"], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return $value;
    }

    /**
     * @param list<mixed> $row
     * @param array<string,int> $map
     * @return array<string,mixed>|null
     */
    private function parseDataRow(array $row, array $map): ?array
    {
        $mc = trim((string)($row[$map['material_centre']] ?? ''));
        if ($mc === '' || strcasecmp($mc, 'Total') === 0) {
            return null;
        }

        $dateRaw = isset($map['date']) ? trim((string)($row[$map['date']] ?? '')) : '';
        if ($dateRaw !== '' && stripos($dateRaw, 'total') !== false) {
            return null;
        }

        // Skip completely empty numeric rows
        $price = $this->toFloat($row[$map['price']] ?? null);
        $qty = $this->toFloat($row[$map['qty']] ?? null);
        $amount = $this->toFloat($row[$map['amount']] ?? null);
        if ($price === 0.0 && $qty === 0.0 && $amount === 0.0 && $dateRaw === '') {
            return null;
        }

        return [
            'voucher_date' => $this->parseDate($dateRaw),
            'voucher_date_raw' => $dateRaw !== '' ? $dateRaw : null,
            'voucher_no' => isset($map['voucher_no']) ? $this->nullableString($row[$map['voucher_no']] ?? null) : null,
            'particulars' => isset($map['particulars']) ? $this->nullableString($row[$map['particulars']] ?? null) : null,
            'item_details' => isset($map['item_details']) ? $this->nullableString($row[$map['item_details']] ?? null) : null,
            'material_centre' => $mc,
            'qty' => $qty,
            'unit' => isset($map['unit']) ? $this->nullableString($row[$map['unit']] ?? null) : null,
            'price' => $price,
            'amount' => $amount,
            'price_band' => self::classifyPrice($price),
        ];
    }

    /**
     * @param list<list<mixed>> $matrix
     * @return array{label:?string,from:?string,to:?string}
     */
    private function detectPeriod(array $matrix, int $headerRowIndex): array
    {
        $scan = min($headerRowIndex, 10);
        for ($i = 0; $i < $scan; $i++) {
            foreach ($matrix[$i] as $cell) {
                if (!is_string($cell) && !is_numeric($cell)) {
                    continue;
                }
                $text = trim((string)$cell);
                if ($text === '') {
                    continue;
                }
                if (preg_match('/From\s+(\d{1,2}[-.\/]\d{1,2}[-.\/]\d{2,4})\s+to\s+(\d{1,2}[-.\/]\d{1,2}[-.\/]\d{2,4})/i', $text, $m)) {
                    $from = $this->parseDate($m[1]);
                    $to = $this->parseDate($m[2]);
                    return [
                        'label' => 'From ' . $m[1] . ' to ' . $m[2],
                        'from' => $from,
                        'to' => $to,
                    ];
                }
            }
        }
        return ['label' => null, 'from' => null, 'to' => null];
    }

    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$raw)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }
        $raw = str_replace(['.', '/'], '-', $raw);
        $formats = ['d-m-Y', 'd-m-y', 'Y-m-d', 'm-d-Y'];
        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $raw);
            if ($dt instanceof \DateTime) {
                return $dt->format('Y-m-d');
            }
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        $cleaned = str_replace([',', ' ', "\xc2\xa0"], '', (string)$value);
        return is_numeric($cleaned) ? (float)$cleaned : 0.0;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string)$value);
        return $s === '' ? null : $s;
    }

    private function fail(array $errors): array
    {
        return [
            'success' => false,
            'upload_id' => null,
            'rows_imported' => 0,
            'period_label' => null,
            'errors' => $errors,
            'summary' => [],
            'band_totals' => [],
        ];
    }
}
