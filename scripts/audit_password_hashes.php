<?php
/**
 * Reports accounts whose stored password hash cannot authenticate anything, i.e. accounts that
 * could only log in through the removed default-password acceptance. Read-only: it prints a
 * report and never writes. Reset the listed accounts from Admin > Users.
 *
 * Usage: php scripts/audit_password_hashes.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

$database = new Database();
$users = $database->fetchAll(
    "SELECT u.id, u.email, u.is_active, u.password_hash, r.name AS role_name
     FROM users u JOIN roles r ON r.id = u.role_id
     ORDER BY u.id"
);

$unusable = [];
$defaultPassword = [];

foreach ($users as $user) {
    $hash = (string)$user['password_hash'];
    // password_get_info() reports $2b$ bcrypt (written by the Node-era tooling) as unknown even
    // though password_verify() accepts it, so match the hash formats directly.
    $looksHashed = preg_match('/^\$(2[aby]\$\d{2}\$.{53}|argon2(id|i|d)?\$)/', $hash) === 1;

    if (!$looksHashed) {
        $unusable[] = $user;
        continue;
    }

    if (password_verify('Jld@Passw0rd!', $hash)) {
        $defaultPassword[] = $user;
    }
}

echo "Checked " . count($users) . " accounts.\n\n";

echo "Accounts with an unusable password hash (cannot log in, need a reset): " . count($unusable) . "\n";
foreach ($unusable as $user) {
    echo "  #{$user['id']} {$user['email']} ({$user['role_name']})"
        . ($user['is_active'] ? '' : ' [disabled]') . "\n";
}

echo "\nAccounts still on the documented default password: " . count($defaultPassword) . "\n";
foreach ($defaultPassword as $user) {
    echo "  #{$user['id']} {$user['email']} ({$user['role_name']})"
        . ($user['is_active'] ? '' : ' [disabled]') . "\n";
}

echo "\nNo changes were made.\n";

exit(count($unusable) > 0 ? 1 : 0);
