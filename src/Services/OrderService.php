<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\OrderRepository;
use App\Repositories\DispatchRepository;
use App\Repositories\PartyRepository;
use App\Repositories\CrmReceivableEntryRepository;
use App\Repositories\CreditApprovalRepository;
use App\Repositories\CreditNoteRepository;
use App\Repositories\DispatchTransferRepository;
use App\Repositories\ScheduledDeliveryRepository;
use App\Models\Order;
use App\Models\ScheduledDelivery;
use App\Support\OrderSchema;

class OrderService
{
    public const MAX_CREDIT_REQUESTS_PER_MONTH = 2;

    private Database $database;
    private OrderRepository $orderRepository;
    private DispatchRepository $dispatchRepository;
    private PartyRepository $partyRepository;
    private CrmReceivableEntryRepository $receivableEntryRepository;
    private CreditApprovalRepository $creditApprovalRepository;
    private ScheduledDeliveryRepository $scheduledDeliveryRepository;
    private CreditNoteRepository $creditNoteRepository;
    private DispatchTransferRepository $dispatchTransferRepository;
    private CreditGateService $creditGate;
    private CreditOverrideService $creditOverrides;
    
    public function __construct()
    {
        $this->database = new Database();
        $this->orderRepository = new OrderRepository();
        $this->dispatchRepository = new DispatchRepository();
        $this->partyRepository = new PartyRepository();
        $this->receivableEntryRepository = new CrmReceivableEntryRepository();
        $this->creditApprovalRepository = new CreditApprovalRepository();
        $this->scheduledDeliveryRepository = new ScheduledDeliveryRepository();
        $this->creditNoteRepository = new CreditNoteRepository();
        $this->dispatchTransferRepository = new DispatchTransferRepository();
        $this->creditGate = new CreditGateService();
        $this->creditOverrides = new CreditOverrideService();
    }
    
    public function getOrders(array $filters = []): array
    {
        return $this->orderRepository->findAll($filters);
    }
    
    public function getOrderById(int $id): ?Order
    {
        $order = $this->orderRepository->findById($id);
        
        if ($order) {
            $order->dispatches = $this->dispatchRepository->findByOrderId($order->id);
            $order->creditNotes = $this->creditNoteRepository->findByOrderId($order->id);
            $this->enrichOrderWeightTotals($order);
        }
        
        return $order;
    }

    private function enrichOrderWeightTotals(Order $order): void
    {
        $totalWeight = 0.0;
        foreach ($order->dispatches as $dispatch) {
            if ($dispatch->loadingWeightTons !== null && $dispatch->loadingWeightTons > 0) {
                $totalWeight += $dispatch->loadingWeightTons;
            }
        }
        $order->totalDispatchedWeight = round($totalWeight, 3);
        $planned = (float)($order->orderWeightTons ?? 0);
        $order->pendingWeightTons = max(0, round($planned - $totalWeight, 3));
    }

    public static function resolveOrderQuantities(array $data): array
    {
        $mode = strtolower(trim((string)($data['order_qty_mode'] ?? 'trucks')));
        if (!in_array($mode, ['trucks', 'weight'], true)) {
            $mode = 'trucks';
        }

        $tonsPerTruck = isset($data['tons_per_truck']) ? (float)$data['tons_per_truck'] : 40.0;
        if ($tonsPerTruck <= 0) {
            $tonsPerTruck = 40.0;
        }

        if ($mode === 'weight') {
            $weight = (float)($data['order_weight_tons'] ?? 0);
            $trucks = max(1, (int)ceil($weight / $tonsPerTruck));
            return [
                'order_qty_mode' => 'weight',
                'order_qty_trucks' => $trucks,
                'order_weight_tons' => round($weight, 3),
                'tons_per_truck' => $tonsPerTruck,
            ];
        }

        $trucks = (int)($data['order_qty_trucks'] ?? 0);
        return [
            'order_qty_mode' => 'trucks',
            'order_qty_trucks' => $trucks,
            'order_weight_tons' => round($trucks * $tonsPerTruck, 3),
            'tons_per_truck' => $tonsPerTruck,
        ];
    }
    
