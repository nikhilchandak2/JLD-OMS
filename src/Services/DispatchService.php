<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\DispatchRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ScheduledDeliveryRepository;
use App\Models\Dispatch;

class DispatchService
{
    private Database $database;
    private DispatchRepository $dispatchRepository;
    private OrderRepository $orderRepository;
    private ScheduledDeliveryRepository $scheduledDeliveryRepository;
    
    public function __construct()
    {
        $this->database = new Database();
        $this->dispatchRepository = new DispatchRepository();
        $this->orderRepository = new OrderRepository();
        $this->scheduledDeliveryRepository = new ScheduledDeliveryRepository();
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
    
    public function createDispatch(array $data): Dispatch
    {
        // Validate that order exists
        $order = $this->orderRepository->findById($data['order_id']);
        if (!$order) {
            throw new \Exception("Order not found");
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
        $dispatch->vehicleNo = $data['vehicle_no'];
        $dispatch->remarks = $data['remarks'];
        $dispatch->dispatchedBy = $data['dispatched_by'];
        
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
        
        // Store old values for audit
        $oldValues = $dispatch->toArray();
        
        // Get the order to validate constraints
        $order = $this->orderRepository->findById($dispatch->orderId);
        if (!$order) {
            throw new \Exception("Associated order not found");
        }
        
        // If updating quantity, validate the new total doesn't exceed order quantity
        if (isset($data['dispatch_qty_trucks'])) {
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
        
        if (isset($data['vehicle_no'])) {
            $dispatch->vehicleNo = $data['vehicle_no'];
        }
        
        if (isset($data['remarks'])) {
            $dispatch->remarks = $data['remarks'];
        }
        
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
            
            // Log the deletion
            $this->logAuditEvent($_SESSION['user_id'] ?? null, 'dispatches', $id, 'DELETE', $dispatch->toArray(), null);
            
            $result = $this->dispatchRepository->delete($id);
            
            // Update order status after deletion
            $this->updateOrderStatus($dispatch->orderId);
            
            // Adjust scheduled deliveries after deletion
            $this->adjustScheduledDeliveries($dispatch->orderId);
            
            $this->database->commit();
            
            return $result;
        } catch (\Exception $e) {
            $this->database->rollback();
            throw new \Exception("Failed to delete dispatch: " . $e->getMessage());
        }
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
}


