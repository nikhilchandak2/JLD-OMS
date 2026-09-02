<?php

namespace App\Repositories;

use App\Core\Database;

class CreditOverrideRequestRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findById(int $id): ?array
    {
        if (!\App\Support\TableSchema::hasTable('credit_override_requests')) {
            return null;
        }
        $dealJoin = \App\Support\TableSchema::leftJoinOrStub('crm_deals', 'd', 'd.id = r.deal_id', ['id', 'title', 'stage']);
        return $this->database->fetch(
            "SELECT r.*,
                    p.name AS party_name,
                    c.name AS company_name,
                    req.name AS requested_by_name,
                    decider.name AS decided_by_name,
                    o.order_no,
                    d.title AS deal_title,
                    d.stage AS deal_stage
             FROM credit_override_requests r
             JOIN parties p ON p.id = r.party_id
             JOIN companies c ON c.id = r.company_id
             LEFT JOIN users req ON req.id = r.requested_by_user_id
             LEFT JOIN users decider ON decider.id = r.decided_by_user_id
             LEFT JOIN orders o ON o.id = r.order_id
             {$dealJoin}
             WHERE r.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        $this->database->execute(
            "INSERT INTO credit_override_requests (
                company_id, deal_id, order_id, party_id, requested_by_user_id, requested_at, expires_at,
                tier, credit_limit_snapshot, outstanding_snapshot, outstanding_breakdown, ledger_as_of,
                incomplete_feed_entities, proposed_order_value, computed_overage, rep_reason, status,
                required_approver_count
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1)",
            [
                $data['company_id'],
                $data['deal_id'],
                $data['order_id'],
                $data['party_id'],
                $data['requested_by_user_id'],
                $data['requested_at'],
                $data['expires_at'],
                $data['tier'],
                $data['credit_limit_snapshot'],
                $data['outstanding_snapshot'],
                $data['outstanding_breakdown'],
                $data['ledger_as_of'],
                $data['incomplete_feed_entities'],
                $data['proposed_order_value'],
                $data['computed_overage'],
                $data['rep_reason'],
            ]
        );

        return (int)$this->database->lastInsertId();
    }

    public function applyDecision(
        int $id,
        string $status,
        int $decidedBy,
        string $decidedAt,
        ?float $modifiedLimitValue,
        ?string $decisionNote
    ): void {
        $this->database->execute(
            "UPDATE credit_override_requests
             SET status = ?, decided_by_user_id = ?, decided_at = ?, modified_limit_value = ?, decision_note = ?
             WHERE id = ?",
            [$status, $decidedBy, $decidedAt, $modifiedLimitValue, $decisionNote, $id]
        );
    }

    public function applyStatus(int $id, string $status, ?string $note = null): void
    {
        if ($note !== null) {
            $this->database->execute(
                "UPDATE credit_override_requests SET status = ?, decision_note = ? WHERE id = ?",
                [$status, $note, $id]
            );
            return;
        }
        $this->database->execute(
            "UPDATE credit_override_requests SET status = ? WHERE id = ?",
            [$status, $id]
        );
    }

    public function findOpenForDeal(int $dealId): ?array
    {
        if (!\App\Support\TableSchema::hasTable('credit_override_requests')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT * FROM credit_override_requests
             WHERE deal_id = ? AND status IN ('pending', 'call_requested')
             ORDER BY id DESC LIMIT 1",
            [$dealId]
        );
    }

    public function findOpenForOrder(int $orderId): ?array
    {
        if (!\App\Support\TableSchema::hasTable('credit_override_requests')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT * FROM credit_override_requests
             WHERE order_id = ? AND status IN ('pending', 'call_requested')
             ORDER BY id DESC LIMIT 1",
            [$orderId]
        );
    }

    public function findApprovedForDeal(int $dealId): ?array
    {
        if (!\App\Support\TableSchema::hasTable('credit_override_requests')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT * FROM credit_override_requests
             WHERE deal_id = ? AND status IN ('approved', 'approved_with_modified_limit')
             ORDER BY id DESC LIMIT 1",
            [$dealId]
        );
    }

    public function listQueue(array $filters = []): array
    {
        if (!\App\Support\TableSchema::hasTable('credit_override_requests')) {
            return [];
        }
        $dealJoin = \App\Support\TableSchema::leftJoinOrStub('crm_deals', 'd', 'd.id = r.deal_id', ['id', 'title', 'stage']);
        $sql = "SELECT r.*,
                       p.name AS party_name,
                       c.name AS company_name,
                       req.name AS requested_by_name,
                       o.order_no,
                       d.title AS deal_title
                FROM credit_override_requests r
                JOIN parties p ON p.id = r.party_id
                JOIN companies c ON c.id = r.company_id
                LEFT JOIN users req ON req.id = r.requested_by_user_id
                LEFT JOIN orders o ON o.id = r.order_id
                {$dealJoin}
                WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND r.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['tier'])) {
            $sql .= " AND r.tier = ?";
            $params[] = (int)$filters['tier'];
        }
        if (!empty($filters['party_id'])) {
            $sql .= " AND r.party_id = ?";
            $params[] = (int)$filters['party_id'];
        }
        if (!empty($filters['open_only'])) {
            $sql .= " AND r.status IN ('pending', 'call_requested')";
        }

        $sql .= " ORDER BY r.tier DESC, r.requested_at ASC";

        return $this->database->fetchAll($sql, $params);
    }

    public function historyForParty(int $partyId, ?int $excludeId = null, int $limit = 20): array
    {
        $sql = "SELECT r.*, req.name AS requested_by_name, decider.name AS decided_by_name
                FROM credit_override_requests r
                LEFT JOIN users req ON req.id = r.requested_by_user_id
                LEFT JOIN users decider ON decider.id = r.decided_by_user_id
                WHERE r.party_id = ?";
        $params = [$partyId];
        if ($excludeId) {
            $sql .= " AND r.id <> ?";
            $params[] = $excludeId;
        }
        $sql .= " ORDER BY r.requested_at DESC LIMIT " . max(1, $limit);

        return $this->database->fetchAll($sql, $params);
    }

    public function volumeByTier(): array
    {
        if (!\App\Support\TableSchema::hasTable('credit_override_requests')) {
            return [];
        }
        return $this->database->fetchAll(
            "SELECT tier, status, COUNT(*) AS count
             FROM credit_override_requests
             GROUP BY tier, status
             ORDER BY tier, status"
        );
    }

    public function findExpirable(string $now): array
    {
        return $this->database->fetchAll(
            "SELECT * FROM credit_override_requests
             WHERE status IN ('pending', 'call_requested')
               AND expires_at IS NOT NULL
               AND expires_at <= ?",
            [$now]
        );
    }
}
