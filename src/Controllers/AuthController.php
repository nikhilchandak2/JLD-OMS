<?php

namespace App\Controllers;

use App\Services\AuthService;

class AuthController
{
    private AuthService $authService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
    }
    
    public function login(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Get input data
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        
        // Validate input
        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email and password are required']);
            return;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid email format']);
            return;
        }
        
        // Attempt login
        $result = $this->authService->login($email, $password);
        
        if ($result['success']) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => $result['message'],
                'user' => $result['user']
            ]);
        } else {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => $result['message']
            ]);
        }
    }
    
    public function logout(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $this->authService->logout();
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }
    
    public function me(): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        
        if ($user) {
            echo json_encode([
                'success' => true,
                'user' => $user
            ]);
        } else {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Not authenticated'
            ]);
        }
    }
    
    public function requestPasswordReset(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $email = trim($input['email'] ?? '');
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid email is required']);
            return;
        }
        
        $token = $this->authService->generatePasswordResetToken($email);
        
        // Always return success for security (don't reveal if email exists)
        echo json_encode([
            'success' => true,
            'message' => 'If the email exists, a password reset link has been sent'
        ]);
        
        // In a real application, you would send an email with the token here
        // For demo purposes, we'll log it
        if ($token) {
            error_log("Password reset token for {$email}: {$token}");
        }
    }
    
    public function resetPassword(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $token = $input['token'] ?? '';
        $password = $input['password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';
        
        // Validate input
        if (empty($token) || empty($password) || empty($confirmPassword)) {
            http_response_code(400);
            echo json_encode(['error' => 'Token, password, and confirmation are required']);
            return;
        }
        
        if ($password !== $confirmPassword) {
            http_response_code(400);
            echo json_encode(['error' => 'Passwords do not match']);
            return;
        }
        
        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 8 characters long']);
            return;
        }
        
        // Reset password
        $success = $this->authService->resetPassword($token, $password);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Password reset successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid or expired reset token'
            ]);
        }
    }
}