    public function getOrdersCount(array $filters = []): int
    {
        return $this->orderRepository->count($filters);
    }
    
    /**
     * Create a new order. Insert-only: never deletes, cancels, or overwrites
     * earlier orders for the same party. Multiple open orders per party are normal.
     */
    public function createOrder(array $data): Order
    {
        // Validate that product and party exist
        $this->validateProductExists($data['product_id']);
        $this->validatePartyExists($data['party_id']);

        $createdByRole = (string)($data['created_by_role'] ?? '');
        if ($createdByRole === '' && isset($data['created_by'])) {
            $row = $this->database->fetch(
                "SELECT r.name AS role_name
                 FROM users u
                 JOIN roles r ON u.role_id = r.id
                 WHERE u.id = ?
                 LIMIT 1",
                [(int)$data['created_by']]
            );
            if ($row && !empty($row['role_name'])) {
                $createdByRole = (string)$row['role_name'];
            }
        }
        $partyId = (int)$data['party_id'];
        
        $order = new Order();
        $order->companyId = $data['company_id'];
        $order->orderNo = $this->orderRepository->generateOrderNumber((int)$data['company_id']);
        $order->orderDate = $data['order_date'];
        $order->productId = $data['product_id'];

        $qty = self::resolveOrderQuantities($data);
        $order->orderQtyTrucks = $qty['order_qty_trucks'];
        $order->orderQtyMode = $qty['order_qty_mode'];
        $order->orderWeightTons = $qty['order_weight_tons'];
        $order->tonsPerTruck = $qty['tons_per_truck'];

        $order->partyId = $data['party_id'];
        $order->billToOtherParty = (bool)($data['bill_to_other_party'] ?? false);
        $order->billingPartyId = $order->billToOtherParty && !empty($data['billing_party_id'])
            ? (int)$data['billing_party_id']
            : null;
        $order->priority = $data['priority'] ?? 'normal';
        $order->scheduledDispatchDate = !empty($data['scheduled_dispatch_date'])
            ? (string)$data['scheduled_dispatch_date']
            : null;
        $order->isRecurring = (bool)($data['is_recurring'] ?? false);
        $order->deliveryFrequencyDays = isset($data['delivery_frequency_days']) ? (int)$data['delivery_frequency_days'] : null;
        $order->trucksPerDelivery = isset($data['trucks_per_delivery']) ? (int)$data['trucks_per_delivery'] : null;
        
        // Auto-calculate total deliveries based on order quantity and trucks per delivery
        if ($order->isRecurring && $order->trucksPerDelivery && $order->trucksPerDelivery > 0) {
            $order->totalDeliveries = (int) ceil($order->orderQtyTrucks / $order->trucksPerDelivery);
        } else {
            $order->totalDeliveries = isset($data['total_deliveries']) ? (int)$data['total_deliveries'] : null;
        }
        $order->createdBy = $data['created_by'];
        $order->status = 'pending';
        
        try {
            $this->database->beginTransaction();
            
            $orderId = $this->orderRepository->create($order);
            $order->id = $orderId;
            
            // Create scheduled deliveries if this is a recurring order
            if ($order->isRecurring) {
                $this->createScheduledDeliveries($order);
            }
            
            // Log the creation
            $this->logAuditEvent($data['created_by'], 'orders', $orderId, 'CREATE', null, $order->toArray());

            if (empty($data['skip_credit_gate']) && OrderSchema::hasCreditGateColumns()) {
                $this->applyCreditGateToNewOrder($order, $data, $createdByRole);
            }
            
            $this->database->commit();
            
            // Return the complete order with relationships
            return $this->getOrderById($orderId);
        } catch (\Exception $e) {
            $this->database->rollback();
            throw new \Exception("Failed to create order: " . $e->getMessage());
        }
    }
    
