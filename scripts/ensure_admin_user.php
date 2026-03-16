<?php
/**
 * Ensure an admin user exists so you can log in on localhost.
 * Run once: php scripts/ensure_admin_user.php
 *
 * Creates or updates admin@example.com with password: Passw0rd!
 * (Resets password to this so login works even if seed hash was wrong.)
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Core\Database;

$email = 'admin@example.com';
$password = 'Passw0rd!';
$name = 'System Administrator';
$roleName = 'admin';

try {
    $db = new Database();
    $pdo = $db->getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    // Ensure roles exist
    $pdo->exec("INSERT IGNORE INTO roles (id, name) VALUES (1, 'entry'), (2, 'view'), (3, 'admin')");

    $roleId = 3; // admin
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->prepare("UPDATE users SET password_hash = ?, name = ?, role_id = ?, is_active = 1, failed_login_attempts = 0, locked_until = NULL WHERE email = ?")
            ->execute([$hash, $name, $roleId, $email]);
        echo "Updated existing user: {$email}\n";
    } else {
        $pdo->prepare("INSERT INTO users (email, password_hash, name, role_id, is_active) VALUES (?, ?, ?, ?, 1)")
            ->execute([$email, $hash, $name, $roleId]);
        echo "Created user: {$email}\n";
    }

    echo "You can log in at http://localhost:8000/login with:\n";
    echo "  Email:    {$email}\n";
    echo "  Password: {$password}\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
