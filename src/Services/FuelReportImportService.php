<?php

namespace App\Services;

use App\Repositories\FuelReportRepository;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Parses vendor monthly fuel reports (Excel / CSV / PDF) into a unified JLD format.
 * Column detection is flexible so new vendor layouts can be added later.
 *
 * Known vendor layouts:
 * - Kobelco EquipOperationReport / MONTHLY WORKING REPORT (.xls)
 * - JCB DI_*_Report (.xlsx)
 */
class FuelReportImportService
{
    /** Friendly site names keyed by Kobelco serial number. */
    public const KOBELCO_MACHINE_NAMES = [
        'YQ15514877' => '8 No. Machine',
        'YQ15515180' => 'Bikaner Silica',
        'YC14-B1156' => '5 No. Machine',
        'YC14505319' => '6 No. Machine',
    ];

    /**
     * Full display label including serial, e.g. "8 No. Machine (Serial No. YQ15514877)".
     */
    public static function kobelcoDisplayName(string $serial): ?string
    {
        $serialKey = strtoupper(trim($serial));
        foreach (self::KOBELCO_MACHINE_NAMES as $key => $label) {
            if (strtoupper($key) === $serialKey) {
                return $label . ' (Serial No. ' . $key . ')';
            }
        }
        return null;
    }

    private const NAME_HEADERS = [
        'machine name', 'machine', 'equipment', 'equipment name', 'unit name',
        'vehicle name', 'vehicle', 'name', 'model', 'asset name', 'plant',
    ];

    private const SERIAL_HEADERS = [
        'serial no', 'serial number', 'serial', 'sr no', 'sr. no', 's.no',
        's no', 'machine serial', 'equipment serial', 'unit serial',
    ];

    private const CHASSIS_HEADERS = [
        'chassis no', 'chassis number', 'chassis', 'chasis no', 'chasis',
        'vin', 'frame no', 'frame number',
    ];

    private const DATE_HEADERS = [
        'date', 'day', 'reading date', 'work date', 'consumption date', 'report date',
    ];

    private const FUEL_HEADERS = [
        'fuel', 'fuel consumed', 'fuel consumption', 'diesel', 'diesel consumed',
        'fuel liters', 'fuel (l)', 'fuel l', 'liters', 'litres', 'consumption',
        'fuel qty', 'qty fuel', 'diesel qty',
    ];

    private const HOURS_HEADERS = [
        'working hours', 'work hours', 'hours', 'hrs', 'engine hours',
        'operating hours', 'run hours', 'hmr', 'hour meter',
    ];

    private const AVG_HEADERS = [
        'average usage', 'avg usage', 'average', 'avg', 'lph', 'liters/hour',
        'litres/hour', 'fuel per hour', 'avg consumption', 'average consumption',
    ];

    private FuelReportRepository $repository;

    public function __construct(?FuelReportRepository $repository = null)
    {
        $this->repository = $repository ?? new FuelReportRepository();
    }

    /**
     * @return array{
     *   success: bool,
     *   upload_id: int|null,
     *   machines_found: int,
     *   readings_saved: int,
     *   report_month: string|null,
     *   errors: string[],
     *   preview: array
     * }
     */
    public function import(
        string $category,
        string $tmpPath,
        string $originalFilename,
        string $extension,
        ?int $uploadedBy
    ): array {
        $category = strtolower(trim($category));
        if (!in_array($category, ['kobelco', 'jcb', 'dumpers'], true)) {
            return $this->fail(['Invalid category. Use kobelco, jcb, or dumpers.']);
        }

        $ext = strtolower(ltrim($extension, '.'));
        $errors = [];
        $mapped = ['headers' => [], 'rows' => []];

        try {
            if (in_array($ext, ['xlsx', 'xls', 'ods'], true)) {
                // Kobelco EquipOperationReport (MONTHLY WORKING REPORT) — one machine per file
                if ($category === 'kobelco') {
                    $mapped = $this->parseKobelcoEquipOperationReport($tmpPath);
                }
                // JCB DI_*_Report.xlsx — Asset ID / TxnTime Slot daily telemetry
                if ($mapped['rows'] === [] && $category === 'jcb') {
                    $mapped = $this->parseJcbDiReport($tmpPath);
                }
                // Dumpers Fleet_Report_Details — Reg No daily fleet report
                if ($mapped['rows'] === [] && $category === 'dumpers') {
                    $mapped = $this->parseDumpersFleetReport($tmpPath);
                }
                if ($mapped['rows'] === []) {
                    $rows = $this->parseSpreadsheet($tmpPath);
                    if ($rows !== []) {
                        $mapped = $this->mapRows($rows);
                    }
                }
            } elseif ($ext === 'csv') {
                $content = file_get_contents($tmpPath);
                if ($content === false) {
                    return $this->fail(['Could not read uploaded CSV.']);
                }
                $mapped = $this->mapRows($this->parseDelimitedText($content));
            } elseif ($ext === 'pdf') {
                $mapped = $this->mapRows($this->parsePdf($tmpPath));
            } else {
                return $this->fail(['Unsupported file type. Upload Excel (.xlsx/.xls), CSV, or PDF.']);
            }
        } catch (\Throwable $e) {
            error_log('Fuel report parse failed: ' . $e->getMessage());
            return $this->fail(['Failed to parse file: ' . $e->getMessage()]);
        }

        if ($mapped['rows'] === []) {
            $hint = $mapped['headers'] !== []
                ? ' Detected columns: ' . implode(', ', $mapped['headers']) . '.'
                : '';
            return $this->fail([
                'Could not map report columns to JLD format (machine + fuel/hours).' . $hint
                . ' For Kobelco use EquipOperationReport .xls; for JCB use DI_*_Report .xlsx; '
                . 'for Dumpers use Fleet_Report_Details .xlsx '
                . '(Date, Reg No, Fuel Consumed, Running Time, etc.).',
            ], $mapped['headers']);
        }

        $storageDir = dirname(__DIR__, 2) . '/storage/fuel-reports/' . $category;
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        $safeName = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalFilename);
        $storedPath = $storageDir . '/' . $safeName;
        @copy($tmpPath, $storedPath);
        $relativePath = 'storage/fuel-reports/' . $category . '/' . $safeName;

