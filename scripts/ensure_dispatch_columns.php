<?php
/**
 * Add dispatch/order columns the live DB is missing, without AFTER (safe when
 * earlier columns were never applied).
 */
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Core\Database;

$db = new Database();
$pdo = $db->getConnection();

$wanted = [
    ['dispatches', 'product_rate', 'DECIMAL(14,2) NULL'],
    ['dispatches', 'loading_weight_tons', 'DECIMAL(10,3) NULL'],
    ['dispatches', 'status', "ENUM('active','rejected','transferred') NOT NULL DEFAULT 'active'"],
    ['dispatches', 'source_dispatch_id', 'INT NULL'],
    ['dispatches', 'busy_invoice_no', 'VARCHAR(100) NULL'],
    ['dispatches', 'rawana_no', 'VARCHAR(100) NULL'],
    ['dispatches', 'eway_bill_no', 'VARCHAR(100) NULL'],
    ['dispatches', 'eway_bill_file_path', 'VARCHAR(500) NULL'],
    ['orders', 'tons_per_truck', 'DECIMAL(8,2) NOT NULL DEFAULT 40.00'],
];

foreach ($wanted as [$table, $column, $ddl]) {
    $row = $db->fetch(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    );
    if ((int)($row['c'] ?? 0) > 0) {
        echo "$table.$column already present\n";
        continue;
    }
    echo "Adding $table.$column ...\n";
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$ddl}");
    echo "  done\n";
}

echo "OK\n";
