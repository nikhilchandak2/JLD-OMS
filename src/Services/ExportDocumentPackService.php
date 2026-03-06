<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use App\Helpers\AmountInWords;

/**
 * Export Documents (Nepal) – generates one Excel with Commercial Invoice, Tax Invoice, Packing List.
 * Uses only export order + dispatch data; not linked to OMS orders/dispatches.
 * Templates: Formats/Export/Commercial Invoice.xlsx, Tax Invoice.xlsx, Packing List.xlsx
 */
class ExportDocumentPackService
{
    private ExcelDocumentService $excelService;
    private string $configPath;
    private string $templateBasePath;
    private array $placeholderMap;

    public function __construct()
    {
        $this->excelService = new ExcelDocumentService();
        $this->configPath = __DIR__ . '/../../config/export_document_mappings';
        $this->templateBasePath = __DIR__ . '/../../';
        $placeholderFile = __DIR__ . '/../../config/export_placeholders.php';
        $this->placeholderMap = is_file($placeholderFile) ? require $placeholderFile : [];
    }

    /**
     * Generate one workbook with all sheets for this dispatch.
     *
     * @param array $exportOrder Row from export_orders (fixed data)
     * @param array $dispatch    Dispatch data: trucks[], invoice_no, invoice_date, total_weight_mt, rate_per_mt, amount, etc.
     * @return string Full path to saved file
     */
    public function generatePack(array $exportOrder, array $dispatch): string
    {
        $outputDir = __DIR__ . '/../../storage/export_documents';
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }
        if (!is_dir($outputDir) || !is_writable($outputDir)) {
            throw new \RuntimeException('Export output directory is missing or not writable: storage/export_documents');
        }

        $order = $this->buildOrderData($exportOrder);
        $dispatch = $this->buildDispatchData($dispatch);
        $trucks = $dispatch['trucks'] ?? [];

        // Only include document types you have templates for (e.g. commercial_invoice + packing_list)
        $documentTypes = ['commercial_invoice', 'packing_list'];
        $mainWorkbook = null;
        $sheetIndex = 0;
        $sheetNames = [
            'commercial_invoice' => 'Commercial Invoice',
            'tax_invoice' => 'Tax Invoice',
            'packing_list' => 'Packing List',
        ];

        foreach ($documentTypes as $docType) {
            $configFile = $this->configPath . '/' . $docType . '.php';
            if (!is_file($configFile)) {
                continue;
            }
            $config = require $configFile;
            $sheetTitle = $sheetNames[$docType] ?? $docType;

            $data = ['order' => $order, 'dispatch' => $dispatch];

            $templatePath = $config['template_file'] ?? '';
            $fullPath = $this->templateBasePath . $templatePath;

            // Load workbook if present; otherwise, if we already have a main workbook, try to process the sheet inside it
            $workbook = null;
            $sheet = null;
            $processingMainSheet = false;

            if (is_file($fullPath)) {
                $workbook = IOFactory::load($fullPath);
                $sheet = $this->getSheetFromConfig($workbook, $config, $sheetTitle);
            } elseif ($mainWorkbook instanceof Spreadsheet) {
                $sheet = $this->getSheetFromConfig($mainWorkbook, $config, $sheetTitle);
                $processingMainSheet = true;
            } else {
                continue;
            }

            // Keep sheet title consistent when possible (helps formulas like Packing List!G39)
            if ($sheet instanceof Worksheet && $sheet->getTitle() !== $sheetTitle) {
                // Only rename when we are explicitly generating this docType; safe because placeholders are unique
                $sheet->setTitle($sheetTitle);
            }

            // Fill placeholders or fall back to cell mappings
            if (!empty($this->placeholderMap)) {
                $this->fillSheetWithPlaceholders($sheet, $config, $order, $dispatch, $trucks);
            } else {
                if (!empty($config['single_value_mappings'])) {
                    $this->excelService->mapSingleValues($sheet, $config['single_value_mappings'], $data);
                }
                if (!empty($config['repeating_rows']) && !empty($trucks)) {
                    $this->excelService->mapRepeatingRows(
                        $sheet,
                        $config['repeating_rows'],
                        $trucks,
                        $data
                    );
                }
            }

            // If we processed a sheet already inside the main workbook, don't merge anything
            if ($processingMainSheet) {
                $sheetIndex++;
                continue;
            }

            if ($mainWorkbook === null) {
                $mainWorkbook = $workbook;
            } else {
                $clonedSheet = clone $sheet;
                // If a sheet with this title already exists (e.g. inside Commercial Invoice template), replace it.
                for ($i = $mainWorkbook->getSheetCount() - 1; $i >= 0; $i--) {
                    if ($mainWorkbook->getSheet($i)->getTitle() === $sheetTitle) {
                        $mainWorkbook->removeSheetByIndex($i);
                        break;
                    }
                }
                $clonedSheet->setTitle($sheetTitle);
                $mainWorkbook->addSheet($clonedSheet);
            }
            $sheetIndex++;
        }