        $reportMonth = $this->detectReportMonth($mapped['rows']);
        $uploadId = $this->repository->createUpload(
            $category,
            $originalFilename,
            $ext,
            $relativePath,
            $reportMonth,
            $uploadedBy
        );

        $machineIds = [];
        $readingsSaved = 0;
        $preview = [];

        foreach ($mapped['rows'] as $row) {
            $identity = $this->buildIdentityKey($row['name'], $row['serial_no'], $row['chassis_no']);
            if ($identity === null) {
                $errors[] = 'Skipped a row with no machine name, serial, or chassis.';
                continue;
            }

            $machineId = $this->repository->upsertMachine(
                $category,
                $row['name'],
                $row['serial_no'],
                $row['chassis_no'],
                $identity
            );
            $machineIds[$machineId] = true;

            if (!empty($row['reading_date'])) {
                $this->repository->deleteReadingByMachineDate($machineId, (string)$row['reading_date']);
            }

            $this->repository->insertDailyReading(
                $machineId,
                $uploadId,
                $row['reading_date'],
                $row['fuel_consumed_liters'],
                $row['working_hours'],
                $row['average_usage'],
                $row['extra'] ?: null
            );
            $readingsSaved++;

            if (count($preview) < 8) {
                $preview[] = [
                    'name' => $row['name'],
                    'serial_no' => $row['serial_no'],
                    'chassis_no' => $row['chassis_no'],
                    'reading_date' => $row['reading_date'],
                    'fuel_consumed_liters' => $row['fuel_consumed_liters'],
                    'working_hours' => $row['working_hours'],
                    'average_usage' => $row['average_usage'],
                ];
            }
        }

        $machinesFound = count($machineIds);
        $notes = $errors !== [] ? implode('; ', array_slice($errors, 0, 10)) : null;
        $this->repository->updateUploadStats($uploadId, $machinesFound, $readingsSaved, $notes);

