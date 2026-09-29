<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

class CrmTechnicalQueueRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findActive(?int $companyId = null): array
    {
        if (!TableSchema::hasTable('crm_technical_queues')) {
            return [];
        }
        $company = TableSchema::hasColumn('crm_technical_queues', 'company_id')
            ? 'company_id'
            : 'NULL AS company_id';
        $sql = "SELECT id, {$company}, name FROM crm_technical_queues WHERE 1=1";
        $params = [];
        if (TableSchema::hasColumn('crm_technical_queues', 'is_active')) {
            $sql .= ' AND is_active = 1';
        }
        if ($companyId !== null && TableSchema::hasColumn('crm_technical_queues', 'company_id')) {
            $sql .= " AND (company_id IS NULL OR company_id = ?)";
            $params[] = $companyId;
        }
        $sql .= " ORDER BY name ASC";

        return $this->database->fetchAll($sql, $params);
    }

    public function findById(int $id): ?array
    {
        if (!TableSchema::hasTable('crm_technical_queues')) {
            return null;
        }
        $company = TableSchema::hasColumn('crm_technical_queues', 'company_id')
            ? 'company_id'
            : 'NULL AS company_id';
        $active = TableSchema::hasColumn('crm_technical_queues', 'is_active')
            ? 'is_active'
            : '1 AS is_active';
        return $this->database->fetch(
            "SELECT id, {$company}, name, {$active} FROM crm_technical_queues WHERE id = ?",
            [$id]
        );
    }

    public function findDefault(): ?array
    {
        if (!TableSchema::hasTable('crm_technical_queues')) {
            return null;
        }
        $company = TableSchema::hasColumn('crm_technical_queues', 'company_id')
            ? 'company_id'
            : 'NULL AS company_id';
        $active = TableSchema::hasColumn('crm_technical_queues', 'is_active')
            ? 'WHERE is_active = 1'
            : '';
        return $this->database->fetch(
            "SELECT id, {$company}, name FROM crm_technical_queues
             {$active}
             ORDER BY id ASC
             LIMIT 1"
        );
    }
}
