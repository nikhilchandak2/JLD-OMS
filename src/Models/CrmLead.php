<?php

namespace App\Models;

class CrmLead
{
    public int $id = 0;
    public string $title = '';
    public string $companyName = '';
    public string $contactName = '';
    public string $phone = '';
    public string $email = '';
    public string $source = '';
    public ?float $value = null;
    public string $stage = 'new';
    public ?int $partyId = null;
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
        $this->title = $data['title'] ?? '';
        $this->companyName = $data['company_name'] ?? '';
        $this->contactName = $data['contact_name'] ?? '';
        $this->phone = $data['phone'] ?? '';
        $this->email = $data['email'] ?? '';
        $this->source = $data['source'] ?? '';
        $this->value = isset($data['value']) ? (float)$data['value'] : null;
        $this->stage = $data['stage'] ?? 'new';
        $this->partyId = isset($data['party_id']) ? (int)$data['party_id'] : null;
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
            'title' => $this->title,
            'company_name' => $this->companyName,
            'contact_name' => $this->contactName,
            'phone' => $this->phone,
            'email' => $this->email,
            'source' => $this->source,
            'value' => $this->value,
            'stage' => $this->stage,
            'party_id' => $this->partyId,
            'assigned_to' => $this->assignedTo,
            'assigned_to_name' => $this->assignedToName,
            'notes' => $this->notes,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
