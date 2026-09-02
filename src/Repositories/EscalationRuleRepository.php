<?php

namespace App\Repositories;

use App\Core\Database;

class EscalationRuleRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    /** @return array<int,array<string,mixed>> */
    public function findActive(): array
    {
        if (!\App\Support\TableSchema::hasTable('escalation_rules')) {
            return [];
        }
        return $this->database->fetchAll(
            "SELECT * FROM escalation_rules WHERE is_active = 1 ORDER BY id ASC"
        );
    }

    public function findActiveByType(string $triggerType, ?int $companyId = null): ?array
    {
        if (!\App\Support\TableSchema::hasTable('escalation_rules')) {
            return null;
        }
        $row = $this->database->fetch(
            "SELECT * FROM escalation_rules
             WHERE is_active = 1 AND trigger_type = ?
               AND (company_id <=> ?)
             ORDER BY id ASC LIMIT 1",
            [$triggerType, $companyId]
        );
        if ($row !== null) {
            return $row;
        }

        return $this->database->fetch(
            "SELECT * FROM escalation_rules
             WHERE is_active = 1 AND trigger_type = ? AND company_id IS NULL
             ORDER BY id ASC LIMIT 1",
            [$triggerType]
        );
    }
}
