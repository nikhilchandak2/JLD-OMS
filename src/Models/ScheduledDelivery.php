<?php

namespace App\Models;

class ScheduledDelivery
{
    public int $id = 0;
    public int $orderId = 0;
    public int $deliverySequence = 0;
    public string $scheduledDate = '';
    public int $trucksQuantity = 0;
    public string $status = 'pending';
    public ?string $actualDeliveryDate = null;
    public ?string $notes = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->fill($data);
        }
    }

    public function fill(array $data): void
    {
        $this->id = (int)($data['id'] ?? 0);
        $this->orderId = (int)($data['order_id'] ?? 0);
        $this->deliverySequence = (int)($data['delivery_sequence'] ?? 0);
        $this->scheduledDate = $data['scheduled_date'] ?? '';
        $this->trucksQuantity = (int)($data['trucks_quantity'] ?? 0);
        $this->status = $data['status'] ?? 'pending';
        $this->actualDeliveryDate = $data['actual_delivery_date'] ?? null;
        $this->notes = $data['notes'] ?? null;
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'delivery_sequence' => $this->deliverySequence,
            'scheduled_date' => $this->scheduledDate,
            'trucks_quantity' => $this->trucksQuantity,
            'status' => $this->status,
            'actual_delivery_date' => $this->actualDeliveryDate,
            'notes' => $this->notes,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }

    public function isOverdue(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }
        
        return strtotime($this->scheduledDate) < strtotime('today');
    }

    public function getDaysUntilDelivery(): int
    {
        $today = strtotime('today');
        $scheduledTime = strtotime($this->scheduledDate);
        
        return (int)ceil(($scheduledTime - $today) / (24 * 60 * 60));
    }
}



