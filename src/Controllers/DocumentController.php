<?php

namespace App\Controllers;

use App\Services\DocumentGeneratorService;
use App\Services\AuthService;
use App\Core\Database;

class DocumentController
{
    private DocumentGeneratorService $documentService;
    private AuthService $authService;
    private Database $database;
    
    public function __construct()
    {
        $this->documentService = new DocumentGeneratorService();
        $this->authService = new AuthService();
        $this->database = new Database();
    }
    
    /**
     * Generate document for an order
     * POST /api/documents/generate
     */
    public function generate(): void
    {
        header('Content-Type: application/json');
        
        // Check authentication
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        
        // Get request data
        $input = json_decode(file_get_contents('php://input'), true);
        
        $orderId = $input['order_id'] ?? null;
        $documentType = $input['document_type'] ?? null;
        $dispatchIds = $input['dispatch_ids'] ?? null;
        
        if (!$orderId || !$documentType) {
            http_response_code(400);
            echo json_encode(['error' => 'order_id and document_type are required']);
            return;
        }
        
        try {
            // Generate document(s)
            $generatedFiles = $this->documentService->generateDocument(
                (int)$orderId,
                $documentType,
                $dispatchIds
            );
            
            // Log generation
            foreach ($generatedFiles as $filePath) {
                $this->logGeneration(
                    (int)$orderId,
                    $dispatchIds,
                    $documentType,
                    $filePath,
                    $user['id']
                );
            }
            
            // Return file URLs for download
            $fileUrls = array_map(function($filePath) {
                $relativePath = str_replace(__DIR__ . '/../../', '', $filePath);
                return '/storage/documents/' . basename($filePath);
            }, $generatedFiles);
            
            echo json_encode([
                'success' => true,
                'message' => 'Document(s) generated successfully',
                'files' => $fileUrls,
                'file_paths' => $generatedFiles
            ]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Failed to generate document',
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get available document types
     * GET /api/documents/types
     */
    public function getTypes(): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        
        try {
            $types = $this->documentService->getAvailableDocumentTypes();
            
            echo json_encode([
                'success' => true,
                'data' => $types
            ]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Failed to load document types',
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Download generated document
     * GET /api/documents/download?file=filename.xlsx
     */
    public function download(): void
    {
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        
        $filename = $_GET['file'] ?? null;
        if (!$filename) {
            http_response_code(400);
            echo json_encode(['error' => 'File parameter is required']);
            return;
        }
        
        // Sanitize filename
        $filename = basename($filename);
        $filePath = __DIR__ . '/../../storage/documents/' . $filename;
        
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            return;
        }
        
        // Determine content type
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $contentTypes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
        ];
        
        $contentType = $contentTypes[$extension] ?? 'application/octet-stream';
        
        // Send file
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        
        readfile($filePath);
        exit;
    }
    
    /**
     * Log document generation
     */
    private function logGeneration(
        int $orderId,
        ?array $dispatchIds,
        string $documentType,
        string $filePath,
        int $generatedBy
    ): void {
        $sql = "INSERT INTO document_generation_logs 
                (order_id, dispatch_ids, document_type, generated_file_path, generated_by, generated_at)
                VALUES (?, ?, ?, ?, ?, NOW())";
        
        $dispatchIdsJson = $dispatchIds ? json_encode($dispatchIds) : null;
        
        $this->database->getConnection()->prepare($sql)->execute([
            $orderId,
            $dispatchIdsJson,
            $documentType,
            $filePath,
            $generatedBy
        ]);
    }
}
