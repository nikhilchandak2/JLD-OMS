<?php

namespace App\Repositories;

use App\Core\Database;

class DispatchTransferRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function create(array $data): int
    {
        $sql = "
            INSERT INTO dispatch_transfers (
                source_dispatch_id, target_dispatch_id, source_order_id, target_order_id,
                source_party_id, target_party_id, trucks_transferred, weight_tons,
                action_type, reason, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $this->database->execute($sql, [
            $data['source_dispatch_id'],
            $data['target_dispatch_id'] ?? null,
            $data['source_order_id'],
            $data['target_order_id'] ?? null,
            $data['source_party_id'],
            $data['target_party_id'] ?? null,
            $data['trucks_transferred'] ?? 1,
            $data['weight_tons'] ?? null,
            $data['action_type'],
            $data['reason'] ?? null,
            $data['created_by'] ?? null,
        ]);

        return (int)$this->database->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $filters = []): array
    {
        if (!\App\Support\TableSchema::hasTable('dispatch_transfers')) {
            return [];
        }
        $busyInvoice = \App\Support\TableSchema::hasColumn('dispatches', 'busy_invoice_no')
            ? 'src_d.busy_invoice_no AS source_invoice_no, tgt_d.busy_invoice_no AS target_invoice_no'
            : 'NULL AS source_invoice_no, NULL AS target_invoice_no';
        $srcStatus = \App\Support\TableSchema::hasColumn('dispatches', 'status')
            ? 'src_d.status AS source_dispatch_status'
            : "'active' AS source_dispatch_status";
        $creditJoin = \App\Support\TableSchema::hasTable('credit_notes')
            ? 'LEFT JOIN credit_notes cn ON cn.dispatch_id = dt.source_dispatch_id'
            : 'LEFT JOIN (SELECT NULL AS dispatch_id, NULL AS busy_credit_note_no, NULL AS amount, NULL AS note_date) cn ON 1=0';
        $sql = "
            SELECT
                dt.id,
                dt.action_type,
                dt.trucks_transferred,
                dt.weight_tons,
                dt.reason,
                dt.created_at,
                dt.source_dispatch_id,
                dt.target_dispatch_id,
                dt.source_order_id,
                dt.target_order_id,
                src_d.dispatch_date AS source_dispatch_date,
                {$busyInvoice},
                {$srcStatus},
                tgt_d.dispatch_date AS transfer_date,
                src_o.order_no AS source_order_no,
                tgt_o.order_no AS target_order_no,
                src_p.name AS source_party_name,
                tgt_p.name AS target_party_name,
                cn.busy_credit_note_no,
                cn.amount AS credit_amount,
                cn.note_date AS credit_note_date,
                u.name AS created_by_name
            FROM dispatch_transfers dt
            JOIN dispatches src_d ON dt.source_dispatch_id = src_d.id
            JOIN orders src_o ON dt.source_order_id = src_o.id
            JOIN parties src_p ON dt.source_party_id = src_p.id
            LEFT JOIN dispatches tgt_d ON dt.target_dispatch_id = tgt_d.id
            LEFT JOIN orders tgt_o ON dt.target_order_id = tgt_o.id
            LEFT JOIN parties tgt_p ON dt.target_party_id = tgt_p.id
            {$creditJoin}
            LEFT JOIN users u ON dt.created_by = u.id
            WHERE 1=1
        ";

        $params = $this->applyFilters($sql, $filters);
        $sql .= " ORDER BY COALESCE(tgt_d.dispatch_date, DATE(dt.created_at)) DESC, dt.id DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
            if (isset($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int)$filters['offset'];
            }
        }

        return $this->database->fetchAll($sql, $params);
    }

    public function count(array $filters = []): int
    {
        if (!\App\Support\TableSchema::hasTable('dispatch_transfers')) {
            return 0;
        }
        $sql = "
            SELECT COUNT(*) AS c
            FROM dispatch_transfers dt
            JOIN dispatches src_d ON dt.source_dispatch_id = src_d.id
            JOIN orders src_o ON dt.source_order_id = src_o.id
            LEFT JOIN dispatches tgt_d ON dt.target_dispatch_id = tgt_d.id
            WHERE 1=1
        ";

        $params = $this->applyFilters($sql, $filters);
        $row = $this->database->fetch($sql, $params);
        return (int)($row['c'] ?? 0);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, mixed>
     */
    private function applyFilters(string &$sql, array $filters): array
    {
        $params = [];

        if (!empty($filters['company_id'])) {
            $sql .= " AND src_o.company_id = ?";
            $params[] = (int)$filters['company_id'];
        }

        if (!empty($filters['action_type'])
            && in_array($filters['action_type'], ['transfer', 'replacement', 'credit_note'], true)) {
            $sql .= " AND dt.action_type = ?";
            $params[] = $filters['action_type'];
        }

        if (!empty($filters['order_id'])) {
            $sql .= " AND (dt.source_order_id = ? OR dt.target_order_id = ?)";
            $params[] = (int)$filters['order_id'];
            $params[] = (int)$filters['order_id'];
        }

        if (!empty($filters['start_date'])) {
            $sql .= " AND DATE(COALESCE(tgt_d.dispatch_date, dt.created_at)) >= ?";
            $params[] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND DATE(COALESCE(tgt_d.dispatch_date, dt.created_at)) <= ?";
            $params[] = $filters['end_date'];
        }

        return $params;
    }

    public function deleteByOrderId(int $orderId): void
    {
        if (!\App\Support\TableSchema::hasTable('dispatch_transfers')) {
            return;
        }
        $this->database->execute(
            "DELETE dt FROM dispatch_transfers dt
             LEFT JOIN dispatches sd ON dt.source_dispatch_id = sd.id
             LEFT JOIN dispatches td ON dt.target_dispatch_id = td.id
             WHERE dt.source_order_id = ?
                OR dt.target_order_id = ?
                OR sd.order_id = ?
                OR td.order_id = ?",
            [$orderId, $orderId, $orderId, $orderId]
        );
    }

    public function deleteByDispatchId(int $dispatchId): void
    {
        $this->database->execute(
            'DELETE FROM dispatch_transfers
             WHERE source_dispatch_id = ? OR target_dispatch_id = ?',
            [$dispatchId, $dispatchId]
        );
    }
}
