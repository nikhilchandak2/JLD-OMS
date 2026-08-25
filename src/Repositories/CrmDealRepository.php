<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

/**
 * SQL for crm_deals. Soft-deleted rows are excluded here (I12) so no caller has to remember.
 *
 * `stage` and `status` are only ever written by applyTransition(), which is called by
 * DealStageService and nothing else.
 */
class CrmDealRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetch(
            "SELECT * FROM crm_deals WHERE id = ? AND " . $this->notDeletedPredicate(),
            [$id]
        );
    }

    /**
     * One query, with the technical hold derived from an open flag (I2) rather than stored.
     */
    public function findAll(array $filters = [], int $limit = 200): array
    {
        if (!TableSchema::hasTable('crm_deals')) {
            return [];
        }
        $ownerJoin = TableSchema::hasColumn('crm_deals', 'owner_user_id')
            ? 'LEFT JOIN users u ON u.id = d.owner_user_id'
            : (TableSchema::hasColumn('crm_deals', 'assigned_to')
                ? 'LEFT JOIN users u ON u.id = d.assigned_to'
                : 'LEFT JOIN users u ON 1=0');
        $reasonJoin = TableSchema::hasTable('crm_deal_reason_codes') && TableSchema::hasColumn('crm_deals', 'lost_reason_code_id')
            ? 'LEFT JOIN crm_deal_reason_codes r ON r.id = d.lost_reason_code_id'
            : 'LEFT JOIN crm_deal_reason_codes r ON 1=0';
        $flagJoin = TableSchema::hasTable('crm_technical_flags')
            ? "LEFT JOIN (
                    SELECT deal_id, COUNT(*) AS open_flags, MIN(created_at) AS oldest_open_flag_at
                    FROM crm_technical_flags
                    WHERE " . $this->technicalFlagOpenPredicate() . "
                      AND deal_id IS NOT NULL
                    GROUP BY deal_id
                ) f ON f.deal_id = d.id"
            : 'LEFT JOIN (SELECT NULL AS deal_id, NULL AS open_flags, NULL AS oldest_open_flag_at) f ON 1=0';

        $sql = "SELECT d.*,
                       p.name AS party_name,
                       u.name AS owner_name,
                       r.label AS lost_reason_label,
                       (f.open_flags IS NOT NULL) AS is_on_technical_hold,
                       f.oldest_open_flag_at
                FROM crm_deals d
                JOIN parties p ON p.id = d.party_id
                {$ownerJoin}
                {$reasonJoin}
                {$flagJoin}
                WHERE " . $this->notDeletedPredicate('d');
        $params = [];

        if (!empty($filters['status']) && TableSchema::hasColumn('crm_deals', 'status')) {
            $sql .= " AND d.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['stage'])) {
            $sql .= " AND d.stage = ?";
            $params[] = (int)$filters['stage'];
        }
        if (!empty($filters['party_id'])) {
            $sql .= " AND d.party_id = ?";
            $params[] = (int)$filters['party_id'];
        }
        if (!empty($filters['owner_user_id']) && TableSchema::hasColumn('crm_deals', 'owner_user_id')) {
            $sql .= " AND d.owner_user_id = ?";
            $params[] = (int)$filters['owner_user_id'];
        }
        if (!empty($filters['company_id']) && TableSchema::hasColumn('crm_deals', 'company_id')) {
            $sql .= " AND d.company_id = ?";
            $params[] = (int)$filters['company_id'];
        }
        if (!empty($filters['on_technical_hold'])) {
            $sql .= " AND f.open_flags IS NOT NULL";
        }

        $sql .= " ORDER BY d.stage DESC, " . (TableSchema::hasColumn('crm_deals', 'stage_entered_at') ? 'd.stage_entered_at' : 'd.created_at') . " ASC LIMIT " . max(1, min(1000, $limit));

        return $this->database->fetchAll($sql, $params);
    }

    public function create(array $data): int
    {
        $values = [
            'party_id' => $data['party_id'],
            'title' => $data['title'],
        ];
        $optional = [
            'company_id' => $data['company_id'] ?? null,
            'source' => $data['source'] ?? 'other',
            'indicative_quantity_tonnes' => $data['indicative_quantity_tonnes'] ?? null,
            'inquiry_date' => $data['inquiry_date'] ?? null,
            'value' => $data['value'] ?? null,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
        foreach ($optional as $column => $value) {
            if (TableSchema::hasColumn('crm_deals', $column)) {
                $values[$column] = $value;
            }
        }
        if (TableSchema::hasColumn('crm_deals', 'stage')) {
            $values['stage'] = 1;
        }
        if (TableSchema::hasColumn('crm_deals', 'status')) {
            $values['status'] = 'active';
        }
        if (TableSchema::hasColumn('crm_deals', 'stage_entered_at')) {
            $values['stage_entered_at'] = date('Y-m-d H:i:s');
        }
        if (TableSchema::hasColumn('crm_deals', 'owner_user_id')) {
            $values['owner_user_id'] = $data['owner_user_id'] ?? null;
        } elseif (TableSchema::hasColumn('crm_deals', 'assigned_to')) {
            $values['assigned_to'] = $data['owner_user_id'] ?? null;
        }

        $columns = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $this->database->query(
            'INSERT INTO crm_deals (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')',
            array_values($values)
        );

        return (int)$this->database->lastInsertId();
    }

    /**
     * Update the descriptive fields of a deal. Deliberately cannot touch stage or status.
     */
    public function updateDetails(int $id, array $data): void
    {
        $allowed = [
            'title', 'value', 'expected_close_date', 'owner_user_id', 'notes',
            'source', 'indicative_quantity_tonnes', 'inquiry_date', 'company_id',
        ];
        $fields = [];
        $values = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if ($field === 'owner_user_id' && !TableSchema::hasColumn('crm_deals', 'owner_user_id')) {
                if (TableSchema::hasColumn('crm_deals', 'assigned_to')) {
                    $fields[] = 'assigned_to = ?';
                    $values[] = $data[$field];
                }
                continue;
            }
            if (!TableSchema::hasColumn('crm_deals', $field)) {
                continue;
            }
            $fields[] = "{$field} = ?";
            $values[] = $data[$field];
        }
        if (empty($fields)) {
            return;
        }
        $values[] = $id;
        $this->database->query(
            "UPDATE crm_deals SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?",
            $values
        );
    }

    /**
     * The only write path for stage/status. Called exclusively by DealStageService.
     */
    public function applyTransition(int $id, int $stage, string $status, ?int $lostReasonCodeId, bool $resetStageClock): void
    {
        $sets = ['stage = ?'];
        $params = [$stage];
        if (TableSchema::hasColumn('crm_deals', 'status')) {
            $sets[] = 'status = ?';
            $params[] = $status;
        }
        if (TableSchema::hasColumn('crm_deals', 'lost_reason_code_id')) {
            $sets[] = 'lost_reason_code_id = ?';
            $params[] = $lostReasonCodeId;
        }
        if ($resetStageClock && TableSchema::hasColumn('crm_deals', 'stage_entered_at')) {
            $sets[] = 'stage_entered_at = NOW()';
        }
        $params[] = $id;
        $this->database->query(
            'UPDATE crm_deals SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ? AND ' . $this->notDeletedPredicate(),
            $params
        );
    }

    public function softDelete(int $id): void
    {
        if (!TableSchema::hasColumn('crm_deals', 'deleted_at')) {
            return;
        }
        $this->database->query(
            "UPDATE crm_deals SET deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    }

    public function countActiveByStage(): array
    {
        if (!TableSchema::hasTable('crm_deals')) {
            return [];
        }
        $where = [$this->notDeletedPredicate()];
        if (TableSchema::hasColumn('crm_deals', 'status')) {
            $where[] = "`status` = 'active'";
        }

        return $this->database->fetchAll(
            "SELECT stage, COUNT(*) AS deals
             FROM crm_deals
             WHERE " . implode(' AND ', $where) . "
             GROUP BY stage
             ORDER BY stage"
        );
    }

    private function notDeletedPredicate(string $alias = ''): string
    {
        if (!TableSchema::hasColumn('crm_deals', 'deleted_at')) {
            return '1=1';
        }
        $col = $alias !== '' ? "{$alias}.deleted_at" : '`deleted_at`';

        return "{$col} IS NULL";
    }

    private function technicalFlagOpenPredicate(): string
    {
        if (!TableSchema::hasColumn('crm_technical_flags', 'status')) {
            return '1=1';
        }

        return "`status` IN ('open', 'claimed')";
    }
}
