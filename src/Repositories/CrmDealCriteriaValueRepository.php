<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Values captured against exit criteria that are not derivable from an existing record
 * (customer feedback text, agreed terms, quote spec, ...). Keyed by field_key so the
 * configuration table stays the single source of truth for what is asked for.
 */
class CrmDealCriteriaValueRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    /** @return array<string,string> field_key => value_text */
    public function findByDeal(int $dealId): array
    {
        if (!\App\Support\TableSchema::hasTable('crm_deal_criteria_values')) {
            return [];
        }
        $rows = $this->database->fetchAll(
            "SELECT field_key, value_text FROM crm_deal_criteria_values WHERE deal_id = ?",
            [$dealId]
        );

        $values = [];
        foreach ($rows as $row) {
            $values[$row['field_key']] = $row['value_text'];
        }

        return $values;
    }

    public function upsert(int $dealId, string $fieldKey, ?string $value, ?int $userId): void
    {
        $this->database->query(
            "INSERT INTO crm_deal_criteria_values (deal_id, field_key, value_text, updated_by_user_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value_text = VALUES(value_text),
                                     updated_by_user_id = VALUES(updated_by_user_id),
                                     updated_at = NOW()",
            [$dealId, $fieldKey, $value, $userId]
        );
    }
}
