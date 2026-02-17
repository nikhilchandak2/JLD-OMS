<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DispatchService;

class DispatchController
{
    private AuthService $authService;
    private DispatchService $dispatchService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->dispatchService = new DispatchService();
    }
    
    public function index(): void
    {
        header('Content-Type: application/json');
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        
        // Get query parameters
        $filters = [
            'order_id' => $_GET['order_id'] ?? null,
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 50,
            'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0
        ];
        
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
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }
        
        // Get input data
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        // Validate required fields
        $requiredFields = ['dispatch_date', 'dispatch_qty_trucks'];
        $errors = [];
        
        foreach ($requiredFields as $field) {
            if (empty($input[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        // Validate data types and values
        if (!empty($input['dispatch_qty_trucks']) && (!is_numeric($input['dispatch_qty_trucks']) || $input['dispatch_qty_trucks'] <= 0)) {
            $errors[] = 'Dispatch quantity must be a positive number';
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
                'vehicle_no' => $input['vehicle_no'] ?? null,
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
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
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
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin'])) {
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
            
            if (isset($input['vehicle_no'])) {
                $updateData['vehicle_no'] = $input['vehicle_no'];
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
    
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}




