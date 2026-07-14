<?php

namespace App\Models;

class Dispatch
{
    public int $id = 0;
    public int $orderId = 0;
    public string $orderNo = '';
    public string $partyName = '';
    public string $dispatchDate = '';
    public int $dispatchQtyTrucks = 0;
    public string $status = 'active';
    public ?string $rejectionReason = null;
    public ?int $transferredToDispatchId = null;
    public ?int $sourceDispatchId = null;
    public ?string $vehicleNo = null;
    public ?string $rawanaNo = null;
    public ?string $ewayBillNo = null;
    public ?float $productRate = null;
    public ?float $loadingWeightTons = null;
    public ?string $busyInvoiceNo = null;
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
        $this->partyName = $data['party_name'] ?? '';
        $this->dispatchDate = $data['dispatch_date'] ?? '';
        $this->dispatchQtyTrucks = $data['dispatch_qty_trucks'] ?? 0;
        $this->status = $data['status'] ?? 'active';
        $this->rejectionReason = $data['rejection_reason'] ?? null;
        $this->transferredToDispatchId = isset($data['transferred_to_dispatch_id']) && $data['transferred_to_dispatch_id'] !== ''
            ? (int)$data['transferred_to_dispatch_id']
            : null;
        $this->sourceDispatchId = isset($data['source_dispatch_id']) && $data['source_dispatch_id'] !== ''
            ? (int)$data['source_dispatch_id']
            : null;
        $this->vehicleNo = $data['vehicle_no'] ?? null;
        $this->rawanaNo = !empty($data['rawana_no']) ? (string)$data['rawana_no'] : null;
        $this->ewayBillNo = !empty($data['eway_bill_no']) ? (string)$data['eway_bill_no'] : null;
        $this->productRate = isset($data['product_rate']) && $data['product_rate'] !== '' && $data['product_rate'] !== null
            ? (float)$data['product_rate']
            : null;
        $this->loadingWeightTons = isset($data['loading_weight_tons']) && $data['loading_weight_tons'] !== '' && $data['loading_weight_tons'] !== null
            ? (float)$data['loading_weight_tons']
            : null;
        $this->busyInvoiceNo = !empty($data['busy_invoice_no']) ? (string)$data['busy_invoice_no'] : null;
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
            'party_name' => $this->partyName,
            'dispatch_date' => $this->dispatchDate,
            'dispatch_qty_trucks' => $this->dispatchQtyTrucks,
            'status' => $this->status,
            'rejection_reason' => $this->rejectionReason,
            'transferred_to_dispatch_id' => $this->transferredToDispatchId,
            'source_dispatch_id' => $this->sourceDispatchId,
            'vehicle_no' => $this->vehicleNo,
            'rawana_no' => $this->rawanaNo,
            'eway_bill_no' => $this->ewayBillNo,
            'product_rate' => $this->productRate,
            'loading_weight_tons' => $this->loadingWeightTons,
            'busy_invoice_no' => $this->busyInvoiceNo,
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

        if ($this->id <= 0 && ($this->productRate === null || $this->productRate <= 0)) {
            $errors[] = 'Product rate per ton is required and must be greater than 0';
        }
        
        if ($this->orderId <= 0) {
            $errors[] = 'Valid order ID is required';
        }
        
        return $errors;
    }
}