    public function updateOrder(int $id, array $data): Order
    {
        $order = $this->orderRepository->findById($id);
        
        if (!$order) {
            throw new \Exception("Order not found");
        }
        
        // Store old values for audit
        $oldValues = $order->toArray();
        
        // Validate business rules
        if (!$order->canBeEdited()) {
            throw new \Exception("Order cannot be edited - it is completed");
        }
        
        // Validate references if provided
        if (isset($data['product_id'])) {
            $this->validateProductExists($data['product_id']);
        }
        
        if (isset($data['party_id'])) {
            $this->validatePartyExists($data['party_id']);
        }

        if (!empty($data['billing_party_id'])) {
            $this->validatePartyExists((int)$data['billing_party_id']);
        }

        if (isset($data['company_id'])) {
            $this->validateCompanyExists((int)$data['company_id']);
            if ((int)$data['company_id'] !== (int)$order->companyId && (int)$order->totalDispatched > 0) {
                throw new \Exception('Cannot change company after trucks have been dispatched.');
            }
        }
        
        // Update order fields
        if (isset($data['company_id'])) {
            $order->companyId = (int)$data['company_id'];
        }

        if (isset($data['order_date'])) {
            $order->orderDate = $data['order_date'];
        }
        
        if (isset($data['product_id'])) {
            $order->productId = $data['product_id'];
        }
        
        if (isset($data['party_id'])) {
            $order->partyId = $data['party_id'];
        }

        if (array_key_exists('bill_to_other_party', $data)) {
            $order->billToOtherParty = (bool)$data['bill_to_other_party'];
            $order->billingPartyId = $order->billToOtherParty && !empty($data['billing_party_id'])
                ? (int)$data['billing_party_id']
                : null;
        }

        if (isset($data['priority'])) {
            $order->priority = $data['priority'];
        }

        if (isset($data['order_qty_mode']) || isset($data['order_qty_trucks']) || isset($data['order_weight_tons']) || isset($data['tons_per_truck'])) {
            $qty = self::resolveOrderQuantities([
                'order_qty_mode' => $data['order_qty_mode'] ?? $order->orderQtyMode,
                'order_qty_trucks' => $data['order_qty_trucks'] ?? $order->orderQtyTrucks,
                'order_weight_tons' => $data['order_weight_tons'] ?? $order->orderWeightTons,
                'tons_per_truck' => $data['tons_per_truck'] ?? $order->tonsPerTruck,
            ]);
            if (!$order->canReduceQuantity($qty['order_qty_trucks'])) {
                throw new \Exception("Cannot reduce order quantity below dispatched quantity ({$order->totalDispatched})");
            }
            $order->orderQtyMode = $qty['order_qty_mode'];
            $order->orderQtyTrucks = $qty['order_qty_trucks'];
            $order->orderWeightTons = $qty['order_weight_tons'];
            $order->tonsPerTruck = $qty['tons_per_truck'];
        }
        
        try {
            $this->database->beginTransaction();
            
            $this->orderRepository->update($order);
            
            // Update status based on new quantity vs dispatched
            $newStatus = $order->updateStatus();
            if ($newStatus !== $order->status) {
                $order->status = $newStatus;
                $this->orderRepository->updateStatus($order->id, $newStatus);
            }
            
            // Log the update
            $this->logAuditEvent($_SESSION['user_id'] ?? null, 'orders', $id, 'UPDATE', $oldValues, $order->toArray());
            
            $this->database->commit();
            
            // Return the updated order with relationships
            return $this->getOrderById($id);
        } catch (\Exception $e) {
            $this->database->rollback();
            throw new \Exception("Failed to update order: " . $e->getMessage());
        }
    }
    
    public function updateOrderStatus(int $orderId): void
    {
        $order = $this->orderRepository->findById($orderId);
        
        if ($order) {
            $newStatus = $order->updateStatus();
            if ($newStatus !== $order->status) {
                $this->orderRepository->updateStatus($orderId, $newStatus);
            }
        }
    }
    
