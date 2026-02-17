<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\BusyIntegrationService;

class BusyIntegrationController
{
    private AuthService $authService;
    private BusyIntegrationService $busyIntegrationService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->busyIntegrationService = new BusyIntegrationService();
    }
    
    /**
     * Webhook endpoint for Busy software to send invoice data
     * This will automatically create dispatches based on invoice information
     */
    public function receiveInvoiceWebhook(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Get the raw POST data
        $rawInput = file_get_contents('php://input');
        $invoiceData = json_decode($rawInput, true);
        
        if (!$invoiceData) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }
        
        // Validate webhook authentication (API key or signature)
        if (!$this->validateWebhookAuth()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized webhook request']);
            return;
        }
        
        try {
            // Process the invoice and create dispatch
            $result = $this->busyIntegrationService->processInvoiceWebhook($invoiceData);
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Invoice processed successfully',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Failed to process invoice',
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Manual sync endpoint for processing invoices from Busy software
     * Requires authentication
     */
    public function syncInvoices(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Check authentication
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'entry'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }
        
        // Get input data
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        try {
            $filters = [
                'start_date' => $input['start_date'] ?? null,
                'end_date' => $input['end_date'] ?? null,
                'party_name' => $input['party_name'] ?? null
            ];
            
            $result = $this->busyIntegrationService->syncInvoicesManually($filters);
            
            echo json_encode([
                'success' => true,
                'message' => 'Invoices synced successfully',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Failed to sync invoices',
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get integration status and statistics
     */
    public function getIntegrationStatus(): void
    {
        header('Content-Type: application/json');
        
        // Check authentication
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        
        try {
            $status = $this->busyIntegrationService->getIntegrationStatus();
            
            echo json_encode([
                'success' => true,
                'data' => $status
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Validate webhook authentication
     * This can be customized based on Busy software's authentication method
     */
    private function validateWebhookAuth(): bool
    {
        // Option 1: API Key in header
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        
        if ($apiKey) {
            // Remove 'Bearer ' prefix if present
            $apiKey = str_replace('Bearer ', '', $apiKey);
            
            // Check against configured API key (you should store this in config/environment)
            $validApiKey = $_ENV['BUSY_WEBHOOK_API_KEY'] ?? 'your-secret-api-key-here';
            return hash_equals($validApiKey, $apiKey);
        }
        
        // Option 2: Signature validation (if Busy supports HMAC signatures)
        $signature = $_SERVER['HTTP_X_BUSY_SIGNATURE'] ?? null;
        if ($signature) {
            $payload = file_get_contents('php://input');
            $secret = $_ENV['BUSY_WEBHOOK_SECRET'] ?? 'your-webhook-secret-here';
            $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
            return hash_equals($expectedSignature, $signature);
        }
        
        // Option 3: IP whitelist (if Busy has fixed IP addresses)
        $allowedIPs = ['127.0.0.1', '::1']; // Add Busy software's IP addresses
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '';
        
        return in_array($clientIP, $allowedIPs);
    }
}


