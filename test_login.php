<?php
/**
 * Test login script to diagnose authentication issues
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "=== Login Diagnostic Test ===\n\n";

// Test database connection
try {
    $db = new Database();
    $conn = $db->getConnection();
    echo "✓ Database connection successful\n\n";
} catch (Exception $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Check if user exists
$email = 'admin@example.com';
$user = $db->fetch(
    "SELECT u.*, r.name as role_name 
     FROM users u 
     LEFT JOIN roles r ON u.role_id = r.id 
     WHERE u.email = ?",
    [$email]
);

if (!$user) {
    echo "✗ User not found: {$email}\n";
    exit(1);
}

echo "✓ User found:\n";
echo "  - ID: {$user['id']}\n";
echo "  - Email: {$user['email']}\n";
echo "  - Name: {$user['name']}\n";
echo "  - Role ID: {$user['role_id']}\n";
echo "  - Role Name: " . ($user['role_name'] ?? 'NULL') . "\n";
echo "  - Is Active: " . ($user['is_active'] ? 'Yes' : 'No') . "\n";
echo "  - Failed Attempts: {$user['failed_login_attempts']}\n";
echo "  - Locked Until: " . ($user['locked_until'] ?? 'NULL') . "\n";
echo "  - Password Hash: " . substr($user['password_hash'], 0, 20) . "...\n\n";

// Test password verification
$password = 'Passw0rd!';
$hash = $user['password_hash'];

echo "Testing password verification:\n";
echo "  - Password: {$password}\n";
echo "  - Hash: {$hash}\n";

$verified = password_verify($password, $hash);

if ($verified) {
    echo "  ✓ Password verification: SUCCESS\n\n";
} else {
    echo "  ✗ Password verification: FAILED\n\n";
    
    // Try generating a new hash
    echo "Generating new hash for 'Passw0rd!':\n";
    $newHash = password_hash($password, PASSWORD_BCRYPT);
    echo "  - New Hash: {$newHash}\n";
    
    // Test the new hash
    $newVerified = password_verify($password, $newHash);
    echo "  - New Hash Verification: " . ($newVerified ? 'SUCCESS' : 'FAILED') . "\n\n";
    
    echo "To fix, run this SQL:\n";
    echo "UPDATE users SET password_hash = '{$newHash}' WHERE email = '{$email}';\n";
}

// Check if account is locked
if ($user['failed_login_attempts'] >= 5) {
    echo "⚠ Account may be locked (failed attempts: {$user['failed_login_attempts']})\n";
    echo "Run: UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE email = '{$email}';\n\n";
}

echo "=== End Test ===\n";
