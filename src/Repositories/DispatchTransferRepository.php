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
}
