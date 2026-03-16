<?php

namespace App\Models;

class CrmActivity
{
    public int $id = 0;
    public int $partyId = 0;
    public string $partyName = '';
    public ?int $dealId = null;
    public ?int $contactId = null;
    public string $type = 'note';
    public string $subject = '';
    public string $description = '';
    public string $activityDate = '';
    public ?int $createdBy = null;
    public string $createdByName = '';
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
        $this->dealId = isset($data['deal_id']) ? (int)$data['deal_id'] : null;
        $this->contactId = isset($data['contact_id']) ? (int)$data['contact_id'] : null;
        $this->type = $data['type'] ?? 'note';
        $this->subject = $data['subject'] ?? '';
        $this->description = $data['description'] ?? '';
        $this->activityDate = $data['activity_date'] ?? '';
        $this->createdBy = isset($data['created_by']) ? (int)$data['created_by'] : null;
        $this->createdByName = $data['created_by_name'] ?? '';
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'party_id' => $this->partyId,
            'party_name' => $this->partyName,
            'deal_id' => $this->dealId,
            'contact_id' => $this->contactId,
            'type' => $this->type,
            'subject' => $this->subject,
            'description' => $this->description,
            'activity_date' => $this->activityDate,
            'created_by' => $this->createdBy,
            'created_by_name' => $this->createdByName,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}

