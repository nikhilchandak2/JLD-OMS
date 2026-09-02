<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * crm_deal_stage_events is append-only: this repository has no update and no delete.
 * Time-in-stage for any deal is derivable from these rows alone.
 */
class CrmDealStageEventRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function append(array $event): int
    {
        $this->database->query(
            "INSERT INTO crm_deal_stage_events
                (deal_id, from_stage, to_stage, from_status, to_status, reason_code_id,
                 reason_note, exit_criteria_snapshot, actor_user_id, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $event['deal_id'],
                $event['from_stage'] ?? null,
                $event['to_stage'] ?? null,
                $event['from_status'] ?? null,
                $event['to_status'] ?? null,
                $event['reason_code_id'] ?? null,
                $event['reason_note'] ?? null,
                isset($event['exit_criteria_snapshot'])
                    ? json_encode($event['exit_criteria_snapshot'])
                    : null,
                $event['actor_user_id'] ?? null,
            ]
        );

        return (int)$this->database->lastInsertId();
    }

    public function findByDeal(int $dealId): array
    {
        if (!\App\Support\TableSchema::hasTable('crm_deal_stage_events')) {
            return [];
        }
        $reasonJoin = \App\Support\TableSchema::hasTable('crm_deal_reason_codes')
            ? 'LEFT JOIN crm_deal_reason_codes r ON r.id = e.reason_code_id'
            : 'LEFT JOIN (SELECT NULL AS id, NULL AS label) r ON 1=0';
        return $this->database->fetchAll(
            "SELECT e.*, u.name AS actor_name, r.label AS reason_label
             FROM crm_deal_stage_events e
             LEFT JOIN users u ON u.id = e.actor_user_id
             {$reasonJoin}
             WHERE e.deal_id = ?
             ORDER BY e.occurred_at ASC, e.id ASC",
            [$dealId]
        );
    }

    public function countByDeal(int $dealId): int
    {
        if (!\App\Support\TableSchema::hasTable('crm_deal_stage_events')) {
            return 0;
        }
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM crm_deal_stage_events WHERE deal_id = ?",
            [$dealId]
        );

        return (int)($row['c'] ?? 0);
    }
}
