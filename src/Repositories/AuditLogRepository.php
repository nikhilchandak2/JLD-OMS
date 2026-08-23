<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Shared writer for audit_logs. Existing modules inline this INSERT; new pipeline code
 * goes through here so actor / old value / new value is never forgotten.
 */
class AuditLogRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function log(
        ?int $userId,
        string $tableName,
        int $recordId,
        string $action,
        ?array $oldValues,
        ?array $newValues
    ): void {
        $this->database->query(
            "INSERT INTO audit_logs (user_id, table_name, record_id, action, old_values, new_values)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $userId,
                $tableName,
                $recordId,
                $action,
                $oldValues === null ? null : json_encode($oldValues),
                $newValues === null ? null : json_encode($newValues),
            ]
        );
    }
}
