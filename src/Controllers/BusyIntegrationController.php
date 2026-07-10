<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\BusyIntegrationService;
use App\Services\BusyInvoiceImportService;
use App\Support\CompanyContext;

class BusyIntegrationController
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private AuthService $authService;
    private BusyIntegrationService $busyIntegrationService;
    private BusyInvoiceImportService $busyInvoiceImportService;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->busyIntegrationService = new BusyIntegrationService();
        $this->busyInvoiceImportService = new BusyInvoiceImportService();
    }

    /** Public webhook — Busy pushes invoice JSON after bill is raised. */
    public function receiveInvoiceWebhook(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $rawInput = file_get_contents('php://input');
        $invoiceData = json_decode($rawInput, true);

        if (!$invoiceData) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        if (!$this->validateWebhookAuth()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized webhook request']);
            return;
        }

        try {
            $result = $this->busyIntegrationService->processInvoice($invoiceData);
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Invoice processed successfully',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Failed to process invoice',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Upload Busy sales-invoice CSV — dispatch team workflow.
     * POST multipart/form-data: file (.csv), optional company_id
     */
    public function uploadInvoicesFromCsv(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        if (!$this->requireDispatchImportAccess()) {
            return;
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded or upload error.']);
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'pdf'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Upload a Busy tax invoice PDF or CSV export.']);
            return;
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            http_response_code(400);
            echo json_encode(['error' => 'File must be between 1 byte and 5MB']);
            return;
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid uploaded file']);
            return;
        }

        $content = file_get_contents($tmpName);
        if ($content === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Could not read file.']);
            return;
        }

        $parsed = $this->busyInvoiceImportService->parseUpload($content, $ext);
        if (!empty($parsed['errors']) && empty($parsed['invoices'])) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Could not parse CSV',
                'details' => $parsed['errors'],
                'preview' => $parsed['preview'],
            ]);
            return;
        }

        $user = $this->authService->getCurrentUser();
        $companyId = isset($_POST['company_id']) ? (int)$_POST['company_id'] : CompanyContext::getActiveCompanyId();

        $result = $this->busyIntegrationService->processInvoicesBatch(
            $parsed['invoices'],
            $user ? (int)$user['id'] : null,
            $companyId > 0 ? $companyId : null
        );

        echo json_encode([
            'success' => $result['failed'] === 0,
            'message' => sprintf(
                '%d invoice(s) processed — %d succeeded, %d failed',
                $result['processed'],
                $result['successful'],
                $result['failed']
            ),
            'data' => $result,
            'parse_warnings' => $parsed['errors'],
            'preview' => $parsed['preview'],
        ]);
    }

    /** POST JSON body — single invoice import (same shape as webhook). */
    public function importInvoice(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        if (!$this->requireDispatchImportAccess()) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        $companyId = isset($input['company_id']) ? (int)$input['company_id'] : CompanyContext::getActiveCompanyId();

        try {
            $result = $this->busyIntegrationService->processInvoice(
                $input,
                $user ? (int)$user['id'] : null,
                $companyId > 0 ? $companyId : null
            );
            echo json_encode([
                'success' => true,
                'message' => 'Invoice imported — dispatch ' . $result['action'],
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function syncInvoices(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'entry', 'dispatch', 'order_processing'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        try {
            $filters = [
                'start_date' => $input['start_date'] ?? null,
                'end_date' => $input['end_date'] ?? null,
                'party_name' => $input['party_name'] ?? null,
            ];

            $result = $this->busyIntegrationService->syncInvoicesManually($filters);

            echo json_encode([
                'success' => true,
                'message' => 'Invoices synced successfully',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Failed to sync invoices',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function getIntegrationStatus(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        try {
            $status = $this->busyIntegrationService->getIntegrationStatus();
            $status['recent_logs'] = $this->busyIntegrationService->getRecentLogs(15);

            echo json_encode([
                'success' => true,
                'data' => $status,
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function requireDispatchImportAccess(): bool
    {
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return false;
        }

        if (!$this->authService->hasAnyRole(['admin', 'dispatch', 'order_processing', 'entry'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Dispatch import access required']);
            return false;
        }

        return true;
    }

    private function validateWebhookAuth(): bool
    {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if ($apiKey) {
            $apiKey = str_replace('Bearer ', '', $apiKey);
            $validApiKey = $_ENV['BUSY_WEBHOOK_API_KEY'] ?? '';
            if ($validApiKey !== '' && hash_equals($validApiKey, $apiKey)) {
                return true;
            }
        }

        $signature = $_SERVER['HTTP_X_BUSY_SIGNATURE'] ?? null;
        if ($signature) {
            $payload = file_get_contents('php://input');
            $secret = $_ENV['BUSY_WEBHOOK_SECRET'] ?? '';
            if ($secret !== '') {
                $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
                return hash_equals($expectedSignature, $signature);
            }
        }

        $allowedIPs = array_filter(array_map('trim', explode(',', $_ENV['BUSY_WEBHOOK_ALLOWED_IPS'] ?? '127.0.0.1,::1')));
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '';

        return in_array($clientIP, $allowedIPs, true);
    }
}
