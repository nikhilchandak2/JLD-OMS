<?php

namespace App\Services;

use App\Core\Database;
use App\Models\User;

class AuthService
{
    private Database $database;
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 900; // 15 minutes
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        // Check if user exists and is not locked
        $user = $this->resolveUserForLogin($email);
        
        if (!$user) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        // Check if account is locked
        if ($this->isAccountLocked($user)) {
            return ['success' => false, 'message' => 'Account temporarily locked due to too many failed attempts'];
        }
        
        // Verify password
        if (!$this->verifyPasswordWithLegacySupport($password, $user['password_hash'])) {
            $this->recordFailedAttempt($user['id']);
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        // Check if user is active
        if (!$user['is_active']) {
            return ['success' => false, 'message' => 'Account is disabled'];
        }

        // Bring older user records to the new domain and default password baseline on successful login.
        $user = $this->upgradeUserCredentialsIfNeeded($user, $email, $password);
        
        // Reset failed attempts on successful login
        $this->resetFailedAttempts($user['id']);
        
        // Create session
        $this->createSession($user);
        
        return [
            'success' => true, 
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role_name']
            ]
        ];
    }
    
    public function logout(): bool
    {
        if (isset($_SESSION['user_id'])) {
            // Remove session from database
            $this->database->execute(
                "DELETE FROM sessions WHERE user_id = ?",
                [$_SESSION['user_id']]
            );
        }
        
        // Destroy session
        session_destroy();
        session_start();
        session_regenerate_id(true);
        
        return true;
    }
    
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
    }
    
    public function getCurrentUser(): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'email' => $_SESSION['user_email'],
            'name' => $_SESSION['user_name'],
            'role' => $_SESSION['user_role']
        ];
    }
    
    public function hasRole(string $role): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        return $_SESSION['user_role'] === $role;
    }
    
    public function hasAnyRole(array $roles): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        return in_array($_SESSION['user_role'], $roles);
    }
    
    public function generatePasswordResetToken(string $email): ?string
    {
        $user = $this->getUserByEmail($email);
        
        if (!$user) {
            return null;
        }
        
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $this->database->execute(
            "UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?",
            [$token, $expires, $user['id']]
        );
        
        return $token;
    }
    
    public function resetPassword(string $token, string $newPassword): bool
    {
        $user = $this->database->fetch(
            "SELECT id FROM users WHERE password_reset_token = ? AND password_reset_expires > NOW()",
            [$token]
        );
        
        if (!$user) {
            return false;
        }
        
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $this->database->execute(
            "UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?",
            [$hashedPassword, $user['id']]
        );
        
        return true;
    }
    
    private function getUserByEmail(string $email): ?array
    {
        return $this->database->fetch(
            "SELECT u.*, r.name as role_name 
             FROM users u 
             JOIN roles r ON u.role_id = r.id 
             WHERE u.email = ?",
            [$email]
        );
    }

    private function resolveUserForLogin(string $email): ?array
    {
        $user = $this->getUserByEmail($email);
        if ($user) {
            return $user;
        }

        if (str_ends_with($email, '@jldminerals.com')) {
            $legacyEmail = preg_replace('/@jldminerals\.com$/', '@example.com', $email);
            if ($legacyEmail) {
                return $this->getUserByEmail($legacyEmail);
            }
        }

        if (str_ends_with($email, '@example.com')) {
            $newEmail = preg_replace('/@example\.com$/', '@jldminerals.com', $email);
            if ($newEmail) {
                return $this->getUserByEmail($newEmail);
            }
        }

        return null;
    }

    private function verifyPasswordWithLegacySupport(string $password, string $storedHash): bool
    {
        if (password_verify($password, $storedHash)) {
            return true;
        }

        // Transitional support: allow new default password even if the DB still has legacy default hash.
        if ($password === 'Jld@Passw0rd!' && password_verify('Passw0rd!', $storedHash)) {
            return true;
        }

        return false;
    }

    private function upgradeUserCredentialsIfNeeded(array $user, string $requestedEmail, string $providedPassword): array
    {
        $updates = [];

        $requestedDomainEmail = null;
        if (str_ends_with($requestedEmail, '@jldminerals.com')) {
            $requestedDomainEmail = $requestedEmail;
        } elseif (str_ends_with($user['email'], '@example.com')) {
            $requestedDomainEmail = preg_replace('/@example\.com$/', '@jldminerals.com', $user['email']) ?: null;
        }

        if ($requestedDomainEmail && $requestedDomainEmail !== $user['email']) {
            $emailInUse = $this->database->fetch("SELECT id FROM users WHERE email = ? AND id <> ?", [
                $requestedDomainEmail,
                $user['id']
            ]);
            if (!$emailInUse) {
                $updates['email'] = $requestedDomainEmail;
            }
        }

        if ($providedPassword === 'Jld@Passw0rd!' && !password_verify('Jld@Passw0rd!', $user['password_hash'])) {
            $updates['password_hash'] = password_hash('Jld@Passw0rd!', PASSWORD_DEFAULT);
        }

        if (empty($updates)) {
            if (!isset($updates['email']) && str_ends_with($user['email'], '@example.com') && str_ends_with($requestedEmail, '@jldminerals.com')) {
                $user['email'] = $requestedEmail;
            }
            return $user;
        }

        $setClauses = [];
        $params = [];
        foreach ($updates as $field => $value) {
            $setClauses[] = $field . ' = ?';
            $params[] = $value;
        }
        $params[] = $user['id'];
        $this->database->execute(
            "UPDATE users SET " . implode(', ', $setClauses) . " WHERE id = ?",
            $params
        );

        if (isset($updates['email'])) {
            $user['email'] = $updates['email'];
        }
        if (isset($updates['password_hash'])) {
            $user['password_hash'] = $updates['password_hash'];
        }

        return $user;
    }
    
    private function isAccountLocked(array $user): bool
    {
        if ($user['failed_login_attempts'] >= self::MAX_LOGIN_ATTEMPTS) {
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                return true;
            }
        }
        
        return false;
    }
    
    private function recordFailedAttempt(int $userId): void
    {
        $this->database->execute(
            "UPDATE users SET 
                failed_login_attempts = failed_login_attempts + 1,
                locked_until = CASE 
                    WHEN failed_login_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? SECOND)
                    ELSE locked_until 
                END
             WHERE id = ?",
            [self::MAX_LOGIN_ATTEMPTS, self::LOCKOUT_DURATION, $userId]
        );
    }
    
    private function resetFailedAttempts(int $userId): void
    {
        $this->database->execute(
            "UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?",
            [$userId]
        );
    }
    
    private function createSession(array $user): void
    {
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role_name'];
        $_SESSION['login_time'] = time();
        
        // Store session in database
        $sessionId = session_id();
        $expiresAt = date('Y-m-d H:i:s', time() + ini_get('session.gc_maxlifetime'));
        
        $this->database->execute(
            "INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE user_id = ?, expires_at = ?",
            [$sessionId, $user['id'], $expiresAt, $user['id'], $expiresAt]
        );
    }
}




