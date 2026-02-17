<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\OrderRepository;
use App\Repositories\DispatchRepository;
use App\Repositories\ScheduledDeliveryRepository;
use App\Models\Order;
use App\Models\ScheduledDelivery;

class OrderService
{
    private Database $database;
    private OrderRepository $orderRepository;
    private DispatchRepository $dispatchRepository;
    private ScheduledDeliveryRepository $scheduledDeliveryRepository;
    
    public function __construct()
    {
        $this->database = new Database();
        $this->orderRepository = new OrderRepository();
        $this->dispatchRepository = new DispatchRepository();
        $this->scheduledDeliveryRepository = new ScheduledDeliveryRepository();
    }
    
    public function getOrders(array $filters = []): array
    {
        $orders = $this->orderRepository->findAll($filters);
        
        // Load dispatches for each order
        foreach ($orders as $order) {
            $order->dispatches = $this->dispatchRepository->findByOrderId($order->id);
        }
        
        return $orders;
    }
    
    public function getOrderById(int $id): ?Order
    {
        $order = $this->orderRepository->findById($id);
        
        if ($order) {
            $order->dispatches = $this->dispatchRepository->findByOrderId($order->id);
        }
        
        return $order;
    }
    
    public function getOrdersCount(array $filters = []): int
    {
        return $this->orderRepository->count($filters);
    }
    
    public function createOrder(array $data): Order
    {
        // Validate that product and party exist
        $this->validateProductExists($data['product_id']);
        $this->validatePartyExists($data['party_id']);
        
        $order = new Order();
        $order->companyId = $data['company_id'];
        $order->orderNo = $this->orderRepository->generateOrderNumber();
        $order->orderDate = $data['order_date'];
        $order->productId = $data['product_id'];
        $order->orderQtyTrucks = $data['order_qty_trucks'];
        $order->partyId = $data['party_id'];
        $order->priority = $data['priority'] ?? 'normal';
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
        
        // If updating quantity, ensure it's not less than dispatched
        if (isset($data['order_qty_trucks'])) {
            if (!$order->canReduceQuantity($data['order_qty_trucks'])) {
                throw new \Exception("Cannot reduce order quantity below dispatched quantity ({$order->totalDispatched})");
            }
        }
        
        // Validate references if provided
        if (isset($data['product_id'])) {
            $this->validateProductExists($data['product_id']);
        }
        
        if (isset($data['party_id'])) {
            $this->validatePartyExists($data['party_id']);
        }
        
        // Update order fields
        if (isset($data['order_date'])) {
            $order->orderDate = $data['order_date'];
        }
        
        if (isset($data['product_id'])) {
            $order->productId = $data['product_id'];
        }
        
        if (isset($data['order_qty_trucks'])) {
            $order->orderQtyTrucks = $data['order_qty_trucks'];
        }
        
        if (isset($data['party_id'])) {
            $order->partyId = $data['party_id'];
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
            
            // Log the deletion
            $this->logAuditEvent($userId, 'orders', $orderId, 'DELETE', $order->toArray(), null);
            
            // Delete the order
            $success = $this->orderRepository->delete($orderId);
            
            $this->database->commit();
            
            return $success;
        } catch (\Exception $e) {
            $this->database->rollback();
            throw new \Exception("Failed to delete order: " . $e->getMessage());
        }
    }
}

