<?php

namespace App\Models;

class Company
{
    public int $id = 0;
    public string $name = '';
    public string $code = '';
    public string $address = '';
    public string $phone = '';
    public string $email = '';
    public string $contactPerson = '';
    public string $gstNumber = '';
    public string $panNumber = '';
    public string $status = 'active';
    public string $createdAt = '';
    public string $updatedAt = '';

    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? 0;
        $this->name = $data['name'] ?? '';
        $this->code = $data['code'] ?? '';
        $this->address = $data['address'] ?? '';
        $this->phone = $data['phone'] ?? '';
        $this->email = $data['email'] ?? '';
        $this->contactPerson = $data['contact_person'] ?? '';
        $this->gstNumber = $data['gst_number'] ?? '';
        $this->panNumber = $data['pan_number'] ?? '';
        $this->status = $data['status'] ?? 'active';
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'contact_person' => $this->contactPerson,
            'gst_number' => $this->gstNumber,
            'pan_number' => $this->panNumber,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}



