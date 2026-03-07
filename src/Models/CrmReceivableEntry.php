<?php

namespace App\Models;

class CrmReceivableEntry
{
    public int $id = 0;
    public int $partyId = 0;
    public string $entryType = 'invoice'; // invoice, payment, adjustment
    public float $amount = 0;
    public string $entryDate = '';
    public string $reference = '';
    public string $description = '';
    public ?int $createdBy = null;
    public string $createdAt = '';

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
        $this->entryType = $data['entry_type'] ?? 'invoice';
        $this->amount = (float)($data['amount'] ?? 0);
        $this->entryDate = $data['entry_date'] ?? '';
        $this->reference = $data['reference'] ?? '';
        $this->description = $data['description'] ?? '';
        $this->createdBy = isset($data['created_by']) ? (int)$data['created_by'] : null;
        $this->createdAt = $data['created_at'] ?? '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'party_id' => $this->partyId,
            'entry_type' => $this->entryType,
            'amount' => $this->amount,
            'entry_date' => $this->entryDate,
            'reference' => $this->reference,
            'description' => $this->description,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
        ];
    }
}
