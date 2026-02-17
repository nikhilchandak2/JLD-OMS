<?php

namespace App\Models;

class Party
{
    public int $id = 0;
    public string $name = '';
    public string $contactPerson = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public bool $isActive = true;
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
        $this->name = $data['name'] ?? '';
        $this->contactPerson = $data['contact_person'] ?? '';
        $this->phone = $data['phone'] ?? '';
        $this->email = $data['email'] ?? '';
        $this->address = $data['address'] ?? '';
        $this->isActive = (bool)($data['is_active'] ?? true);
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_person' => $this->contactPerson,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors[] = 'Party name is required';
        }

        if (empty($this->contactPerson)) {
            $errors[] = 'Contact person is required';
        }

        if (!empty($this->email) && !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }

        return $errors;
    }
}



