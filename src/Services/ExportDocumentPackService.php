<?php

namespace App\Services;

/**
 * Export Documents (Nepal) – generates one Excel with Commercial Invoice, Tax Invoice, Packing List.
 * Uses only export order + dispatch data; not linked to OMS orders/dispatches.
 */
class ExportDocumentPackService
{
    /**
     * Generate one workbook with all sheets for this dispatch.
     *
     * @param array $exportOrder Row from export_orders (fixed data)
     * @param array $dispatch    Dispatch data: trucks[], lr_no[], dates[], weight_mt, bags, rate_per_mt, amount, invoice_no, invoice_date, etc.
     * @return string Full path to saved file
     */
    public function generatePack(array $exportOrder, array $dispatch): string
    {
        $outputDir = __DIR__ . '/../../storage/export_documents';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // TODO: Load Excel templates, fill from $exportOrder + $dispatch, write Commercial Invoice, Tax Invoice, Packing List sheets, save.
        // For now throw so the API returns a clear message until templates and mapping are added.
        throw new \RuntimeException(
            'Export document pack generation is not yet implemented. Add templates and mapping in config/export_document_mappings/ and implement fill logic here.'
        );
    }
}
