<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

class CrmTechnicalFlagRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function create(array $data): int
    {
        $built = TableSchema::insertSql('crm_technical_flags', [
            'deal_id' => $data['deal_id'] ?? null,
            'party_id' => $data['party_id'],
            'raised_from_stage' => $data['raised_from_stage'] ?? null,
            'raised_by_user_id' => $data['raised_by_user_id'] ?? null,
            'nature_of_query' => $data['nature_of_query'],
            'routed_to_queue_id' => $data['routed_to_queue_id'],
            'expected_turnaround_at' => $data['expected_turnaround_at'] ?? null,
            'status' => 'open',
        ]);
        if ($built === null) {
            throw new \RuntimeException('crm_technical_flags is not available.');
        }
        $this->database->query($built[0], $built[1]);

        return (int)$this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        if (!TableSchema::hasTable('crm_technical_flags')) {
            return null;
        }
        return $this->database->fetch("SELECT * FROM crm_technical_flags WHERE id = ?", [$id]);
    }

    /**
     * Queue view. Overdue flags sort to the top (B4 ageing visibility), then oldest first.
     */
    public function findQueue(array $filters = []): array
    {
        if (!TableSchema::hasTable('crm_technical_flags')) {
            return [];
        }

        $overdue = TableSchema::hasColumn('crm_technical_flags', 'status')
            && TableSchema::hasColumn('crm_technical_flags', 'expected_turnaround_at')
            ? "(f.status IN ('open', 'claimed')
                        AND f.expected_turnaround_at IS NOT NULL
                        AND f.expected_turnaround_at < NOW()) AS is_overdue"
            : '0 AS is_overdue';
        $queueJoin = TableSchema::hasTable('crm_technical_queues')
            && TableSchema::hasColumn('crm_technical_flags', 'routed_to_queue_id')
            ? 'JOIN crm_technical_queues q ON q.id = f.routed_to_queue_id'
            : 'LEFT JOIN (SELECT NULL AS id, NULL AS name) q ON 1=0';
        $dealJoin = TableSchema::leftJoinOrStub('crm_deals', 'd', 'd.id = f.deal_id', ['id', 'title']);
        $raisedJoin = TableSchema::leftJoinIfColumn(
            'crm_technical_flags',
            'raised_by_user_id',
            'users',
            'ru',
            'ru.id = f.raised_by_user_id'
        );
        $claimedJoin = TableSchema::leftJoinIfColumn(
            'crm_technical_flags',
            'claimed_by_user_id',
            'users',
            'cu',
            'cu.id = f.claimed_by_user_id'
        );
        $sql = "SELECT f.*,
                       q.name AS queue_name,
                       p.name AS party_name,
                       d.title AS deal_title,
                       ru.name AS raised_by_name,
                       cu.name AS claimed_by_name,
                       {$overdue}
                FROM crm_technical_flags f
                {$queueJoin}
                JOIN parties p ON p.id = f.party_id
                {$dealJoin}
                {$raisedJoin}
                {$claimedJoin}
                WHERE 1 = 1";
        $params = [];

        if (!empty($filters['queue_id']) && TableSchema::hasColumn('crm_technical_flags', 'routed_to_queue_id')) {
            $sql .= " AND f.routed_to_queue_id = ?";
            $params[] = (int)$filters['queue_id'];
        }
        if (!empty($filters['status']) && TableSchema::hasColumn('crm_technical_flags', 'status')) {
            $sql .= " AND f.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['open_only']) && TableSchema::hasColumn('crm_technical_flags', 'status')) {
            $sql .= " AND f.status IN ('open', 'claimed')";
        }
        if (!empty($filters['deal_id']) && TableSchema::hasColumn('crm_technical_flags', 'deal_id')) {
            $sql .= " AND f.deal_id = ?";
            $params[] = (int)$filters['deal_id'];
        }
        if (!empty($filters['party_id'])) {
            $sql .= " AND f.party_id = ?";
            $params[] = (int)$filters['party_id'];
        }

        $sql .= " ORDER BY is_overdue DESC, f.created_at ASC LIMIT 500";

        return $this->database->fetchAll($sql, $params);
    }

    public function hasOpenFlag(?int $dealId, ?int $partyId = null): bool
    {
        if (!TableSchema::hasTable('crm_technical_flags')) {
            return false;
        }
        $statusPred = TableSchema::hasColumn('crm_technical_flags', 'status')
            ? "AND status IN ('open', 'claimed')"
            : '';
        if ($dealId !== null) {
            $row = $this->database->fetch(
                "SELECT 1 AS found FROM crm_technical_flags
                 WHERE deal_id = ? {$statusPred} LIMIT 1",
                [$dealId]
            );
        } else {
            $row = $this->database->fetch(
                "SELECT 1 AS found FROM crm_technical_flags
                 WHERE party_id = ? AND deal_id IS NULL {$statusPred} LIMIT 1",
                [$partyId]
            );
        }

        return $row !== null;
    }

    public function claim(int $id, int $userId): void
    {
        $sets = [];
        $params = [];
        if (TableSchema::hasColumn('crm_technical_flags', 'status')) {
            $sets[] = "status = 'claimed'";
        }
        if (TableSchema::hasColumn('crm_technical_flags', 'claimed_by_user_id')) {
            $sets[] = 'claimed_by_user_id = ?';
            $params[] = $userId;
        }
        if (TableSchema::hasColumn('crm_technical_flags', 'claimed_at')) {
            $sets[] = 'claimed_at = NOW()';
        }
        if (TableSchema::hasColumn('crm_technical_flags', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }
        if ($sets === []) {
            return;
        }
        $where = TableSchema::hasColumn('crm_technical_flags', 'status')
            ? "id = ? AND status = 'open'"
            : 'id = ?';
        $params[] = $id;
        $this->database->query(
            'UPDATE crm_technical_flags SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            $params
        );
    }

    public function resolve(int $id, int $userId, string $resolutionType, string $note): void
    {
        $sets = [];
        $params = [];
        if (TableSchema::hasColumn('crm_technical_flags', 'status')) {
            $sets[] = "status = 'resolved'";
        }
        if (TableSchema::hasColumn('crm_technical_flags', 'resolution_type')) {
            $sets[] = 'resolution_type = ?';
            $params[] = $resolutionType;
        }
        if (TableSchema::hasColumn('crm_technical_flags', 'resolution_note')) {
            $sets[] = 'resolution_note = ?';
            $params[] = $note;
        }
        if (TableSchema::hasColumn('crm_technical_flags', 'resolved_by_user_id')) {
            $sets[] = 'resolved_by_user_id = ?';
            $params[] = $userId;
        }
        if (TableSchema::hasColumn('crm_technical_flags', 'resolved_at')) {
            $sets[] = 'resolved_at = NOW()';
        }
        if (TableSchema::hasColumn('crm_technical_flags', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }
        if ($sets === []) {
            return;
        }
        $where = TableSchema::hasColumn('crm_technical_flags', 'status')
            ? "id = ? AND status IN ('open', 'claimed')"
            : 'id = ?';
        $params[] = $id;
        $this->database->query(
            'UPDATE crm_technical_flags SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            $params
        );
    }

    public function cancel(int $id, string $note): void
    {
        $this->database->query(
            "UPDATE crm_technical_flags
             SET status = 'cancelled', resolution_note = ?, updated_at = NOW()
             WHERE id = ? AND status IN ('open', 'claimed')",
            [$note, $id]
        );
    }

    /**
     * Flag frequency and resolution time, queryable from day one - this is the number that
     * later justifies or rules out a dedicated technical sales support hire.
     */
    public function resolutionStats(?string $fromDate = null, ?string $toDate = null): array
    {
        if (!TableSchema::hasTable('crm_technical_flags') || !TableSchema::hasTable('crm_technical_queues')) {
            return [];
        }
        $statusOpen = TableSchema::hasColumn('crm_technical_flags', 'status')
            ? "SUM(f.status IN ('open', 'claimed')) AS still_open,
                       SUM(f.status = 'resolved') AS resolved"
            : 'COUNT(*) AS still_open, 0 AS resolved';
        $overdue = TableSchema::hasColumn('crm_technical_flags', 'status')
            && TableSchema::hasColumn('crm_technical_flags', 'expected_turnaround_at')
            ? "SUM(f.status IN ('open', 'claimed')
                           AND f.expected_turnaround_at IS NOT NULL
                           AND f.expected_turnaround_at < NOW()) AS overdue"
            : '0 AS overdue';
        $siteVisits = TableSchema::hasColumn('crm_technical_flags', 'resolution_type')
            ? "SUM(f.resolution_type = 'site_visit') AS site_visits"
            : '0 AS site_visits';
        $avgHours = TableSchema::hasColumn('crm_technical_flags', 'resolved_at')
            ? "ROUND(AVG(CASE WHEN f.resolved_at IS NOT NULL
                                 THEN TIMESTAMPDIFF(HOUR, f.created_at, f.resolved_at) END), 1)
                         AS avg_resolution_hours"
            : 'NULL AS avg_resolution_hours';
        $queueJoin = TableSchema::hasColumn('crm_technical_flags', 'routed_to_queue_id')
            ? 'JOIN crm_technical_queues q ON q.id = f.routed_to_queue_id'
            : 'LEFT JOIN (SELECT NULL AS id, NULL AS name) q ON 1=0';
        $sql = "SELECT q.name AS queue_name,
                       COUNT(*) AS flags_raised,
                       {$statusOpen},
                       {$overdue},
                       {$siteVisits},
                       {$avgHours}
                FROM crm_technical_flags f
                {$queueJoin}
                WHERE 1 = 1";
        $params = [];
        if ($fromDate !== null) {
            $sql .= " AND f.created_at >= ?";
            $params[] = $fromDate;
        }
        if ($toDate !== null) {
            $sql .= " AND f.created_at < ?";
            $params[] = $toDate;
        }
        $sql .= " GROUP BY q.id, q.name ORDER BY q.name ASC";

        return $this->database->fetchAll($sql, $params);
    }
}
