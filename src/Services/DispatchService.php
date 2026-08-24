<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\DispatchRepository;
use App\Repositories\OrderRepository;
use App\Repositories\CreditApprovalRepository;
use App\Repositories\ScheduledDeliveryRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\DispatchTransferRepository;
use App\Repositories\CreditNoteRepository;
use App\Support\CompanyContext;
use App\Models\Dispatch;
use App\Models\Order;

class DispatchService
{
    private Database $database;
    private DispatchRepository $dispatchRepository;
    private OrderRepository $orderRepository;
    private CreditApprovalRepository $creditApprovalRepository;
    private ScheduledDeliveryRepository $scheduledDeliveryRepository;
    private CompanyRepository $companyRepository;
    private DispatchTransferRepository $dispatchTransferRepository;
    private CreditNoteRepository $creditNoteRepository;
    
    public function __construct()
    {
        $this->database = new Database();
        $this->dispatchRepository = new DispatchRepository();
        $this->orderRepository = new OrderRepository();
        $this->creditApprovalRepository = new CreditApprovalRepository();
        $this->scheduledDeliveryRepository = new ScheduledDeliveryRepository();
        $this->companyRepository = new CompanyRepository();
        $this->dispatchTransferRepository = new DispatchTransferRepository();
        $this->creditNoteRepository = new CreditNoteRepository();
    }
    
    public function getDispatches(array $filters = []): array
    {
        return $this->dispatchRepository->findAll($filters);
    }
    
    public function getDispatchById(int $id): ?Dispatch
    {
        return $this->dispatchRepository->findById($id);
    }
    
    public function getDispatchesCount(array $filters = []): int
    {
        return $this->dispatchRepository->count($filters);
    }

    /**
     * Dispatch dashboard data: pending/partial orders queue + summary cards.
     */
    public function getDispatchQueue(): array
    {
        $companyId = CompanyContext::getActiveCompanyId();
        $orders = $this->orderRepository->findDispatchQueue($companyId);
        $today = $this->dispatchRepository->getDispatchedTodayTotals($companyId);

        $pendingCount = 0;
        $partialCount = 0;
        $trucksRemaining = 0;
        foreach ($orders as $row) {
            if (($row['status'] ?? '') === 'partial') {
                $partialCount++;
            } else {
                $pendingCount++;
            }
            $trucksRemaining += (int)($row['remaining_trucks'] ?? 0);
        }

        return [
            'summary' => [
                'pending_orders' => $pendingCount,
                'partial_orders' => $partialCount,
                'trucks_remaining' => $trucksRemaining,
                'dispatched_today_trucks' => $today['trucks'],
                'dispatched_today_count' => $today['dispatch_count'],
            ],
            'orders' => $orders,
        ];
    }
    
