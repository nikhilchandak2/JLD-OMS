<?php

namespace App\Models;

class CrmDeal
{
    public int $id = 0;
    public int $partyId = 0;
    public string $partyName = '';
    public ?int $leadId = null;
    public string $title = '';
    public ?float $value = null;
    public string $stage = 'prospect_identified';
    public ?string $expectedCloseDate = null;
    public ?int $assignedTo = null;
    public string $assignedToName = '';
    public string $notes = '';
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
        $this->partyName = $data['party_name'] ?? '';
        $this->leadId = isset($data['lead_id']) ? (int)$data['lead_id'] : null;
        $this->title = $data['title'] ?? '';
        $this->value = isset($data['value']) ? (float)$data['value'] : null;
        $this->stage = $data['stage'] ?? 'prospect_identified';
        $this->expectedCloseDate = $data['expected_close_date'] ?? null;
        $this->assignedTo = isset($data['assigned_to']) ? (int)$data['assigned_to'] : null;
        $this->assignedToName = $data['assigned_to_name'] ?? '';
        $this->notes = $data['notes'] ?? '';
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'party_id' => $this->partyId,
            'party_name' => $this->partyName,
            'lead_id' => $this->leadId,
            'title' => $this->title,
            'value' => $this->value,
            'stage' => $this->stage,
            'expected_close_date' => $this->expectedCloseDate,
            'assigned_to' => $this->assignedTo,
            'assigned_to_name' => $this->assignedToName,
            'notes' => $this->notes,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
