<?php

namespace App\Models;

class CrmSample
{
    public int $id = 0;
    public int $partyId = 0;
    public ?int $dealId = null;
    public string $sampleType = '';
    public string $quantitySent = '';
    public ?string $requestDate = null;
    public ?string $dispatchDate = null;
    public ?string $trialDate = null;
    public string $status = 'sample_sent';
    public string $outcome = '';
    public string $technicalFeedback = '';
    public ?int $createdBy = null;
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
        $this->partyId = (int)($data['party_id'] ?? 0);
        $this->dealId = isset($data['deal_id']) ? (int)$data['deal_id'] : null;
        $this->sampleType = $data['sample_type'] ?? '';
        $this->quantitySent = $data['quantity_sent'] ?? '';
        $this->requestDate = $data['request_date'] ?? null;
        $this->dispatchDate = $data['dispatch_date'] ?? null;
        $this->trialDate = $data['trial_date'] ?? null;
        $this->status = $data['status'] ?? 'sample_sent';
        $this->outcome = $data['outcome'] ?? '';
        $this->technicalFeedback = $data['technical_feedback'] ?? '';
        $this->createdBy = isset($data['created_by']) ? (int)$data['created_by'] : null;
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'party_id' => $this->partyId,
            'deal_id' => $this->dealId,
            'sample_type' => $this->sampleType,
            'quantity_sent' => $this->quantitySent,
            'request_date' => $this->requestDate,
            'dispatch_date' => $this->dispatchDate,
            'trial_date' => $this->trialDate,
            'status' => $this->status,
            'outcome' => $this->outcome,
            'technical_feedback' => $this->technicalFeedback,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
