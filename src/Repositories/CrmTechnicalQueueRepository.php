<?php

namespace App\Repositories;

use App\Core\Database;

class CrmTechnicalQueueRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findActive(?int $companyId = null): array
    {
        if (!\App\Support\TableSchema::hasTable('crm_technical_queues')) {
            return [];
        }
        $sql = "SELECT id, company_id, name FROM crm_technical_queues WHERE is_active = 1";
        $params = [];
        if ($companyId !== null) {
            $sql .= " AND (company_id IS NULL OR company_id = ?)";
            $params[] = $companyId;
        }
        $sql .= " ORDER BY name ASC";

        return $this->database->fetchAll($sql, $params);
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetch(
            "SELECT id, company_id, name, is_active FROM crm_technical_queues WHERE id = ?",
            [$id]
        );
    }

    public function findDefault(): ?array
    {
        return $this->database->fetch(
            "SELECT id, company_id, name FROM crm_technical_queues
             WHERE is_active = 1
             ORDER BY id ASC
             LIMIT 1"
        );
    }
}