    /**
     * Manual dispatch from UI. E-way bill companies: one dispatch record per truck, each with its own E-way bill.
     *
     * @param array<int, array{tmp_name:string,name:string,size:int,error:int}> $ewayFiles
     * @return Dispatch[]
     */
    public function createManualDispatch(array $data, array $ewayFiles = []): array
    {
        $order = $this->orderRepository->findById((int)$data['order_id']);
        if (!$order) {
            throw new \Exception('Order not found');
        }

        $qty = (int)($data['dispatch_qty_trucks'] ?? 0);
        if ($qty <= 0) {
            throw new \Exception('Dispatch quantity must be at least 1');
        }

        $docType = $order->transportDocType ?? 'rawana';
        if ($docType === '') {
            $company = $this->companyRepository->findById((int)$order->companyId);
            $docType = $company?->transportDocType ?? 'rawana';
        }

        if ($docType === 'eway_bill') {
            $trucks = $data['truck_eway_bills'] ?? [];
            if (!is_array($trucks) || count($trucks) !== $qty) {
                throw new \Exception("Provide E-way bill details for each of {$qty} truck(s).");
            }

            $created = [];
            $fileService = new EwayBillFileService();
            foreach ($trucks as $index => $truck) {
                $ewayNo = trim((string)($truck['eway_bill_no'] ?? ''));
                if ($ewayNo === '') {
                    throw new \Exception('E-way bill number is required for truck ' . ($index + 1));
                }

                $record = $this->createDispatch([
                    'order_id' => $order->id,
                    'dispatch_date' => $data['dispatch_date'],
                    'dispatch_qty_trucks' => 1,
                    'product_rate' => (float)$data['product_rate'],
                    'eway_bill_no' => $ewayNo,
                    'remarks' => $data['remarks'] ?? null,
                    'dispatched_by' => $data['dispatched_by'],
                ]);

                if (isset($ewayFiles[$index]) && ($ewayFiles[$index]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $path = $fileService->store($record->id, $order->id, $ewayFiles[$index]);
                    $this->dispatchRepository->updateEwayBillFile($record->id, $path);
                    $record = $this->getDispatchById($record->id) ?? $record;
                }

                $created[] = $record;
            }

            return $created;
        }

        return [$this->createDispatch($data)];
    }

    public function createDispatch(array $data): Dispatch
    {
        // Validate that order exists
        $order = $this->orderRepository->findById($data['order_id']);
        if (!$order) {
            throw new \Exception("Order not found");
        }

        $approval = $this->creditApprovalRepository->getForOrder($order->id);
        if ($approval && ($approval['status'] ?? '') !== 'approved') {
            $status = (string)($approval['status'] ?? 'pending');
            throw new \Exception("Cannot dispatch until admin credit approval is granted. Current approval status: {$status}.");
        }

        if (\App\Support\OrderSchema::hasCreditGateColumns()
            && ($order->creditGateStatus ?? 'cleared') === 'blocked') {
            throw new \Exception('Cannot dispatch: the Director has not cleared this order\'s credit gate.');
        }
        
        // Validate business rules
        $dispatchQty = $data['dispatch_qty_trucks'];
        
        if (!$order->canDispatch($dispatchQty)) {
            throw new \Exception(
                "Cannot dispatch {$dispatchQty} trucks. Order has {$order->orderQtyTrucks} trucks, " .
                "{$order->totalDispatched} already dispatched. Available: " . 
                ($order->orderQtyTrucks - $order->totalDispatched)
            );
        }
        
        $dispatch = new Dispatch();
        $dispatch->orderId = $data['order_id'];
        $dispatch->dispatchDate = $data['dispatch_date'];
        $dispatch->dispatchQtyTrucks = $data['dispatch_qty_trucks'];
        $dispatch->productRate = isset($data['product_rate']) ? (float)$data['product_rate'] : null;
        $dispatch->loadingWeightTons = isset($data['loading_weight_tons']) && $data['loading_weight_tons'] !== ''
            ? (float)$data['loading_weight_tons']
            : null;
        $dispatch->busyInvoiceNo = !empty($data['busy_invoice_no']) ? (string)$data['busy_invoice_no'] : null;
        $dispatch->vehicleNo = $data['vehicle_no'] ?? null;
        $dispatch->rawanaNo = !empty($data['rawana_no']) ? trim((string)$data['rawana_no']) : null;
        $dispatch->ewayBillNo = !empty($data['eway_bill_no']) ? trim((string)$data['eway_bill_no']) : null;
        $dispatch->ewayBillFilePath = !empty($data['eway_bill_file_path']) ? (string)$data['eway_bill_file_path'] : null;
        $dispatch->remarks = $data['remarks'] ?? null;
        $dispatch->dispatchedBy = $data['dispatched_by'];

        $this->validateTransportDocument($order, $dispatch, true);
        
        // Validate dispatch data
        $errors = $dispatch->validate();
        if (!empty($errors)) {
            throw new \Exception("Validation failed: " . implode(', ', $errors));
        }
        
        try {
            $this->database->beginTransaction();
            
            $dispatchId = $this->dispatchRepository->create($dispatch);
            $dispatch->id = $dispatchId;
            
            // Update order status based on new total dispatched
            $this->updateOrderStatus($order->id);
            
            // Adjust scheduled deliveries if this is a recurring order
            $this->adjustScheduledDeliveries($order->id);
            
            // Log the creation
            $this->logAuditEvent($data['dispatched_by'], 'dispatches', $dispatchId, 'CREATE', null, $dispatch->toArray());
            
            $this->database->commit();
            
            // Return the complete dispatch with relationships
            return $this->getDispatchById($dispatchId);
        } catch (\Exception $e) {
            $this->database->rollback();
            throw new \Exception("Failed to create dispatch: " . $e->getMessage());
        }
    }
    
    public function updateDispatch(int $id, array $data): Dispatch
    {
        $dispatch = $this->dispatchRepository->findById($id);
        
        if (!$dispatch) {
            throw new \Exception("Dispatch not found");
        }

        $oldValues = $dispatch->toArray();
        
        // Get the order to validate constraints
        $order = $this->orderRepository->findById($dispatch->orderId);
        if (!$order) {
            throw new \Exception("Associated order not found");
        }
        
        // If updating quantity on an active dispatch, validate the new total doesn't exceed order quantity
        if (isset($data['dispatch_qty_trucks']) && ($dispatch->status ?? 'active') === 'active') {
            $newQty = $data['dispatch_qty_trucks'];
            $currentTotalWithoutThis = $order->totalDispatched - $dispatch->dispatchQtyTrucks;
            $newTotal = $currentTotalWithoutThis + $newQty;
            
            if ($newTotal > $order->orderQtyTrucks) {
                throw new \Exception(
                    "Cannot update dispatch quantity to {$newQty}. " .
                    "Order has {$order->orderQtyTrucks} trucks, would result in {$newTotal} total dispatched."
                );
            }
        }
        
        // Update dispatch fields
        if (isset($data['dispatch_date'])) {
            $dispatch->dispatchDate = $data['dispatch_date'];
        }
        
        if (isset($data['dispatch_qty_trucks'])) {
            $dispatch->dispatchQtyTrucks = $data['dispatch_qty_trucks'];
        }

        if (isset($data['product_rate'])) {
            $dispatch->productRate = (float)$data['product_rate'];
        }

        if (array_key_exists('loading_weight_tons', $data)) {
            $dispatch->loadingWeightTons = $data['loading_weight_tons'] !== null && $data['loading_weight_tons'] !== ''
                ? (float)$data['loading_weight_tons']
                : null;
        }

        if (isset($data['vehicle_no'])) {
            $dispatch->vehicleNo = $data['vehicle_no'];
        }

        if (array_key_exists('rawana_no', $data)) {
            $dispatch->rawanaNo = $data['rawana_no'] !== null && $data['rawana_no'] !== ''
                ? trim((string)$data['rawana_no'])
                : null;
        }

        if (array_key_exists('eway_bill_no', $data)) {
            $dispatch->ewayBillNo = $data['eway_bill_no'] !== null && $data['eway_bill_no'] !== ''
                ? trim((string)$data['eway_bill_no'])
                : null;
        }
        
        if (isset($data['remarks'])) {
            $dispatch->remarks = $data['remarks'];
        }

        $this->validateTransportDocument($order, $dispatch, false);
        
        // Validate updated dispatch data
        $errors = $dispatch->validate();
        if (!empty($errors)) {
            throw new \Exception("Validation failed: " . implode(', ', $errors));
        }
        
        try {
            $this->database->beginTransaction();
            
            $this->dispatchRepository->update($dispatch);
            
            // Update order status if quantity changed
            if (isset($data['dispatch_qty_trucks'])) {
                $this->updateOrderStatus($order->id);
                // Adjust scheduled deliveries if quantity changed
                $this->adjustScheduledDeliveries($order->id);
            }
            
            // Log the update
            $this->logAuditEvent($_SESSION['user_id'] ?? null, 'dispatches', $id, 'UPDATE', $oldValues, $dispatch->toArray());
            
            $this->database->commit();
            
            // Return the updated dispatch with relationships
            return $this->getDispatchById($id);
        } catch (\Exception $e) {
            $this->database->rollback();
            throw new \Exception("Failed to update dispatch: " . $e->getMessage());
        }
    }
    
    public function deleteDispatch(int $id): bool
    {
        $dispatch = $this->dispatchRepository->findById($id);
        
        if (!$dispatch) {
            throw new \Exception("Dispatch not found");
        }
        
        try {
            $this->database->beginTransaction();

            $affectedOrderIds = [$dispatch->orderId];
            $this->unlinkTransferPeers($dispatch, $affectedOrderIds);
            $this->purgeDispatchDependencies($id);
            
            // Log the deletion
            $this->logAuditEvent($_SESSION['user_id'] ?? null, 'dispatches', $id, 'DELETE', $dispatch->toArray(), null);
            
            $result = $this->dispatchRepository->delete($id);

            foreach (array_unique($affectedOrderIds) as $orderId) {
                $this->updateOrderStatus($orderId);
                $this->adjustScheduledDeliveries($orderId);
            }
            
            $this->database->commit();
            
            return $result;
        } catch (\Exception $e) {
            $this->database->rollback();
            throw new \Exception("Failed to delete dispatch: " . $e->getMessage());
        }
    }

    /**
     * Clear peer transfer links before deleting a dispatch (source or target of a transfer).
     *
     * @param list<int> $affectedOrderIds
     */
    private function unlinkTransferPeers(Dispatch $dispatch, array &$affectedOrderIds): void
    {
        // Source was transferred to another dispatch — clear the target's source link
        if (!empty($dispatch->transferredToDispatchId)) {
            $target = $this->dispatchRepository->findById((int)$dispatch->transferredToDispatchId);
            if ($target) {
                $this->dispatchRepository->updateLifecycle($target->id, ['source_dispatch_id' => null]);
                $affectedOrderIds[] = $target->orderId;
            }
        }

        // This dispatch was created by a transfer — restore the source to active
        if (!empty($dispatch->sourceDispatchId)) {
            $source = $this->dispatchRepository->findById((int)$dispatch->sourceDispatchId);
            if ($source) {
                $this->dispatchRepository->updateLifecycle($source->id, [
                    'status' => 'active',
                    'rejection_reason' => null,
                    'transferred_to_dispatch_id' => null,
                ]);
                $affectedOrderIds[] = $source->orderId;
            }
        }
    }

    private function purgeDispatchDependencies(int $dispatchId): void
    {
        if ($this->tableExists('credit_notes')) {
            $this->creditNoteRepository->deleteByDispatchId($dispatchId);
        }
        if ($this->tableExists('dispatch_transfers')) {
            $this->dispatchTransferRepository->deleteByDispatchId($dispatchId);
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
    
    private function updateOrderStatus(int $orderId): void
    {
        // Recalculate total dispatched for the order
        $totalDispatched = $this->dispatchRepository->getTotalDispatchedForOrder($orderId);
        
        $order = $this->orderRepository->findById($orderId);
        if ($order) {
            $order->totalDispatched = $totalDispatched;
            $newStatus = $order->updateStatus();
            
            if ($newStatus !== $order->status) {
                $this->orderRepository->updateStatus($orderId, $newStatus);
            }
        }
    }

    /** Public wrapper used after reject/transfer lifecycle changes. */
    public function recalculateOrderStatus(int $orderId): void
    {
        $this->updateOrderStatus($orderId);
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
    
    private function adjustScheduledDeliveries(int $orderId): void
    {
        // Get the order to check if it's recurring
        $order = $this->orderRepository->findById($orderId);
        if (!$order || !$order->isRecurring) {
            return; // Not a recurring order, no adjustment needed
        }
        
        // Get all scheduled deliveries for this order
        $scheduledDeliveries = $this->scheduledDeliveryRepository->findByOrderId($orderId);
        if (empty($scheduledDeliveries)) {
            return; // No scheduled deliveries to adjust
        }
        
        // Calculate total dispatched trucks
        $totalDispatched = $this->dispatchRepository->getTotalDispatchedForOrder($orderId);
        
        // Calculate remaining trucks to be delivered
        $remainingTrucks = $order->orderQtyTrucks - $totalDispatched;
        
        if ($remainingTrucks <= 0) {
            // All trucks have been dispatched, mark all remaining deliveries as completed or delete them
            foreach ($scheduledDeliveries as $delivery) {
                if ($delivery->status === 'pending') {
                    $this->scheduledDeliveryRepository->update($delivery->id, ['status' => 'completed', 'trucks_quantity' => 0]);
                }
            }
            return;
        }
        
        // Get only pending deliveries (not completed ones)
        $pendingDeliveries = array_filter($scheduledDeliveries, function($delivery) {
            return $delivery->status === 'pending';
        });
        
        if (empty($pendingDeliveries)) {
            return; // No pending deliveries to adjust
        }
        
        // Sort by delivery sequence to maintain order
        usort($pendingDeliveries, function($a, $b) {
            return $a->deliverySequence - $b->deliverySequence;
        });
        
        // Redistribute remaining trucks across pending deliveries
        $trucksPerDelivery = $order->trucksPerDelivery ?? 1;
        $deliveryCount = count($pendingDeliveries);
        
        // Calculate new distribution
        $baseQuantityPerDelivery = intval($remainingTrucks / $deliveryCount);
        $extraTrucks = $remainingTrucks % $deliveryCount;
        
        // If we have a preferred trucks per delivery, try to use that
        if ($trucksPerDelivery > 0) {
            $newTotalDeliveries = ceil($remainingTrucks / $trucksPerDelivery);
            
            // If we need fewer deliveries than we have pending, mark excess as completed
            if ($newTotalDeliveries < $deliveryCount) {
                for ($i = $newTotalDeliveries; $i < $deliveryCount; $i++) {
                    $this->scheduledDeliveryRepository->update($pendingDeliveries[$i]->id, [
                        'status' => 'completed',
                        'trucks_quantity' => 0
                    ]);
                }
                // Update the pending deliveries array to only include the ones we'll use
                $pendingDeliveries = array_slice($pendingDeliveries, 0, $newTotalDeliveries);
            }
            
            // Distribute trucks using the preferred quantity per delivery
            $remainingToDistribute = $remainingTrucks;
            foreach ($pendingDeliveries as $index => $delivery) {
                $isLastDelivery = ($index === count($pendingDeliveries) - 1);
                
                if ($isLastDelivery) {
                    // Last delivery gets all remaining trucks
                    $newQuantity = $remainingToDistribute;
                } else {
                    // Use preferred quantity per delivery, but not more than remaining
                    $newQuantity = min($trucksPerDelivery, $remainingToDistribute);
                }
                
                $this->scheduledDeliveryRepository->update($delivery->id, [
                    'trucks_quantity' => $newQuantity
                ]);
                
                $remainingToDistribute -= $newQuantity;
            }
        } else {
            // Fallback: distribute evenly across all pending deliveries
            foreach ($pendingDeliveries as $index => $delivery) {
                $newQuantity = $baseQuantityPerDelivery;
                
                // Distribute extra trucks to the first few deliveries
                if ($index < $extraTrucks) {
                    $newQuantity++;
                }
                
                $this->scheduledDeliveryRepository->update($delivery->id, [
                    'trucks_quantity' => $newQuantity
                ]);
            }
        }
    }

    private function validateTransportDocument(Order $order, Dispatch $dispatch, bool $isCreate): void
    {
        $docType = $order->transportDocType ?? 'rawana';
        if ($docType === '') {
            $company = $this->companyRepository->findById((int)$order->companyId);
            $docType = $company?->transportDocType ?? 'rawana';
        }

        if ($docType === 'eway_bill') {
            if ($isCreate && ($dispatch->ewayBillNo === null || $dispatch->ewayBillNo === '')) {
                throw new \Exception(
                    'E-way bill number is required for dispatches under ' . ($order->companyName ?: 'JLD Minerals') . '.'
                );
            }
            return;
        }

        // Rawana-based companies: optional at create (often filled from Busy invoice import)
    }
}


