<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\OrderService;
use App\Services\CreditLimitExceededException;
use App\Models\Order;
use App\Support\CompanyContext;
use App\Support\IndianDate;
use App\Repositories\CompanyRepository;

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

        $filters = CompanyContext::mergeFilter([
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null,
            'party_id' => isset($_GET['party_id']) ? max(0, (int)$_GET['party_id']) : null,
            'party_search' => isset($_GET['party_search']) ? mb_substr(trim((string)$_GET['party_search']), 0, 100) : null,
            'product_id' => isset($_GET['product_id']) ? max(0, (int)$_GET['product_id']) : null,
            'status' => $status,
            'limit' => $limit,
            'offset' => $offset
        ]);

        if ($filters['party_search'] === '') {
            $filters['party_search'] = null;
        }
        // Free-text party search takes precedence over exact party_id
        if (!empty($filters['party_search'])) {
            $filters['party_id'] = null;
        }
        
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

            $activeCompanyId = CompanyContext::getActiveCompanyId();
            if ($activeCompanyId !== null && (int)$order->companyId !== $activeCompanyId) {
                http_response_code(404);
                echo json_encode([
                    'error' => sprintf(
                        'This order belongs to %s. Switch the active company from the header to view it.',
                        $order->companyName !== '' ? $order->companyName : 'another company'
                    ),
                    'order_company_id' => (int)$order->companyId,
                    'order_company_name' => $order->companyName,
                ]);
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
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'order_processing', 'sales'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }
        
        // Get input data
        $input = $this->getJsonOrPostInput();
        
        // Validate required fields
        $qtyMode = strtolower(trim((string)($input['order_qty_mode'] ?? 'trucks')));
        if (!in_array($qtyMode, ['trucks', 'weight'], true)) {
            $qtyMode = 'trucks';
        }

        $requiredFields = ['order_date', 'product_id', 'party_id', 'company_id'];
        if ($qtyMode === 'weight') {
            $requiredFields[] = 'order_weight_tons';
        } else {
            $requiredFields[] = 'order_qty_trucks';
        }
        $errors = [];
        
        foreach ($requiredFields as $field) {
            if (empty($input[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        // Validate data types and values
        if (!empty($input['order_qty_trucks']) && (!is_numeric($input['order_qty_trucks']) || $input['order_qty_trucks'] <= 0)) {
            $errors[] = 'Order quantity (trucks) must be a positive number';
        }

        if (!empty($input['order_weight_tons']) && (!is_numeric($input['order_weight_tons']) || (float)$input['order_weight_tons'] <= 0)) {
            $errors[] = 'Order weight (MT) must be a positive number';
        }

        if (!empty($input['tons_per_truck']) && (!is_numeric($input['tons_per_truck']) || (float)$input['tons_per_truck'] <= 0)) {
            $errors[] = 'Tons per truck must be a positive number';
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
            $errors[] = 'Valid order date is required (DD/MM/YYYY or YYYY-MM-DD)';
        }

        $priority = strtolower(trim((string)($input['priority'] ?? 'normal')));
        if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
            $errors[] = 'Priority must be normal or urgent';
        }

        $qty = isset($input['order_qty_trucks']) ? (int)$input['order_qty_trucks'] : 0;
        if ($qtyMode === 'trucks' && $qty > self::MAX_ORDER_QTY_TRUCKS) {
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

        $hasScheduledDispatch = filter_var($input['has_scheduled_dispatch'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($hasScheduledDispatch) {
            $scheduledDate = trim((string)($input['scheduled_dispatch_date'] ?? ''));
            if ($scheduledDate === '' || !$this->isValidDate($scheduledDate)) {
                $errors[] = 'Scheduled dispatch date is required (DD/MM/YYYY or YYYY-MM-DD)';
            } elseif (!empty($input['order_date'])) {
                $orderStorage = IndianDate::toStorage((string)$input['order_date']);
                $scheduledStorage = IndianDate::toStorage($scheduledDate);
                if ($orderStorage && $scheduledStorage && $scheduledStorage < $orderStorage) {
                    $errors[] = 'Scheduled dispatch date cannot be before the order date';
                }
            }
        }

        if ($isRecurring && $hasScheduledDispatch) {
            $errors[] = 'Choose either recurring delivery or scheduled dispatch, not both';
        }

        $billToOtherParty = filter_var($input['bill_to_other_party'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($billToOtherParty) {
            $billingPartyId = isset($input['billing_party_id']) ? (int)$input['billing_party_id'] : 0;
            if ($billingPartyId <= 0) {
                $errors[] = 'Billing party is required when bill to another party is enabled';
            } elseif (!empty($input['party_id']) && $billingPartyId === (int)$input['party_id']) {
                $errors[] = 'Billing party must be different from the delivery party';
            }
        }
        
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['error' => 'Validation failed', 'details' => $errors]);
            return;
        }
        
        try {
            $companyId = (int)($input['company_id'] ?? 0);
            $companyRepo = new CompanyRepository();
            $company = $companyRepo->findById($companyId);
            if (!$company || ($company->status ?? '') !== 'active') {
                http_response_code(400);
                echo json_encode(['error' => 'Valid active company is required']);
                return;
            }

            $orderData = [
                'company_id' => $companyId,
                'order_date' => IndianDate::toStorage((string)$input['order_date']),
                'product_id' => (int)$input['product_id'],
                'order_qty_mode' => $qtyMode,
                'order_qty_trucks' => isset($input['order_qty_trucks']) ? (int)$input['order_qty_trucks'] : null,
                'order_weight_tons' => isset($input['order_weight_tons']) ? (float)$input['order_weight_tons'] : null,
                'tons_per_truck' => isset($input['tons_per_truck']) ? (float)$input['tons_per_truck'] : 40,
                'party_id' => (int)$input['party_id'],
                'bill_to_other_party' => $billToOtherParty,
                'billing_party_id' => $billToOtherParty && !empty($input['billing_party_id'])
                    ? (int)$input['billing_party_id']
                    : null,
                'priority' => $priority,
                'scheduled_dispatch_date' => $hasScheduledDispatch
                    ? IndianDate::toStorage(trim((string)($input['scheduled_dispatch_date'] ?? '')))
                    : null,
                'is_recurring' => $isRecurring,
                'delivery_frequency_days' => isset($input['delivery_frequency_days']) ? (int)$input['delivery_frequency_days'] : null,
                'trucks_per_delivery' => isset($input['trucks_per_delivery']) ? (int)$input['trucks_per_delivery'] : null,
                'total_deliveries' => isset($input['total_deliveries']) ? (int)$input['total_deliveries'] : null,
                'created_by' => $user['id'],
                'created_by_role' => $user['role'] ?? '',
                'proposed_order_value' => isset($input['proposed_order_value']) && $input['proposed_order_value'] !== ''
                    ? (float)$input['proposed_order_value']
                    : 0,
                'rep_reason' => isset($input['rep_reason']) ? trim((string)$input['rep_reason']) : (isset($input['reason']) ? trim((string)$input['reason']) : ''),
            ];
            
            $order = $this->orderService->createOrder($orderData);
            
            http_response_code(201);
            $gateStatus = $order->creditGateStatus ?? 'cleared';
            $message = match ($gateStatus) {
                'pending_director' => 'Order created — pending Director confirmation. Dispatch may proceed.',
                'blocked' => 'Order created but blocked until the Director decides.',
                default => 'Order created successfully',
            };
            echo json_encode([
                'success' => true,
                'message' => $message,
                'data' => $order->toArray()
            ]);
        } catch (\App\Services\CreditGateException $e) {
            http_response_code(400);
            echo json_encode(array_merge(['error' => $e->getMessage()], $e->getDetails()));
        } catch (CreditLimitExceededException $e) {
            http_response_code(409);
            echo json_encode([
                'error' => $e->getMessage(),
                'credit_blocked' => true,
                'credit_status' => $e->getCreditStatus()
            ]);
        } catch (\RuntimeException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
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
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'order_processing', 'sales'])) {
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
            
            // Validate quantity changes (trucks or weight mode)
            $qtyMode = strtolower(trim((string)($input['order_qty_mode'] ?? $order->orderQtyMode ?? 'trucks')));
            if (!in_array($qtyMode, ['trucks', 'weight'], true)) {
                $qtyMode = 'trucks';
            }

            if ($qtyMode === 'trucks' && array_key_exists('order_qty_trucks', $input) && $input['order_qty_trucks'] !== null) {
                $newQuantity = (int)$input['order_qty_trucks'];
                if ($newQuantity <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Order quantity must be a positive number']);
                    return;
                }
                if ($newQuantity > self::MAX_ORDER_QTY_TRUCKS) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Order quantity exceeds allowed maximum']);
                    return;
                }
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

            if ($qtyMode === 'weight' && array_key_exists('order_weight_tons', $input) && $input['order_weight_tons'] !== null) {
                $weight = (float)$input['order_weight_tons'];
                if ($weight <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Order weight (MT) must be a positive number']);
                    return;
                }
                $tonsPerTruck = isset($input['tons_per_truck']) ? (float)$input['tons_per_truck'] : (float)($order->tonsPerTruck ?? 40);
                if ($tonsPerTruck <= 0) {
                    $tonsPerTruck = 40.0;
                }
                $derivedTrucks = max(1, (int)ceil($weight / $tonsPerTruck));
                if (!$order->canReduceQuantity($derivedTrucks)) {
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Cannot reduce order quantity below dispatched quantity',
                        'dispatched' => $order->totalDispatched,
                        'requested' => $derivedTrucks
                    ]);
                    return;
                }
            }
            
            $updateData = [];
            
            // Only update provided fields
            if (array_key_exists('order_date', $input)) {
                if (!$this->isValidDate((string)$input['order_date'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Valid order date is required (DD/MM/YYYY or YYYY-MM-DD)']);
                    return;
                }
                $updateData['order_date'] = IndianDate::toStorage((string)$input['order_date']);
            }
            
            if (array_key_exists('product_id', $input)) {
                if (!is_numeric($input['product_id']) || (int)$input['product_id'] <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Valid product ID is required']);
                    return;
                }
                $updateData['product_id'] = (int)$input['product_id'];
            }
            
            if (array_key_exists('order_qty_trucks', $input) && $input['order_qty_trucks'] !== null) {
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

            if (array_key_exists('company_id', $input)) {
                if (!is_numeric($input['company_id']) || (int)$input['company_id'] <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Valid company ID is required']);
                    return;
                }
                if ((int)$input['company_id'] !== (int)$order->companyId && (int)$order->totalDispatched > 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Cannot change company after trucks have been dispatched']);
                    return;
                }
                $updateData['company_id'] = (int)$input['company_id'];
            }

            if (array_key_exists('priority', $input)) {
                $priority = strtolower(trim((string)$input['priority']));
                if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Priority must be normal or urgent']);
                    return;
                }
                $updateData['priority'] = $priority;
            }

            if (array_key_exists('order_qty_mode', $input)) {
                $updateData['order_qty_mode'] = strtolower(trim((string)$input['order_qty_mode']));
            }
            if (array_key_exists('order_weight_tons', $input) && $input['order_weight_tons'] !== null) {
                $updateData['order_weight_tons'] = (float)$input['order_weight_tons'];
            }
            if (array_key_exists('tons_per_truck', $input)) {
                $updateData['tons_per_truck'] = (float)$input['tons_per_truck'];
            }

            if (array_key_exists('bill_to_other_party', $input)) {
                $billToOtherParty = filter_var($input['bill_to_other_party'], FILTER_VALIDATE_BOOLEAN);
                $partyIdForBilling = (int)($updateData['party_id'] ?? $order->partyId);
                if ($billToOtherParty) {
                    $billingPartyId = isset($input['billing_party_id']) ? (int)$input['billing_party_id'] : 0;
                    if ($billingPartyId <= 0) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Billing party is required when bill to another party is enabled']);
                        return;
                    }
                    if ($billingPartyId === $partyIdForBilling) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Billing party must be different from the delivery party']);
                        return;
                    }
                    $updateData['bill_to_other_party'] = true;
                    $updateData['billing_party_id'] = $billingPartyId;
                } else {
                    $updateData['bill_to_other_party'] = false;
                    $updateData['billing_party_id'] = null;
                }
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
        
        // Destructive: admin only — no other role may delete portal orders.
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        if (!$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Only an admin can delete orders. Contact admin if deletion is required.']);
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
            
            $success = $this->orderService->deleteOrder($id, $user['id']);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Order deleted successfully' . ($order->totalDispatched > 0 ? ' (including linked dispatches)' : '')
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
        return \App\Support\IndianDate::isValid($date);
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

        if (!$this->authService->hasAnyRole(['admin', 'order_processing', 'entry', 'view', 'sales', 'dispatch'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Orders access required']);
            return false;
        }

        return true;
    }
}