    private function validateProductExists(int $productId): void
    {
        $result = $this->database->fetch("SELECT id FROM products WHERE id = ? AND is_active = 1", [$productId]);
        
        if (!$result) {
            throw new \Exception("Product not found or inactive");
        }
    }
    
    private function validatePartyExists(int $partyId): void
    {
        $result = $this->database->fetch("SELECT id FROM parties WHERE id = ? AND is_active = 1", [$partyId]);
        
        if (!$result) {
            throw new \Exception("Party not found or inactive");
        }
    }

    private function applyCreditGateToNewOrder(Order $order, array $data, string $createdByRole): void
    {
        $proposed = isset($data['proposed_order_value']) && $data['proposed_order_value'] !== ''
            ? (float)$data['proposed_order_value']
            : 0.0;
        $evaluation = $this->creditGate->evaluate((int)$order->partyId, (int)$order->companyId, $proposed);
        $status = (string)$evaluation['credit_gate_status'];
        $requestId = null;
        $actor = ['id' => $order->createdBy ?: null, 'role' => $createdByRole !== '' ? $createdByRole : null];

        if ((int)$evaluation['tier'] === CreditGateService::TIER_AUTO) {
            $this->logAuditEvent($order->createdBy ?: null, 'orders', (int)$order->id, 'UPDATE', null, [
                'credit_gate' => 'auto_cleared',
                'tier' => 1,
                'ledger_as_of' => $evaluation['ledger_as_of'],
            ]);
        } else {
            $reason = trim((string)($data['rep_reason'] ?? $data['reason'] ?? 'Order capture'));
            if ($reason === '') {
                $reason = 'Order capture';
            }
            $request = $this->creditOverrides->raise($evaluation, $actor, $reason, null, (int)$order->id);
            $requestId = (int)$request['id'];
        }

        $this->database->execute(
            "UPDATE orders SET credit_gate_status = ?, credit_override_request_id = ? WHERE id = ?",
            [$status, $requestId, $order->id]
        );
        $order->creditGateStatus = $status;
        $order->creditOverrideRequestId = $requestId;
    }

    private function validateCompanyExists(int $companyId): void
    {
        $result = $this->database->fetch("SELECT id FROM companies WHERE id = ? AND status = 'active'", [$companyId]);

        if (!$result) {
            throw new \Exception("Company not found or inactive");
        }
    }

    private function getCreditLimitAndOutstanding(int $partyId): ?array
    {
        $party = $this->partyRepository->findById($partyId);
        if (!$party || $party->creditLimit === null || $party->creditLimit <= 0) {
            return null;
        }

        $outstanding = $this->receivableEntryRepository->getOutstandingForParty($partyId);

        return [
            'credit_limit' => (float)$party->creditLimit,
            'outstanding' => (float)$outstanding
        ];
    }

