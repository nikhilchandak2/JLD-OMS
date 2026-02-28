<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Core\Database;

/**
 * Export Documents (Nepal) – standalone module.
 * Handles Nepal export docs only: Commercial Invoice, Tax Invoice, Packing List, etc.
 * Not linked to OMS orders, dispatches, vehicle tracking, or administration.
 */
class ExportDocumentsController
{
    private AuthService $authService;
    private Database $database;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->database = new Database();
    }

    /**
     * Check if export_orders table exists. GET /api/export/check-setup
     */
    public function checkSetup(): void
    {
        $this->requireAuthJson();
        header('Content-Type: application/json');
        try {
            $conn = $this->database->getConnection();
            $dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
            $stmt = $conn->query("SHOW TABLES LIKE 'export_orders'");
            $exists = $stmt->rowCount() > 0;
            echo json_encode([
                'table_exists' => $exists,
                'database' => $dbName,
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['table_exists' => false, 'database' => null, 'error' => $e->getMessage()]);
        }
    }

    /**
     * List export orders (Nepal). Uses export_orders table only.
     * GET /api/export/orders
     */
    public function listExportOrders(): void
    {
        $this->requireAuthJson();
        header('Content-Type: application/json');

        try {
            $conn = $this->database->getConnection();
            $stmt = $conn->query("
                SELECT id, reference_no, buyer_po_no, buyer_po_date, consignee, created_at
                FROM export_orders
                ORDER BY created_at DESC
            ");
            $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (\Throwable $e) {
            if ($this->tableMissing($e)) {
                echo json_encode(['success' => true, 'data' => []]);
                return;
            }
            http_response_code(500);
            echo json_encode(['error' => 'Failed to list export orders', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Create export order. Separate from OMS orders.
     * POST /api/export/orders
     */
    public function createExportOrder(): void
    {
        $this->requireAuthJson();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($input['reference_no']) || empty($input['buyer_po_no'])) {
            http_response_code(400);
            echo json_encode(['error' => 'reference_no and buyer_po_no are required']);
            return;
        }

        try {
            $conn = $this->database->getConnection();
            $sql = "INSERT INTO export_orders (reference_no, buyer_po_no, buyer_po_date, consignee, notify_applicant, pan_no, exim_code, lc_number, lc_issue_date, harmonic_code, country_origin, customs_entry, payment_terms, delivery_terms, product_description, packaging, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $input['reference_no'] ?? '',
                $input['buyer_po_no'] ?? '',
                $input['buyer_po_date'] ?? null,
                $input['consignee'] ?? '',
                $input['notify_applicant'] ?? '',
                $input['pan_no'] ?? '',
                $input['exim_code'] ?? '',
                $input['lc_number'] ?? '',
                $input['lc_issue_date'] ?? null,
                $input['harmonic_code'] ?? '',
                $input['country_origin'] ?? 'INDIAN ORIGIN',
                $input['customs_entry'] ?? '',
                $input['payment_terms'] ?? '',
                $input['delivery_terms'] ?? '',
                $input['product_description'] ?? '',
                $input['packaging'] ?? '',
            ]);
            $id = (int) $conn->lastInsertId();
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (\Throwable $e) {
            if ($this->tableMissing($e)) {
                http_response_code(503);
                echo json_encode([
                    'error' => 'Export module not set up. Run migration for export_orders.',
                    'detail' => $e->getMessage(),
                ]);
                return;
            }
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create export order', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Get one export order. GET /api/export/orders/{id}
     */
    public function showExportOrder(string $id): void
    {
        $this->requireAuthJson();
        header('Content-Type: application/json');

        try {
            $conn = $this->database->getConnection();
            $stmt = $conn->prepare("SELECT * FROM export_orders WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Export order not found']);
                return;
            }
            echo json_encode(['success' => true, 'data' => $row]);
        } catch (\Throwable $e) {
            if ($this->tableMissing($e)) {
                http_response_code(404);
                echo json_encode(['error' => 'Export order not found']);
                return;
            }
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load export order', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Generate dispatch pack: one Excel with Commercial Invoice, Tax Invoice, Packing List.
     * Uses export order + dispatch data only. POST /api/export/dispatch-pack
     */
    public function generateDispatchPack(): void
    {
        $this->requireAuthJson();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $exportOrderId = $input['export_order_id'] ?? null;
        $dispatchData = $input['dispatch'] ?? null;

        if (!$exportOrderId || !$dispatchData) {
            http_response_code(400);
            echo json_encode(['error' => 'export_order_id and dispatch (trucks, lr_no, weight_mt, amount, etc.) are required']);
            return;
        }

        try {
            $conn = $this->database->getConnection();
            $stmt = $conn->prepare("SELECT * FROM export_orders WHERE id = ?");
            $stmt->execute([$exportOrderId]);
            $exportOrder = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$exportOrder) {
                http_response_code(404);
                echo json_encode(['error' => 'Export order not found']);
                return;
            }

            // Delegate to export document service (to be implemented)
            $service = new \App\Services\ExportDocumentPackService();
            $filePath = $service->generatePack($exportOrder, $dispatchData);

            $filename = basename($filePath);
            $downloadUrl = '/api/export/download?file=' . urlencode($filename);
            echo json_encode([
                'success' => true,
                'file' => $filename,
                'download_url' => $downloadUrl,
            ]);
        } catch (\Throwable $e) {
            if ($this->tableMissing($e)) {
                http_response_code(503);
                echo json_encode(['error' => 'Export module not set up. Run migration for export_orders.']);
                return;
            }
            http_response_code(500);
            echo json_encode([
                'error' => 'Failed to generate documents',
                'message' => $e->getMessage(),
                'detail' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Download generated file. GET /api/export/download?file=...
     */
    public function download(): void
    {
        $this->requireAuthJson();

        $filename = $_GET['file'] ?? null;
        if (!$filename) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'File parameter required']);
            return;
        }
        $filename = basename($filename);
        $dir = __DIR__ . '/../../storage/export_documents';
        $path = realpath($dir . '/' . $filename);
        if (!$path || strpos($path, realpath($dir)) !== 0) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'File not found']);
            return;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $types = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
        ];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    private function requireAuthJson(): void
    {
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
    }

    private function tableMissing(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return (stripos($msg, 'export_orders') !== false && (stripos($msg, 'doesn\'t exist') !== false || stripos($msg, 'not found') !== false));
    }
}
