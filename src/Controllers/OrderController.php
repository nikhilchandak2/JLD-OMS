<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\OrderService;
use App\Models\Order;

class OrderController
{
    private AuthService $authService;
    private OrderService $orderService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->orderService = new OrderService();
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
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null,
            'party_id' => $_GET['party_id'] ?? null,
            'product_id' => $_GET['product_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 50,
            'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0
        ];
        
        try {
            $orders = $this->orderService->getOrders($filters);
            $total = $this->orderService->getOrdersCount($filters);
            
            echo json_encode([
                'success' => true,
                'data' => array_map(fn($order) => $order->toArray(), $orders),
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
            $order = $this->orderService->getOrderById($id);
            
            if (!$order) {
                http_response_code(404);
                echo json_encode(['error' => 'Order not found']);
                return;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $order->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function create(): void
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
        $requiredFields = ['company_id', 'order_date', 'product_id', 'order_qty_trucks', 'party_id'];
        $errors = [];
        
        foreach ($requiredFields as $field) {
            if (empty($input[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        // Validate data types and values
        if (!empty($input['order_qty_trucks']) && (!is_numeric($input['order_qty_trucks']) || $input['order_qty_trucks'] <= 0)) {
            $errors[] = 'Order quantity must be a positive number';
        }
        
        if (!empty($input['product_id']) && (!is_numeric($input['product_id']) || $input['product_id'] <= 0)) {
            $errors[] = 'Valid product ID is required';
        }
        
        if (!empty($input['party_id']) && (!is_numeric($input['party_id']) || $input['party_id'] <= 0)) {
            $errors[] = 'Valid party ID is required';
        }
        
        if (!empty($input['order_date']) && !$this->isValidDate($input['order_date'])) {
            $errors[] = 'Valid order date is required (YYYY-MM-DD format)';
        }
        
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['error' => 'Validation failed', 'details' => $errors]);
            return;
        }
        
        try {
            $orderData = [
                'company_id' => (int)$input['company_id'],
                'order_date' => $input['order_date'],
                'product_id' => (int)$input['product_id'],
                'order_qty_trucks' => (int)$input['order_qty_trucks'],
                'party_id' => (int)$input['party_id'],
                'priority' => $input['priority'] ?? 'normal',
                'is_recurring' => (bool)($input['is_recurring'] ?? false),
                'delivery_frequency_days' => isset($input['delivery_frequency_days']) ? (int)$input['delivery_frequency_days'] : null,
                'trucks_per_delivery' => isset($input['trucks_per_delivery']) ? (int)$input['trucks_per_delivery'] : null,
                'total_deliveries' => isset($input['total_deliveries']) ? (int)$input['total_deliveries'] : null,
                'created_by' => $user['id']
            ];
            
            $order = $this->orderService->createOrder($orderData);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order->toArray()
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
            $order = $this->orderService->getOrderById($id);
            
            if (!$order) {
                http_response_code(404);
                echo json_encode(['error' => 'Order not found']);
                return;
            }
            
            // Validate that order can be edited
            if (!$order->canBeEdited()) {
                http_response_code(400);
                echo json_encode(['error' => 'Completed orders cannot be edited']);
                return;
            }
            
            // Validate new quantity if provided
            if (isset($input['order_qty_trucks'])) {
                $newQuantity = (int)$input['order_qty_trucks'];
                if (!$order->canReduceQuantity($newQuantity)) {
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Cannot reduce order quantity below dispatched quantity',
                        'dispatched' => $order->totalDispatched,
                        'requested' => $newQuantity
                    ]);
                    return;
                }
            }
            
            $updateData = [];
            
            // Only update provided fields
            if (isset($input['order_date']) && $this->isValidDate($input['order_date'])) {
                $updateData['order_date'] = $input['order_date'];
            }
            
            if (isset($input['product_id']) && is_numeric($input['product_id']) && $input['product_id'] > 0) {
                $updateData['product_id'] = (int)$input['product_id'];
            }
            
            if (isset($input['order_qty_trucks']) && is_numeric($input['order_qty_trucks']) && $input['order_qty_trucks'] > 0) {
                $updateData['order_qty_trucks'] = (int)$input['order_qty_trucks'];
            }
            
            if (isset($input['party_id']) && is_numeric($input['party_id']) && $input['party_id'] > 0) {
                $updateData['party_id'] = (int)$input['party_id'];
            }
            
            if (empty($updateData)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                return;
            }
            
            $updatedOrder = $this->orderService->updateOrder($id, $updateData);
            
            echo json_encode([
                'success' => true,
                'message' => 'Order updated successfully',
                'data' => $updatedOrder->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
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
        
        // Check permissions - only admin users can delete orders
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required to delete orders']);
            return;
        }
        
        try {
            // Check if order exists
            $order = $this->orderService->getOrderById($id);
            if (!$order) {
                http_response_code(404);
                echo json_encode(['error' => 'Order not found']);
                return;
            }
            
            // Check if order has dispatches
            if ($order->totalDispatched > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete order with existing dispatches. Please delete dispatches first.']);
                return;
            }
            
            $success = $this->orderService->deleteOrder($id, $user['id']);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Order deleted successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete order']);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function getScheduledDeliveries(int $id): void
    {
        header('Content-Type: application/json');
        
        try {
            $deliveries = $this->orderService->getScheduledDeliveries($id);
            
            echo json_encode([
                'success' => true,
                'data' => $deliveries
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}

