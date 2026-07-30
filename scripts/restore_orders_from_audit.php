<?php
/**
 * Restore orders deleted via the OMS portal from audit_logs snapshots.
 *
 * Portal order deletes store the full order JSON in audit_logs.old_values.
 * Cascaded dispatches are NOT in that snapshot and cannot be restored here;
 * after restoring orders, use Busy Remap / re-upload for invoices that should
 * attach to the restored (earlier) orders.
 *
 * Usage (on the OMS server, from project root):
 *
 *   # Preview deleted orders for these parties
 *   php scripts/restore_orders_from_audit.php --list
 *
 *   # Restore all matching deletes that are not already present (by order_no)
 *   php scripts/restore_orders_from_audit.php --apply
 *
 *   # Restore one audit row
 *   php scripts/restore_orders_from_audit.php --apply --audit-id=12345
 *
 * Optional:
 *   --parties=ROLLZA,LANDGRACE,BLUEZONE,SIMOLEX
 *   --dry-run   (with --apply: show what would be inserted, write nothing)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$opts = getopt('', ['list', 'apply', 'dry-run', 'audit-id:', 'parties:']);
$doList = array_key_exists('list', $opts);
$doApply = array_key_exists('apply', $opts);
$dryRun = array_key_exists('dry-run', $opts);
$auditId = isset($opts['audit-id']) ? (int)$opts['audit-id'] : 0;
$partyFilter = isset($opts['parties'])
    ? array_values(array_filter(array_map('trim', explode(',', (string)$opts['parties']))))
    : ['ROLLZA', 'LANDGRACE', 'BLUEZONE', 'SIMOLEX'];

if (!$doList && !$doApply) {
    fwrite(STDERR, "Use --list and/or --apply. See script header for examples.\n");
    exit(1);
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'OMS';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

$pdo = new PDO(
    "mysql:host={$host};dbname={$dbName};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$likeParts = [];
$params = [];
foreach ($partyFilter as $name) {
    $likeParts[] = 'a.old_values LIKE ?';
    $params[] = '%' . $name . '%';
}
$partySql = $likeParts !== [] ? '(' . implode(' OR ', $likeParts) . ')' : '1=1';

$sql = "
    SELECT a.id AS audit_id, a.record_id AS old_order_id, a.user_id, a.created_at AS deleted_at, a.old_values
    FROM audit_logs a
    WHERE a.table_name = 'orders'
      AND a.action = 'DELETE'
      AND {$partySql}
";
if ($auditId > 0) {
    $sql .= ' AND a.id = ?';
    $params[] = $auditId;
}
$sql .= ' ORDER BY a.id ASC';

$rows = $pdo->prepare($sql);
$rows->execute($params);
$candidates = $rows->fetchAll();

$hasBilling = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'billing_party_id'"
)->fetchColumn();

echo "Database: {$dbName}@{$host}\n";
echo "Party filter: " . implode(', ', $partyFilter) . "\n";
echo "Deleted order audit rows found: " . count($candidates) . "\n\n";

if ($candidates === []) {
    echo "No matching DELETE audits found.\n";
    echo "If orders were removed by SQL/scripts (not portal delete), they cannot be restored from audit_logs.\n";
    exit(0);
}

$parsed = [];
foreach ($candidates as $row) {
    $data = json_decode((string)$row['old_values'], true);
    if (!is_array($data) || empty($data['order_no'])) {
        echo "SKIP audit #{$row['audit_id']}: invalid old_values JSON\n";
        continue;
    }
    $parsed[] = [
        'audit_id' => (int)$row['audit_id'],
        'old_order_id' => (int)$row['old_order_id'],
        'deleted_at' => $row['deleted_at'],
        'deleted_by' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
        'data' => $data,
    ];
}

if ($doList) {
    echo str_pad('audit', 8) . str_pad('old_id', 8) . str_pad('order_no', 16)
        . str_pad('order_date', 12) . str_pad('party', 28) . str_pad('qty', 6)
        . "deleted_at\n";
    echo str_repeat('-', 100) . "\n";
    foreach ($parsed as $item) {
        $d = $item['data'];
        $exists = orderNoExists($pdo, (string)$d['order_no']);
        $flag = $exists ? ' [EXISTS]' : '';
        echo str_pad((string)$item['audit_id'], 8)
            . str_pad((string)$item['old_order_id'], 8)
            . str_pad((string)$d['order_no'], 16)
            . str_pad((string)($d['order_date'] ?? ''), 12)
            . str_pad(substr((string)($d['party_name'] ?? ''), 0, 26), 28)
            . str_pad((string)($d['order_qty_trucks'] ?? ''), 6)
            . $item['deleted_at'] . $flag . "\n";
    }
    echo "\n";
}

if (!$doApply) {
    exit(0);
}

$restored = 0;
$skipped = 0;

foreach ($parsed as $item) {
    $d = $item['data'];
    $orderNo = trim((string)$d['order_no']);
    $partyId = (int)($d['party_id'] ?? 0);
    $productId = (int)($d['product_id'] ?? 0);
    $companyId = (int)($d['company_id'] ?? 0);

    if ($orderNo === '' || $partyId <= 0 || $productId <= 0 || $companyId <= 0) {
        echo "SKIP {$orderNo}: missing party/product/company in snapshot\n";
        $skipped++;
        continue;
    }

    if (orderNoExists($pdo, $orderNo)) {
        echo "SKIP {$orderNo}: already exists — not inserting duplicate\n";
        $skipped++;
        continue;
    }

    if (!partyExists($pdo, $partyId) || !productExists($pdo, $productId) || !companyExists($pdo, $companyId)) {
        echo "SKIP {$orderNo}: party/product/company id missing in DB\n";
        $skipped++;
        continue;
    }

    $createdBy = (int)($d['created_by'] ?? 0);
    if ($createdBy <= 0 || !userExists($pdo, $createdBy)) {
        $createdBy = 1;
    }

    $status = in_array(($d['status'] ?? 'pending'), ['pending', 'partial', 'completed', 'cancelled'], true)
        ? $d['status']
        : 'pending';

    echo ($dryRun ? '[DRY-RUN] Would restore' : 'Restoring')
        . " {$orderNo} (audit #{$item['audit_id']}, was order id {$item['old_order_id']})"
        . " party={$d['party_name']} date={$d['order_date']} qty={$d['order_qty_trucks']}\n";

    if ($dryRun) {
        $restored++;
        continue;
    }

    if ($hasBilling) {
        $ins = $pdo->prepare("
            INSERT INTO orders (
                company_id, order_no, order_date, scheduled_dispatch_date, product_id,
                order_qty_trucks, order_qty_mode, order_weight_tons, tons_per_truck,
                party_id, bill_to_other_party, billing_party_id, status, priority,
                is_recurring, delivery_frequency_days, trucks_per_delivery, total_deliveries,
                created_by
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?
            )
        ");
        $ins->execute([
            $companyId,
            $orderNo,
            $d['order_date'] ?? date('Y-m-d'),
            !empty($d['scheduled_dispatch_date']) ? $d['scheduled_dispatch_date'] : null,
            $productId,
            (int)($d['order_qty_trucks'] ?? 0),
            $d['order_qty_mode'] ?? 'trucks',
            $d['order_weight_tons'] ?? null,
            isset($d['tons_per_truck']) ? (float)$d['tons_per_truck'] : 40.0,
            $partyId,
            !empty($d['bill_to_other_party']) ? 1 : 0,
            !empty($d['billing_party_id']) ? (int)$d['billing_party_id'] : null,
            $status,
            $d['priority'] ?? 'normal',
            !empty($d['is_recurring']) ? 1 : 0,
            $d['delivery_frequency_days'] ?? null,
            $d['trucks_per_delivery'] ?? null,
            $d['total_deliveries'] ?? null,
            $createdBy,
        ]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO orders (
                company_id, order_no, order_date, scheduled_dispatch_date, product_id,
                order_qty_trucks, order_qty_mode, order_weight_tons, tons_per_truck,
                party_id, status, priority,
                is_recurring, delivery_frequency_days, trucks_per_delivery, total_deliveries,
                created_by
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?
            )
        ");
        $ins->execute([
            $companyId,
            $orderNo,
            $d['order_date'] ?? date('Y-m-d'),
            !empty($d['scheduled_dispatch_date']) ? $d['scheduled_dispatch_date'] : null,
            $productId,
            (int)($d['order_qty_trucks'] ?? 0),
            $d['order_qty_mode'] ?? 'trucks',
            $d['order_weight_tons'] ?? null,
            isset($d['tons_per_truck']) ? (float)$d['tons_per_truck'] : 40.0,
            $partyId,
            $status,
            $d['priority'] ?? 'normal',
            !empty($d['is_recurring']) ? 1 : 0,
            $d['delivery_frequency_days'] ?? null,
            $d['trucks_per_delivery'] ?? null,
            $d['total_deliveries'] ?? null,
            $createdBy,
        ]);
    }

    $newId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO audit_logs (user_id, table_name, record_id, action, old_values, new_values)
        VALUES (?, 'orders', ?, 'CREATE', ?, ?)
    ")->execute([
        $item['deleted_by'] ?: 1,
        $newId,
        json_encode([
            'restored_from_audit_id' => $item['audit_id'],
            'previous_order_id' => $item['old_order_id'],
            'deleted_at' => $item['deleted_at'],
        ]),
        json_encode(array_merge($d, ['id' => $newId])),
    ]);

    echo "  -> created as orders.id={$newId}\n";
    $restored++;
}

echo "\nDone. Restored: {$restored}, skipped: {$skipped}" . ($dryRun ? ' (dry-run)' : '') . "\n";
echo "Note: cascaded dispatches were not restored. After deploy of date-aware matching,\n";
echo "unmap wrongly attached Busy invoices from newer orders if needed, then Remap so\n";
echo "older invoice dates attach to these restored orders.\n";

function orderNoExists(PDO $pdo, string $orderNo): bool
{
    $st = $pdo->prepare('SELECT id FROM orders WHERE order_no = ? LIMIT 1');
    $st->execute([$orderNo]);
    return (bool)$st->fetch();
}

function partyExists(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('SELECT id FROM parties WHERE id = ?');
    $st->execute([$id]);
    return (bool)$st->fetch();
}

function productExists(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('SELECT id FROM products WHERE id = ?');
    $st->execute([$id]);
    return (bool)$st->fetch();
}

function companyExists(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('SELECT id FROM companies WHERE id = ?');
    $st->execute([$id]);
    return (bool)$st->fetch();
}

function userExists(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $st->execute([$id]);
    return (bool)$st->fetch();
}
