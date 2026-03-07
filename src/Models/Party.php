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
    public ?string $region = null;
    public ?string $productCategory = null;
    public ?string $productionCapacity = null;
    public ?string $factoryLocations = null;
    public ?float $creditLimit = null;
    public ?int $paymentTermsDays = null;
    public ?string $technicalNotes = null;
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
        $this->region = $data['region'] ?? null;
        $this->productCategory = $data['product_category'] ?? null;
        $this->productionCapacity = $data['production_capacity'] ?? null;
        $this->factoryLocations = $data['factory_locations'] ?? null;
        $this->creditLimit = isset($data['credit_limit']) ? (float)$data['credit_limit'] : null;
        $this->paymentTermsDays = isset($data['payment_terms_days']) ? (int)$data['payment_terms_days'] : null;
        $this->technicalNotes = $data['technical_notes'] ?? null;
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
            'region' => $this->region,
            'product_category' => $this->productCategory,
            'production_capacity' => $this->productionCapacity,
            'factory_locations' => $this->factoryLocations,
            'credit_limit' => $this->creditLimit,
            'payment_terms_days' => $this->paymentTermsDays,
            'technical_notes' => $this->technicalNotes,
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



