<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\BusyIntegrationService;
use App\Services\BusyInvoiceImportService;
use App\Repositories\BusyInvoiceUploadRepository;
use App\Support\CompanyContext;
use App\Support\IndianDate;

class BusyIntegrationController
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private AuthService $authService;
    private BusyIntegrationService $busyIntegrationService;
    private BusyInvoiceImportService $busyInvoiceImportService;
    private BusyInvoiceUploadRepository $busyInvoiceUploadRepository;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->busyIntegrationService = new BusyIntegrationService();
        $this->busyInvoiceImportService = new BusyInvoiceImportService();
        $this->busyInvoiceUploadRepository = new BusyInvoiceUploadRepository();
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
            if (($result['mapping_status'] ?? '') === 'unmapped') {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => $result['error'] ?? 'Invoice saved but not mapped to any order',
                    'data' => $result,
                ]);
                return;
            }
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

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid uploaded file']);
            return;
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            http_response_code(400);
            echo json_encode(['error' => 'File must be between 1 byte and 5MB']);
            return;
        }

        $content = file_get_contents($tmpName);
        if ($content === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Could not read file.']);
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (strncmp($content, '%PDF-', 5) === 0) {
            $ext = 'pdf';
        }
        if (!in_array($ext, ['csv', 'pdf'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Upload a Busy tax invoice PDF or CSV export.']);
            return;
        }

        $parsed = $this->busyInvoiceImportService->parseUpload($content, $ext, $tmpName);
        $user = $this->authService->getCurrentUser();
        $companyId = isset($_POST['company_id']) ? (int)$_POST['company_id'] : CompanyContext::getActiveCompanyId();
        $companyId = $companyId > 0 ? $companyId : null;
        $originalName = (string)($file['name'] ?? ('busy_invoice.' . $ext));

        $invoiceDates = [];
        foreach ($parsed['invoices'] ?? [] as $inv) {
            $d = trim((string)($inv['invoice_date'] ?? ''));
            if ($d !== '') {
                $invoiceDates[] = substr($d, 0, 10);
            }
        }
        sort($invoiceDates);
        $invoiceDateFrom = $invoiceDates[0] ?? null;
        $invoiceDateTo = $invoiceDates !== [] ? $invoiceDates[count($invoiceDates) - 1] : null;

        $storedPath = null;
        try {
            $storedPath = $this->busyInvoiceUploadRepository->storeUploadedFile($tmpName, $originalName, $ext);
        } catch (\Throwable $e) {
            error_log('Busy upload store failed: ' . $e->getMessage());
        }

        if (!empty($parsed['errors']) && empty($parsed['invoices'])) {
            $details = $parsed['errors'];
            $summary = $details[0] ?? 'Unknown parse error';
            $uploadId = $this->busyInvoiceUploadRepository->create([
                'original_filename' => $originalName,
                'file_type' => $ext,
                'stored_path' => $storedPath,
                'file_size' => $size,
                'company_id' => $companyId,
                'invoice_date_from' => $invoiceDateFrom,
                'invoice_date_to' => $invoiceDateTo,
                'invoice_count' => 0,
                'mapped_count' => 0,
                'unmapped_count' => 0,
                'failed_count' => 1,
                'status' => 'failed',
                'parse_notes' => $summary,
                'uploaded_by' => $user ? (int)$user['id'] : null,
            ]);

            $payload = [
                'error' => 'Could not parse invoice file: ' . $summary,
                'details' => $details,
                'preview' => $parsed['preview'],
                'upload_id' => $uploadId,
            ];
            if (filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $payload['file_type'] = $ext;
                $payload['is_pdf'] = strncmp($content, '%PDF-', 5) === 0;
            }
            http_response_code(400);
            echo json_encode($payload);
            return;
        }

        $uploadId = $this->busyInvoiceUploadRepository->create([
            'original_filename' => $originalName,
            'file_type' => $ext,
            'stored_path' => $storedPath,
            'file_size' => $size,
            'company_id' => $companyId,
            'invoice_date_from' => $invoiceDateFrom,
            'invoice_date_to' => $invoiceDateTo,
            'invoice_count' => count($parsed['invoices']),
            'status' => 'processed',
            'parse_notes' => !empty($parsed['errors']) ? implode('; ', $parsed['errors']) : null,
            'uploaded_by' => $user ? (int)$user['id'] : null,
        ]);

        $result = $this->busyIntegrationService->processInvoicesBatch(
            $parsed['invoices'],
            $user ? (int)$user['id'] : null,
            $companyId
        );

        $mapped = (int)($result['successful'] ?? 0);
        $unmapped = (int)($result['unmapped'] ?? 0);
        $failed = (int)($result['failed'] ?? 0);
        $autoOrders = (int)($result['auto_orders_created'] ?? 0);
        $status = 'processed';
        if ($failed > 0 && $mapped === 0 && $unmapped === 0) {
            $status = 'failed';
        } elseif ($failed > 0 || $unmapped > 0) {
            $status = 'partial';
        }

        $this->busyInvoiceUploadRepository->updateStats($uploadId, [
            'invoice_count' => (int)($result['processed'] ?? count($parsed['invoices'])),
            'mapped_count' => $mapped,
            'unmapped_count' => $unmapped,
            'failed_count' => $failed,
            'status' => $status,
            'parse_notes' => !empty($parsed['errors']) ? implode('; ', $parsed['errors']) : null,
            'invoice_date_from' => $invoiceDateFrom,
            'invoice_date_to' => $invoiceDateTo,
        ]);

        $invoiceNos = array_map(
            static fn($inv) => (string)($inv['invoice_no'] ?? ''),
            $parsed['invoices']
        );
        $this->busyInvoiceUploadRepository->linkInvoices($uploadId, $invoiceNos);

        $parts = [
            sprintf('%d succeeded', $mapped),
        ];
        if ($autoOrders > 0) {
            $parts[] = sprintf('%d auto-order(s) created', $autoOrders);
        }
        if ($unmapped > 0) {
            $parts[] = sprintf('%d unmapped (no order)', $unmapped);
        }
        if ($failed > 0) {
            $parts[] = sprintf('%d failed', $failed);
        }

        echo json_encode([
            'success' => $failed === 0,
            'message' => sprintf(
                '%d invoice(s) processed — %s',
                $result['processed'],
                implode(', ', $parts)
            ),
            'data' => $result,
            'upload_id' => $uploadId,
            'parse_warnings' => $parsed['errors'],
            'preview' => $parsed['preview'],
        ]);
    }

    /**
     * GET /api/busy/invoice-uploads — CSV/PDF upload history.
     */
    public function listInvoiceUploads(): void
    {
        header('Content-Type: application/json');

        if (!$this->requireDispatchImportAccess()) {
            return;
        }

        $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

        try {
            // Ensure legacy batches from the daily ledger are filled in
            $this->busyInvoiceUploadRepository->ensureSchema();

            $result = $this->busyInvoiceUploadRepository->findAll([
                'start_date' => trim((string)($_GET['start_date'] ?? '')) ?: null,
                'end_date' => trim((string)($_GET['end_date'] ?? '')) ?: null,
                'company_id' => isset($_GET['company_id']) ? (int)$_GET['company_id'] : null,
                'q' => trim((string)($_GET['q'] ?? '')) ?: null,
                'limit' => $limit,
                'offset' => $offset,
            ]);

            $rows = array_map(function (array $row): array {
                $invFrom = $row['invoice_date_from'] ?? null;
                $invTo = $row['invoice_date_to'] ?? null;
                return [
                    'id' => (int)$row['id'],
                    'original_filename' => $row['original_filename'],
                    'file_type' => $row['file_type'],
                    'file_size' => $row['file_size'] !== null ? (int)$row['file_size'] : null,
                    'has_file' => !empty($row['stored_path']),
                    'company_id' => $row['company_id'] !== null ? (int)$row['company_id'] : null,
                    'company_name' => $row['company_name'] ?? null,
                    'invoice_date_from' => $invFrom,
                    'invoice_date_to' => $invTo,
                    'invoice_date_label' => $invFrom
                        ? IndianDate::format((string)$invFrom)
                            . ($invTo && $invTo !== $invFrom ? ' – ' . IndianDate::format((string)$invTo) : '')
                        : null,
                    'invoice_count' => (int)$row['invoice_count'],
                    'mapped_count' => (int)$row['mapped_count'],
                    'unmapped_count' => (int)$row['unmapped_count'],
                    'failed_count' => (int)$row['failed_count'],
                    'status' => $row['status'],
                    'parse_notes' => $row['parse_notes'],
                    'uploaded_by' => $row['uploaded_by'] !== null ? (int)$row['uploaded_by'] : null,
                    'uploaded_by_name' => $row['uploaded_by_name'] ?? null,
                    'created_at' => !empty($row['created_at'])
                        ? IndianDate::formatDateTime((string)$row['created_at'])
                        : null,
                    'created_at_raw' => $row['created_at'] ?? null,
                ];
            }, $result['rows']);

            echo json_encode([
                'success' => true,
                'data' => $rows,
                'pagination' => [
                    'total' => $result['total'],
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/busy/invoice-uploads/{id}/download — download stored CSV/PDF.
     */
    public function downloadInvoiceUpload(int $id): void
    {
        if (!$this->requireDispatchImportAccess()) {
            return;
        }

        $row = $this->busyInvoiceUploadRepository->findById($id);
        if (!$row) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Upload not found']);
            return;
        }

        $stored = trim((string)($row['stored_path'] ?? ''));
        if ($stored === '') {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Original file was not retained for this upload']);
            return;
        }

        $fullPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $stored);
        // Also try relative to project root via repository storage
        if (!is_file($fullPath)) {
            $fullPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $stored;
        }
        if (!is_file($fullPath)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Stored file missing on disk']);
            return;
        }

        $filename = (string)($row['original_filename'] ?? basename($fullPath));
        $ext = strtolower((string)($row['file_type'] ?? pathinfo($filename, PATHINFO_EXTENSION)));
        $mime = $ext === 'pdf' ? 'application/pdf' : 'text/csv';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . (string)filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    /**
     * GET /api/busy/daily-invoices — daily Busy CSV invoices (mapped + unmapped).
     */
    public function dailyInvoices(): void
    {
        header('Content-Type: application/json');

        if (!$this->requireDispatchImportAccess()) {
            return;
        }

        $date = trim((string)($_GET['date'] ?? ''));
        $startDate = trim((string)($_GET['start_date'] ?? ''));
        $endDate = trim((string)($_GET['end_date'] ?? ''));
        $allDates = !empty($_GET['all_dates']);

        // Default: today. all_dates=1 skips date filter (used for "all unmapped").
        if (!$allDates && $date === '' && $startDate === '' && $endDate === '') {
            $date = date('Y-m-d');
        }

        $mappingStatus = trim((string)($_GET['mapping_status'] ?? ''));
        if ($mappingStatus !== '' && !in_array($mappingStatus, ['mapped', 'unmapped', 'error', 'open'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'mapping_status must be mapped, unmapped, error, or open']);
            return;
        }

        // Ledger is cross-company by default — unmapped invoices must stay visible
        // regardless of the header company switcher. Optional company_id still works.
        $companyId = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
        $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

        try {
            $result = $this->busyIntegrationService->listDailyInvoices([
                'date' => (!$allDates && $date !== '') ? $date : null,
                'start_date' => (!$allDates && $startDate !== '') ? $startDate : null,
                'end_date' => (!$allDates && $endDate !== '') ? $endDate : null,
                'mapping_status' => $mappingStatus !== '' ? $mappingStatus : null,
                'company_id' => $companyId > 0 ? $companyId : null,
                'search' => trim((string)($_GET['search'] ?? '')) ?: null,
                'limit' => $limit,
                'offset' => $offset,
            ]);

            echo json_encode([
                'success' => true,
                'data' => $result['rows'],
                'summary' => $result['summary'],
                'pagination' => $result['pagination'],
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * POST /api/busy/daily-invoices/remap — re-match unmapped invoices to orders created later.
     */
    public function remapDailyInvoices(): void
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
        if (!is_array($input)) {
            $input = [];
        }

        // Optional scope: omit date to remap ALL unmapped/error invoices (in batches)
        $date = trim((string)($input['date'] ?? ''));
        $startDate = trim((string)($input['start_date'] ?? ''));
        $endDate = trim((string)($input['end_date'] ?? ''));
        $onlySelectedDate = !empty($input['only_selected_date']);
        $limit = isset($input['limit']) ? (int)$input['limit'] : 75;

        $user = $this->authService->getCurrentUser();
        $companyId = isset($input['company_id']) ? (int)$input['company_id'] : 0;

        $filters = [
            'company_id' => $companyId > 0 ? $companyId : null,
            'invoice_nos' => $input['invoice_nos'] ?? null,
            'limit' => $limit,
        ];
        if (!empty($input['after_id'])) {
            $filters['after_id'] = (int)$input['after_id'];
        }
        if ($onlySelectedDate && $date !== '') {
            $filters['date'] = $date;
        } elseif ($date !== '' && empty($input['all_dates'])) {
            // Default: prefer the date shown on the daily page when provided
            $filters['date'] = $date;
        } elseif ($startDate !== '' || $endDate !== '') {
            $filters['start_date'] = $startDate !== '' ? $startDate : null;
            $filters['end_date'] = $endDate !== '' ? $endDate : null;
        }

        try {
            @ini_set('max_execution_time', '120');
            @set_time_limit(120);

            $result = $this->busyIntegrationService->remapUnmappedInvoices(
                $filters,
                $user ? (int)$user['id'] : null
            );

            $message = sprintf(
                'Remap batch — %d processed, %d mapped, %d still unmapped',
                $result['processed'],
                $result['mapped'],
                $result['still_unmapped']
            );
            if (!empty($result['auto_orders_created'])) {
                $message .= sprintf(
                    ' (%d auto-order(s) created)',
                    (int)$result['auto_orders_created']
                );
            }
            if (!empty($result['has_more'])) {
                $message .= ' — more unmapped remain; continue Remap.';
            }

            echo json_encode([
                'success' => true,
                'message' => $message,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            error_log('Busy remap failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        }
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
            $isUnmapped = ($result['mapping_status'] ?? '') === 'unmapped';
            echo json_encode([
                'success' => true,
                'message' => $isUnmapped
                    ? 'Invoice saved but not mapped to any order'
                    : 'Invoice imported — dispatch ' . ($result['action'] ?? 'processed'),
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
            $logLimit = isset($_GET['log_limit']) ? (int)$_GET['log_limit'] : 50;
            $status = $this->busyIntegrationService->getIntegrationStatus();
            $status['recent_logs'] = $this->busyIntegrationService->getRecentLogs($logLimit);

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
