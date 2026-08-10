<?php

namespace Tests;

use App\Services\AuthService;

class AuthServiceTest extends DatabaseTestCase
{
    private AuthService $authService;
    private array $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authService = new AuthService();
        $this->user = $this->createUser('admin');
    }

    public function testSuccessfulLogin(): void
    {
        $result = $this->authService->login($this->user['email'], $this->user['password']);

        $this->assertTrue($result['success']);
        $this->assertEquals('Login successful', $result['message']);
        $this->assertArrayHasKey('user', $result);
        $this->assertEquals($this->user['email'], $result['user']['email']);
        $this->assertEquals('admin', $result['user']['role']);
    }

    public function testLoginWithInvalidEmail(): void
    {
        $result = $this->authService->login('nonexistent@jldminerals.com', 'password');

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid credentials', $result['message']);
    }

    public function testLoginWithInvalidPassword(): void
    {
        $result = $this->authService->login($this->user['email'], 'wrongpassword');

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid credentials', $result['message']);
    }

    public function testDefaultPasswordDoesNotAuthenticateAnAccountThatDoesNotUseIt(): void
    {
        $result = $this->authService->login($this->user['email'], 'Jld@Passw0rd!');

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid credentials', $result['message']);
    }

    public function testAccountStoringTheDefaultPasswordStillAuthenticates(): void
    {
        $seeded = $this->createUser('entry', 'Jld@Passw0rd!');

        $result = $this->authService->login($seeded['email'], 'Jld@Passw0rd!');

        $this->assertTrue($result['success']);
        $this->assertEquals('entry', $result['user']['role']);
    }

    public function testRetiredDefaultPasswordNeverAuthenticates(): void
    {
        $legacy = $this->createUser('entry', 'Passw0rd!');

        $result = $this->authService->login($legacy['email'], 'Passw0rd!');

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid credentials', $result['message']);
    }

    public function testFailedDefaultPasswordAttemptCountsTowardsLockout(): void
    {
        $this->authService->login($this->user['email'], 'Jld@Passw0rd!');

        $row = $this->database->fetch(
            "SELECT failed_login_attempts FROM users WHERE email = ?",
            [$this->user['email']]
        );
        $this->assertEquals(1, $row['failed_login_attempts']);
    }

    public function testLoginWithInactiveUser(): void
    {
        $inactive = $this->createUser('entry', 'Test@Passw0rd!', false);

        $result = $this->authService->login($inactive['email'], $inactive['password']);

        $this->assertFalse($result['success']);
        $this->assertEquals('Account is disabled', $result['message']);
    }

    public function testAccountLockoutAfterFailedAttempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $result = $this->authService->login($this->user['email'], 'wrongpassword');
            $this->assertFalse($result['success']);
        }

        $result = $this->authService->login($this->user['email'], 'wrongpassword');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Account temporarily locked', $result['message']);
    }

    public function testLockedAccountRejectsTheCorrectPassword(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->authService->login($this->user['email'], 'wrongpassword');
        }

        $result = $this->authService->login($this->user['email'], $this->user['password']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Account temporarily locked', $result['message']);
    }

    public function testSuccessfulLoginResetsFailedAttempts(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->authService->login($this->user['email'], 'wrongpassword');
        }

        $result = $this->authService->login($this->user['email'], $this->user['password']);
        $this->assertTrue($result['success']);

        $row = $this->database->fetch(
            "SELECT failed_login_attempts FROM users WHERE email = ?",
            [$this->user['email']]
        );
        $this->assertEquals(0, $row['failed_login_attempts']);
    }

    public function testIsAuthenticated(): void
    {
        $this->assertFalse($this->authService->isAuthenticated());

        $this->authService->login($this->user['email'], $this->user['password']);

        $this->assertTrue($this->authService->isAuthenticated());
    }

    public function testGetCurrentUser(): void
    {
        $this->assertNull($this->authService->getCurrentUser());

        $this->authService->login($this->user['email'], $this->user['password']);

        $current = $this->authService->getCurrentUser();
        $this->assertNotNull($current);
        $this->assertEquals($this->user['email'], $current['email']);
        $this->assertEquals('admin', $current['role']);
    }

    public function testHasRole(): void
    {
        $this->authService->login($this->user['email'], $this->user['password']);

        $this->assertTrue($this->authService->hasRole('admin'));
        $this->assertFalse($this->authService->hasRole('entry'));
        $this->assertFalse($this->authService->hasRole('view'));
    }

    public function testHasAnyRole(): void
    {
        $this->authService->login($this->user['email'], $this->user['password']);

        $this->assertTrue($this->authService->hasAnyRole(['admin', 'entry']));
        $this->assertTrue($this->authService->hasAnyRole(['view', 'admin']));
        $this->assertFalse($this->authService->hasAnyRole(['entry', 'view']));
    }

    public function testRoleChecksFailWhenNotLoggedIn(): void
    {
        $this->assertFalse($this->authService->hasRole('admin'));
        $this->assertFalse($this->authService->hasAnyRole(['admin', 'entry']));
    }

    public function testLogout(): void
    {
        $this->authService->login($this->user['email'], $this->user['password']);
        $this->assertTrue($this->authService->isAuthenticated());

        $result = $this->authService->logout();
        $this->assertTrue($result);

        $this->assertFalse($this->authService->isAuthenticated());
    }

    public function testPasswordResetToken(): void
    {
        $token = $this->authService->generatePasswordResetToken($this->user['email']);

        $this->assertNotNull($token);
        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars

        $row = $this->database->fetch(
            "SELECT password_reset_token, password_reset_expires FROM users WHERE email = ?",
            [$this->user['email']]
        );

        $this->assertEquals($token, $row['password_reset_token']);
        $this->assertNotNull($row['password_reset_expires']);
    }

    public function testPasswordResetTokenForUnknownEmail(): void
    {
        $this->assertNull($this->authService->generatePasswordResetToken('nobody@jldminerals.com'));
    }

    public function testPasswordReset(): void
    {
        $token = $this->authService->generatePasswordResetToken($this->user['email']);

        $newPassword = 'NewPassword123!';
        $this->assertTrue($this->authService->resetPassword($token, $newPassword));

        $oldPasswordLogin = $this->authService->login($this->user['email'], $this->user['password']);
        $this->assertFalse($oldPasswordLogin['success']);

        $newPasswordLogin = $this->authService->login($this->user['email'], $newPassword);
        $this->assertTrue($newPasswordLogin['success']);
    }

    public function testPasswordResetWithInvalidToken(): void
    {
        $this->assertFalse($this->authService->resetPassword('invalid-token', 'NewPassword123!'));
    }

    public function testPasswordResetTokenIsSingleUse(): void
    {
        $token = $this->authService->generatePasswordResetToken($this->user['email']);

        $this->assertTrue($this->authService->resetPassword($token, 'NewPassword123!'));
        $this->assertFalse($this->authService->resetPassword($token, 'AnotherPassword123!'));
    }
}
