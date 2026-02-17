<?php

namespace App\Models;

class Dispatch
{
    public int $id = 0;
    public int $orderId = 0;
    public string $orderNo = '';
    public string $dispatchDate = '';
    public int $dispatchQtyTrucks = 0;
    public ?string $vehicleNo = null;
    public ?string $remarks = null;
    public int $dispatchedBy = 0;
    public string $dispatchedByName = '';
    public string $createdAt = '';
    
    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->fill($data);
        }
    }
    
    public function fill(array $data): void
    {
        $this->id = $data['id'] ?? 0;
        $this->orderId = $data['order_id'] ?? 0;
        $this->orderNo = $data['order_no'] ?? '';
        $this->dispatchDate = $data['dispatch_date'] ?? '';
        $this->dispatchQtyTrucks = $data['dispatch_qty_trucks'] ?? 0;
        $this->vehicleNo = $data['vehicle_no'] ?? null;
        $this->remarks = $data['remarks'] ?? null;
        $this->dispatchedBy = $data['dispatched_by'] ?? 0;
        $this->dispatchedByName = $data['dispatched_by_name'] ?? '';
        $this->createdAt = $data['created_at'] ?? '';
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'order_no' => $this->orderNo,
            'dispatch_date' => $this->dispatchDate,
            'dispatch_qty_trucks' => $this->dispatchQtyTrucks,
            'vehicle_no' => $this->vehicleNo,
            'remarks' => $this->remarks,
            'dispatched_by' => $this->dispatchedBy,
            'dispatched_by_name' => $this->dispatchedByName,
            'created_at' => $this->createdAt
        ];
    }
    
    public function validate(): array
    {
        $errors = [];
        
        if (empty($this->dispatchDate)) {
            $errors[] = 'Dispatch date is required';
        }
        
        if ($this->dispatchQtyTrucks <= 0) {
            $errors[] = 'Dispatch quantity must be greater than 0';
        }
        
        if ($this->orderId <= 0) {
            $errors[] = 'Valid order ID is required';
        }
        
        return $errors;
    }
}