        return [
            'success' => $readingsSaved > 0,
            'upload_id' => $uploadId,
            'machines_found' => $machinesFound,
            'readings_saved' => $readingsSaved,
            'report_month' => $reportMonth,
            'errors' => $errors,
            'preview' => $preview,
            'columns' => ['headers' => $mapped['headers']],
        ];
    }

    private function fail(array $errors, array $headers = []): array
    {
        return [
            'success' => false,
            'upload_id' => null,
            'machines_found' => 0,
            'readings_saved' => 0,
            'report_month' => null,
            'errors' => $errors,
            'preview' => [],
            'columns' => $headers !== [] ? ['headers' => $headers] : null,
        ];
    }

    /**
     * Kobelco EquipOperationReport — sheet "Monthly Working Report".
     * One machine per file. Header has Model + Serial No.; daily block has
     * Date, Working Hrs (HH:MM), Hour Meter, Fuel level, Total Fuel Consump., Ave. Fuel Consump.
     *
     * @return array{headers: string[], rows: list<array<string, mixed>>}
     */
    private function parseKobelcoEquipOperationReport(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $title = strtolower(trim($sheet->getTitle()));
        $b2 = strtoupper($this->sheetCell($sheet, 'B2'));

        if (!str_contains($title, 'monthly working') && !str_contains($b2, 'MONTHLY WORKING REPORT')) {
            // Not this layout — try scanning for Serial No. + Total Fuel Consump. headers
            if (!$this->sheetHasText($sheet, 'Total Fuel Consump') || !$this->sheetHasText($sheet, 'Serial No')) {
                return ['headers' => [], 'rows' => []];
            }
        }

        $model = $this->findLabeledValue($sheet, 'Model', 6, 12);
        $serial = $this->findLabeledValue($sheet, 'Serial No', 6, 12);
        $customer = $this->findLabeledValue($sheet, 'Customer Name', 4, 8);
        $periodStart = $this->sheetCell($sheet, 'U9');
        $periodEnd = $this->sheetCell($sheet, 'AD9');
        $year = $this->extractYear($periodStart) ?? $this->extractYear($periodEnd) ?? (int)date('Y');

        $name = $this->resolveKobelcoMachineName($serial, $model);
        if ($customer !== '') {
            // customer goes in extra
        }

        $headerRow = $this->findRowContaining($sheet, 'Total Fuel Consump', 1, 40);
        if ($headerRow === null) {
            return ['headers' => [], 'rows' => []];
        }

        $colDate = 'B';
        $colWorkingHrs = $this->findColumnInRow($sheet, $headerRow, 'Working Hrs') ?? 'BF';
        $colHourMeter = $this->findColumnInRow($sheet, $headerRow, 'Hour Meter') ?? 'BK';
        $colFuelLevel = $this->findColumnInRow($sheet, $headerRow, 'Fuel level') ?? 'BP';
        $colTotalFuel = $this->findColumnInRow($sheet, $headerRow, 'Total Fuel Consump') ?? 'BV';
        $colAvgFuel = $this->findColumnInRow($sheet, $headerRow, 'Ave. Fuel Consump') ?? 'CA';

        $rows = [];
        $maxRow = min($sheet->getHighestRow(), $headerRow + 45);
        for ($r = $headerRow + 1; $r <= $maxRow; $r++) {
            $dateRaw = $this->sheetCell($sheet, $colDate . $r);
            if ($dateRaw === '') {
                continue;
            }
            // Stop when we hit the next section (Operation type, etc.)
            if (preg_match('/^(operation|fuel consumption|monthly working)/i', $dateRaw)) {
                break;
            }
            $readingDate = $this->parseKobelcoDayLabel($dateRaw, $year);
            if ($readingDate === null) {
                continue;
            }

            $workingRaw = $this->sheetCell($sheet, $colWorkingHrs . $r);
            $hourMeterRaw = $this->sheetCell($sheet, $colHourMeter . $r);
            $fuelLevelRaw = $this->sheetCell($sheet, $colFuelLevel . $r);
            $totalFuelRaw = $sheet->getCell($colTotalFuel . $r)->getCalculatedValue();
            $avgFuelRaw = $sheet->getCell($colAvgFuel . $r)->getCalculatedValue();
            $fuelDisplay = $this->sheetCell($sheet, $colTotalFuel . $r);
            $avgDisplay = $this->sheetCell($sheet, $colAvgFuel . $r);

            $workingHours = $this->hhmmToDecimalHours($workingRaw);
            $fuelLiters = is_numeric($totalFuelRaw) ? (float)$totalFuelRaw : $this->toFloat((string)$totalFuelRaw);
            $avgUsage = is_numeric($avgFuelRaw) ? (float)$avgFuelRaw : $this->toFloat((string)$avgFuelRaw);
            if ($avgUsage === null && $fuelLiters !== null && $workingHours !== null && $workingHours > 0) {
                $avgUsage = round($fuelLiters / $workingHours, 4);
            }

            $rows[] = [
                'name' => $name,
                'serial_no' => $serial !== '' ? $serial : null,
                'chassis_no' => null,
                'reading_date' => $readingDate,
                'fuel_consumed_liters' => $fuelLiters,
                'working_hours' => $workingHours,
                'average_usage' => $avgUsage,
                'extra' => array_filter([
                    'vendor' => 'kobelco',
                    'model' => $model !== '' ? $model : null,
                    'customer' => $customer !== '' ? $customer : null,
                    'hour_meter' => $hourMeterRaw !== '' ? $hourMeterRaw : null,
                    'fuel_level' => $fuelLevelRaw !== '' ? $fuelLevelRaw : null,
                    'working_hrs_display' => $workingRaw !== '' ? $workingRaw : null,
                    'fuel_display' => $fuelDisplay !== '' ? $fuelDisplay : null,
                    'avg_display' => $avgDisplay !== '' ? $avgDisplay : null,
                    'period_start' => $periodStart !== '' ? $periodStart : null,
                    'period_end' => $periodEnd !== '' ? $periodEnd : null,
                ], static fn($v) => $v !== null),
            ];
        }

        return [
            'headers' => [
                'Model', 'Serial No.', 'Date', 'Working Hrs', 'Hour Meter',
                'Fuel level', 'Total Fuel Consump.', 'Ave. Fuel Consump.',
            ],
            'rows' => $rows,
        ];
    }

    private function sheetCell(Worksheet $sheet, string $coord): string
    {
        $v = $sheet->getCell($coord)->getFormattedValue();
        return trim(preg_replace('/\s+/u', ' ', (string)($v ?? '')) ?? '');
    }

    private function sheetHasText(Worksheet $sheet, string $needle): bool
    {
        $needle = strtolower($needle);
        $maxRow = min(30, $sheet->getHighestRow());
        $maxCol = min(100, Coordinate::columnIndexFromString($sheet->getHighestColumn()));
        for ($r = 1; $r <= $maxRow; $r++) {
            for ($c = 1; $c <= $maxCol; $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $v = strtolower($this->sheetCell($sheet, $col . $r));
                if ($v !== '' && str_contains($v, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function findLabeledValue(Worksheet $sheet, string $label, int $fromRow, int $toRow): string
    {
        $labelNorm = strtolower($label);
        $maxCol = min(100, Coordinate::columnIndexFromString($sheet->getHighestColumn()));
        for ($r = $fromRow; $r <= $toRow; $r++) {
            for ($c = 1; $c <= $maxCol; $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $v = strtolower($this->sheetCell($sheet, $col . $r));
                if ($v === '' || !str_contains($v, $labelNorm)) {
                    continue;
                }
                // Value is typically several columns to the right of the label
                for ($offset = 1; $offset <= 30; $offset++) {
                    $valCol = Coordinate::stringFromColumnIndex($c + $offset);
                    $val = $this->sheetCell($sheet, $valCol . $r);
                    if ($val !== '' && !preg_match('/^(model|serial|customer|cust\.|latest|date)/i', $val)) {
                        return $val;
                    }
                }
            }
        }
        return '';
    }

    private function findRowContaining(Worksheet $sheet, string $needle, int $fromRow, int $toRow): ?int
    {
        $needle = strtolower($needle);
        $maxCol = min(100, Coordinate::columnIndexFromString($sheet->getHighestColumn()));
        $toRow = min($toRow, $sheet->getHighestRow());
        for ($r = $fromRow; $r <= $toRow; $r++) {
            for ($c = 1; $c <= $maxCol; $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $v = strtolower($this->sheetCell($sheet, $col . $r));
                if ($v !== '' && str_contains($v, $needle)) {
                    return $r;
                }
            }
        }
        return null;
    }

    private function findColumnInRow(Worksheet $sheet, int $row, string $needle): ?string
    {
        $needle = strtolower($needle);
        $maxCol = min(100, Coordinate::columnIndexFromString($sheet->getHighestColumn()));
        for ($c = 1; $c <= $maxCol; $c++) {
            $col = Coordinate::stringFromColumnIndex($c);
            $v = strtolower($this->sheetCell($sheet, $col . $row));
            if ($v !== '' && str_contains($v, $needle)) {
                return $col;
            }
        }
        return null;
    }

    private function extractYear(string $text): ?int
    {
        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * JCB DI_*_Report.xlsx — daily telemetry rows.
     * Supports Premium (FuelUsedInWorking + DistanceTravelledInRoading)
     * and Standard (Fuel Used In Working, fewer columns) layouts.
     *
     * @return array{headers: string[], rows: list<array<string, mixed>>}
     */
    private function parseJcbDiReport(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $headerRow = $this->findRowContainingNormalized($sheet, 'fuelusedinworking', 1, 15);
        if ($headerRow === null) {
            $headerRow = $this->findRowContainingNormalized($sheet, 'assetid', 1, 15);
        }
        if ($headerRow === null) {
            return ['headers' => [], 'rows' => []];
        }

        $colAsset = $this->findColumnInRowNormalized($sheet, $headerRow, ['assetid', 'asset id']);
        $colDate = $this->findColumnInRowNormalized($sheet, $headerRow, ['txntimeslot', 'txn time slot']);
        $colFuel = $this->findColumnInRowNormalized($sheet, $headerRow, [
            'fuelusedinworking', 'fuel used in working',
        ]);
        $colWorking = $this->findColumnInRowNormalized($sheet, $headerRow, ['workingtime', 'working time']);
        $colEngineOn = $this->findColumnInRowNormalized($sheet, $headerRow, [
            'engineontime', 'engineon time', 'engine on time',
        ]);
        $colIdle = $this->findColumnInRowNormalized($sheet, $headerRow, ['idletime', 'idle time']);
        $colDistance = $this->findColumnInRowNormalized($sheet, $headerRow, [
            'distancetravelledinroading', 'distance travelled in roading',
        ]);
        $colModel = $this->findColumnInRowNormalized($sheet, $headerRow, ['modelname', 'model name']);
        $colProfile = $this->findColumnInRowNormalized($sheet, $headerRow, ['profilename', 'profile name']);
        $colMds = $this->findColumnInRowNormalized($sheet, $headerRow, ['mdscode', 'mds code']);
        if ($colAsset === null || $colDate === null) {
            return ['headers' => [], 'rows' => []];
        }
        if ($colFuel === null && $colWorking === null) {
            return ['headers' => [], 'rows' => []];
        }

        $headerLabels = [];
        $maxCol = min(80, Coordinate::columnIndexFromString($sheet->getHighestColumn()));
        for ($c = 1; $c <= $maxCol; $c++) {
            $col = Coordinate::stringFromColumnIndex($c);
            $label = $this->sheetCell($sheet, $col . $headerRow);
            if ($label !== '') {
                $headerLabels[] = $label;
            }
        }

        $rows = [];
        $maxRow = $sheet->getHighestRow();
        for ($r = $headerRow + 1; $r <= $maxRow; $r++) {
            $asset = $this->sheetCell($sheet, $colAsset . $r);
            if ($asset === '') {
                continue;
            }

            $dateRaw = $sheet->getCell($colDate . $r)->getFormattedValue();
            $dateCalc = $sheet->getCell($colDate . $r)->getCalculatedValue();
            $readingDate = $this->normalizeDate(trim((string)($dateRaw ?? '')));
            if ($readingDate === null && $dateCalc instanceof \DateTimeInterface) {
                $readingDate = $dateCalc->format('Y-m-d');
            } elseif ($readingDate === null && is_numeric($dateCalc)) {
                try {
                    $readingDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$dateCalc)->format('Y-m-d');
                } catch (\Throwable $e) {
                    $readingDate = null;
                }
            }
            if ($readingDate === null) {
                continue;
            }

            $model = $colModel ? $this->sheetCell($sheet, $colModel . $r) : '';
            $profile = $colProfile ? $this->sheetCell($sheet, $colProfile . $r) : '';
            $mds = $colMds ? $this->sheetCell($sheet, $colMds . $r) : '';

            $fuelDisplay = $colFuel ? $this->sheetCell($sheet, $colFuel . $r) : '';
            $workingDisplay = $colWorking ? $this->sheetCell($sheet, $colWorking . $r) : '';
            $engineDisplay = $colEngineOn ? $this->sheetCell($sheet, $colEngineOn . $r) : '';
            $idleDisplay = $colIdle ? $this->sheetCell($sheet, $colIdle . $r) : '';
            $distanceDisplay = $colDistance ? $this->sheetCell($sheet, $colDistance . $r) : '';

            $fuelLiters = $colFuel ? $this->cellNumeric($sheet, $colFuel . $r) : null;
            $workingHours = $colWorking ? $this->cellNumeric($sheet, $colWorking . $r) : null;
            $engineOn = $colEngineOn ? $this->cellNumeric($sheet, $colEngineOn . $r) : null;
            $idleHours = $colIdle ? $this->cellNumeric($sheet, $colIdle . $r) : null;
            $distance = $colDistance ? $this->cellNumeric($sheet, $colDistance . $r) : null;

            $avgUsage = null;
            $avgDisplay = null;
            if ($fuelLiters !== null && $workingHours !== null && $workingHours > 0) {
                $avgUsage = round($fuelLiters / $workingHours, 2);
                $avgDisplay = number_format($avgUsage, 2, '.', '') . ' L/h';
            }

            $name = $model !== '' ? $model : ($profile !== '' ? $profile : ('JCB ' . $asset));

            $fmt2 = static function (?string $v): ?string {
                if ($v === null || trim($v) === '') {
                    return null;
                }
                $v = trim($v);
                if (preg_match('/^(-?\d+(?:\.\d+)?)(.*)$/', $v, $m)) {
                    $num = round((float)$m[1], 2);
                    $formatted = rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
                    if ($formatted === '' || $formatted === '-') {
                        $formatted = '0';
                    }
                    return $formatted . ($m[2] ?? '');
                }
                return $v;
            };

            $rows[] = [
                'name' => $name,
                'serial_no' => null,
                'chassis_no' => $asset,
                'reading_date' => $readingDate,
                'fuel_consumed_liters' => $fuelLiters !== null ? round($fuelLiters, 2) : null,
                'working_hours' => $workingHours !== null ? round($workingHours, 2) : null,
                'average_usage' => $avgUsage,
                'extra' => array_filter([
                    'vendor' => 'jcb',
                    'model' => $model !== '' ? $model : null,
                    'profile' => $profile !== '' ? $profile : null,
                    'mds_code' => $mds !== '' ? $mds : null,
                    'asset_id' => $asset,
                    'working_hrs_display' => $fmt2($workingDisplay !== '' ? $workingDisplay : null),
                    'fuel_display' => $fmt2($fuelDisplay !== '' ? $fuelDisplay : null),
                    'avg_display' => $avgDisplay,
                    'engine_on_display' => $fmt2($engineDisplay !== '' ? $engineDisplay : null),
                    'engine_on_hours' => $engineOn !== null ? round($engineOn, 2) : null,
                    'idle_display' => $fmt2($idleDisplay !== '' ? $idleDisplay : null),
                    'idle_hours' => $idleHours !== null ? round($idleHours, 2) : null,
                    'distance_display' => $fmt2($distanceDisplay !== '' ? $distanceDisplay : null),
                    'distance_roading' => $distance !== null ? round($distance, 2) : null,
                ], static fn($v) => $v !== null),
            ];
        }

        return [
            'headers' => $headerLabels !== [] ? $headerLabels : [
                'Asset ID', 'TxnTime Slot', 'EngineOn Time', 'Working Time', 'Idle Time',
                'Fuel Used In Working', 'DistanceTravelledInRoading',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Dumpers Fleet_Report_Details_*.xlsx — daily rows keyed by Reg No.
     *
     * Columns: Date, Reg No, Vehicle Model, Fuel Type, Distance Covered(KM),
     * Fuel Consumed(ltr), Mileage, Idling Fuel Consumption(ltr),
     * Running Time(hh:mm:ss), Idling Time, Halt Time, Start/End Odo.
     *
     * @return array{headers: list<string>, rows: list<array<string, mixed>>}
     */
    private function parseDumpersFleetReport(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Fleet Report') ?: $spreadsheet->getActiveSheet();

        $headerRow = $this->findRowContainingNormalized($sheet, 'fuelconsumed', 1, 10);
        if ($headerRow === null) {
            $headerRow = $this->findRowContainingNormalized($sheet, 'regno', 1, 10);
        }
        if ($headerRow === null) {
            return ['headers' => [], 'rows' => []];
        }

        $colDate = $this->findColumnInRowNormalized($sheet, $headerRow, ['date']);
        $colReg = $this->findColumnInRowNormalized($sheet, $headerRow, ['regno', 'reg no', 'registration']);
        $colModel = $this->findColumnInRowNormalized($sheet, $headerRow, ['vehiclemodel', 'vehicle model', 'model']);
        $colFuelType = $this->findColumnInRowNormalized($sheet, $headerRow, ['fueltype', 'fuel type']);
        $colDistance = $this->findColumnInRowNormalized($sheet, $headerRow, [
            'distancecoveredkm', 'distancecovered', 'distance covered',
        ]);
        $colFuel = $this->findColumnInRowNormalized($sheet, $headerRow, [
            'fuelconsumedltr', 'fuelconsumed', 'fuel consumed',
        ]);
        $colMileage = $this->findColumnInRowNormalized($sheet, $headerRow, ['mileage']);
        $colIdleFuel = $this->findColumnInRowNormalized($sheet, $headerRow, [
            'idlingfuelconsumptionltr', 'idlingfuelconsumption', 'idling fuel',
        ]);
        $colRunning = $this->findColumnInRowNormalized($sheet, $headerRow, [
            'runningtimehhmmss', 'runningtime', 'running time',
        ]);
        $colIdling = $this->findColumnInRowNormalized($sheet, $headerRow, [
            'idlingtimehhmmss', 'idlingtime', 'idling time',
        ]);
        $colHalt = $this->findColumnInRowNormalized($sheet, $headerRow, [
            'halttimehhmmss', 'halttime', 'halt time',
        ]);

        if ($colDate === null || $colReg === null) {
            return ['headers' => [], 'rows' => []];
        }
        if ($colFuel === null && $colRunning === null) {
            return ['headers' => [], 'rows' => []];
        }

        $headerLabels = [];
        $maxCol = min(80, Coordinate::columnIndexFromString($sheet->getHighestColumn()));
        for ($c = 1; $c <= $maxCol; $c++) {
            $col = Coordinate::stringFromColumnIndex($c);
            $label = $this->sheetCell($sheet, $col . $headerRow);
            if ($label !== '') {
                $headerLabels[] = $label;
            }
        }

        $fmt2 = static function (?string $v): ?string {
            if ($v === null || trim($v) === '') {
                return null;
            }
            $v = trim($v);
            if (preg_match('/^\d{1,4}:\d{2}(:\d{2})?$/', $v)) {
                return $v;
            }
            if (preg_match('/^(-?\d+(?:\.\d+)?)(.*)$/', $v, $m)) {
                $num = round((float)$m[1], 2);
                $formatted = rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
                if ($formatted === '' || $formatted === '-') {
                    $formatted = '0';
                }
                return $formatted . ($m[2] ?? '');
            }
            return $v;
        };

        $rows = [];
        $maxRow = $sheet->getHighestRow();
        for ($r = $headerRow + 1; $r <= $maxRow; $r++) {
            $reg = $this->sheetCell($sheet, $colReg . $r);
            if ($reg === '') {
                continue;
            }

            $dateRaw = $sheet->getCell($colDate . $r)->getFormattedValue();
            $dateCalc = $sheet->getCell($colDate . $r)->getCalculatedValue();
            $readingDate = $this->normalizeDate(trim((string)($dateRaw ?? '')));
            if ($readingDate === null && $dateCalc instanceof \DateTimeInterface) {
                $readingDate = $dateCalc->format('Y-m-d');
            } elseif ($readingDate === null && is_numeric($dateCalc)) {
                try {
                    $readingDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$dateCalc)->format('Y-m-d');
                } catch (\Throwable $e) {
                    $readingDate = null;
                }
            }
            if ($readingDate === null) {
                continue;
            }

            $model = $colModel ? $this->sheetCell($sheet, $colModel . $r) : '';
            $fuelType = $colFuelType ? $this->sheetCell($sheet, $colFuelType . $r) : '';

            $fuelDisplay = $colFuel ? $this->sheetCell($sheet, $colFuel . $r) : '';
            $runningDisplay = $colRunning ? $this->sheetCell($sheet, $colRunning . $r) : '';
            $distanceDisplay = $colDistance ? $this->sheetCell($sheet, $colDistance . $r) : '';
            $mileageDisplay = $colMileage ? $this->sheetCell($sheet, $colMileage . $r) : '';
            $idleFuelDisplay = $colIdleFuel ? $this->sheetCell($sheet, $colIdleFuel . $r) : '';
            $idlingDisplay = $colIdling ? $this->sheetCell($sheet, $colIdling . $r) : '';
            $haltDisplay = $colHalt ? $this->sheetCell($sheet, $colHalt . $r) : '';

            $fuelLiters = $colFuel ? $this->cellNumeric($sheet, $colFuel . $r) : null;
            $distanceKm = $colDistance ? $this->cellNumeric($sheet, $colDistance . $r) : null;
            $mileage = $colMileage ? $this->cellNumeric($sheet, $colMileage . $r) : null;
            $idleFuel = $colIdleFuel ? $this->cellNumeric($sheet, $colIdleFuel . $r) : null;

            $workingHours = null;
            if ($colRunning) {
                $workingHours = $this->hhmmToDecimalHours($runningDisplay);
                if ($workingHours === null) {
                    $workingHours = $this->cellNumeric($sheet, $colRunning . $r);
                }
            }
            $idleHours = null;
            if ($colIdling) {
                $idleHours = $this->hhmmToDecimalHours($idlingDisplay);
                if ($idleHours === null) {
                    $idleHours = $this->cellNumeric($sheet, $colIdling . $r);
                }
            }
            $haltHours = null;
            if ($colHalt) {
                $haltHours = $this->hhmmToDecimalHours($haltDisplay);
                if ($haltHours === null) {
                    $haltHours = $this->cellNumeric($sheet, $colHalt . $r);
                }
            }

            $avgUsage = $mileage !== null ? round($mileage, 2) : null;
            $avgDisplay = $avgUsage !== null
                ? number_format($avgUsage, 2, '.', '') . ' km/L'
                : null;
            if ($avgUsage === null && $fuelLiters !== null && $workingHours !== null && $workingHours > 0) {
                $avgUsage = round($fuelLiters / $workingHours, 2);
                $avgDisplay = number_format($avgUsage, 2, '.', '') . ' L/h';
            }

            $name = $reg . ($model !== '' ? ' (' . $model . ')' : '');

            $rows[] = [
                'name' => $name,
                'serial_no' => null,
                'chassis_no' => $reg,
                'reading_date' => $readingDate,
                'fuel_consumed_liters' => $fuelLiters !== null ? round($fuelLiters, 2) : null,
                'working_hours' => $workingHours !== null ? round($workingHours, 4) : null,
                'average_usage' => $avgUsage,
                'extra' => array_filter([
                    'vendor' => 'dumpers',
                    'reg_no' => $reg,
                    'vehicle_model' => $model !== '' ? $model : null,
                    'fuel_type' => $fuelType !== '' ? $fuelType : null,
                    'working_hrs_display' => $fmt2($runningDisplay !== '' ? $runningDisplay : null),
                    'fuel_display' => $fmt2($fuelDisplay !== '' ? $fuelDisplay : null),
                    'avg_display' => $avgDisplay,
                    'distance_display' => $fmt2($distanceDisplay !== '' ? $distanceDisplay : null),
                    'distance_km' => $distanceKm !== null ? round($distanceKm, 2) : null,
                    'mileage_display' => $fmt2($mileageDisplay !== '' ? $mileageDisplay : null),
                    'mileage' => $mileage !== null ? round($mileage, 2) : null,
                    'idle_fuel_display' => $fmt2($idleFuelDisplay !== '' ? $idleFuelDisplay : null),
                    'idle_fuel_liters' => $idleFuel !== null ? round($idleFuel, 2) : null,
                    'idle_display' => $fmt2($idlingDisplay !== '' ? $idlingDisplay : null),
                    'idle_hours' => $idleHours !== null ? round($idleHours, 4) : null,
                    'halt_display' => $fmt2($haltDisplay !== '' ? $haltDisplay : null),
                    'halt_hours' => $haltHours !== null ? round($haltHours, 4) : null,
                ], static fn($v) => $v !== null),
            ];
        }

        return [
            'headers' => $headerLabels !== [] ? $headerLabels : [
                'Date', 'Reg No', 'Vehicle Model', 'Fuel Type', 'Distance Covered(KM)',
                'Fuel Consumed(ltr)', 'Mileage', 'Idling Fuel Consumption(ltr)',
                'Running Time(hh:mm:ss)', 'Idling Time(hh:mm:ss)', 'Halt Time(hh:mm:ss)',
            ],
            'rows' => $rows,
        ];
    }

    private function normalizeHeaderKey(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? $value);
    }

    private function findRowContainingNormalized(Worksheet $sheet, string $needleKey, int $fromRow, int $toRow): ?int
    {
        $needleKey = $this->normalizeHeaderKey($needleKey);
        $maxCol = min(100, Coordinate::columnIndexFromString($sheet->getHighestColumn()));
        $toRow = min($toRow, $sheet->getHighestRow());
        for ($r = $fromRow; $r <= $toRow; $r++) {
            for ($c = 1; $c <= $maxCol; $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $v = $this->normalizeHeaderKey($this->sheetCell($sheet, $col . $r));
                if ($v !== '' && str_contains($v, $needleKey)) {
                    return $r;
                }
            }
        }
        return null;
    }

    /**
     * @param list<string> $aliases
     */
    private function findColumnInRowNormalized(Worksheet $sheet, int $row, array $aliases): ?string
    {
        $aliasKeys = array_map(fn($a) => $this->normalizeHeaderKey($a), $aliases);
        $maxCol = min(100, Coordinate::columnIndexFromString($sheet->getHighestColumn()));
        for ($c = 1; $c <= $maxCol; $c++) {
            $col = Coordinate::stringFromColumnIndex($c);
            $v = $this->normalizeHeaderKey($this->sheetCell($sheet, $col . $row));
            if ($v === '') {
                continue;
            }
            foreach ($aliasKeys as $alias) {
                if ($alias !== '' && ($v === $alias || str_contains($v, $alias))) {
                    return $col;
                }
            }
        }
        return null;
    }

    private function cellNumeric(Worksheet $sheet, string $coord): ?float
    {
        $raw = $sheet->getCell($coord)->getCalculatedValue();
        if (is_numeric($raw)) {
            return (float)$raw;
        }
        return $this->toFloat($this->sheetCell($sheet, $coord));
    }

    /**
     * Map known Kobelco serials to site machine names; fall back to model / serial.
     */
    public static function resolveKobelcoMachineName(?string $serial, ?string $model = null): string
    {
        $mapped = self::kobelcoDisplayName((string)$serial);
        if ($mapped !== null) {
            return $mapped;
        }
        $model = trim((string)$model);
        if ($model !== '') {
            return $model;
        }
        $serialKey = trim((string)$serial);
        if ($serialKey !== '') {
            return 'Kobelco ' . $serialKey;
        }
        return 'Kobelco';
    }

    /**
     * Kobelco day label e.g. "01 Jun(Mon)" + year from period → Y-m-d
     */
    private function parseKobelcoDayLabel(string $label, int $year): ?string
    {
        if (preg_match('/^(\d{1,2})\s+([A-Za-z]{3})/', trim($label), $m)) {
            $ts = strtotime(sprintf('%s %s %d', $m[1], $m[2], $year));
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }
        return $this->normalizeDate($label);
    }

    /** "11:02" or "03:15:00" (hours:minutes[:seconds]) → decimal hours */
    private function hhmmToDecimalHours(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,4}):(\d{2}):(\d{2})$/', $value, $m)) {
            return round(((int)$m[1]) + ((int)$m[2]) / 60 + ((int)$m[3]) / 3600, 4);
        }
        if (preg_match('/^(\d{1,4}):(\d{2})$/', $value, $m)) {
            return round(((int)$m[1]) + ((int)$m[2]) / 60, 4);
        }
        return $this->toFloat($value);
    }

    /**
     * @return list<list<string>>
     */
    private function parseSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $matrix = [];
        foreach ($sheet->toArray(null, true, true, false) as $row) {
            $cells = array_map(static function ($v) {
                if ($v === null) {
                    return '';
                }
                if ($v instanceof \DateTimeInterface) {
                    return $v->format('Y-m-d');
                }
                return trim((string)$v);
            }, $row);
            if ($this->rowIsEmpty($cells)) {
                continue;
            }
            $matrix[] = $cells;
        }
        return $matrix;
    }

    /**
     * @return list<list<string>>
     */
    private function parseDelimitedText(string $content): array
    {
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
        if ($lines === []) {
            return [];
        }
        $delimiter = str_contains($lines[0], "\t") ? "\t" : ',';
        $matrix = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter);
            $cells = array_map(static fn($v) => trim((string)$v), $cells);
            if (!$this->rowIsEmpty($cells)) {
                $matrix[] = $cells;
            }
        }
        return $matrix;
    }

    /**
     * PDF: extract text lines and split into loose columns (space / pipe).
     * Vendor-specific PDF layouts should be added when samples are available.
     *
     * @return list<list<string>>
     */
    private function parsePdf(string $path): array
    {
        if (!class_exists(PdfParser::class)) {
            throw new \RuntimeException('PDF support is not installed. Run composer install.');
        }
        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);
        $text = $pdf->getText();
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $matrix = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);
            if ($line === '') {
                continue;
            }
            if (str_contains($line, '|')) {
                $cells = array_map('trim', explode('|', $line));
            } elseif (str_contains($line, "\t")) {
                $cells = array_map('trim', explode("\t", $line));
            } else {
                // Split on 2+ spaces to keep multi-word names together
                $cells = preg_split('/\s{2,}/', $line) ?: [$line];
                $cells = array_map('trim', $cells);
            }
            if (!$this->rowIsEmpty($cells)) {
                $matrix[] = $cells;
            }
        }
        return $matrix;
    }

    /**
     * @param list<list<string>> $matrix
     * @return array{headers: string[], rows: list<array<string, mixed>>}
     */
    private function mapRows(array $matrix): array
    {
        $headerIndex = $this->locateHeaderRow($matrix);
        if ($headerIndex === null) {
            return ['headers' => [], 'rows' => []];
        }

        $headerCells = $matrix[$headerIndex];
        $headers = array_map(fn($h) => $this->normalizeHeader((string)$h), $headerCells);

        $nameCol = $this->findColumn($headers, self::NAME_HEADERS);
        $serialCol = $this->findColumn($headers, self::SERIAL_HEADERS);
        $chassisCol = $this->findColumn($headers, self::CHASSIS_HEADERS);
        $dateCol = $this->findColumn($headers, self::DATE_HEADERS);
        $fuelCol = $this->findColumn($headers, self::FUEL_HEADERS);
        $hoursCol = $this->findColumn($headers, self::HOURS_HEADERS);
        $avgCol = $this->findColumn($headers, self::AVG_HEADERS);

        if ($nameCol === null && $serialCol === null && $chassisCol === null) {
            return ['headers' => $headerCells, 'rows' => []];
        }
        if ($fuelCol === null && $hoursCol === null && $dateCol === null) {
            // Still allow machine roster-only sheets
        }

        $mapped = [];
        for ($i = $headerIndex + 1; $i < count($matrix); $i++) {
            $cells = $matrix[$i];
            $name = $nameCol !== null ? $this->cell($cells, $nameCol) : null;
            $serial = $serialCol !== null ? $this->cell($cells, $serialCol) : null;
            $chassis = $chassisCol !== null ? $this->cell($cells, $chassisCol) : null;
            if (($name === null || $name === '') && ($serial === null || $serial === '') && ($chassis === null || $chassis === '')) {
                continue;
            }

            $fuel = $fuelCol !== null ? $this->toFloat($this->cell($cells, $fuelCol)) : null;
            $hours = $hoursCol !== null ? $this->toFloat($this->cell($cells, $hoursCol)) : null;
            $avg = $avgCol !== null ? $this->toFloat($this->cell($cells, $avgCol)) : null;
            if ($avg === null && $fuel !== null && $hours !== null && $hours > 0) {
                $avg = round($fuel / $hours, 4);
            }

            $dateRaw = $dateCol !== null ? $this->cell($cells, $dateCol) : null;
            $readingDate = $this->normalizeDate($dateRaw);

            $extra = [];
            foreach ($headerCells as $idx => $label) {
                if (in_array($idx, array_filter([$nameCol, $serialCol, $chassisCol, $dateCol, $fuelCol, $hoursCol, $avgCol]), true)) {
                    continue;
                }
                $val = $this->cell($cells, $idx);
                if ($val !== null && $val !== '' && $label !== '') {
                    $extra[(string)$label] = $val;
                }
            }

            $mapped[] = [
                'name' => $name !== '' ? $name : null,
                'serial_no' => $serial !== '' ? $serial : null,
                'chassis_no' => $chassis !== '' ? $chassis : null,
                'reading_date' => $readingDate,
                'fuel_consumed_liters' => $fuel,
                'working_hours' => $hours,
                'average_usage' => $avg,
                'extra' => $extra,
            ];
        }

        return ['headers' => $headerCells, 'rows' => $mapped];
    }

    /**
     * @param list<list<string>> $matrix
     */
    private function locateHeaderRow(array $matrix): ?int
    {
        $max = min(25, count($matrix));
        $bestIdx = null;
        $bestScore = 0;
        for ($i = 0; $i < $max; $i++) {
            $headers = array_map(fn($h) => $this->normalizeHeader((string)$h), $matrix[$i]);
            $score = 0;
            if ($this->findColumn($headers, self::NAME_HEADERS) !== null) {
                $score += 2;
            }
            if ($this->findColumn($headers, self::SERIAL_HEADERS) !== null) {
                $score += 2;
            }
            if ($this->findColumn($headers, self::CHASSIS_HEADERS) !== null) {
                $score += 2;
            }
            if ($this->findColumn($headers, self::DATE_HEADERS) !== null) {
                $score += 1;
            }
            if ($this->findColumn($headers, self::FUEL_HEADERS) !== null) {
                $score += 2;
            }
            if ($this->findColumn($headers, self::HOURS_HEADERS) !== null) {
                $score += 1;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdx = $i;
            }
        }
        return $bestScore >= 2 ? $bestIdx : null;
    }

    /**
     * @param list<string> $headers
     * @param list<string> $aliases
     */
    private function findColumn(array $headers, array $aliases): ?int
    {
        foreach ($headers as $idx => $header) {
            foreach ($aliases as $alias) {
                if ($header === $alias || str_contains($header, $alias)) {
                    return (int)$idx;
                }
            }
        }
        return null;
    }

    private function normalizeHeader(string $h): string
    {
        $h = strtolower(trim($h));
        $h = preg_replace('/\s+/', ' ', $h) ?? $h;
        return $h;
    }

    /**
     * @param list<string> $cells
     */
    private function cell(array $cells, int $idx): ?string
    {
        if (!array_key_exists($idx, $cells)) {
            return null;
        }
        $v = trim((string)$cells[$idx]);
        return $v === '' ? null : $v;
    }

    /**
     * @param list<string> $cells
     */
    private function rowIsEmpty(array $cells): bool
    {
        foreach ($cells as $c) {
            if (trim((string)$c) !== '') {
                return false;
            }
        }
        return true;
    }

    private function toFloat(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = str_replace([',', ' '], ['', ''], $value);
        $clean = preg_replace('/[^0-9.\-]/', '', $clean) ?? $clean;
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }
        return (float)$clean;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10);
        }
        $ts = strtotime($value);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        // Excel serial date as string
        if (is_numeric($value) && (float)$value > 30000 && (float)$value < 60000) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value);
                return $dt->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    private function buildIdentityKey(?string $name, ?string $serial, ?string $chassis): ?string
    {
        $serial = $serial !== null ? strtoupper(preg_replace('/\s+/', '', $serial) ?? $serial) : '';
        $chassis = $chassis !== null ? strtoupper(preg_replace('/\s+/', '', $chassis) ?? $chassis) : '';
        $name = $name !== null ? strtoupper(trim(preg_replace('/\s+/', ' ', $name) ?? $name)) : '';

        if ($serial !== '') {
            return 'S:' . $serial;
        }
        if ($chassis !== '') {
            return 'C:' . $chassis;
        }
        if ($name !== '') {
            return 'N:' . $name;
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function detectReportMonth(array $rows): ?string
    {
        foreach ($rows as $row) {
            if (!empty($row['reading_date'])) {
                return substr((string)$row['reading_date'], 0, 7) . '-01';
            }
        }
        return null;
    }
}
