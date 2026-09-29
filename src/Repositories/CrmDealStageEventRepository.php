<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

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
        $built = TableSchema::insertSql('crm_deal_stage_events', [
            'deal_id' => $event['deal_id'],
            'from_stage' => $event['from_stage'] ?? null,
            'to_stage' => $event['to_stage'] ?? null,
            'from_status' => $event['from_status'] ?? null,
            'to_status' => $event['to_status'] ?? null,
            'reason_code_id' => $event['reason_code_id'] ?? null,
            'reason_note' => $event['reason_note'] ?? null,
            'exit_criteria_snapshot' => isset($event['exit_criteria_snapshot'])
                ? json_encode($event['exit_criteria_snapshot'])
                : null,
            'actor_user_id' => $event['actor_user_id'] ?? null,
            'occurred_at' => date('Y-m-d H:i:s'),
        ]);
        if ($built === null) {
            throw new \RuntimeException('crm_deal_stage_events is not available.');
        }
        $this->database->query($built[0], $built[1]);

        return (int)$this->database->lastInsertId();
    }

    public function findByDeal(int $dealId): array
    {
        if (!TableSchema::hasTable('crm_deal_stage_events')) {
            return [];
        }
        $reasonJoin = TableSchema::hasTable('crm_deal_reason_codes')
            && TableSchema::hasColumn('crm_deal_stage_events', 'reason_code_id')
            ? 'LEFT JOIN crm_deal_reason_codes r ON r.id = e.reason_code_id'
            : 'LEFT JOIN (SELECT NULL AS id, NULL AS label) r ON 1=0';
        $actorJoin = TableSchema::leftJoinIfColumn(
            'crm_deal_stage_events',
            'actor_user_id',
            'users',
            'u',
            'u.id = e.actor_user_id'
        );
        $order = TableSchema::hasColumn('crm_deal_stage_events', 'occurred_at')
            ? 'e.occurred_at ASC, e.id ASC'
            : 'e.id ASC';
        return $this->database->fetchAll(
            "SELECT e.*, u.name AS actor_name, r.label AS reason_label
             FROM crm_deal_stage_events e
             {$actorJoin}
             {$reasonJoin}
             WHERE e.deal_id = ?
             ORDER BY {$order}",
            [$dealId]
        );
    }

    public function countByDeal(int $dealId): int
    {
        if (!TableSchema::hasTable('crm_deal_stage_events')) {
            return 0;
        }
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM crm_deal_stage_events WHERE deal_id = ?",
            [$dealId]
        );

        return (int)($row['c'] ?? 0);
    }
}
