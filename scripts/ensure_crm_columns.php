<?php
/**
 * Add CRM pipeline columns the live DB is missing, without AFTER
 * (safe when earlier 046 ALTERs never applied).
 */
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Core\Database;

$db = new Database();
$pdo = $db->getConnection();

$wanted = [
    ['crm_deals', 'status', "ENUM('active','won','lost','dropped') NOT NULL DEFAULT 'active'"],
    ['crm_deals', 'deleted_at', 'DATETIME NULL'],
    ['crm_deals', 'stage_entered_at', 'DATETIME NULL'],
    ['crm_deals', 'company_id', 'INT NULL'],
    ['crm_deals', 'source', "VARCHAR(32) NOT NULL DEFAULT 'other'"],
    ['crm_deals', 'indicative_quantity_tonnes', 'DECIMAL(12,3) NULL'],
    ['crm_deals', 'inquiry_date', 'DATE NULL'],
    ['crm_deals', 'lost_reason_code_id', 'INT NULL'],
    ['crm_technical_flags', 'status', "ENUM('open','claimed','resolved','cancelled') NOT NULL DEFAULT 'open'"],
    ['crm_tasks', 'status', "ENUM('pending','completed') NOT NULL DEFAULT 'pending'"],
    ['companies', 'status', "ENUM('active','inactive') NOT NULL DEFAULT 'active'"],
    ['pipeline_deal_snapshot', 'status', 'VARCHAR(20) NOT NULL DEFAULT \'active\''],
    ['pipeline_time_in_stage_facts', 'status', 'VARCHAR(20) NOT NULL DEFAULT \'active\''],
];

foreach ($wanted as [$table, $column, $ddl]) {
    $tableExists = $db->fetch(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );
    if ((int)($tableExists['c'] ?? 0) === 0) {
        echo "$table does not exist — skip $column\n";
        continue;
    }
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
