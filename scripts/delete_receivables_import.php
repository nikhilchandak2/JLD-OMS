<?php
/**
 * Delete all CRM receivable entries (data imported from receivables CSV).
 * Does not delete parties – only the invoice/payment entries in crm_receivable_entries.
 *
 * Usage: php scripts/delete_receivables_import.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Core\Database;

try {
    $db = new Database();
    $pdo = $db->getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    echo "Deleting receivables data (CSV import)...\n";

    $stmt = $pdo->query("SELECT COUNT(*) FROM crm_receivable_entries");
    $count = (int) $stmt->fetchColumn();
    $pdo->exec("DELETE FROM crm_receivable_entries");
    echo "  Deleted {$count} receivable entr" . ($count === 1 ? 'y' : 'ies') . ".\n";

    echo "Done. Parties are unchanged. Re-import receivables from CSV if needed.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
