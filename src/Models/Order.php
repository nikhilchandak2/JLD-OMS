<?php

namespace App\Models;

class Order
{
    public int $id = 0;
    public int $companyId = 0;
    public string $companyName = '';
    public string $transportDocType = 'rawana';
    public string $orderNo = '';
    public string $orderDate = '';
    public ?string $scheduledDispatchDate = null;
    public int $productId = 0;
    public string $productName = '';
    public int $orderQtyTrucks = 0;
    public string $orderQtyMode = 'trucks';
    public ?float $orderWeightTons = null;
    public float $tonsPerTruck = 40.0;
    public int $partyId = 0;
    public string $partyName = '';
    public bool $billToOtherParty = false;
    public ?int $billingPartyId = null;
    public string $billingPartyName = '';
    public string $status = 'pending';
    public string $priority = 'normal';
    public bool $isRecurring = false;
    public ?int $deliveryFrequencyDays = null;
    public ?int $trucksPerDelivery = null;
    public ?int $totalDeliveries = null;
    public int $createdBy = 0;
    public string $createdByName = '';
    public string $createdAt = '';
    public string $updatedAt = '';
    
    // Computed fields
    public int $totalDispatched = 0;
    public int $pendingTrucks = 0;
    public float $totalDispatchedWeight = 0;
    public float $pendingWeightTons = 0;
    public array $dispatches = [];
    
    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->fill($data);
        }
    }
    
    public function fill(array $data): void
    {
        $this->id = $data['id'] ?? 0;
        $this->companyId = $data['company_id'] ?? 0;
        $this->companyName = $data['company_name'] ?? '';
        $this->transportDocType = $data['transport_doc_type'] ?? 'rawana';
        $this->orderNo = $data['order_no'] ?? '';
        $this->orderDate = $data['order_date'] ?? '';
        $this->scheduledDispatchDate = !empty($data['scheduled_dispatch_date']) ? (string)$data['scheduled_dispatch_date'] : null;
        $this->productId = $data['product_id'] ?? 0;
        $this->productName = $data['product_name'] ?? '';
        $this->orderQtyTrucks = $data['order_qty_trucks'] ?? 0;
        $this->orderQtyMode = $data['order_qty_mode'] ?? 'trucks';
        $this->orderWeightTons = isset($data['order_weight_tons']) && $data['order_weight_tons'] !== '' && $data['order_weight_tons'] !== null
            ? (float)$data['order_weight_tons']
            : null;
        $this->tonsPerTruck = isset($data['tons_per_truck']) && $data['tons_per_truck'] !== ''
            ? (float)$data['tons_per_truck']
            : 40.0;
        $this->partyId = $data['party_id'] ?? 0;
        $this->partyName = $data['party_name'] ?? '';
        $this->billToOtherParty = (bool)($data['bill_to_other_party'] ?? false);
        $this->billingPartyId = isset($data['billing_party_id']) && $data['billing_party_id'] !== '' && $data['billing_party_id'] !== null
            ? (int)$data['billing_party_id']
            : null;
        $this->billingPartyName = $data['billing_party_name'] ?? '';
        $this->status = $data['status'] ?? 'pending';
        $this->priority = $data['priority'] ?? 'normal';
        $this->isRecurring = (bool)($data['is_recurring'] ?? false);
        $this->deliveryFrequencyDays = isset($data['delivery_frequency_days']) ? (int)$data['delivery_frequency_days'] : null;
        $this->trucksPerDelivery = isset($data['trucks_per_delivery']) ? (int)$data['trucks_per_delivery'] : null;
        $this->totalDeliveries = isset($data['total_deliveries']) ? (int)$data['total_deliveries'] : null;
        $this->createdBy = $data['created_by'] ?? 0;
        $this->createdByName = $data['created_by_name'] ?? '';
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
        
        // Computed fields
        $this->totalDispatched = $data['total_dispatched'] ?? 0;
        $this->totalDispatchedWeight = (float)($data['total_dispatched_weight'] ?? 0);
        $this->pendingTrucks = $this->orderQtyTrucks - $this->totalDispatched;
        $plannedWeight = (float)($this->orderWeightTons ?? 0);
        $this->pendingWeightTons = max(0, $plannedWeight - $this->totalDispatchedWeight);
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id ?? 0,
            'company_id' => $this->companyId ?? 0,
            'company_name' => $this->companyName ?? '',
            'transport_doc_type' => $this->transportDocType,
            'order_no' => $this->orderNo ?? '',
            'order_date' => $this->orderDate ?? '',
            'scheduled_dispatch_date' => $this->scheduledDispatchDate,
            'product_id' => $this->productId ?? 0,
            'product_name' => $this->productName ?? '',
            'order_qty_trucks' => $this->orderQtyTrucks ?? 0,
            'order_qty_mode' => $this->orderQtyMode,
            'order_weight_tons' => $this->orderWeightTons,
            'tons_per_truck' => $this->tonsPerTruck,
            'party_id' => $this->partyId ?? 0,
            'party_name' => $this->partyName ?? '',
            'bill_to_other_party' => $this->billToOtherParty,
            'billing_party_id' => $this->billingPartyId,
            'billing_party_name' => $this->billingPartyName,
            'status' => $this->status ?? 'pending',
            'priority' => $this->priority ?? 'normal',
            'is_recurring' => $this->isRecurring,
            'delivery_frequency_days' => $this->deliveryFrequencyDays,
            'trucks_per_delivery' => $this->trucksPerDelivery,
            'total_deliveries' => $this->totalDeliveries,
            'created_by' => $this->createdBy ?? 0,
            'created_by_name' => $this->createdByName ?? '',
            'created_at' => $this->createdAt ?? '',
            'updated_at' => $this->updatedAt ?? '',
            'total_dispatched' => $this->totalDispatched,
            'pending_trucks' => $this->pendingTrucks,
            'total_dispatched_weight' => $this->totalDispatchedWeight,
            'pending_weight_tons' => $this->pendingWeightTons,
            'dispatches' => array_map(function($dispatch) {
                return is_object($dispatch) ? $dispatch->toArray() : $dispatch;
            }, $this->dispatches ?? [])
        ];
    }
    
    public function canBeEdited(): bool
    {
        return $this->status !== 'completed';
    }
    
    public function canReduceQuantity(int $newQuantity): bool
    {
        return $newQuantity >= $this->totalDispatched;
    }
    
    public function canDispatch(int $quantity): bool
    {
        return ($this->totalDispatched + $quantity) <= $this->orderQtyTrucks;
    }
    
    public function updateStatus(): string
    {
        if ($this->totalDispatched === 0) {
            return 'pending';
        } elseif ($this->totalDispatched < $this->orderQtyTrucks) {
            return 'partial';
        } else {
            return 'completed';
        }
    }
}

