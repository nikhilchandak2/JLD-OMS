<?php

namespace App\Support;

use App\Core\Database;

/** Cached INFORMATION_SCHEMA lookups so lagging live DBs can skip missing columns. */
class TableSchema
{
    /** @var array<string,bool> */
    private static array $columns = [];
    /** @var array<string,bool> */
    private static array $tables = [];
    /** @var array<string,bool> */
    private static array $indexes = [];

    public static function hasTable(string $table): bool
    {
        if (!array_key_exists($table, self::$tables)) {
            $row = (new Database())->fetch(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
            self::$tables[$table] = ((int)($row['c'] ?? 0)) > 0;
        }

        return self::$tables[$table];
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, self::$columns)) {
            if (!self::hasTable($table)) {
                self::$columns[$key] = false;
            } else {
                $row = (new Database())->fetch(
                    "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$table, $column]
                );
                self::$columns[$key] = ((int)($row['c'] ?? 0)) > 0;
            }
        }

        return self::$columns[$key];
    }

    public static function hasIndex(string $table, string $index): bool
    {
        $key = $table . '.' . $index;
        if (!array_key_exists($key, self::$indexes)) {
            if (!self::hasTable($table)) {
                self::$indexes[$key] = false;
            } else {
                $row = (new Database())->fetch(
                    "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                    [$table, $index]
                );
                self::$indexes[$key] = ((int)($row['c'] ?? 0)) > 0;
            }
        }

        return self::$indexes[$key];
    }

    public static function forceIndex(string $table, string $index): string
    {
        return self::hasIndex($table, $index) ? "FORCE INDEX (`{$index}`)" : '';
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    public static function existingColumns(string $table, array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            if (self::hasColumn($table, $column)) {
                $out[] = $column;
            }
        }

        return $out;
    }

    public static function firstActiveCompanyIdSql(): string
    {
        return self::hasColumn('companies', 'status')
            ? "SELECT id FROM companies WHERE `status` = 'active' ORDER BY id LIMIT 1"
            : "SELECT id FROM companies ORDER BY id LIMIT 1";
    }

    /**
     * First existing column from $candidates, optionally aliased for SELECT lists.
     */
    public static function columnExpr(string $table, array $candidates, string $alias = '', string $as = ''): string
    {
        foreach ($candidates as $column) {
            if (!self::hasColumn($table, $column)) {
                continue;
            }
            $expr = $alias === '' ? "`{$column}`" : "{$alias}.`{$column}`";
            if ($as !== '' && $as !== $column) {
                return "{$expr} AS `{$as}`";
            }
            return $expr;
        }

        return $as !== '' ? "NULL AS `{$as}`" : 'NULL';
    }

    public static function forget(): void
    {
        self::$columns = [];
        self::$tables = [];
        self::$indexes = [];
    }
}
