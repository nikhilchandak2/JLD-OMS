<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\ScheduledDelivery;

class ScheduledDeliveryRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findByOrderId(int $orderId): array
    {
        $sql = "SELECT * FROM scheduled_deliveries WHERE order_id = ? ORDER BY delivery_sequence ASC";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$orderId]);
        
        $deliveries = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $deliveries[] = new ScheduledDelivery($row);
        }
        
        return $deliveries;
    }

    public function findById(int $id): ?ScheduledDelivery
    {
        $sql = "SELECT * FROM scheduled_deliveries WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new ScheduledDelivery($row) : null;
    }

    public function create(ScheduledDelivery $delivery): ScheduledDelivery
    {
        $sql = "INSERT INTO scheduled_deliveries (order_id, delivery_sequence, scheduled_date, trucks_quantity, status, notes) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([
            $delivery->orderId,
            $delivery->deliverySequence,
            $delivery->scheduledDate,
            $delivery->trucksQuantity,
            $delivery->status,
            $delivery->notes
        ]);
        
        $delivery->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($delivery->id);
    }

    public function createMultiple(array $deliveries): array
    {
        $this->database->getConnection()->beginTransaction();
        
        try {
            $createdDeliveries = [];
            foreach ($deliveries as $delivery) {
                $createdDeliveries[] = $this->create($delivery);
            }
            
            $this->database->getConnection()->commit();
            return $createdDeliveries;
        } catch (\Exception $e) {
            $this->database->getConnection()->rollback();
            throw $e;
        }
    }

    public function update(int $id, array $data): ?ScheduledDelivery
    {
        $fields = [];
        $values = [];
        
        $allowedFields = ['scheduled_date', 'trucks_quantity', 'status', 'actual_delivery_date', 'notes'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return $this->findById($id);
        }
        
        $fields[] = "updated_at = NOW()";
        $values[] = $id;
        
        $sql = "UPDATE scheduled_deliveries SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($values);
        
        return $this->findById($id);
    }

    public function findUpcoming(int $days = 7): array
    {
        $sql = "SELECT sd.*, o.order_no, o.party_id, pt.name as party_name, o.product_id, p.name as product_name
                FROM scheduled_deliveries sd
                JOIN orders o ON sd.order_id = o.id
                JOIN parties pt ON o.party_id = pt.id
                JOIN products p ON o.product_id = p.id
                WHERE sd.status = 'pending' 
                AND sd.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                ORDER BY sd.scheduled_date ASC, sd.delivery_sequence ASC";
        
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$days]);
        
        $deliveries = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $delivery = new ScheduledDelivery($row);
            // Add additional order info
            $delivery->orderNo = $row['order_no'];
            $delivery->partyName = $row['party_name'];
            $delivery->productName = $row['product_name'];
            $deliveries[] = $delivery;
        }
        
        return $deliveries;
    }

    public function findOverdue(): array
    {
        $sql = "SELECT sd.*, o.order_no, o.party_id, pt.name as party_name, o.product_id, p.name as product_name
                FROM scheduled_deliveries sd
                JOIN orders o ON sd.order_id = o.id
                JOIN parties pt ON o.party_id = pt.id
                JOIN products p ON o.product_id = p.id
                WHERE sd.status = 'pending' 
                AND sd.scheduled_date < CURDATE()
                ORDER BY sd.scheduled_date ASC, sd.delivery_sequence ASC";
        
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute();
        
        $deliveries = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $delivery = new ScheduledDelivery($row);
            // Add additional order info
            $delivery->orderNo = $row['order_no'];
            $delivery->partyName = $row['party_name'];
            $delivery->productName = $row['product_name'];
            $deliveries[] = $delivery;
        }
        
        return $deliveries;
    }
    
    public function deleteByOrderId(int $orderId): bool
    {
        $sql = "DELETE FROM scheduled_deliveries WHERE order_id = ?";
        $this->database->execute($sql, [$orderId]);
        return true;
    }
}
