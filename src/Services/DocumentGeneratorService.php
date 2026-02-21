<?php

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Repositories\DispatchRepository;
use App\Repositories\PartyRepository;
use App\Repositories\CompanyRepository;

class DocumentGeneratorService
{
    private ExcelDocumentService $excelService;
    private WordDocumentService $wordService;
    private OrderRepository $orderRepository;
    private DispatchRepository $dispatchRepository;
    private PartyRepository $partyRepository;
    private CompanyRepository $companyRepository;
    
    public function __construct()
    {
        $this->excelService = new ExcelDocumentService();
        $this->wordService = new WordDocumentService();
        $this->orderRepository = new OrderRepository();
        $this->dispatchRepository = new DispatchRepository();
        $this->partyRepository = new PartyRepository();
        $this->companyRepository = new CompanyRepository();
    }
    
    /**
     * Generate document for an order
     * 
     * @param int $orderId Order ID
     * @param string $documentType Document type (commercial_tax_invoice, scomet_format, etc.)
     * @param array $dispatchIds Optional: specific dispatch IDs, or null for all dispatches
     * @return array Generated file paths
     */
    public function generateDocument(int $orderId, string $documentType, ?array $dispatchIds = null): array
    {
        // Load mapping configuration
        $config = $this->loadMappingConfig($documentType);
        
        // Load order and related data
        $order = $this->orderRepository->findById($orderId);
        if (!$order) {
            throw new \Exception("Order not found: {$orderId}");
        }
        
        $party = $this->partyRepository->findById($order->partyId);
        if (!$party) {
            throw new \Exception("Party not found for order");
        }
        
        $company = $this->companyRepository->findById($order->companyId);
        if (!$company) {
            throw new \Exception("Company not found for order");
        }
        
        // Load dispatches
        if ($dispatchIds) {
            $dispatches = [];
            foreach ($dispatchIds as $dispatchId) {
                $dispatch = $this->dispatchRepository->findById($dispatchId);
                if ($dispatch && $dispatch->orderId === $orderId) {
                    $dispatches[] = $dispatch;
                }
            }
        } else {
            $dispatches = $this->dispatchRepository->findByOrderId($orderId);
        }
        
        if (empty($dispatches)) {
            throw new \Exception("No dispatches found for order");
        }
        
        // Prepare data structure
        $data = [
            'order' => $order->toArray(),
            'party' => $party->toArray(),
            'company' => $company->toArray(),
        ];
        
        // Generate files based on output mode
        $outputMode = $config['output_mode'] ?? 'consolidated';
        $generatedFiles = [];
        
        if ($outputMode === 'per_truck') {
            // Generate one file per dispatch
            foreach ($dispatches as $dispatch) {
                $filePath = $this->generateSingleDocument(
                    $config,
                    $data,
                    [$dispatch]
                );
                $generatedFiles[] = $filePath;
            }
        } else {
            // Generate consolidated file with all dispatches
            $filePath = $this->generateSingleDocument(
                $config,
                $data,
                $dispatches
            );
            $generatedFiles[] = $filePath;
        }
        
        return $generatedFiles;
    }
    
    /**
     * Generate a single document
     */
    private function generateSingleDocument(array $config, array $data, array $dispatches): string
    {
        $type = $config['type'] ?? 'excel';
        
        if ($type === 'excel') {
            return $this->generateExcelDocument($config, $data, $dispatches);
        } else {
            return $this->generateWordDocument($config, $data, $dispatches);
        }
    }
    
    /**
     * Generate Excel document
     */
    private function generateExcelDocument(array $config, array $data, array $dispatches): string
    {
        // Load template
        $spreadsheet = $this->excelService->loadTemplate($config['template_file']);
        $sheet = $spreadsheet->getActiveSheet();
        
        // Map single values
        if (isset($config['single_value_mappings'])) {
            $this->excelService->mapSingleValues(
                $sheet,
                $config['single_value_mappings'],
                $data
            );
        }
        
        // Map repeating rows
        if (isset($config['repeating_rows'])) {
            $this->excelService->mapRepeatingRows(
                $sheet,
                $config['repeating_rows'],
                $dispatches,
                $data
            );
        }
        
        // Generate output filename
        $dispatchData = !empty($dispatches) ? ['dispatch' => $dispatches[0]->toArray()] : [];
        $filename = $this->excelService->generateFilename(
            $config['output_filename_pattern'],
            array_merge($data, $dispatchData)
        );
        
        // Create output directory
        $outputDir = __DIR__ . '/../../storage/documents/';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        $outputPath = $outputDir . $filename;
        
        // Save file
        return $this->excelService->save($spreadsheet, $outputPath);
    }
    
    /**
     * Generate Word document
     */
    private function generateWordDocument(array $config, array $data, array $dispatches): string
    {
        // Load template
        $template = $this->wordService->loadTemplate($config['template_file']);
        
        // Replace placeholders
        if (isset($config['placeholders'])) {
            $this->wordService->replacePlaceholders(
                $template,
                $config['placeholders'],
                $data
            );
        }
        
        // Duplicate table rows
        if (isset($config['tables'])) {
            foreach ($config['tables'] as $tableConfig) {
                $this->wordService->duplicateTableRows(
                    $template,
                    $tableConfig,
                    $dispatches,
                    $data
                );
            }
        }
        
        // Generate output filename
        $dispatchData = !empty($dispatches) ? ['dispatch' => $dispatches[0]->toArray()] : [];
        $filename = $this->wordService->generateFilename(
            $config['output_filename_pattern'],
            array_merge($data, $dispatchData)
        );
        
        // Create output directory
        $outputDir = __DIR__ . '/../../storage/documents/';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        $outputPath = $outputDir . $filename;
        
        // Save file
        return $this->wordService->save($template, $outputPath);
    }
    
    /**
     * Load mapping configuration
     */
    private function loadMappingConfig(string $documentType): array
    {
        $configFile = __DIR__ . '/../../config/document_mappings/' . $documentType . '.php';
        
        if (!file_exists($configFile)) {
            throw new \Exception("Mapping configuration not found: {$documentType}");
        }
        
        return require $configFile;
    }
    
    /**
     * Get available document types
     */
    public function getAvailableDocumentTypes(): array
    {
        $configDir = __DIR__ . '/../../config/document_mappings/';
        $files = glob($configDir . '*.php');
        
        $types = [];
        foreach ($files as $file) {
            $type = basename($file, '.php');
            $config = require $file;
            $types[] = [
                'type' => $type,
                'name' => $this->getDocumentTypeName($type),
                'template_file' => $config['template_file'] ?? '',
            ];
        }
        
        return $types;
    }
    
    /**
     * Get human-readable document type name
     */
    private function getDocumentTypeName(string $type): string
    {
        $names = [
            'commercial_tax_invoice' => 'Commercial Tax Invoice',
            'scomet_format' => 'SCOMET Format',
            'beneficiary_coo' => 'Beneficiary COO',
            'bilty' => 'Bilty (Bill of Lading)',
            'safta_invoice' => 'SAFTA Invoice',
        ];
        
        return $names[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }
}
