<?php

namespace App\Models;

class User
{
    public int $id = 0;
    public string $email = '';
    public string $passwordHash = '';
    public string $name = '';
    public int $roleId = 0;
    public string $roleName = '';
    public bool $isActive = true;
    public int $failedLoginAttempts = 0;
    public ?string $lockedUntil = null;
    public ?string $passwordResetToken = null;
    public ?string $passwordResetExpires = null;
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
        $this->id = $data['id'] ?? 0;
        $this->email = $data['email'] ?? '';
        $this->passwordHash = $data['password_hash'] ?? '';
        $this->name = $data['name'] ?? '';
        $this->roleId = $data['role_id'] ?? 0;
        $this->roleName = $data['role_name'] ?? '';
        $this->isActive = (bool)($data['is_active'] ?? true);
        $this->failedLoginAttempts = $data['failed_login_attempts'] ?? 0;
        $this->lockedUntil = $data['locked_until'] ?? null;
        $this->passwordResetToken = $data['password_reset_token'] ?? null;
        $this->passwordResetExpires = $data['password_reset_expires'] ?? null;
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'role_id' => $this->roleId,
            'role_name' => $this->roleName,
            'is_active' => $this->isActive,
            'failed_login_attempts' => $this->failedLoginAttempts,
            'locked_until' => $this->lockedUntil,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }
    
    public function hasRole(string $role): bool
    {
        return $this->roleName === $role;
    }
    
    public function canCreateOrders(): bool
    {
        return in_array($this->roleName, ['entry', 'admin']);
    }
    
    public function canViewReports(): bool
    {
        return in_array($this->roleName, ['view', 'admin']);
    }
    
    public function canManageUsers(): bool
    {
        return $this->roleName === 'admin';
    }
}

