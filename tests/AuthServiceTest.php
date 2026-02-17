<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Services\AuthService;
use App\Core\Database;

class AuthServiceTest extends TestCase
{
    private AuthService $authService;
    private Database $database;
    
    protected function setUp(): void
    {
        // Load test environment
        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
        
        $this->database = new Database();
        $this->authService = new AuthService();
        
        // Start session for testing
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Start transaction for test isolation
        $this->database->beginTransaction();
    }
    
    protected function tearDown(): void
    {
        // Clear session
        session_destroy();
        
        // Rollback transaction to clean up test data
        $this->database->rollback();
    }
    
    public function testSuccessfulLogin(): void
    {
        // Use existing seed user
        $result = $this->authService->login('admin@example.com', 'Passw0rd!');
        
        $this->assertTrue($result['success']);
        $this->assertEquals('Login successful', $result['message']);
        $this->assertArrayHasKey('user', $result);
        $this->assertEquals('admin@example.com', $result['user']['email']);
        $this->assertEquals('admin', $result['user']['role']);
    }
    
    public function testLoginWithInvalidEmail(): void
    {
        $result = $this->authService->login('nonexistent@example.com', 'password');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid credentials', $result['message']);
    }
    
    public function testLoginWithInvalidPassword(): void
    {
        $result = $this->authService->login('admin@example.com', 'wrongpassword');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid credentials', $result['message']);
    }
    
    public function testLoginWithInactiveUser(): void
    {
        // First, deactivate the user
        $this->database->execute(
            "UPDATE users SET is_active = 0 WHERE email = ?",
            ['admin@example.com']
        );
        
        $result = $this->authService->login('admin@example.com', 'Passw0rd!');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Account is disabled', $result['message']);
    }
    
    public function testAccountLockoutAfterFailedAttempts(): void
    {
        // Make 5 failed login attempts
        for ($i = 0; $i < 5; $i++) {
            $result = $this->authService->login('admin@example.com', 'wrongpassword');
            $this->assertFalse($result['success']);
        }
        
        // 6th attempt should be locked
        $result = $this->authService->login('admin@example.com', 'wrongpassword');
        $this->assertFalse($result['success']);
        $this->assertStringContains('Account temporarily locked', $result['message']);
    }
    
    public function testSuccessfulLoginResetsFailedAttempts(): void
    {
        // Make some failed attempts
        for ($i = 0; $i < 3; $i++) {
            $this->authService->login('admin@example.com', 'wrongpassword');
        }
        
        // Successful login should reset counter
        $result = $this->authService->login('admin@example.com', 'Passw0rd!');
        $this->assertTrue($result['success']);
        
        // Check that failed attempts were reset
        $user = $this->database->fetch(
            "SELECT failed_login_attempts FROM users WHERE email = ?",
            ['admin@example.com']
        );
        $this->assertEquals(0, $user['failed_login_attempts']);
    }
    
    public function testIsAuthenticated(): void
    {
        // Should not be authenticated initially
        $this->assertFalse($this->authService->isAuthenticated());
        
        // Login
        $this->authService->login('admin@example.com', 'Passw0rd!');
        
        // Should now be authenticated
        $this->assertTrue($this->authService->isAuthenticated());
    }
    
    public function testGetCurrentUser(): void
    {
        // Should return null when not authenticated
        $this->assertNull($this->authService->getCurrentUser());
        
        // Login
        $this->authService->login('admin@example.com', 'Passw0rd!');
        
        // Should return user data
        $user = $this->authService->getCurrentUser();
        $this->assertNotNull($user);
        $this->assertEquals('admin@example.com', $user['email']);
        $this->assertEquals('admin', $user['role']);
    }
    
    public function testHasRole(): void
    {
        // Login as admin
        $this->authService->login('admin@example.com', 'Passw0rd!');
        
        $this->assertTrue($this->authService->hasRole('admin'));
        $this->assertFalse($this->authService->hasRole('entry'));
        $this->assertFalse($this->authService->hasRole('view'));
    }
    
    public function testHasAnyRole(): void
    {
        // Login as admin
        $this->authService->login('admin@example.com', 'Passw0rd!');
        
        $this->assertTrue($this->authService->hasAnyRole(['admin', 'entry']));
        $this->assertTrue($this->authService->hasAnyRole(['view', 'admin']));
        $this->assertFalse($this->authService->hasAnyRole(['entry', 'view']));
    }
    
    public function testLogout(): void
    {
        // Login first
        $this->authService->login('admin@example.com', 'Passw0rd!');
        $this->assertTrue($this->authService->isAuthenticated());
        
        // Logout
        $result = $this->authService->logout();
        $this->assertTrue($result);
        
        // Should no longer be authenticated
        $this->assertFalse($this->authService->isAuthenticated());
    }
    
    public function testPasswordResetToken(): void
    {
        $token = $this->authService->generatePasswordResetToken('admin@example.com');
        
        $this->assertNotNull($token);
        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
        
        // Verify token was stored in database
        $user = $this->database->fetch(
            "SELECT password_reset_token, password_reset_expires FROM users WHERE email = ?",
            ['admin@example.com']
        );
        
        $this->assertEquals($token, $user['password_reset_token']);
        $this->assertNotNull($user['password_reset_expires']);
    }
    
    public function testPasswordReset(): void
    {
        // Generate reset token
        $token = $this->authService->generatePasswordResetToken('admin@example.com');
        
        // Reset password
        $newPassword = 'NewPassword123!';
        $result = $this->authService->resetPassword($token, $newPassword);
        
        $this->assertTrue($result);
        
        // Verify old password no longer works
        $loginResult = $this->authService->login('admin@example.com', 'Passw0rd!');
        $this->assertFalse($loginResult['success']);
        
        // Verify new password works
        $loginResult = $this->authService->login('admin@example.com', $newPassword);
        $this->assertTrue($loginResult['success']);
    }
    
    public function testPasswordResetWithInvalidToken(): void
    {
        $result = $this->authService->resetPassword('invalid-token', 'NewPassword123!');
        $this->assertFalse($result);
    }
}




