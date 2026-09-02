<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

class AccountDormancySignalRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function deleteForDate(string $computedOn): void
    {
        $this->database->execute(
            "DELETE FROM account_dormancy_signals WHERE computed_on = ?",
            [$computedOn]
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function insert(array $row): int
    {
        $this->database->execute(
            "INSERT INTO account_dormancy_signals (
                party_id, company_id, computed_on,
                days_since_last_order, last_order_date,
                days_since_last_visit, last_visit_date,
                severity, reason_summary, forecast_gap_flag
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $row['party_id'],
                $row['company_id'],
                $row['computed_on'],
                $row['days_since_last_order'],
                $row['last_order_date'],
                $row['days_since_last_visit'],
                $row['last_visit_date'],
                $row['severity'],
                $row['reason_summary'],
                !empty($row['forecast_gap_flag']) ? 1 : 0,
            ]
        );

        return (int)$this->database->lastInsertId();
    }

    /**
     * Last order / last visit per active party. This is the nightly job's heaviest query.
     *
     * @return array<int,array<string,mixed>>
     */
    public function activitySnapshot(string $asOf): array
    {
        return $this->database->fetchAll($this->activitySnapshotSql(), [$asOf, $asOf]);
    }

    public function activitySnapshotSql(): string
    {
        $owner = TableSchema::hasColumn('parties', 'assigned_sales_owner')
            ? 'p.assigned_sales_owner'
            : 'NULL AS assigned_sales_owner';
        $tier = TableSchema::hasColumn('parties', 'account_tier')
            ? 'p.account_tier'
            : 'NULL AS account_tier';
        $orderForce = TableSchema::forceIndex('orders', 'idx_orders_party_date');
        $visitForce = TableSchema::hasTable('crm_visits')
            ? TableSchema::forceIndex('crm_visits', 'idx_visits_party_date')
            : '';
        $visitJoin = TableSchema::hasTable('crm_visits')
            ? "LEFT JOIN (
                    SELECT v.party_id, MAX(v.visit_date) AS last_visit_date
                    FROM crm_visits v {$visitForce}
                    GROUP BY v.party_id
                ) last_v ON last_v.party_id = p.id"
            : 'LEFT JOIN (SELECT NULL AS party_id, NULL AS last_visit_date) last_v ON 1=0';
        $active = TableSchema::hasColumn('parties', 'is_active') ? 'p.is_active = 1' : '1=1';

        return "SELECT p.id AS party_id,
                       p.name AS party_name,
                       {$owner},
                       {$tier},
                       last_o.last_order_date,
                       last_o.last_order_company_id,
                       DATEDIFF(?, last_o.last_order_date) AS days_since_last_order,
                       last_v.last_visit_date,
                       DATEDIFF(?, last_v.last_visit_date) AS days_since_last_visit
                FROM parties p
                LEFT JOIN (
                    SELECT o.party_id,
                           MAX(o.order_date) AS last_order_date,
                           SUBSTRING_INDEX(GROUP_CONCAT(o.company_id ORDER BY o.order_date DESC, o.id DESC), ',', 1)
                             AS last_order_company_id
                    FROM orders o {$orderForce}
                    GROUP BY o.party_id
                ) last_o ON last_o.party_id = p.id
                {$visitJoin}
                WHERE {$active}";
    }

    /** @return array<int,array<string,mixed>> */
    public function explainActivitySnapshot(string $asOf): array
    {
        return $this->database->fetchAll('EXPLAIN ' . $this->activitySnapshotSql(), [$asOf, $asOf]);
    }

    /**
     * Inner aggregate that dominates the nightly job. Used by the EXPLAIN assertion.
     *
     * @return array<int,array<string,mixed>>
     */
    public function explainLastOrderAggregate(): array
    {
        return $this->database->fetchAll(
            "EXPLAIN SELECT o.party_id, MAX(o.order_date) AS last_order_date
             FROM orders o " . TableSchema::forceIndex('orders', 'idx_orders_party_date') . "
             GROUP BY o.party_id"
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findForDate(string $computedOn, ?int $ownerUserId = null): array
    {
        if (!TableSchema::hasTable('account_dormancy_signals')) {
            return [];
        }
        $ownerSelect = TableSchema::hasColumn('parties', 'assigned_sales_owner')
            ? 'p.assigned_sales_owner'
            : 'NULL AS assigned_sales_owner';
        $ownerJoin = TableSchema::hasColumn('parties', 'assigned_sales_owner')
            ? 'LEFT JOIN users u ON u.id = p.assigned_sales_owner'
            : 'LEFT JOIN users u ON 1=0';
        $sql = "SELECT s.*, p.name AS party_name, {$ownerSelect}, u.name AS owner_name
                FROM account_dormancy_signals s
                JOIN parties p ON p.id = s.party_id
                {$ownerJoin}
                WHERE s.computed_on = ?";
        $params = [$computedOn];
        if ($ownerUserId !== null && TableSchema::hasColumn('parties', 'assigned_sales_owner')) {
            $sql .= " AND p.assigned_sales_owner = ?";
            $params[] = $ownerUserId;
        }
        $sql .= " ORDER BY FIELD(s.severity, 'urgent', 'watch'), s.days_since_last_order DESC, p.name ASC";

        return $this->database->fetchAll($sql, $params);
    }

    public function latestForParty(int $partyId): ?array
    {
        if (!TableSchema::hasTable('account_dormancy_signals')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT s.*, p.name AS party_name
             FROM account_dormancy_signals s
             JOIN parties p ON p.id = s.party_id
             WHERE s.party_id = ?
             ORDER BY s.computed_on DESC, s.id DESC
             LIMIT 1",
            [$partyId]
        );
    }
}
