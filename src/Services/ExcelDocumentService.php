<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ExcelDocumentService
{
    /**
     * Load Excel template
     */
    public function loadTemplate(string $templatePath): Spreadsheet
    {
        $fullPath = __DIR__ . '/../../' . $templatePath;
        
        if (!file_exists($fullPath)) {
            throw new \Exception("Template file not found: {$templatePath}");
        }
        
        return IOFactory::load($fullPath);
    }
    
    /**
     * Replace {{PLACEHOLDER}} in cell values with data. Use this so your Excel template
     * keeps its exact layout and formatting; put {{INVOICE_NO}}, {{CONSIGNEE}}, etc. in the cells.
     *
     * @param array $placeholderMap [ 'INVOICE_NO' => 'dispatch.invoice_no', ... ]
     * @param int|null $onlyRow If set, only replace in this 1-based row (for truck rows)
     */
    public function replacePlaceholders(Worksheet $sheet, array $data, array $placeholderMap, ?int $onlyRow = null): void
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();
        $highestColIndex = Coordinate::columnIndexFromString($highestCol);

        $rowStart = $onlyRow !== null ? $onlyRow : 1;
        $rowEnd = $onlyRow !== null ? $onlyRow : $highestRow;

        for ($row = $rowStart; $row <= $rowEnd; $row++) {
            for ($col = 1; $col <= $highestColIndex; $col++) {
                $cellRef = Coordinate::stringFromColumnIndex($col) . $row;
                $cell = $sheet->getCell($cellRef);
                $value = $cell->getValue();

                if (!is_string($value) || strpos($value, '{{') === false) {
                    continue;
                }

                $replaced = $value;
                foreach ($placeholderMap as $key => $path) {
                    $placeholder = '{{' . $key . '}}';
                    if (strpos($replaced, $placeholder) !== false) {
                        $resolved = $this->getDataValue($data, $path);
                        $replaced = str_replace($placeholder, (string)($resolved ?? ''), $replaced);
                    }
                }
                $sheet->setCellValue($cellRef, $replaced);
            }
        }
    }

    /**
     * Copy styles and values from one row to another (e.g. template row to newly inserted row so placeholders can be replaced).
     */
    public function copyRowStyle(Worksheet $sheet, int $fromRow, int $toRow): void
    {
        $highestCol = $sheet->getHighestDataColumn();
        $highestColIndex = Coordinate::columnIndexFromString($highestCol);
        for ($col = 1; $col <= $highestColIndex; $col++) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $fromCell = $colLetter . $fromRow;
            $toCell = $colLetter . $toRow;
            $sheet->duplicateStyle($sheet->getStyle($fromCell), $toCell);
            $sheet->setCellValue($toCell, $sheet->getCell($fromCell)->getValue());
        }
    }

    /**
     * Map single values to cells
     */
    public function mapSingleValues(Worksheet $sheet, array $mappings, array $data): void
    {
        foreach ($mappings as $cellRef => $dataPath) {
            $value = $this->getDataValue($data, $dataPath);
            
            if ($value !== null) {
                $sheet->setCellValue($cellRef, $value);
            }
        }
    }
    
    /**
     * Map repeating rows (for multiple dispatches/trucks)
     */
    public function mapRepeatingRows(
        Worksheet $sheet,
        array $config,
        array $dispatches,
        array $orderData
    ): void {
        if (empty($dispatches) || !isset($config['start_row']) || !isset($config['template_row'])) {
            return;
        }
        
        $startRow = $config['start_row'];
        $templateRow = $config['template_row'];
        $mappings = $config['mappings'] ?? [];
        
        // Get template row formatting
        $templateRowData = [];
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        
        // Copy template row formatting
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $templateCell = $colLetter . $templateRow;
            
            $templateRowData[$col] = [
                'value' => $sheet->getCell($templateCell)->getValue(),
                'style' => $sheet->getStyle($templateCell),
                'merged' => $sheet->getMergeCells(),
            ];
        }
        
        // Insert rows for each dispatch
        $rowIndex = $startRow;
        foreach ($dispatches as $index => $dispatch) {
            if ($index > 0) {
                // Insert new row after template row
                $sheet->insertNewRowBefore($rowIndex + 1, 1);
                $rowIndex++;
                
                // Copy formatting from template row
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $colLetter = Coordinate::stringFromColumnIndex($col);
                    $newCell = $colLetter . $rowIndex;
                    $templateCell = $colLetter . $templateRow;
                    
                    // Copy style
                    $sheet->duplicateStyle(
                        $sheet->getStyle($templateCell),
                        $newCell
                    );
                }
            }
            
            // Map dispatch data
            $combinedData = array_merge($orderData, ['dispatch' => $dispatch]);
            
            foreach ($mappings as $cellRef => $dataPath) {
                // Replace row number in cell reference
                $cellRef = preg_replace('/\d+$/', $rowIndex, $cellRef);
                $value = $this->getDataValue($combinedData, $dataPath);
                
                if ($value !== null) {
                    $sheet->setCellValue($cellRef, $value);
                }
            }
        }
    }
    
    /**
     * Save spreadsheet to file
     */
    public function save(Spreadsheet $spreadsheet, string $outputPath): string
    {
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);
        
        return $outputPath;
    }
    
    /**
     * Get value from data array using dot notation path
     */
    private function getDataValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;
        
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }
        
        return $value;
    }
    
    /**
     * Generate filename from pattern
     */
    public function generateFilename(string $pattern, array $data): string
    {
        $filename = $pattern;
        
        // Replace placeholders
        $filename = str_replace('{ORDER_NO}', $data['order']['order_no'] ?? '', $filename);
        $filename = str_replace('{DATE}', date('Ymd', strtotime($data['order']['order_date'] ?? 'now')), $filename);
        $filename = str_replace('{VEHICLE_NO}', $data['dispatch']['vehicle_no'] ?? '', $filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        return $filename;
    }
}
