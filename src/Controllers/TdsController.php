<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\TdsReportService;
use App\Repositories\TdsReportRepository;

class TdsController
{
    private const MAX_UPLOAD_BYTES = 20971520; // 20MB
    private const ALLOWED_EXT = ['xlsx', 'xls', 'csv', 'ods'];

    private AuthService $authService;
    private TdsReportRepository $repository;
    private TdsReportService $service;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->repository = new TdsReportRepository();
        $this->service = new TdsReportService($this->repository);
    }

    /**
     * GET /api/tds/uploads
     */
    public function uploads(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireAccess()) {
            return;
        }

        try {
            $this->repository->ensureSchema();
            echo json_encode([
                'success' => true,
                'data' => $this->repository->listUploads(30),
            ]);
        } catch (\Throwable $e) {
            error_log('TDS uploads failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load TDS uploads']);
        }
    }

    /**
     * GET /api/tds/uploads/{id}
     */
    public function show(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireAccess()) {
            return;
        }

        $uploadId = (int)$id;
        if ($uploadId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid upload id']);
            return;
        }

        try {
            $this->repository->ensureSchema();
            $upload = $this->repository->findUpload($uploadId);
            if (!$upload) {
                http_response_code(404);
                echo json_encode(['error' => 'Upload not found']);
                return;
            }

            $materialCentre = isset($_GET['material_centre']) ? trim((string)$_GET['material_centre']) : null;
            $priceBand = isset($_GET['price_band']) ? trim((string)$_GET['price_band']) : null;

            echo json_encode([
                'success' => true,
                'upload' => $upload,
                'summary' => $this->repository->summaryByMaterialCentre($uploadId),
                'band_totals' => $this->repository->bandTotals($uploadId),
                'material_centres' => $this->repository->listMaterialCentres($uploadId),
                'lines' => $this->repository->listLines($uploadId, $materialCentre, $priceBand, 5000, 0),
                'band_labels' => TdsReportService::BAND_LABELS,
            ]);
        } catch (\Throwable $e) {
            error_log('TDS show failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load TDS report']);
        }
    }

    /**
     * POST /api/tds/upload
     */
    public function upload(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        if (!$this->requireAccess()) {
            return;
        }

        try {
            $this->repository->ensureSchema();
        } catch (\Throwable $e) {
            error_log('TDS schema ensure failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'TDS tables are missing. Run database migration 041_tds_reports.sql on the server.',
                'detail' => $e->getMessage(),
            ]);
            return;
        }

        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Upload was rejected by the server (file too large for PHP post_max_size / upload_max_filesize).',
            ]);
            return;
        }

        $file = $_FILES['file'] ?? null;
        if (!$file) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded.']);
            return;
        }

        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => $this->uploadErrorMessage($uploadError)]);
            return;
        }

        if ((int)($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            http_response_code(400);
            echo json_encode(['error' => 'File is too large (max 20MB).']);
            return;
        }

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Only Excel (.xlsx/.xls) or CSV files are allowed.']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        $result = $this->service->import(
            (string)$file['tmp_name'],
            (string)$file['name'],
            $ext,
            isset($user['id']) ? (int)$user['id'] : null
        );

        if (!$result['success']) {
            http_response_code(400);
            echo json_encode([
                'error' => $result['errors'][0] ?? 'Import failed',
                'errors' => $result['errors'],
            ]);
            return;
        }

        echo json_encode($result);
    }

    /**
     * GET /api/tds/uploads/{id}/export
     */
    public function export(string $id): void
    {
        if (!$this->requireAccess()) {
            return;
        }

        $uploadId = (int)$id;
        if ($uploadId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid upload id']);
            return;
        }

        try {
            $this->repository->ensureSchema();
            $upload = $this->repository->findUpload($uploadId);
            if (!$upload) {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Upload not found']);
                return;
            }

            $exported = $this->service->exportToTempFile($uploadId);
            $path = $exported['path'];
            $filename = $exported['filename'];

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (string)filesize($path));
            header('Cache-Control: no-store');
            readfile($path);
            @unlink($path);
        } catch (\Throwable $e) {
            error_log('TDS export failed: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Failed to export TDS report']);
        }
    }

    /**
     * DELETE /api/tds/uploads/{id}
     */
    public function delete(string $id): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        if (!$this->requireAccess()) {
            return;
        }

        $uploadId = (int)$id;
        if ($uploadId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid upload id']);
            return;
        }

        try {
            $this->repository->ensureSchema();
            if (!$this->repository->findUpload($uploadId)) {
                http_response_code(404);
                echo json_encode(['error' => 'Upload not found']);
                return;
            }
            $this->repository->deleteUpload($uploadId);
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            error_log('TDS delete failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete upload']);
        }
    }

    private function requireAccess(): bool
    {
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'accounts'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin or Accounts access required']);
            return false;
        }
        return true;
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the server upload size limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded. Try again.',
            UPLOAD_ERR_NO_FILE => 'No file uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by a PHP extension.',
            default => 'Upload failed (error code ' . $code . ').',
        };
    }
}
