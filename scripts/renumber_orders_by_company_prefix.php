<?php
/**
 * One-time: assign PREFIX-0001… per company in order_date, id order.
 * Idempotent via migration_flags.order_prefix_renumber_v1
 *
 * Run: php scripts/renumber_orders_by_company_prefix.php
 * Also invoked from scripts/migrate.php after SQL migrations.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Support\OrderPrefix;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$database = new Database();
$pdo = $database->getConnection();

$flag = 'order_prefix_renumber_v1';
$existing = $database->fetch('SELECT name FROM migration_flags WHERE name = ?', [$flag]);
if ($existing) {
    echo "Skip renumber: flag {$flag} already applied.\n";
    exit(0);
}

$col = $database->fetch(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'order_prefix'"
);
if (!$col) {
    echo "Skip renumber: companies.order_prefix column missing (run SQL migrations first).\n";
    exit(0);
}

$companies = $database->fetchAll('SELECT id, name, order_prefix FROM companies ORDER BY id ASC');
if ($companies === []) {
    echo "No companies — nothing to renumber.\n";
    $database->execute('INSERT INTO migration_flags (name) VALUES (?)', [$flag]);
    exit(0);
}

$pdo->beginTransaction();
try {
    // Ensure every company has a prefix
    $used = [];
    foreach ($companies as $c) {
        $prefix = strtoupper(trim((string)($c['order_prefix'] ?? '')));
        if ($prefix === '') {
            $prefix = OrderPrefix::suggestFromName((string)$c['name']);
            $base = $prefix;
            $n = 2;
            while (isset($used[$prefix])) {
                $prefix = substr($base, 0, 16) . $n;
                $n++;
            }
            $database->execute('UPDATE companies SET order_prefix = ? WHERE id = ?', [$prefix, (int)$c['id']]);
            $c['order_prefix'] = $prefix;
        }
        $used[$prefix] = true;
    }

    // Reload
    $companies = $database->fetchAll('SELECT id, name, order_prefix FROM companies ORDER BY id ASC');

    // Stage 1: move all order_no to temp unique values to free UNIQUE index
    $database->execute("UPDATE orders SET order_no = CONCAT('__TMP_', id) WHERE order_no NOT LIKE '__TMP_%'");

    foreach ($companies as $c) {
        $companyId = (int)$c['id'];
        $prefix = strtoupper(trim((string)$c['order_prefix']));
        $orders = $database->fetchAll(
            'SELECT id FROM orders WHERE company_id = ? ORDER BY order_date ASC, id ASC',
            [$companyId]
        );
        $seq = 0;
        foreach ($orders as $row) {
            $seq++;
            $orderNo = OrderPrefix::format($prefix, $seq);
            $database->execute('UPDATE orders SET order_no = ? WHERE id = ?', [$orderNo, (int)$row['id']]);
        }
        echo "Company {$companyId} ({$prefix}): {$seq} order(s) renumbered.\n";
    }

    $database->execute('INSERT INTO migration_flags (name) VALUES (?)', [$flag]);
    $pdo->commit();
    echo "Renumber complete; flag {$flag} set.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Renumber failed: ' . $e->getMessage() . "\n");
    exit(1);
}
