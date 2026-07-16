<?php

namespace App\Repositories;

use App\Core\Database;

class CreditNoteRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function create(array $data): int
    {
        $sql = "
            INSERT INTO credit_notes (
                party_id, dispatch_id, order_id, busy_credit_note_no, original_invoice_no,
                amount, weight_tons, rate_per_ton, note_date, reason, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $this->database->execute($sql, [
            $data['party_id'],
            $data['dispatch_id'] ?? null,
            $data['order_id'] ?? null,
            $data['busy_credit_note_no'] ?? null,
            $data['original_invoice_no'] ?? null,
            $data['amount'],
            $data['weight_tons'] ?? null,
            $data['rate_per_ton'] ?? null,
            $data['note_date'],
            $data['reason'] ?? null,
            $data['status'] ?? 'posted',
            $data['created_by'] ?? null,
        ]);

        return (int)$this->database->lastInsertId();
    }

    public function findByDispatchId(int $dispatchId): array
    {
        $sql = "SELECT * FROM credit_notes WHERE dispatch_id = ? ORDER BY id DESC";
        return $this->database->fetchAll($sql, [$dispatchId]);
    }

    public function findByOrderId(int $orderId): array
    {
        $sql = "
            SELECT cn.*, p.name AS party_name
            FROM credit_notes cn
            JOIN parties p ON cn.party_id = p.id
            WHERE cn.order_id = ?
            ORDER BY cn.id DESC
        ";
        return $this->database->fetchAll($sql, [$orderId]);
    }

    public function deleteByOrderId(int $orderId): void
    {
        $this->database->execute(
            "DELETE cn FROM credit_notes cn
             LEFT JOIN dispatches d ON cn.dispatch_id = d.id
             WHERE cn.order_id = ? OR d.order_id = ?",
            [$orderId, $orderId]
        );
    }
}
