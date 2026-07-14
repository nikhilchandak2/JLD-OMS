<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DispatchService;
use App\Services\DispatchTransferService;
use App\Support\CompanyContext;

class DispatchController
{
    private AuthService $authService;
    private DispatchService $dispatchService;
    private DispatchTransferService $dispatchTransferService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->dispatchService = new DispatchService();
        $this->dispatchTransferService = new DispatchTransferService();
    }
    
    public function index(): void
    {
        header('Content-Type: application/json');

        if (!$this->requireDispatchReadAccess()) {
            return;
        }
        
        // Get query parameters
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);
        $status = isset($_GET['status']) ? trim((string)$_GET['status']) : null;
        if ($status !== null && $status !== '' && !in_array($status, ['active', 'rejected', 'transferred'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid status filter']);
            return;
        }

        $filters = CompanyContext::mergeFilter([
            'order_id' => isset($_GET['order_id']) ? max(0, (int)$_GET['order_id']) : null,
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null,
            'status' => $status !== '' ? $status : null,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        try {
            $dispatches = $this->dispatchService->getDispatches($filters);
            $total = $this->dispatchService->getDispatchesCount($filters);
            
            echo json_encode([
                'success' => true,
                'data' => array_map(fn($dispatch) => $dispatch->toArray(), $dispatches),
                'pagination' => [
                    'total' => $total,
                    'limit' => $filters['limit'],
                    'offset' => $filters['offset'],
                    'has_more' => ($filters['offset'] + $filters['limit']) < $total
                ]
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    /** GET /api/dispatch/pending – dispatch dashboard queue + summary. */
    public function pending(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        if (!$this->authService->hasAnyRole(['admin', 'dispatch', 'order_processing'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Dispatch access required']);
            return;
        }

        try {
            $data = $this->dispatchService->getDispatchQueue();
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            error_log('Failed to fetch dispatch queue: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch dispatch queue']);
        }
    }

    public function create(int $orderId): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'order_processing', 'dispatch'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }
        
        // Get input data
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        // Validate required fields
        $requiredFields = ['dispatch_date', 'dispatch_qty_trucks', 'product_rate'];
        $errors = [];
        
        foreach ($requiredFields as $field) {
            if ($field === 'product_rate') {
                if (!isset($input[$field]) || $input[$field] === '' || !is_numeric($input[$field]) || (float)$input[$field] <= 0) {
                    $errors[] = 'Product rate per ton is required';
                }
                continue;
            }
            if (empty($input[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        // Validate data types and values
        if (!empty($input['dispatch_qty_trucks']) && (!is_numeric($input['dispatch_qty_trucks']) || $input['dispatch_qty_trucks'] <= 0)) {
            $errors[] = 'Dispatch quantity must be a positive number';
        }

        if (!empty($input['product_rate']) && (!is_numeric($input['product_rate']) || (float)$input['product_rate'] <= 0)) {
            $errors[] = 'Product rate per ton must be a positive number';
        }
        
        if (!empty($input['dispatch_date']) && !$this->isValidDate($input['dispatch_date'])) {
            $errors[] = 'Valid dispatch date is required (YYYY-MM-DD format)';
        }
        
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['error' => 'Validation failed', 'details' => $errors]);
            return;
        }
        
        try {
            $dispatchData = [
                'order_id' => $orderId,
                'dispatch_date' => $input['dispatch_date'],
                'dispatch_qty_trucks' => (int)$input['dispatch_qty_trucks'],
                'product_rate' => (float)$input['product_rate'],
                'rawana_no' => $input['rawana_no'] ?? null,
                'eway_bill_no' => $input['eway_bill_no'] ?? null,
                'remarks' => $input['remarks'] ?? null,
                'dispatched_by' => $user['id']
            ];
            
            $dispatch = $this->dispatchService->createDispatch($dispatchData);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Dispatch created successfully',
                'data' => $dispatch->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function show(int $id): void
    {
        header('Content-Type: application/json');

        if (!$this->requireDispatchReadAccess()) {
            return;
        }
        
        try {
            $dispatch = $this->dispatchService->getDispatchById($id);
            
            if (!$dispatch) {
                http_response_code(404);
                echo json_encode(['error' => 'Dispatch not found']);
                return;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $dispatch->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function update(int $id): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'order_processing', 'dispatch'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }
        
        // Get input data
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }
        
        try {
            $updateData = [];
            
            // Only update provided fields
            if (isset($input['dispatch_date']) && $this->isValidDate($input['dispatch_date'])) {
                $updateData['dispatch_date'] = $input['dispatch_date'];
            }
            
            if (isset($input['dispatch_qty_trucks']) && is_numeric($input['dispatch_qty_trucks']) && $input['dispatch_qty_trucks'] > 0) {
                $updateData['dispatch_qty_trucks'] = (int)$input['dispatch_qty_trucks'];
            }

            if (isset($input['product_rate']) && is_numeric($input['product_rate']) && (float)$input['product_rate'] > 0) {
                $updateData['product_rate'] = (float)$input['product_rate'];
            }

            if (array_key_exists('loading_weight_tons', $input)) {
                if ($input['loading_weight_tons'] === null || $input['loading_weight_tons'] === '') {
                    $updateData['loading_weight_tons'] = null;
                } elseif (is_numeric($input['loading_weight_tons']) && (float)$input['loading_weight_tons'] > 0) {
                    $updateData['loading_weight_tons'] = (float)$input['loading_weight_tons'];
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Loading weight must be a positive number (metric tons)']);
                    return;
                }
            }
            
            if (array_key_exists('rawana_no', $input)) {
                $updateData['rawana_no'] = $input['rawana_no'];
            }

            if (array_key_exists('eway_bill_no', $input)) {
                $updateData['eway_bill_no'] = $input['eway_bill_no'];
            }
            
            if (isset($input['remarks'])) {
                $updateData['remarks'] = $input['remarks'];
            }
            
            if (empty($updateData)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                return;
            }
            
            $updatedDispatch = $this->dispatchService->updateDispatch($id, $updateData);
            
            echo json_encode([
                'success' => true,
                'message' => 'Dispatch updated successfully',
                'data' => $updatedDispatch->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function delete(int $id): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        
        try {
            $success = $this->dispatchService->deleteDispatch($id);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Dispatch deleted successfully'
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Dispatch not found']);
            }
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /** GET /api/dispatches/{id}/transfer-targets — pending orders for reject-transfer workflow. */
    public function transferTargets(int $id): void
    {
        header('Content-Type: application/json');

        if (!$this->requireDispatchWriteAccess()) {
            return;
        }

        $dispatch = $this->dispatchService->getDispatchById($id);
        if (!$dispatch) {
            http_response_code(404);
            echo json_encode(['error' => 'Dispatch not found']);
            return;
        }

        try {
            $targets = $this->dispatchTransferService->getTransferTargets(
                (int)$dispatch->orderId,
                CompanyContext::getActiveCompanyId()
            );
            echo json_encode(['success' => true, 'data' => $targets]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /** POST /api/dispatches/{id}/reject-transfer — party rejected truck: credit note and/or transfer. */
    public function rejectTransfer(int $id): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->requireDispatchWriteAccess()) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        if (empty($input['action'])) {
            http_response_code(400);
            echo json_encode(['error' => 'action is required (transfer, credit_note, or replacement)']);
            return;
        }

        try {
            $result = $this->dispatchTransferService->handleRejection($id, $input, (int)$user['id']);
            echo json_encode([
                'success' => true,
                'message' => $result['message'] ?? 'Dispatch rejection processed',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    private function requireDispatchReadAccess(): bool
    {
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return false;
        }

        if (!$this->authService->hasAnyRole(['admin', 'order_processing', 'entry', 'view', 'sales', 'dispatch'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Dispatch access required']);
            return false;
        }

        return true;
    }

    private function requireDispatchWriteAccess(): bool
    {
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return false;
        }

        if (!$this->authService->hasAnyRole(['admin', 'dispatch', 'order_processing', 'entry'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Dispatch write access required']);
            return false;
        }

        return true;
    }
}




