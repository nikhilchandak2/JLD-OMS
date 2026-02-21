<?php
/**
 * Test Document Generation Script
 * 
 * Usage: php scripts/test_document_generation.php <order_id> <document_type>
 * Example: php scripts/test_document_generation.php 1 commercial_tax_invoice
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\DocumentGeneratorService;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

if ($argc < 3) {
    echo "Usage: php test_document_generation.php <order_id> <document_type>\n";
    echo "Available document types:\n";
    
    $service = new DocumentGeneratorService();
    $types = $service->getAvailableDocumentTypes();
    foreach ($types as $type) {
        echo "  - {$type['type']}: {$type['name']}\n";
    }
    exit(1);
}

$orderId = (int)$argv[1];
$documentType = $argv[2];

try {
    echo "Generating document...\n";
    echo "Order ID: {$orderId}\n";
    echo "Document Type: {$documentType}\n\n";
    
    $service = new DocumentGeneratorService();
    $generatedFiles = $service->generateDocument($orderId, $documentType);
    
    echo "✓ Document(s) generated successfully!\n\n";
    echo "Generated files:\n";
    foreach ($generatedFiles as $file) {
        echo "  - {$file}\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
