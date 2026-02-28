<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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

    public function __construct()
    {
        $this->excelService = new ExcelDocumentService();
        $this->configPath = __DIR__ . '/../../config/export_document_mappings';
        $this->templateBasePath = __DIR__ . '/../../';
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

        foreach ($documentTypes as $docType) {
            $configFile = $this->configPath . '/' . $docType . '.php';
            if (!is_file($configFile)) {
                continue;
            }
            $config = require $configFile;
            $templatePath = $config['template_file'] ?? '';
            $fullPath = $this->templateBasePath . $templatePath;

            if (!is_file($fullPath)) {
                continue; // skip if this template is not present
            }

            $workbook = IOFactory::load($fullPath);
            $sheet = $workbook->getSheet(0);

            $data = ['order' => $order, 'dispatch' => $dispatch];

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

            $sheetNames = [
                'commercial_invoice' => 'Commercial Invoice',
                'tax_invoice' => 'Tax Invoice',
                'packing_list' => 'Packing List',
            ];
            $sheet->setTitle($sheetNames[$docType] ?? $docType);

            if ($mainWorkbook === null) {
                $mainWorkbook = $workbook;
            } else {
                $clonedSheet = clone $sheet;
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
        $writer->save($outputPath);

        return $outputPath;
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
