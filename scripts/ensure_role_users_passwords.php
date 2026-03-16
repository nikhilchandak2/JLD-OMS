<?php
/**
 * Set password to Passw0rd! for all role users (admin, order, accounts, operator, crm).
 * Run after migration 017: php scripts/ensure_role_users_passwords.php
 * Then you can test each user on http://localhost:8000
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Core\Database;

$password = 'Passw0rd!';
$emails = [
    'admin@example.com',
    'order@example.com',
    'accounts@example.com',
    'operator@example.com',
    'crm@example.com',
];

try {
    $db = new Database();
    $pdo = $db->getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
    foreach ($emails as $email) {
        $stmt->execute([$hash, $email]);
        if ($stmt->rowCount() > 0) {
            echo "Updated: {$email}\n";
        } else {
            echo "Skipped (not found): {$email}\n";
        }
    }

    echo "\nLogin at http://localhost:8000 with password: {$password}\n\n";
    echo "| Email                  | Role              | Access                          |\n";
    echo "|------------------------|-------------------|----------------------------------|\n";
    echo "| admin@example.com      | Admin             | Full system                      |\n";
    echo "| order@example.com      | Order Processing  | Orders, Reports, Export          |\n";
    echo "| accounts@example.com   | Accounts          | Party management, Products       |\n";
    echo "| operator@example.com   | Operator          | Vehicles, Tracking, Trips, etc.  |\n";
    echo "| crm@example.com        | CRM               | CRM Dashboard, Funnel             |\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
