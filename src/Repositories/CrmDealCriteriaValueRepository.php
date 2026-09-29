<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

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
        if (!TableSchema::hasTable('crm_deal_criteria_values')) {
            return [];
        }
        $value = TableSchema::columnExpr('crm_deal_criteria_values', ['value_text'], '', 'value_text');
        $key = TableSchema::columnExpr('crm_deal_criteria_values', ['field_key'], '', 'field_key');
        $rows = $this->database->fetchAll(
            "SELECT {$key}, {$value} FROM crm_deal_criteria_values WHERE deal_id = ?",
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
        if (!TableSchema::hasTable('crm_deal_criteria_values')) {
            return;
        }
        if (TableSchema::hasColumn('crm_deal_criteria_values', 'value_text')
            && TableSchema::hasColumn('crm_deal_criteria_values', 'updated_by_user_id')) {
            $updatedAt = TableSchema::hasColumn('crm_deal_criteria_values', 'updated_at')
                ? ', updated_at = NOW()'
                : '';
            $this->database->query(
                "INSERT INTO crm_deal_criteria_values (deal_id, field_key, value_text, updated_by_user_id)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value_text = VALUES(value_text),
                                         updated_by_user_id = VALUES(updated_by_user_id){$updatedAt}",
                [$dealId, $fieldKey, $value, $userId]
            );
            return;
        }
        $built = TableSchema::insertSql('crm_deal_criteria_values', [
            'deal_id' => $dealId,
            'field_key' => $fieldKey,
            'value_text' => $value,
            'updated_by_user_id' => $userId,
        ]);
        if ($built !== null) {
            $this->database->query($built[0], $built[1]);
        }
    }
}
