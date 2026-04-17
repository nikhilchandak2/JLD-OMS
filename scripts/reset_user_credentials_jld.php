<?php
/**
 * Reset user emails from @example.com to @jldminerals.com
 * and reset password to Jld@Passw0rd! for affected users.
 *
 * Usage:
 *   php scripts/reset_user_credentials_jld.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Core\Database;

$newPassword = 'Jld@Passw0rd!';
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

try {
    $db = new Database();
    $pdo = $db->getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    $pdo->beginTransaction();

    $rows = $pdo->query("SELECT id, email FROM users WHERE email LIKE '%@example.com'")->fetchAll(\PDO::FETCH_ASSOC);
    $updatedEmailCount = 0;
    $skippedDuplicateCount = 0;
    $affectedIds = [];

    $findByEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $updateEmail = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $oldEmail = (string)$row['email'];
        $newEmail = preg_replace('/@example\.com$/i', '@jldminerals.com', $oldEmail) ?? $oldEmail;

        $findByEmail->execute([$newEmail]);
        $existing = $findByEmail->fetch(\PDO::FETCH_ASSOC);
        if ($existing && (int)$existing['id'] !== $id) {
            $skippedDuplicateCount++;
            continue;
        }

        if ($newEmail !== $oldEmail) {
            $updateEmail->execute([$newEmail, $id]);
            $updatedEmailCount++;
        }
        $affectedIds[] = $id;
    }

    $resetPasswordStmt = $pdo->prepare("
        UPDATE users
        SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL, is_active = 1
        WHERE email LIKE '%@jldminerals.com'
    ");
    $resetPasswordStmt->execute([$newHash]);
    $passwordResetCount = $resetPasswordStmt->rowCount();

    $pdo->commit();

    echo "Email updates: {$updatedEmailCount}\n";
    echo "Skipped due to duplicate target email: {$skippedDuplicateCount}\n";
    echo "Password resets (@jldminerals.com): {$passwordResetCount}\n";
    echo "New default password: {$newPassword}\n";
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

