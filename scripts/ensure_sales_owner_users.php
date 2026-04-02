<?php
/**
 * Create 5 sales-owner test users (role: crm) with unique emails and passwords.
 *
 * Run after migrations so roles/users tables exist:
 *   php scripts/ensure_sales_owner_users.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Core\Database;

$roleName = 'crm';

$users = [
    ['email' => 'salesowner1@example.com', 'name' => 'Sales Owner 1', 'password' => 'Passw0rd!1'],
    ['email' => 'salesowner2@example.com', 'name' => 'Sales Owner 2', 'password' => 'Passw0rd!2'],
    ['email' => 'salesowner3@example.com', 'name' => 'Sales Owner 3', 'password' => 'Passw0rd!3'],
    ['email' => 'salesowner4@example.com', 'name' => 'Sales Owner 4', 'password' => 'Passw0rd!4'],
    ['email' => 'salesowner5@example.com', 'name' => 'Sales Owner 5', 'password' => 'Passw0rd!5'],
];

try {
    $db = new Database();
    $pdo = $db->getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = ? LIMIT 1");
    $roleStmt->execute([$roleName]);
    $roleId = $roleStmt->fetchColumn();
    if (!$roleId) {
        throw new \RuntimeException("Role not found: {$roleName}");
    }

    foreach ($users as $u) {
        $hash = password_hash($u['password'], PASSWORD_DEFAULT);

        // Upsert user by email and reset lockout fields for easier testing.
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, name, role_id, is_active, failed_login_attempts, locked_until)
            VALUES (?, ?, ?, ?, 1, 0, NULL)
            ON DUPLICATE KEY UPDATE
                password_hash = VALUES(password_hash),
                name = VALUES(name),
                role_id = VALUES(role_id),
                is_active = 1,
                failed_login_attempts = 0,
                locked_until = NULL
        ");

        $stmt->execute([
            $u['email'],
            $hash,
            $u['name'],
            (int)$roleId,
        ]);

        echo "Ready: {$u['email']} (password: {$u['password']})\n";
    }

    echo "\nAll sales owners are ready.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

