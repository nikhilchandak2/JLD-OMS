<?php

namespace App\Support;

use App\Core\Database;

/** Detects dispatch table columns so queries work before/after lifecycle migrations. */
class DispatchSchema
{
    private static ?bool $hasStatus = null;
    private static ?bool $hasEwayFile = null;
    private static ?bool $hasTransportDocType = null;

    public static function hasCompanyTransportDocTypeColumn(): bool
    {
        if (self::$hasTransportDocType !== null) {
            return self::$hasTransportDocType;
        }

        $db = new Database();
        $row = $db->fetch(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'transport_doc_type'"
        );
        self::$hasTransportDocType = ((int)($row['c'] ?? 0)) > 0;
        return self::$hasTransportDocType;
    }

    public static function companyTransportDocSelect(string $alias = 'c'): string
    {
        if (self::hasCompanyTransportDocTypeColumn()) {
            return "{$alias}.transport_doc_type AS transport_doc_type";
        }
        return "'rawana' AS transport_doc_type";
    }

    public static function hasDispatchStatusColumn(): bool
    {
        if (self::$hasStatus !== null) {
            return self::$hasStatus;
        }

        $db = new Database();
        $row = $db->fetch(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dispatches' AND COLUMN_NAME = 'status'"
        );
        self::$hasStatus = ((int)($row['c'] ?? 0)) > 0;
        return self::$hasStatus;
    }

    public static function hasEwayBillFileColumn(): bool
    {
        if (self::$hasEwayFile !== null) {
            return self::$hasEwayFile;
        }

        $db = new Database();
        $row = $db->fetch(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dispatches' AND COLUMN_NAME = 'eway_bill_file_path'"
        );
        self::$hasEwayFile = ((int)($row['c'] ?? 0)) > 0;
        return self::$hasEwayFile;
    }

    /** SQL predicate for counting only active (non-rejected) dispatches. */
    public static function activeDispatchWhere(string $tableAlias = ''): string
    {
        if (!self::hasDispatchStatusColumn()) {
            return '1=1';
        }
        $col = $tableAlias !== '' ? "{$tableAlias}.status" : 'status';
        return "{$col} = 'active'";
    }
}
