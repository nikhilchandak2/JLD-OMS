<?php

namespace App\Models;

class CrmTask
{
    public int $id = 0;
    public string $title = '';
    public ?string $description = null;
    public ?int $partyId = null;
    public ?string $dueDate = null; // YYYY-MM-DD
    public string $status = 'pending'; // pending, completed
    public ?int $assignedTo = null;
    public ?int $createdBy = null;
    public string $assignedToName = '';
    public string $partyName = '';
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
        $this->title = (string)($data['title'] ?? '');
        $this->description = array_key_exists('description', $data) ? ($data['description'] !== null ? (string)$data['description'] : null) : null;
        $this->partyId = isset($data['party_id']) ? (int)$data['party_id'] : (isset($data['partyId']) ? (int)$data['partyId'] : null);
        $this->dueDate = $data['due_date'] ?? ($data['dueDate'] ?? null);
        $this->status = (string)($data['status'] ?? 'pending');
        $this->assignedTo = isset($data['assigned_to']) ? (int)$data['assigned_to'] : (isset($data['assignedTo']) ? (int)$data['assignedTo'] : null);
        $this->createdBy = isset($data['created_by']) ? (int)$data['created_by'] : (isset($data['createdBy']) ? (int)$data['createdBy'] : null);
        $this->assignedToName = (string)($data['assigned_to_name'] ?? $data['assignedToName'] ?? '');
        $this->partyName = (string)($data['party_name'] ?? $data['partyName'] ?? '');
        $this->createdAt = (string)($data['created_at'] ?? $data['createdAt'] ?? '');
        $this->updatedAt = (string)($data['updated_at'] ?? $data['updatedAt'] ?? '');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'party_id' => $this->partyId,
            'due_date' => $this->dueDate,
            'status' => $this->status,
            'assigned_to' => $this->assignedTo,
            'assigned_to_name' => $this->assignedToName,
            'party_name' => $this->partyName,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}

