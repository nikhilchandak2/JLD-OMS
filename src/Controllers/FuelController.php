<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\FuelReportImportService;
use App\Services\FuelReportPdfService;
use App\Repositories\FuelReportRepository;

class FuelController
{
    private const MAX_UPLOAD_BYTES = 15728640; // 15MB
    private const ALLOWED_EXT = ['xlsx', 'xls', 'csv', 'pdf', 'ods'];

    private AuthService $authService;
    private FuelReportRepository $repository;
    private FuelReportImportService $importService;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->repository = new FuelReportRepository();
        $this->importService = new FuelReportImportService($this->repository);
    }

    /**
     * GET /api/fuel/categories — machine counts per category
     */
    public function categories(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireFuelAccess()) {
            return;
        }

        try {
            $this->repository->ensureSchema();
            echo json_encode([
                'success' => true,
                'data' => $this->repository->categoryCounts(),
            ]);
        } catch (\Throwable $e) {
            error_log('Fuel categories failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load categories']);
        }
    }

    /**
     * GET /api/fuel/machines?category=kobelco|jcb|dumpers&month=YYYY-MM
     */
    public function machines(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireFuelAccess()) {
            return;
        }

        $category = strtolower(trim((string)($_GET['category'] ?? '')));
        if (!$this->isValidCategory($category)) {
            http_response_code(400);
            echo json_encode(['error' => 'category must be kobelco, jcb, or dumpers']);
            return;
        }

        $month = $this->normalizeMonth($_GET['month'] ?? null);

        try {
            $this->repository->ensureSchema();
            $machines = $this->repository->listMachinesWithStats($category, $month);
            $uploads = $this->repository->findUploadsByCategory($category, 25);
            $months = $this->repository->listMonthsForCategory($category);
            $summary = $this->repository->categorySummary($category, $month);
            echo json_encode([
                'success' => true,
                'category' => $category,
                'month' => $month,
                'months' => $months,
                'summary' => $summary,
                'data' => $machines,
                'uploads' => $uploads,
                'count' => count($machines),
            ]);
        } catch (\Throwable $e) {
            error_log('Fuel machines failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load machines']);
        }
    }

    /**
     * GET /api/fuel/machines/{id}/readings?month=YYYY-MM
     */
    public function machineReadings(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireFuelAccess()) {
            return;
        }

        $machineId = (int)$id;
        if ($machineId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid machine id']);
            return;
        }

        $month = $this->normalizeMonth($_GET['month'] ?? null);

        try {
            $machine = $this->repository->findMachineById($machineId);
            if (!$machine) {
                http_response_code(404);
                echo json_encode(['error' => 'Machine not found']);
                return;
            }

            $months = $this->repository->listMonthsForMachine($machineId);
            if ($month === null && $months !== []) {
                $month = $months[0]; // latest month by default
            }

            $readings = $this->repository->getMachineDailyReadings($machineId, $month, 400);
            echo json_encode([
                'success' => true,
                'machine_id' => $machineId,
                'machine' => $machine,
                'month' => $month,
                'months' => $months,
                'data' => $readings,
            ]);
        } catch (\Throwable $e) {
            error_log('Fuel readings failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load readings']);
        }
    }

    /**
     * GET /api/fuel/machines/{id}/readings/pdf?month=YYYY-MM
     */
    public function machineReadingsPdf(string $id): void
    {
        if (!$this->requireFuelAccess()) {
            return;
        }

        $machineId = (int)$id;
        if ($machineId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid machine id']);
            return;
        }

        $month = $this->normalizeMonth($_GET['month'] ?? null);
        if ($month === null) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'month is required (YYYY-MM)']);
            return;
        }

        try {
            $machine = $this->repository->findMachineById($machineId);
            if (!$machine) {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Machine not found']);
                return;
            }

            $readings = $this->repository->getMachineDailyReadings($machineId, $month, 400);
            $pdfService = new FuelReportPdfService();
            $pdf = $pdfService->generateMachineMonthPdf($machine, $month, $readings);

            $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)($machine['name'] ?? 'machine')) ?: 'machine';
            $filename = 'fuel_' . ($machine['category'] ?? 'report') . '_' . $safeName . '_' . $month . '.pdf';

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: private, max-age=0, must-revalidate');
            echo $pdf;
        } catch (\Throwable $e) {
            error_log('Fuel readings PDF failed: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Failed to generate PDF']);
        }
    }

    /**
     * POST /api/fuel/reports/upload  (multipart: file, category)
     */
    public function uploadReport(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        if (!$this->requireFuelAccess()) {
            return;
        }

        try {
            $this->repository->ensureSchema();
        } catch (\Throwable $e) {
            error_log('Fuel schema ensure failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Fuel tables are missing. Run database migration 040_fuel_monthly_reports.sql on the server.',
                'detail' => $e->getMessage(),
            ]);
            return;
        }

        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Upload was rejected by the server (file too large for PHP post_max_size / upload_max_filesize). Try a smaller file or raise those limits.',
            ]);
            return;
        }

        $user = $this->authService->getCurrentUser();
        $category = strtolower(trim((string)($_POST['category'] ?? '')));
        if (!$this->isValidCategory($category)) {
            http_response_code(400);
            echo json_encode(['error' => 'category must be kobelco, jcb, or dumpers']);
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

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Only Excel (.xlsx/.xls), CSV, or PDF files are allowed.']);
            return;
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            http_response_code(400);
            echo json_encode(['error' => 'File must be between 1 byte and 15MB']);
            return;
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_readable($tmpName)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid uploaded file']);
            return;
        }
        // Prefer is_uploaded_file, but some hosts move temp files; accept readable temp path.
        if (is_uploaded_file($tmpName) === false && !is_file($tmpName)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid uploaded file']);
            return;
        }

        try {
            $result = $this->importService->import(
                $category,
                $tmpName,
                (string)$file['name'],
                $ext,
                isset($user['id']) ? (int)$user['id'] : null
            );

            if (!$result['success']) {
                http_response_code(422);
            }

            echo json_encode($result);
        } catch (\Throwable $e) {
            error_log('Fuel report upload failed: ' . $e->getMessage());
            http_response_code(500);
            $msg = $e->getMessage();
            $hint = 'Failed to import fuel report';
            if (stripos($msg, 'fuel_') !== false || stripos($msg, "doesn't exist") !== false || stripos($msg, 'exist') !== false) {
                $hint = 'Fuel database tables are missing. Run migration 040_fuel_monthly_reports.sql on the server.';
            }
            echo json_encode([
                'error' => $hint,
                'detail' => $msg,
            ]);
        }
    }

    /**
     * DELETE /api/fuel/reports/{id}
     */
    public function deleteUpload(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireFuelAccess()) {
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
            $result = $this->repository->deleteUpload($uploadId);
            if (!$result['success']) {
                http_response_code(404);
                echo json_encode(['error' => $result['error'] ?? 'Upload not found']);
                return;
            }
            echo json_encode([
                'success' => true,
                'message' => 'Upload deleted',
                'readings_deleted' => $result['readings_deleted'],
                'machines_deleted' => $result['machines_deleted'],
                'category' => $result['category'] ?? null,
                'original_filename' => $result['original_filename'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('Fuel upload delete failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete upload']);
        }
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is larger than the server upload limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
            default => 'Upload error (code ' . $code . ').',
        };
    }

    private function requireFuelAccess(): bool
    {
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return false;
        }
        if (!$this->authService->hasAnyRole(['admin', 'operator'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return false;
        }
        return true;
    }

    private function isValidCategory(string $category): bool
    {
        return in_array($category, ['kobelco', 'jcb', 'dumpers'], true);
    }

    private function normalizeMonth(mixed $value): ?string
    {
        $month = strtolower(trim((string)($value ?? '')));
        if ($month === '' || $month === 'all') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return null;
        }
        return $month;
    }
}
