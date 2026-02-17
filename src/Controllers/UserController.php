<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\UserService;

class UserController
{
    private AuthService $authService;
    private UserService $userService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->userService = new UserService();
    }
    
    public function index(): void
    {
        header('Content-Type: application/json');
        
        // Check permissions - only admin can manage users
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        
        try {
            $users = $this->userService->getAllUsers();
            
            echo json_encode([
                'success' => true,
                'data' => array_map(fn($user) => $user->toArray(), $users)
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function show(int $id): void
    {
        header('Content-Type: application/json');
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        
        try {
            $targetUser = $this->userService->getUserById($id);
            
            if (!$targetUser) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $targetUser->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function create(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        
        // Get input data
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        // Validate required fields
        $requiredFields = ['email', 'password', 'name', 'role_id'];
        $errors = [];
        
        foreach ($requiredFields as $field) {
            if (empty($input[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        // Validate email format
        if (!empty($input['email']) && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }
        
        // Validate password strength
        if (!empty($input['password']) && strlen($input['password']) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }
        
        // Validate role ID
        if (!empty($input['role_id']) && (!is_numeric($input['role_id']) || $input['role_id'] <= 0)) {
            $errors[] = 'Valid role ID is required';
        }
        
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['error' => 'Validation failed', 'details' => $errors]);
            return;
        }
        
        try {
            $userData = [
                'email' => trim($input['email']),
                'password' => $input['password'],
                'name' => trim($input['name']),
                'role_id' => (int)$input['role_id'],
                'is_active' => isset($input['is_active']) ? (bool)$input['is_active'] : true
            ];
            
            $newUser = $this->userService->createUser($userData);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'User created successfully',
                'data' => $newUser->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function update(int $id): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        
        // Get input data
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }
        
        try {
            $updateData = [];
            
            // Only update provided fields
            if (isset($input['email']) && !empty($input['email'])) {
                if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid email format']);
                    return;
                }
                $updateData['email'] = trim($input['email']);
            }
            
            if (isset($input['name']) && !empty($input['name'])) {
                $updateData['name'] = trim($input['name']);
            }
            
            if (isset($input['role_id']) && is_numeric($input['role_id']) && $input['role_id'] > 0) {
                $updateData['role_id'] = (int)$input['role_id'];
            }
            
            if (isset($input['is_active'])) {
                $updateData['is_active'] = (bool)$input['is_active'];
            }
            
            if (isset($input['password']) && !empty($input['password'])) {
                if (strlen($input['password']) < 8) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Password must be at least 8 characters long']);
                    return;
                }
                $updateData['password'] = $input['password'];
            }
            
            if (empty($updateData)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                return;
            }
            
            $updatedUser = $this->userService->updateUser($id, $updateData);
            
            echo json_encode([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $updatedUser->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function delete(int $id): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        
        // Prevent self-deletion
        if ($user['id'] == $id) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot delete your own account']);
            return;
        }
        
        try {
            $success = $this->userService->deleteUser($id);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'User deleted successfully'
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
            }
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function roles(): void
    {
        header('Content-Type: application/json');
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        
        try {
            $roles = $this->userService->getAllRoles();
            
            echo json_encode([
                'success' => true,
                'data' => $roles
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}




