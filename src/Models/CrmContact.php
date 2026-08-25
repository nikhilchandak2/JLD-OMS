<?php

namespace App\Models;

class CrmContact
{
    public int $id = 0;
    public int $partyId = 0;
    public string $name = '';
    public string $role = '';
    public string $phone = '';
    public string $email = '';
    public bool $isPrimary = false;
    public string $influenceLevel = 'unknown';
    public string $relationshipStrength = 'unknown';
    public ?int $introducedByUserId = null;
    public ?string $introducedOn = null;
    public ?string $preferredChannel = null;
    public ?string $preferredLanguage = null;
    public ?string $contextNotes = null;
    public ?string $introducedByName = null;
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
        $this->name = $data['name'] ?? '';
        $this->role = $data['role'] ?? '';
        $this->phone = $data['phone'] ?? '';
        $this->email = $data['email'] ?? '';
        $this->isPrimary = (bool)($data['is_primary'] ?? false);
        $this->influenceLevel = $data['influence_level'] ?? 'unknown';
        $this->relationshipStrength = $data['relationship_strength'] ?? 'unknown';
        $this->introducedByUserId = isset($data['introduced_by_user_id']) && $data['introduced_by_user_id'] !== '' && $data['introduced_by_user_id'] !== null
            ? (int)$data['introduced_by_user_id']
            : null;
        $this->introducedOn = $data['introduced_on'] ?? null;
        $this->preferredChannel = $data['preferred_channel'] ?? null;
        $this->preferredLanguage = $data['preferred_language'] ?? null;
        $this->contextNotes = $data['context_notes'] ?? null;
        $this->introducedByName = $data['introduced_by_name'] ?? null;
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'party_id' => $this->partyId,
            'name' => $this->name,
            'role' => $this->role,
            'phone' => $this->phone,
            'email' => $this->email,
            'is_primary' => $this->isPrimary,
            'influence_level' => $this->influenceLevel,
            'relationship_strength' => $this->relationshipStrength,
            'introduced_by_user_id' => $this->introducedByUserId,
            'introduced_on' => $this->introducedOn,
            'preferred_channel' => $this->preferredChannel,
            'preferred_language' => $this->preferredLanguage,
            'context_notes' => $this->contextNotes,
            'introduced_by_name' => $this->introducedByName,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