        if ($mainWorkbook === null) {
            $paths = ['Formats/Export/Commercial Invoice.xlsx', 'Formats/Export/Packing List.xlsx'];
            $base = realpath($this->templateBasePath) ?: $this->templateBasePath;
            throw new \RuntimeException(
                'No export templates could be loaded. Ensure these files exist on the server: ' .
                implode(', ', $paths) . ' (relative to ' . $base . '). Also ensure storage/export_documents is writable.'
            );
        }

        $invoiceNo = preg_replace('/[^a-zA-Z0-9_-]/', '_', $dispatch['invoice_no'] ?? 'export');
        $date = $dispatch['invoice_date'] ?? date('d-m-Y');
        $dateSafe = preg_replace('/[^0-9-]/', '', $date);
        $filename = "Nepal_Export_{$invoiceNo}_{$dateSafe}.xlsx";
        $outputPath = $outputDir . '/' . $filename;

        $writer = IOFactory::createWriter($mainWorkbook, 'Xlsx');
        // Avoid "Sheet does not exist" when formulas reference other sheets (e.g. Packing List!G39)
        $writer->setPreCalculateFormulas(false);
        $writer->save($outputPath);

        return $outputPath;
    }

    private function getSheetFromConfig(Spreadsheet $workbook, array $config, string $fallbackTitle): Worksheet
    {
        $sheetName = $config['sheet_name'] ?? null;
        if (is_string($sheetName) && $sheetName !== '') {
            $s = $workbook->getSheetByName($sheetName);
            if ($s instanceof Worksheet) {
                return $s;
            }
        }
        if (is_int($sheetName)) {
            try {
                return $workbook->getSheet($sheetName);
            } catch (\Throwable $e) {
                // fall through
            }
        }
        $byTitle = $workbook->getSheetByName($fallbackTitle);
        if ($byTitle instanceof Worksheet) {
            return $byTitle;
        }
        return $workbook->getSheet(0);
    }

    private function fillSheetWithPlaceholders(Worksheet $sheet, array $config, array $order, array $dispatch, array $trucks): void
    {
        $templateRow = isset($config['repeating_rows']['template_row']) ? (int) $config['repeating_rows']['template_row'] : null;
        $numTrucks = count($trucks);

        if ($templateRow !== null && $numTrucks > 0) {
            // Detect height of one truck block by scanning for any truck placeholders in a window
            $truckKeys = ['TRUCK_NO', 'LR_NO', 'DATE', 'QTY_MT', 'BAGS'];
            $blockHeight = 1;
            $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            $maxRowFound = null;
            for ($r = $templateRow; $r <= $templateRow + 30; $r++) {
                for ($c = 1; $c <= $highestColIndex; $c++) {
                    $cellRef = Coordinate::stringFromColumnIndex($c) . $r;
                    $v = $sheet->getCell($cellRef)->getValue();
                    if (!is_string($v) || strpos($v, '{{') === false) {
                        continue;
                    }
                    foreach ($truckKeys as $k) {
                        if (strpos($v, '{{' . $k . '}}') !== false) {
                            $maxRowFound = $maxRowFound === null ? $r : max($maxRowFound, $r);
                            break;
                        }
                    }
                }
            }
            if ($maxRowFound !== null && $maxRowFound >= $templateRow) {
                $blockHeight = max(1, ($maxRowFound - $templateRow) + 1);
            }

            // Insert blocks for extra trucks
            if ($numTrucks > 1) {
                for ($i = 1; $i < $numTrucks; $i++) {
                    $sheet->insertNewRowBefore($templateRow + ($blockHeight * $i), $blockHeight);
                }
                for ($i = 1; $i < $numTrucks; $i++) {
                    for ($br = 0; $br < $blockHeight; $br++) {
                        $this->excelService->copyRowStyle(
                            $sheet,
                            $templateRow + $br,
                            $templateRow + ($blockHeight * $i) + $br
                        );
                    }
                }
            }

            // Replace placeholders row-by-row; inside the block area, swap dispatch context per-truck
            $highestRow = $sheet->getHighestDataRow();
            for ($row = 1; $row <= $highestRow; $row++) {
                $truckIndex = 0;
                if ($row >= $templateRow && $row < ($templateRow + ($numTrucks * $blockHeight))) {
                    $truckIndex = intdiv(($row - $templateRow), $blockHeight);
                }
                $rowData = [
                    'order' => $order,
                    'dispatch' => array_merge($dispatch, $trucks[$truckIndex] ?? ($trucks[0] ?? [])),
                ];
                $this->excelService->replacePlaceholders($sheet, $rowData, $this->placeholderMap, $row);
            }
            return;
        }

        // No repeating section: replace all placeholders using dispatch header data
        $dataForSheet = ['order' => $order, 'dispatch' => array_merge($dispatch, $trucks[0] ?? [])];
        $this->excelService->replacePlaceholders($sheet, $dataForSheet, $this->placeholderMap);
    }

    private function buildOrderData(array $exportOrder): array
    {
        $defaults = is_file(__DIR__ . '/../../config/export_exporter_defaults.php')
            ? require __DIR__ . '/../../config/export_exporter_defaults.php'
            : [];

        $order = array_merge($defaults, [
            'reference_no' => $exportOrder['reference_no'] ?? '',
            'buyer_po_no' => $exportOrder['buyer_po_no'] ?? '',
            'buyer_po_date' => $this->formatDate($exportOrder['buyer_po_date'] ?? null),
            'consignee' => $exportOrder['consignee'] ?? '',
            'consignee_address' => $exportOrder['consignee_address'] ?? '',
            'notify_applicant' => $exportOrder['notify_applicant'] ?? '',
            'notify_address' => $exportOrder['notify_address'] ?? '',
            'pan_no' => $exportOrder['pan_no'] ?? '',
            'exim_code' => $exportOrder['exim_code'] ?? '',
            'lc_number' => $exportOrder['lc_number'] ?? '',
            'lc_issue_date' => $this->formatDate($exportOrder['lc_issue_date'] ?? null),
            'harmonic_code' => $exportOrder['harmonic_code'] ?? '',
            'country_origin' => $exportOrder['country_origin'] ?? 'INDIAN ORIGIN',
            'country_destination' => $exportOrder['country_destination'] ?? 'NEPAL',
            'customs_entry' => $exportOrder['customs_entry'] ?? '',
            'payment_terms' => $exportOrder['payment_terms'] ?? '',
            'delivery_terms' => $exportOrder['delivery_terms'] ?? '',
            'product_description' => $exportOrder['product_description'] ?? '',
            'product_item' => $exportOrder['product_item'] ?? '',
            'packaging' => $exportOrder['packaging'] ?? '',
            'total_bags' => $exportOrder['total_bags'] ?? '',
            'final_destination' => $exportOrder['final_destination'] ?? '',
            'our_pi_no' => $exportOrder['our_pi_no'] ?? '',
        ]);

        return $order;
    }

    private function buildDispatchData(array $dispatch): array
    {
        $trucks = $dispatch['trucks'] ?? [];
        if (empty($trucks) && !empty($dispatch['truck_no'])) {
            $trucks = [[
                'truck_no' => $dispatch['truck_no'],
                'lr_no' => $dispatch['lr_no'] ?? '',
                'date' => $this->formatDate($dispatch['lr_date'] ?? $dispatch['invoice_date'] ?? null),
                'qty_mt' => $dispatch['weight_mt'] ?? $dispatch['total_weight_mt'] ?? '',
                'bags' => $dispatch['bags'] ?? '',
            ]];
        }

        $totalWeight = $dispatch['total_weight_mt'] ?? null;
        if ($totalWeight === null && !empty($trucks)) {
            $sum = 0;
            foreach ($trucks as $t) {
                $sum += (float)($t['qty_mt'] ?? 0);
            }
            $totalWeight = $sum;
        }

        $amount = isset($dispatch['amount']) ? (float)$dispatch['amount'] : null;
        if ($amount === null && isset($dispatch['total_weight_mt'], $dispatch['rate_per_mt'])) {
            $amount = (float)$dispatch['total_weight_mt'] * (float)$dispatch['rate_per_mt'];
        }

        $truckNumbers = [];
        $lrParts = [];
        foreach ($trucks as $t) {
            $truckNumbers[] = $t['truck_no'] ?? '';
            $lrParts[] = ($t['lr_no'] ?? '') . ' Dt: ' . $this->formatDate($t['date'] ?? null);
        }

        $sb = trim(($dispatch['shipping_bill_no'] ?? '') . ' Dt: ' . $this->formatDate($dispatch['shipping_bill_date'] ?? null));

        return array_merge($dispatch, [
            'trucks' => $trucks,
            'invoice_no' => $dispatch['invoice_no'] ?? '',
            'invoice_date' => $this->formatDate($dispatch['invoice_date'] ?? date('Y-m-d')),
            'truck_numbers' => implode(', ', array_filter($truckNumbers)),
            'lr_numbers_and_dates' => implode(', ', array_filter($lrParts)),
            'total_weight_mt' => $totalWeight,
            'rate_per_mt' => $dispatch['rate_per_mt'] ?? '',
            'amount' => $amount,
            'amount_in_words' => $amount !== null ? AmountInWords::toRupees($amount) : '',
            'assessable_value' => $dispatch['assessable_value'] ?? $amount,
            'shipping_bill' => $sb,
        ]);
    }

    private function formatDate($date): string
    {
        if (empty($date)) {
            return '';
        }
        if (is_numeric($date)) {
            return date('d-m-Y', (int)$date);
        }
        $ts = strtotime($date);
        return $ts ? date('d-m-Y', $ts) : (string)$date;
    }
}