    /**
     * Credit snapshot for a party. Ledger figures come from the daily batch (B1);
     * the as-of stamp is the oldest contributing entity (B6).
     */
    public function getPartyCreditStatus(int $partyId, int $companyId = 0): array
    {
        $party = $this->partyRepository->findById($partyId);
        if (!$party) {
            throw new \Exception("Party not found");
        }

        if ($companyId <= 0) {
            $row = $this->database->fetch("SELECT id FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
            $companyId = (int)($row['id'] ?? 0);
        }

        $yearMonth = date('Y-m');
        $requestsUsed = $this->creditApprovalRepository->countRequestsForPartyInMonth($partyId, $yearMonth);
        $requestsRemaining = max(0, self::MAX_CREDIT_REQUESTS_PER_MONTH - $requestsUsed);

        $legacy = [
            'party_id' => $partyId,
            'party_name' => $party->name,
            'requests_used_this_month' => $requestsUsed,
            'requests_remaining_this_month' => $requestsRemaining,
            'max_requests_per_month' => self::MAX_CREDIT_REQUESTS_PER_MONTH,
            'has_pending_request' => $this->creditApprovalRepository->hasPendingForParty($partyId),
        ];

        if ($companyId <= 0) {
            return $legacy + [
                'has_credit_limit' => $party->creditLimit !== null && $party->creditLimit > 0,
                'credit_limit' => $party->creditLimit !== null ? (float)$party->creditLimit : null,
                'outstanding' => (float)$this->receivableEntryRepository->getOutstandingForParty($partyId),
                'over_limit' => false,
            ];
        }

        $evaluation = $this->creditGate->evaluate($partyId, $companyId, 0.0);
        $limit = $evaluation['credit_limit'];

        return array_merge($legacy, $evaluation, [
            'has_credit_limit' => $limit !== null && $limit > 0,
            'over_limit' => (int)$evaluation['tier'] > CreditGateService::TIER_AUTO,
        ]);
    }

    /**
     * Sales raises a party-level credit request. Enforces max 2 requests per party
     * per calendar month (all statuses count) and no duplicate pending requests.
     */
    public function createPartyCreditRequest(
        int $partyId,
        int $requestedBy,
        ?float $requestedLimitIncrease,
        ?string $reason
    ): array {
        $status = $this->getPartyCreditStatus($partyId);

        if ($status['has_pending_request']) {
            throw new \Exception('A credit request for this party is already pending admin decision.');
        }

        if ($status['requests_used_this_month'] >= self::MAX_CREDIT_REQUESTS_PER_MONTH) {
            throw new \Exception(sprintf(
                'Credit request limit reached: only %d requests per party per month are allowed. Try again next month.',
                self::MAX_CREDIT_REQUESTS_PER_MONTH
            ));
        }

        $requestId = $this->creditApprovalRepository->createPartyRequest(
            $partyId,
            (float)$status['outstanding'],
            (float)($status['credit_limit'] ?? 0),
            $requestedLimitIncrease,
            $reason,
            $requestedBy
        );

        $this->logAuditEvent(
            $requestedBy,
            'credit_approval_requests',
            $requestId,
            'CREATE',
            null,
            [
                'party_id' => $partyId,
                'requested_limit_increase' => $requestedLimitIncrease,
                'reason' => $reason,
                'status' => 'pending'
            ]
        );

        return array_merge($this->getPartyCreditStatus($partyId), ['request_id' => $requestId]);
    }

    public function getCreditApprovalForOrder(int $orderId): ?array
    {
        $row = $this->creditApprovalRepository->getForOrder($orderId);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'order_id' => (int)$row['order_id'],
            'party_id' => (int)$row['party_id'],
            'outstanding' => (float)$row['outstanding'],
            'credit_limit' => (float)$row['credit_limit'],
            'status' => (string)$row['status'],
            'requested_at' => $row['requested_at'] ?? null,
            'decided_at' => $row['decided_at'] ?? null,
            'decision_note' => $row['decision_note'] ?? null
        ];
    }

    public function getPendingCreditApprovals(): array
    {
        return $this->creditApprovalRepository->getPendingApprovals();
    }

    public function decideCreditApproval(
        int $approvalId,
        string $decision,
        int $decidedBy,
        ?string $note,
        ?float $creditLimitIncrease
    ): bool {
        // Update request + party credit limit atomically.
        $this->database->beginTransaction();
        try {
            $ok = $this->creditApprovalRepository->decide(
                $approvalId,
                $decision,
                $decidedBy,
                $note,
                $creditLimitIncrease
            );
            if (!$ok) {
                $this->database->rollback();
                return false;
            }
            $this->database->commit();
            return true;
        } catch (\Exception $e) {
            $this->database->rollback();
            throw $e;
        }
    }
    
