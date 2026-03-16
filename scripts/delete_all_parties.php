<?php
/**
 * One-off: Delete all parties and their dependent data (orders, dispatches, CRM data).
 * Use when clearing sample data before importing actual parties from CSV.
 *
 * Usage: php scripts/delete_all_parties.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Core\Database;

try {
    $db = new Database();
    $pdo = $db->getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    echo "Deleting all parties and dependent data...\n";

    // Order matters: delete orders first (parties are referenced by orders without CASCADE)
    // Deleting orders will CASCADE to dispatches and document_generation_logs
    $stmt = $pdo->query("SELECT COUNT(*) FROM orders");
    $orderCount = (int) $stmt->fetchColumn();
    $pdo->exec("DELETE FROM orders");
    echo "  Deleted {$orderCount} order(s).\n";

    $stmt = $pdo->query("SELECT COUNT(*) FROM parties");
    $partyCount = (int) $stmt->fetchColumn();
    $pdo->exec("DELETE FROM parties");
    echo "  Deleted {$partyCount} party(ies).\n";

    echo "Done. You can now import actual parties from CSV (e.g. Admin > Parties or CRM receivables import).\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
