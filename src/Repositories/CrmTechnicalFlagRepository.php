<?php

namespace App\Repositories;

use App\Core\Database;

class CrmTechnicalFlagRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function create(array $data): int
    {
        $this->database->query(
            "INSERT INTO crm_technical_flags
                (deal_id, party_id, raised_from_stage, raised_by_user_id, nature_of_query,
                 routed_to_queue_id, expected_turnaround_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'open')",
            [
                $data['deal_id'] ?? null,
                $data['party_id'],
                $data['raised_from_stage'] ?? null,
                $data['raised_by_user_id'] ?? null,
                $data['nature_of_query'],
                $data['routed_to_queue_id'],
                $data['expected_turnaround_at'] ?? null,
            ]
        );

        return (int)$this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetch("SELECT * FROM crm_technical_flags WHERE id = ?", [$id]);
    }

    /**
     * Queue view. Overdue flags sort to the top (B4 ageing visibility), then oldest first.
     */
    public function findQueue(array $filters = []): array
    {
        $sql = "SELECT f.*,
                       q.name AS queue_name,
                       p.name AS party_name,
                       d.title AS deal_title,
                       ru.name AS raised_by_name,
                       cu.name AS claimed_by_name,
                       (f.status IN ('open', 'claimed')
                        AND f.expected_turnaround_at IS NOT NULL
                        AND f.expected_turnaround_at < NOW()) AS is_overdue
                FROM crm_technical_flags f
                JOIN crm_technical_queues q ON q.id = f.routed_to_queue_id
                JOIN parties p ON p.id = f.party_id
                LEFT JOIN crm_deals d ON d.id = f.deal_id
                LEFT JOIN users ru ON ru.id = f.raised_by_user_id
                LEFT JOIN users cu ON cu.id = f.claimed_by_user_id
                WHERE 1 = 1";
        $params = [];

        if (!empty($filters['queue_id'])) {
            $sql .= " AND f.routed_to_queue_id = ?";
            $params[] = (int)$filters['queue_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= " AND f.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['open_only'])) {
            $sql .= " AND f.status IN ('open', 'claimed')";
        }
        if (!empty($filters['deal_id'])) {
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
        if ($dealId !== null) {
            $row = $this->database->fetch(
                "SELECT 1 AS found FROM crm_technical_flags
                 WHERE deal_id = ? AND status IN ('open', 'claimed') LIMIT 1",
                [$dealId]
            );
        } else {
            $row = $this->database->fetch(
                "SELECT 1 AS found FROM crm_technical_flags
                 WHERE party_id = ? AND deal_id IS NULL AND status IN ('open', 'claimed') LIMIT 1",
                [$partyId]
            );
        }

        return $row !== null;
    }

    public function claim(int $id, int $userId): void
    {
        $this->database->query(
            "UPDATE crm_technical_flags
             SET status = 'claimed', claimed_by_user_id = ?, claimed_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'open'",
            [$userId, $id]
        );
    }

    public function resolve(int $id, int $userId, string $resolutionType, string $note): void
    {
        $this->database->query(
            "UPDATE crm_technical_flags
             SET status = 'resolved', resolution_type = ?, resolution_note = ?,
                 resolved_by_user_id = ?, resolved_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status IN ('open', 'claimed')",
            [$resolutionType, $note, $userId, $id]
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
        $sql = "SELECT q.name AS queue_name,
                       COUNT(*) AS flags_raised,
                       SUM(f.status IN ('open', 'claimed')) AS still_open,
                       SUM(f.status = 'resolved') AS resolved,
                       SUM(f.resolution_type = 'site_visit') AS site_visits,
                       ROUND(AVG(CASE WHEN f.resolved_at IS NOT NULL
                                 THEN TIMESTAMPDIFF(HOUR, f.created_at, f.resolved_at) END), 1)
                         AS avg_resolution_hours,
                       SUM(f.status IN ('open', 'claimed')
                           AND f.expected_turnaround_at IS NOT NULL
                           AND f.expected_turnaround_at < NOW()) AS overdue
                FROM crm_technical_flags f
                JOIN crm_technical_queues q ON q.id = f.routed_to_queue_id
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
