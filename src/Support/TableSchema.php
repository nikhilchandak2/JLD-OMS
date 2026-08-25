<?php

namespace App\Support;

use App\Core\Database;

/** Cached INFORMATION_SCHEMA lookups so lagging live DBs can skip missing columns. */
class TableSchema
{
    /** @var array<string,bool> */
    private static array $columns = [];

    public static function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, self::$columns)) {
            $row = (new Database())->fetch(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            self::$columns[$key] = ((int)($row['c'] ?? 0)) > 0;
        }

        return self::$columns[$key];
    }

    public static function firstActiveCompanyIdSql(): string
    {
        return self::hasColumn('companies', 'status')
            ? "SELECT id FROM companies WHERE `status` = 'active' ORDER BY id LIMIT 1"
            : "SELECT id FROM companies ORDER BY id LIMIT 1";
    }
}
