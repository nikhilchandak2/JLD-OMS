<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\FuelReportImportService;
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

        $user = $this->authService->getCurrentUser();
        $category = strtolower(trim((string)($_POST['category'] ?? '')));
        if (!$this->isValidCategory($category)) {
            http_response_code(400);
            echo json_encode(['error' => 'category must be kobelco, jcb, or dumpers']);
            return;
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded or upload error.']);
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
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
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
            echo json_encode(['error' => 'Failed to import fuel report']);
        }
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
