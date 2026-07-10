<?php

namespace App\Repositories;

use App\Core\Database;

class CreditApprovalRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function createRequest(
        int $orderId,
        int $partyId,
        float $outstanding,
        float $creditLimit,
        int $requestedBy
    ): int {
        $sql = "
            INSERT INTO credit_approval_requests
                (order_id, party_id, outstanding, credit_limit, requested_by)
            VALUES
                (?, ?, ?, ?, ?)
        ";

        $this->database->execute($sql, [
            $orderId,
            $partyId,
            $outstanding,
            $creditLimit,
            $requestedBy
        ]);

        return (int)$this->database->lastInsertId();
    }

    /**
     * Party-level credit request (no order attached). Raised by sales when
     * order creation is blocked because the party is over its credit limit.
     */
    public function createPartyRequest(
        int $partyId,
        float $outstanding,
        float $creditLimit,
        ?float $requestedLimitIncrease,
        ?string $reason,
        int $requestedBy
    ): int {
        $sql = "
            INSERT INTO credit_approval_requests
                (order_id, party_id, outstanding, credit_limit, requested_limit_increase, reason, requested_by)
            VALUES
                (NULL, ?, ?, ?, ?, ?, ?)
        ";

        $this->database->execute($sql, [
            $partyId,
            $outstanding,
            $creditLimit,
            $requestedLimitIncrease,
            $reason,
            $requestedBy
        ]);

        return (int)$this->database->lastInsertId();
    }

    /** Counts ALL requests (pending/approved/rejected) raised for a party in the given calendar month. */
    public function countRequestsForPartyInMonth(int $partyId, string $yearMonth): int
    {
        $sql = "
            SELECT COUNT(*) AS cnt
            FROM credit_approval_requests
            WHERE party_id = ? AND DATE_FORMAT(requested_at, '%Y-%m') = ?
        ";
        $row = $this->database->fetch($sql, [$partyId, $yearMonth]);
        return (int)($row['cnt'] ?? 0);
    }

    public function hasPendingForParty(int $partyId): bool
    {
        $sql = "SELECT id FROM credit_approval_requests WHERE party_id = ? AND status = 'pending' LIMIT 1";
        $row = $this->database->fetch($sql, [$partyId]);
        return (bool)$row;
    }

    public function getForOrder(int $orderId): ?array
    {
        $sql = "SELECT * FROM credit_approval_requests WHERE order_id = ? LIMIT 1";
        $row = $this->database->fetch($sql, [$orderId]);
        return $row ?: null;
    }

    public function getPendingApprovals(): array
    {
        // order_id is optional: party-level requests have no order attached.
        $sql = "
            SELECT
                car.id,
                car.order_id,
                car.party_id,
                car.outstanding,
                car.credit_limit,
                car.requested_limit_increase,
                car.reason,
                car.status,
                car.requested_at,
                car.decided_at,
                car.decision_note,
                o.order_no,
                o.order_date,
                c.name AS company_name,
                p.name AS party_name,
                pr.name AS product_name,
                req.name AS requested_by_name,
                COALESCE(decider.name, NULL) AS decided_by_name,
                (
                    SELECT COUNT(*)
                    FROM credit_approval_requests car2
                    WHERE car2.party_id = car.party_id
                      AND DATE_FORMAT(car2.requested_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
                ) AS requests_this_month
            FROM credit_approval_requests car
            LEFT JOIN orders o ON car.order_id = o.id
            LEFT JOIN companies c ON o.company_id = c.id
            JOIN parties p ON car.party_id = p.id
            LEFT JOIN products pr ON o.product_id = pr.id
            JOIN users AS req ON car.requested_by = req.id
            LEFT JOIN users AS decider ON car.decided_by = decider.id
            WHERE car.status = 'pending'
            ORDER BY car.requested_at DESC
        ";

        return $this->database->fetchAll($sql);
    }

    /**
     * @param ?float $creditLimitIncrease Positive increment to apply to parties.credit_limit when decision=approved
     */
    public function decide(
        int $approvalId,
        string $decision,
        int $decidedBy,
        ?string $note,
        ?float $creditLimitIncrease
    ): bool
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('decision must be approved or rejected');
        }

        if ($decision === 'approved') {
            $inc = $creditLimitIncrease ?? 0.0;
            if (!is_numeric($inc) || $inc <= 0) {
                throw new \InvalidArgumentException('credit_limit_increase must be a positive number when approving');
            }

            // Multi-table update so we can atomically update both the request record and parties.credit_limit.
            $sql = "
                UPDATE credit_approval_requests car
                JOIN parties p ON p.id = car.party_id
                SET car.status = ?,
                    car.decided_by = ?,
                    car.decided_at = NOW(),
                    car.decision_note = ?,
                    car.credit_limit_increase = ?,
                    car.new_credit_limit = COALESCE(p.credit_limit, 0) + ?,
                    p.credit_limit = COALESCE(p.credit_limit, 0) + ?
                WHERE car.id = ? AND car.status = 'pending'
            ";

            return $this->database->execute($sql, [
                $decision,
                $decidedBy,
                $note,
                $inc,
                $inc,
                $inc,
                $approvalId
            ]);
        }

        $sql = "
            UPDATE credit_approval_requests
            SET status = ?,
                decided_by = ?,
                decided_at = NOW(),
                decision_note = ?
            WHERE id = ? AND status = 'pending'
        ";

        return $this->database->execute($sql, [
            $decision,
            $decidedBy,
            $note,
            $approvalId
        ]);
    }
}

