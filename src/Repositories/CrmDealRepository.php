<?php

namespace App\Repositories;

use App\Core\Database;

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
            "SELECT * FROM crm_deals WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    }

    /**
     * One query, with the technical hold derived from an open flag (I2) rather than stored.
     */
    public function findAll(array $filters = [], int $limit = 200): array
    {
        $sql = "SELECT d.*,
                       p.name AS party_name,
                       u.name AS owner_name,
                       r.label AS lost_reason_label,
                       (f.open_flags IS NOT NULL) AS is_on_technical_hold,
                       f.oldest_open_flag_at
                FROM crm_deals d
                JOIN parties p ON p.id = d.party_id
                LEFT JOIN users u ON u.id = d.owner_user_id
                LEFT JOIN crm_deal_reason_codes r ON r.id = d.lost_reason_code_id
                LEFT JOIN (
                    SELECT deal_id, COUNT(*) AS open_flags, MIN(created_at) AS oldest_open_flag_at
                    FROM crm_technical_flags
                    WHERE status IN ('open', 'claimed') AND deal_id IS NOT NULL
                    GROUP BY deal_id
                ) f ON f.deal_id = d.id
                WHERE d.deleted_at IS NULL";
        $params = [];

        if (!empty($filters['status'])) {
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
        if (!empty($filters['owner_user_id'])) {
            $sql .= " AND d.owner_user_id = ?";
            $params[] = (int)$filters['owner_user_id'];
        }
        if (!empty($filters['company_id'])) {
            $sql .= " AND d.company_id = ?";
            $params[] = (int)$filters['company_id'];
        }
        if (!empty($filters['on_technical_hold'])) {
            $sql .= " AND f.open_flags IS NOT NULL";
        }

        $sql .= " ORDER BY d.stage DESC, d.stage_entered_at ASC LIMIT " . max(1, min(1000, $limit));

        return $this->database->fetchAll($sql, $params);
    }

    public function create(array $data): int
    {
        $this->database->query(
            "INSERT INTO crm_deals
                (party_id, company_id, title, stage, status, stage_entered_at, source,
                 indicative_quantity_tonnes, inquiry_date, value, expected_close_date,
                 owner_user_id, notes)
             VALUES (?, ?, ?, 1, 'active', NOW(), ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['party_id'],
                $data['company_id'] ?? null,
                $data['title'],
                $data['source'],
                $data['indicative_quantity_tonnes'] ?? null,
                $data['inquiry_date'],
                $data['value'] ?? null,
                $data['expected_close_date'] ?? null,
                $data['owner_user_id'] ?? null,
                $data['notes'] ?? null,
            ]
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
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
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
        $sql = "UPDATE crm_deals
                SET stage = ?, status = ?, lost_reason_code_id = ?"
            . ($resetStageClock ? ", stage_entered_at = NOW()" : "")
            . ", updated_at = NOW()
                WHERE id = ? AND deleted_at IS NULL";

        $this->database->query($sql, [$stage, $status, $lostReasonCodeId, $id]);
    }

    public function softDelete(int $id): void
    {
        $this->database->query(
            "UPDATE crm_deals SET deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    }

    public function countActiveByStage(): array
    {
        return $this->database->fetchAll(
            "SELECT stage, COUNT(*) AS deals
             FROM crm_deals
             WHERE status = 'active' AND deleted_at IS NULL
             GROUP BY stage
             ORDER BY stage"
        );
    }
}
