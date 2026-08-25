<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

class CrmAccountContextRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findByParty(int $partyId): ?array
    {
        if (!TableSchema::hasTable('crm_account_context')) {
            return null;
        }
        $updatedJoin = TableSchema::hasColumn('crm_account_context', 'updated_by_user_id')
            ? 'LEFT JOIN users u ON u.id = c.updated_by_user_id'
            : 'LEFT JOIN users u ON 1=0';
        return $this->database->fetch(
            "SELECT c.*, u.name AS updated_by_name
             FROM crm_account_context c
             {$updatedJoin}
             WHERE c.party_id = ?",
            [$partyId]
        );
    }

    public function upsert(int $partyId, array $data): void
    {
        $hasUpdatedBy = TableSchema::hasColumn('crm_account_context', 'updated_by_user_id');
        if ($hasUpdatedBy) {
            $this->database->execute(
                "INSERT INTO crm_account_context (party_id, production_capacity_note, seasonality_note, updated_by_user_id, updated_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    production_capacity_note = VALUES(production_capacity_note),
                    seasonality_note = VALUES(seasonality_note),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    updated_at = NOW()",
                [
                    $partyId,
                    $data['production_capacity_note'],
                    $data['seasonality_note'],
                    $data['updated_by_user_id'],
                ]
            );
            return;
        }
        $this->database->execute(
            "INSERT INTO crm_account_context (party_id, production_capacity_note, seasonality_note, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                production_capacity_note = VALUES(production_capacity_note),
                seasonality_note = VALUES(seasonality_note),
                updated_at = NOW()",
            [
                $partyId,
                $data['production_capacity_note'],
                $data['seasonality_note'],
            ]
        );
    }
}
