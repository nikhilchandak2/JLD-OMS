<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\TemplateProcessor;

class WordDocumentService
{
    /**
     * Load Word template
     */
    public function loadTemplate(string $templatePath): TemplateProcessor
    {
        $fullPath = __DIR__ . '/../../' . $templatePath;
        
        if (!file_exists($fullPath)) {
            throw new \Exception("Template file not found: {$templatePath}");
        }
        
        return new TemplateProcessor($fullPath);
    }
    
    /**
     * Replace placeholders in Word document
     */
    public function replacePlaceholders(TemplateProcessor $template, array $placeholders, array $data): void
    {
        foreach ($placeholders as $placeholder => $dataPath) {
            $value = $this->getDataValue($data, $dataPath);
            
            if ($value !== null) {
                // Remove curly braces from placeholder if present
                $cleanPlaceholder = trim($placeholder, '{}');
                $template->setValue($cleanPlaceholder, $value);
            }
        }
    }
    
    /**
     * Duplicate table rows for multiple dispatches
     */
    public function duplicateTableRows(
        TemplateProcessor $template,
        array $tableConfig,
        array $dispatches,
        array $orderData
    ): void {
        if (empty($dispatches) || !isset($tableConfig['table_index'])) {
            return;
        }
        
        $tableIndex = $tableConfig['table_index'];
        $templateRow = $tableConfig['template_row'] ?? 1;
        $mappings = $tableConfig['mappings'] ?? [];
        
        // Clone template row for each dispatch
        foreach ($dispatches as $index => $dispatch) {
            if ($index === 0) {
                // First dispatch - use template row
                $rowIndex = $templateRow;
            } else {
                // Clone row for additional dispatches
                $template->cloneRow("table{$tableIndex}_row{$templateRow}", 1);
                $rowIndex = $templateRow + $index;
            }
            
            // Map dispatch data to row
            $combinedData = array_merge($orderData, ['dispatch' => $dispatch]);
            
            foreach ($mappings as $columnIndex => $dataPath) {
                $value = $this->getDataValue($combinedData, $dataPath);
                
                if ($value !== null) {
                    // Replace placeholder in cloned row
                    $placeholder = "table{$tableIndex}_row{$rowIndex}_col{$columnIndex}";
                    $template->setValue($placeholder, $value);
                }
            }
        }
    }
    
    /**
     * Save template to file
     */
    public function save(TemplateProcessor $template, string $outputPath): string
    {
        $template->saveAs($outputPath);
        
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
