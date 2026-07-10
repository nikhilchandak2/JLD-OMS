<?php
/**
 * Ensure an admin user exists so you can log in on localhost.
 * Run once: php scripts/ensure_admin_user.php
 *
 * Creates or updates admin@jldminerals.com with password: Jld@Passw0rd!
 * (Resets password to this so login works even if seed hash was wrong.)
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Core\Database;

$email = 'admin@jldminerals.com';
$password = 'Jld@Passw0rd!';
$name = 'System Administrator';
$roleName = 'admin';

try {
    $db = new Database();
    $pdo = $db->getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    // Ensure core roles exist (do not use fixed IDs — migration 017 may have used 1–4 for other roles)
    foreach (['entry', 'view', 'admin'] as $roleName) {
        $pdo->prepare('INSERT IGNORE INTO roles (name) VALUES (?)')->execute([$roleName]);
    }

    $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
    $roleStmt->execute(['admin']);
    $roleId = $roleStmt->fetchColumn();
    if ($roleId === false) {
        throw new \RuntimeException("Role 'admin' not found after insert.");
    }
    $roleId = (int) $roleId;

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
