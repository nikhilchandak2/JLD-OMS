<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

class CreditOverrideRequestRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findById(int $id): ?array
    {
        if (!TableSchema::hasTable('credit_override_requests')) {
            return null;
        }
        $dealJoin = TableSchema::leftJoinOrStub('crm_deals', 'd', 'd.id = r.deal_id', ['id', 'title', 'stage']);
        $reqJoin = TableSchema::leftJoinIfColumn(
            'credit_override_requests',
            'requested_by_user_id',
            'users',
            'req',
            'req.id = r.requested_by_user_id'
        );
        $decJoin = TableSchema::leftJoinIfColumn(
            'credit_override_requests',
            'decided_by_user_id',
            'users',
            'decider',
            'decider.id = r.decided_by_user_id'
        );
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
             {$reqJoin}
             {$decJoin}
             LEFT JOIN orders o ON o.id = r.order_id
             {$dealJoin}
             WHERE r.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        $built = TableSchema::insertSql('credit_override_requests', [
            'company_id' => $data['company_id'],
            'deal_id' => $data['deal_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'party_id' => $data['party_id'],
            'requested_by_user_id' => $data['requested_by_user_id'] ?? null,
            'requested_at' => $data['requested_at'],
            'expires_at' => $data['expires_at'] ?? null,
            'tier' => $data['tier'],
            'credit_limit_snapshot' => $data['credit_limit_snapshot'] ?? null,
            'outstanding_snapshot' => $data['outstanding_snapshot'] ?? 0,
            'outstanding_breakdown' => $data['outstanding_breakdown'] ?? null,
            'ledger_as_of' => $data['ledger_as_of'] ?? null,
            'incomplete_feed_entities' => $data['incomplete_feed_entities'] ?? null,
            'proposed_order_value' => $data['proposed_order_value'] ?? 0,
            'computed_overage' => $data['computed_overage'] ?? 0,
            'rep_reason' => $data['rep_reason'] ?? '',
            'status' => 'pending',
            'required_approver_count' => 1,
        ]);
        if ($built === null) {
            throw new \RuntimeException('credit_override_requests is not available.');
        }
        $this->database->execute($built[0], $built[1]);

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
        $sets = [];
        $params = [];
        if (TableSchema::hasColumn('credit_override_requests', 'status')) {
            $sets[] = 'status = ?';
            $params[] = $status;
        }
        if (TableSchema::hasColumn('credit_override_requests', 'decided_by_user_id')) {
            $sets[] = 'decided_by_user_id = ?';
            $params[] = $decidedBy;
        }
        if (TableSchema::hasColumn('credit_override_requests', 'decided_at')) {
            $sets[] = 'decided_at = ?';
            $params[] = $decidedAt;
        }
        if (TableSchema::hasColumn('credit_override_requests', 'modified_limit_value')) {
            $sets[] = 'modified_limit_value = ?';
            $params[] = $modifiedLimitValue;
        }
        if (TableSchema::hasColumn('credit_override_requests', 'decision_note')) {
            $sets[] = 'decision_note = ?';
            $params[] = $decisionNote;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->database->execute(
            'UPDATE credit_override_requests SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }

    public function applyStatus(int $id, string $status, ?string $note = null): void
    {
        $sets = [];
        $params = [];
        if (TableSchema::hasColumn('credit_override_requests', 'status')) {
            $sets[] = 'status = ?';
            $params[] = $status;
        }
        if ($note !== null && TableSchema::hasColumn('credit_override_requests', 'decision_note')) {
            $sets[] = 'decision_note = ?';
            $params[] = $note;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->database->execute(
            'UPDATE credit_override_requests SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }

    public function findOpenForDeal(int $dealId): ?array
    {
        if (!TableSchema::hasTable('credit_override_requests')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT * FROM credit_override_requests
             WHERE deal_id = ?"
            . (TableSchema::hasColumn('credit_override_requests', 'status')
                ? " AND status IN ('pending', 'call_requested')"
                : '') . "
             ORDER BY id DESC LIMIT 1",
            [$dealId]
        );
    }

    public function findOpenForOrder(int $orderId): ?array
    {
        if (!TableSchema::hasTable('credit_override_requests')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT * FROM credit_override_requests
             WHERE order_id = ?"
            . (TableSchema::hasColumn('credit_override_requests', 'status')
                ? " AND status IN ('pending', 'call_requested')"
                : '') . "
             ORDER BY id DESC LIMIT 1",
            [$orderId]
        );
    }

    public function findApprovedForDeal(int $dealId): ?array
    {
        if (!TableSchema::hasTable('credit_override_requests')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT * FROM credit_override_requests
             WHERE deal_id = ?"
            . (TableSchema::hasColumn('credit_override_requests', 'status')
                ? " AND status IN ('approved', 'approved_with_modified_limit')"
                : '') . "
             ORDER BY id DESC LIMIT 1",
            [$dealId]
        );
    }

    public function listQueue(array $filters = []): array
    {
        if (!TableSchema::hasTable('credit_override_requests')) {
            return [];
        }
        $dealJoin = TableSchema::leftJoinOrStub('crm_deals', 'd', 'd.id = r.deal_id', ['id', 'title', 'stage']);
        $reqJoin = TableSchema::leftJoinIfColumn(
            'credit_override_requests',
            'requested_by_user_id',
            'users',
            'req',
            'req.id = r.requested_by_user_id'
        );
        $sql = "SELECT r.*,
                       p.name AS party_name,
                       c.name AS company_name,
                       req.name AS requested_by_name,
                       o.order_no,
                       d.title AS deal_title
                FROM credit_override_requests r
                JOIN parties p ON p.id = r.party_id
                JOIN companies c ON c.id = r.company_id
                {$reqJoin}
                LEFT JOIN orders o ON o.id = r.order_id
                {$dealJoin}
                WHERE 1=1";
        $params = [];

        if (!empty($filters['status']) && TableSchema::hasColumn('credit_override_requests', 'status')) {
            $sql .= " AND r.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['tier']) && TableSchema::hasColumn('credit_override_requests', 'tier')) {
            $sql .= " AND r.tier = ?";
            $params[] = (int)$filters['tier'];
        }
        if (!empty($filters['party_id'])) {
            $sql .= " AND r.party_id = ?";
            $params[] = (int)$filters['party_id'];
        }
        if (!empty($filters['open_only']) && TableSchema::hasColumn('credit_override_requests', 'status')) {
            $sql .= " AND r.status IN ('pending', 'call_requested')";
        }

        $order = [];
        if (TableSchema::hasColumn('credit_override_requests', 'tier')) {
            $order[] = 'r.tier DESC';
        }
        if (TableSchema::hasColumn('credit_override_requests', 'requested_at')) {
            $order[] = 'r.requested_at ASC';
        }
        $order[] = 'r.id ASC';
        $sql .= ' ORDER BY ' . implode(', ', $order);

        return $this->database->fetchAll($sql, $params);
    }

    public function historyForParty(int $partyId, ?int $excludeId = null, int $limit = 20): array
    {
        if (!TableSchema::hasTable('credit_override_requests')) {
            return [];
        }
        $reqJoin = TableSchema::leftJoinIfColumn(
            'credit_override_requests',
            'requested_by_user_id',
            'users',
            'req',
            'req.id = r.requested_by_user_id'
        );
        $decJoin = TableSchema::leftJoinIfColumn(
            'credit_override_requests',
            'decided_by_user_id',
            'users',
            'decider',
            'decider.id = r.decided_by_user_id'
        );
        $sql = "SELECT r.*, req.name AS requested_by_name, decider.name AS decided_by_name
                FROM credit_override_requests r
                {$reqJoin}
                {$decJoin}
                WHERE r.party_id = ?";
        $params = [$partyId];
        if ($excludeId) {
            $sql .= " AND r.id <> ?";
            $params[] = $excludeId;
        }
        $order = TableSchema::hasColumn('credit_override_requests', 'requested_at')
            ? 'r.requested_at DESC'
            : 'r.id DESC';
        $sql .= " ORDER BY {$order} LIMIT " . max(1, $limit);

        return $this->database->fetchAll($sql, $params);
    }

    public function volumeByTier(): array
    {
        if (!TableSchema::hasTable('credit_override_requests')) {
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
        if (!TableSchema::hasTable('credit_override_requests')
            || !TableSchema::hasColumn('credit_override_requests', 'expires_at')) {
            return [];
        }
        $status = TableSchema::hasColumn('credit_override_requests', 'status')
            ? "status IN ('pending', 'call_requested') AND"
            : '';
        return $this->database->fetchAll(
            "SELECT * FROM credit_override_requests
             WHERE {$status} expires_at IS NOT NULL AND expires_at <= ?",
            [$now]
        );
    }
}