    private function logAuditEvent(?int $userId, string $tableName, int $recordId, string $action, ?array $oldValues, ?array $newValues): void
    {
        if (!$userId) {
            return; // Skip audit if no user context
        }
        
        $sql = "
            INSERT INTO audit_logs (user_id, table_name, record_id, action, old_values, new_values)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $userId,
            $tableName,
            $recordId,
            $action,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null
        ]);
    }
    
    private function createScheduledDeliveries(Order $order): void
    {
        if (!$order->isRecurring || !$order->deliveryFrequencyDays || !$order->trucksPerDelivery || !$order->totalDeliveries) {
            return;
        }
        
        $deliveries = [];
        $currentDate = new \DateTime($order->orderDate);
        $remainingTrucks = $order->orderQtyTrucks;
        
        for ($i = 1; $i <= $order->totalDeliveries; $i++) {
            $delivery = new ScheduledDelivery();
            $delivery->orderId = $order->id;
            $delivery->deliverySequence = $i;
            $delivery->scheduledDate = $currentDate->format('Y-m-d');
            
            // Calculate trucks for this delivery
            if ($i == $order->totalDeliveries) {
                // Last delivery gets remaining trucks (handles odd figures)
                $delivery->trucksQuantity = $remainingTrucks;
            } else {
                // Regular delivery gets standard quantity
                $delivery->trucksQuantity = min($order->trucksPerDelivery, $remainingTrucks);
                $remainingTrucks -= $delivery->trucksQuantity;
            }
            
            $delivery->status = 'pending';
            
            $deliveries[] = $delivery;
            
            // Add frequency days for next delivery
            if ($i < $order->totalDeliveries) {
                $currentDate->add(new \DateInterval('P' . $order->deliveryFrequencyDays . 'D'));
            }
        }
        
        $this->scheduledDeliveryRepository->createMultiple($deliveries);
    }
    
    public function getScheduledDeliveries(int $orderId): array
    {
        $deliveries = $this->scheduledDeliveryRepository->findByOrderId($orderId);
        
        // Convert objects to arrays for JSON response
        return array_map(function($delivery) {
            return $delivery->toArray();
        }, $deliveries);
    }
    
    public function getUpcomingDeliveries(int $days = 7): array
    {
        return $this->scheduledDeliveryRepository->findUpcoming($days);
    }
    
    public function getOverdueDeliveries(): array
    {
        return $this->scheduledDeliveryRepository->findOverdue();
    }
    
    public function deleteOrder(int $orderId, int $userId): bool
    {
        try {
            $this->database->beginTransaction();
            
            // Get order details for audit log
            $order = $this->orderRepository->findById($orderId);
            if (!$order) {
                throw new \Exception("Order not found");
            }
            
            // Delete scheduled deliveries if it's a recurring order
            if ($order->isRecurring) {
                $this->scheduledDeliveryRepository->deleteByOrderId($orderId);
            }

            $this->purgeRejectTransferDependencies($orderId);
            
            // Log the deletion
            $this->logAuditEvent($userId, 'orders', $orderId, 'DELETE', $order->toArray(), null);
            
            // Delete the order (dispatches cascade once transfer/credit-note links are cleared)
            $success = $this->orderRepository->delete($orderId);
            if (!$success) {
                throw new \Exception('Order could not be deleted');
            }
            
            $this->database->commit();
            
            return $success;
        } catch (\Exception $e) {
            $this->database->rollback();
            throw new \Exception("Failed to delete order: " . $e->getMessage());
        }
    }

    /** Remove credit notes and transfer records that block order deletion (migration 035). */
    private function purgeRejectTransferDependencies(int $orderId): void
    {
        if ($this->tableExists('credit_notes')) {
            $this->creditNoteRepository->deleteByOrderId($orderId);
        }
        if ($this->tableExists('dispatch_transfers')) {
            $this->dispatchTransferRepository->deleteByOrderId($orderId);
        }
    }

    private function tableExists(string $table): bool
    {
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        );
        return ((int)($row['c'] ?? 0)) > 0;
    }
}

