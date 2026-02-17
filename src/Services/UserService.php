<?php

namespace App\Services;

use App\Core\Database;
use App\Models\User;

class UserService
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function getAllUsers(): array
    {
        $sql = "
            SELECT u.*, r.name as role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            ORDER BY u.name
        ";
        
        $results = $this->database->fetchAll($sql);
        
        return array_map(function($row) {
            return new User($row);
        }, $results);
    }
    
    public function getUserById(int $id): ?User
    {
        $sql = "
            SELECT u.*, r.name as role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.id = ?
        ";
        
        $result = $this->database->fetch($sql, [$id]);
        
        return $result ? new User($result) : null;
    }
    
    public function getUserByEmail(string $email): ?User
    {
        $sql = "
            SELECT u.*, r.name as role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.email = ?
        ";
        
        $result = $this->database->fetch($sql, [$email]);
        
        return $result ? new User($result) : null;
    }
    
    public function createUser(array $data): User
    {
        // Validate that email doesn't already exist
        $existingUser = $this->getUserByEmail($data['email']);
        if ($existingUser) {
            throw new \Exception("Email already exists");
        }
        
        // Validate that role exists
        $this->validateRoleExists($data['role_id']);
        
        // Hash password
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $sql = "
            INSERT INTO users (email, password_hash, name, role_id, is_active)
            VALUES (?, ?, ?, ?, ?)
        ";
        
        try {
            $this->database->beginTransaction();
            
            $this->database->execute($sql, [
                $data['email'],
                $passwordHash,
                $data['name'],
                $data['role_id'],
                $data['is_active'] ?? true
            ]);
            
            $userId = (int)$this->database->lastInsertId();
            
            // Log the creation
            $this->logAuditEvent($_SESSION['user_id'] ?? null, 'users', $userId, 'CREATE', null, [
                'email' => $data['email'],
                'name' => $data['name'],
                'role_id' => $data['role_id'],
                'is_active' => $data['is_active'] ?? true
            ]);
            
            $this->database->commit();
            
            return $this->getUserById($userId);
        } catch (\Exception $e) {
            $this->database->rollback();
            throw new \Exception("Failed to create user: " . $e->getMessage());
        }
    }
    
    public function updateUser(int $id, array $data): User
    {
        $user = $this->getUserById($id);
        
        if (!$user) {
            throw new \Exception("User not found");
        }
        
        // Store old values for audit
        $oldValues = $user->toArray();
        
        // Check if email is being changed and if it already exists
        if (isset($data['email']) && $data['email'] !== $user->email) {
            $existingUser = $this->getUserByEmail($data['email']);
            if ($existingUser && $existingUser->id !== $id) {
                throw new \Exception("Email already exists");
            }
        }
        
        // Validate role if being changed
        if (isset($data['role_id'])) {
            $this->validateRoleExists($data['role_id']);
        }
        
        $updateFields = [];
        $params = [];
        
        if (isset($data['email'])) {
            $updateFields[] = "email = ?";
            $params[] = $data['email'];
        }
        
        if (isset($data['name'])) {
            $updateFields[] = "name = ?";
            $params[] = $data['name'];
        }
        
        if (isset($data['role_id'])) {
            $updateFields[] = "role_id = ?";
            $params[] = $data['role_id'];
        }
        
        if (isset($data['is_active'])) {
            $updateFields[] = "is_active = ?";
            $params[] = $data['is_active'];
        }
        
        if (isset($data['password'])) {
            $updateFields[] = "password_hash = ?";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        if (empty($updateFields)) {
            throw new \Exception("No fields to update");
        }
        
        $params[] = $id;
        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
        
        try {
            $this->database->beginTransaction();
            
            $this->database->execute($sql, $params);
            
            // Log the update
            $updatedUser = $this->getUserById($id);
            $this->logAuditEvent($_SESSION['user_id'] ?? null, 'users', $id, 'UPDATE', $oldValues, $updatedUser->toArray());
            
            $this->database->commit();
            
            return $updatedUser;
        } catch (\Exception $e) {
            $this->database->rollback();
            throw new \Exception("Failed to update user: " . $e->getMessage());
        }
    }
    
    public function deleteUser(int $id): bool
    {
        $user = $this->getUserById($id);
        
        if (!$user) {
            throw new \Exception("User not found");
        }
        
        // Check if user has created orders or dispatches
        $orderCount = $this->database->fetch("SELECT COUNT(*) as count FROM orders WHERE created_by = ?", [$id])['count'];
        $dispatchCount = $this->database->fetch("SELECT COUNT(*) as count FROM dispatches WHERE dispatched_by = ?", [$id])['count'];
        
        if ($orderCount > 0 || $dispatchCount > 0) {
            throw new \Exception("Cannot delete user with existing orders or dispatches. Deactivate instead.");
        }
        
        try {
            $this->database->beginTransaction();
            
            // Log the deletion
            $this->logAuditEvent($_SESSION['user_id'] ?? null, 'users', $id, 'DELETE', $user->toArray(), null);
            
            $result = $this->database->execute("DELETE FROM users WHERE id = ?", [$id]);
            
            $this->database->commit();
            
            return $result;
        } catch (\Exception $e) {
            $this->database->rollback();
            throw new \Exception("Failed to delete user: " . $e->getMessage());
        }
    }
    
    public function getAllRoles(): array
    {
        $sql = "SELECT id, name FROM roles ORDER BY name";
        return $this->database->fetchAll($sql);
    }
    
    private function validateRoleExists(int $roleId): void
    {
        $result = $this->database->fetch("SELECT id FROM roles WHERE id = ?", [$roleId]);
        
        if (!$result) {
            throw new \Exception("Role not found");
        }
    }
    
    private function logAuditEvent(?int $userId, string $tableName, int $recordId, string $action, ?array $oldValues, ?array $newValues): void
    {
        if (!$userId) {
            return; // Skip audit if no user context
        }
        
        $sql = "
            INSERT INTO audit_logs (user_id, table_name, record_id, action, old_values, new_values)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $userId,
            $tableName,
            $recordId,
            $action,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null
        ]);
    }
}




