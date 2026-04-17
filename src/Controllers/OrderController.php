<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\OrderService;
use App\Models\Order;

class OrderController
{
    private const MAX_ORDER_QTY_TRUCKS = 100000;
    private const ALLOWED_ORDER_STATUSES = ['pending', 'partial', 'completed'];
    private const ALLOWED_PRIORITIES = ['normal', 'urgent'];

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

        if (!$this->requireOrdersReadAccess()) {
            return;
        }
        
        // Get query parameters
        $status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : null;
        if ($status !== null && !in_array($status, self::ALLOWED_ORDER_STATUSES, true)) {
            $status = null;
        }

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $filters = [
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null,
            'party_id' => isset($_GET['party_id']) ? max(0, (int)$_GET['party_id']) : null,
            'product_id' => isset($_GET['product_id']) ? max(0, (int)$_GET['product_id']) : null,
            'status' => $status,
            'limit' => $limit,
            'offset' => $offset
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
            $this->respondServerError('Failed to fetch orders', $e);
        }
    }
    
    public function show(int $id): void
    {
        header('Content-Type: application/json');

        if (!$this->requireOrdersReadAccess()) {
            return;
        }
        
        try {
            $order = $this->orderService->getOrderById($id);
            
            if (!$order) {
                http_response_code(404);
                echo json_encode(['error' => 'Order not found']);
                return;
            }

            $creditApproval = $this->orderService->getCreditApprovalForOrder($id);
            
            echo json_encode([
                'success' => true,
                'data' => array_merge($order->toArray(), [
                    'credit_approval' => $creditApproval
                ])
            ]);
        } catch (\Exception $e) {
            $this->respondServerError('Failed to fetch order', $e);
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
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'order_processing'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }
        
        // Get input data
        $input = $this->getJsonOrPostInput();
        
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
        
        if (!empty($input['company_id']) && (!is_numeric($input['company_id']) || $input['company_id'] <= 0)) {
            $errors[] = 'Valid company ID is required';
        }

        if (!empty($input['product_id']) && (!is_numeric($input['product_id']) || $input['product_id'] <= 0)) {
            $errors[] = 'Valid product ID is required';
        }
        
        if (!empty($input['party_id']) && (!is_numeric($input['party_id']) || $input['party_id'] <= 0)) {
            $errors[] = 'Valid party ID is required';
        }
        
        if (!empty($input['order_date']) && !$this->isValidDate((string)$input['order_date'])) {
            $errors[] = 'Valid order date is required (YYYY-MM-DD format)';
        }

        $priority = strtolower(trim((string)($input['priority'] ?? 'normal')));
        if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
            $errors[] = 'Priority must be normal or urgent';
        }

        $qty = isset($input['order_qty_trucks']) ? (int)$input['order_qty_trucks'] : 0;
        if ($qty > self::MAX_ORDER_QTY_TRUCKS) {
            $errors[] = 'Order quantity exceeds allowed maximum';
        }

        $isRecurring = filter_var($input['is_recurring'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($isRecurring) {
            $trucksPerDelivery = isset($input['trucks_per_delivery']) ? (int)$input['trucks_per_delivery'] : 0;
            $frequencyDays = isset($input['delivery_frequency_days']) ? (int)$input['delivery_frequency_days'] : 0;
            if ($trucksPerDelivery <= 0) {
                $errors[] = 'Trucks per delivery must be a positive number for recurring orders';
            }
            if ($frequencyDays <= 0) {
                $errors[] = 'Delivery frequency must be a positive number for recurring orders';
            }
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
                'priority' => $priority,
                'is_recurring' => $isRecurring,
                'delivery_frequency_days' => isset($input['delivery_frequency_days']) ? (int)$input['delivery_frequency_days'] : null,
                'trucks_per_delivery' => isset($input['trucks_per_delivery']) ? (int)$input['trucks_per_delivery'] : null,
                'total_deliveries' => isset($input['total_deliveries']) ? (int)$input['total_deliveries'] : null,
                'created_by' => $user['id'],
                'created_by_role' => $user['role'] ?? ''
            ];
            
            $order = $this->orderService->createOrder($orderData);

            $creditApproval = $this->orderService->getCreditApprovalForOrder($order->id);
            $approvalRequired = $creditApproval && ($creditApproval['status'] ?? '') === 'pending';
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => $approvalRequired ? 'Order created successfully. Waiting for admin approval.' : 'Order created successfully',
                'data' => array_merge($order->toArray(), [
                    'credit_approval' => $creditApproval
                ])
            ]);
        } catch (\Exception $e) {
            $this->respondServerError('Failed to create order', $e);
        }
    }

    public function creditApprovalsPending(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        try {
            $list = $this->orderService->getPendingCreditApprovals();
            echo json_encode(['success' => true, 'data' => $list]);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $isMissingTable = stripos($msg, 'Base table or view not found') !== false
                || stripos($msg, 'credit_approval_requests') !== false;

            if ($isMissingTable) {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Credit approval feature is not configured. Please run migrations.',
                    'details' => 'Run: php scripts/run_migration.php 018 and php scripts/run_migration.php 019'
                ]);
                return;
            }

            $this->respondServerError('Failed to fetch credit approvals', $e);
        }
    }

    public function decideCreditApproval(int $approvalId): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        $input = $this->getJsonOrPostInput();
        $decision = isset($input['decision']) ? (string)$input['decision'] : '';
        $note = isset($input['note']) ? trim((string)$input['note']) : null;
        $creditLimitIncrease = array_key_exists('credit_limit_increase', $input)
            ? (float)$input['credit_limit_increase']
            : null;

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'decision must be approved or rejected']);
            return;
        }

        if ($note !== null && strlen($note) > 1000) {
            http_response_code(400);
            echo json_encode(['error' => 'note must be at most 1000 characters']);
            return;
        }

        if ($decision === 'approved' && $creditLimitIncrease !== null && $creditLimitIncrease < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'credit_limit_increase must be a positive number']);
            return;
        }

        try {
            $ok = $this->orderService->decideCreditApproval(
                $approvalId,
                $decision,
                (int)$user['id'],
                $note ?: null,
                $decision === 'approved' ? $creditLimitIncrease : null
            );
            if (!$ok) {
                http_response_code(409);
                echo json_encode(['error' => 'Approval request not found or already decided']);
                return;
            }

            echo json_encode(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->respondServerError('Failed to decide credit approval', $e);
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
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'order_processing'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }
        
        // Get input data
        $input = $this->getJsonOrPostInput();
        
        if (!$input || !is_array($input)) {
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
            if (array_key_exists('order_date', $input)) {
                if (!$this->isValidDate((string)$input['order_date'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Valid order date is required (YYYY-MM-DD format)']);
                    return;
                }
                $updateData['order_date'] = (string)$input['order_date'];
            }
            
            if (array_key_exists('product_id', $input)) {
                if (!is_numeric($input['product_id']) || (int)$input['product_id'] <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Valid product ID is required']);
                    return;
                }
                $updateData['product_id'] = (int)$input['product_id'];
            }
            
            if (array_key_exists('order_qty_trucks', $input)) {
                if (!is_numeric($input['order_qty_trucks']) || (int)$input['order_qty_trucks'] <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Order quantity must be a positive number']);
                    return;
                }
                if ((int)$input['order_qty_trucks'] > self::MAX_ORDER_QTY_TRUCKS) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Order quantity exceeds allowed maximum']);
                    return;
                }
                $updateData['order_qty_trucks'] = (int)$input['order_qty_trucks'];
            }
            
            if (array_key_exists('party_id', $input)) {
                if (!is_numeric($input['party_id']) || (int)$input['party_id'] <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Valid party ID is required']);
                    return;
                }
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
            $this->respondServerError('Failed to update order', $e);
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
            $this->respondServerError('Failed to delete order', $e);
        }
    }
    
    public function getScheduledDeliveries(int $id): void
    {
        header('Content-Type: application/json');

        if (!$this->requireOrdersReadAccess()) {
            return;
        }
        
        try {
            $order = $this->orderService->getOrderById($id);
            if (!$order) {
                http_response_code(404);
                echo json_encode(['error' => 'Order not found']);
                return;
            }

            $deliveries = $this->orderService->getScheduledDeliveries($id);
            
            echo json_encode([
                'success' => true,
                'data' => $deliveries
            ]);
        } catch (\Exception $e) {
            $this->respondServerError('Failed to fetch scheduled deliveries', $e);
        }
    }
    
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    private function getJsonOrPostInput(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return is_array($_POST) ? $_POST : [];
    }

    private function respondServerError(string $message, \Throwable $e): void
    {
        error_log($message . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $message]);
    }

    private function requireOrdersReadAccess(): bool
    {
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return false;
        }

        if (!$this->authService->hasAnyRole(['admin', 'order_processing', 'entry', 'view'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Orders access required']);
            return false;
        }

        return true;
    }
}

