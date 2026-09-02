<?php

namespace App\Repositories;

use App\Core\Database;

class CrmDealReasonCodeRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findActive(?string $appliesTo = null): array
    {
        if (!\App\Support\TableSchema::hasTable('crm_deal_reason_codes')) {
            return [];
        }
        $sql = "SELECT id, code, label, applies_to FROM crm_deal_reason_codes WHERE is_active = 1";
        $params = [];
        if ($appliesTo !== null) {
            $sql .= " AND applies_to IN (?, 'both')";
            $params[] = $appliesTo;
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";

        return $this->database->fetchAll($sql, $params);
    }

    public function findById(int $id): ?array
    {
        if (!\App\Support\TableSchema::hasTable('crm_deal_reason_codes')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT id, code, label, applies_to, is_active FROM crm_deal_reason_codes WHERE id = ?",
            [$id]
        );
    }
}
