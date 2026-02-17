<?php

namespace App\Models;

class Product
{
    public int $id = 0;
    public string $code = '';
    public string $name = '';
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
        $this->code = $data['code'] ?? '';
        $this->name = $data['name'] ?? '';
        $this->isActive = (bool)($data['is_active'] ?? true);
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->code)) {
            $errors[] = 'Product code is required';
        }

        if (empty($this->name)) {
            $errors[] = 'Product name is required';
        }

        return $errors;
    }
}



