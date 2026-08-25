<?php

namespace App\Repositories;

use App\Core\Database;

class PartyHandoverNoteRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    /** @return list<array<string,mixed>> */
    public function findActiveByParty(int $partyId): array
    {
        if (!\App\Support\TableSchema::hasTable('party_handover_notes')) {
            return [];
        }
        return $this->database->fetchAll(
            "SELECT n.id, n.party_id, n.author_user_id, n.note, n.created_at, n.is_active,
                    u.name AS author_name
             FROM party_handover_notes n
             LEFT JOIN users u ON u.id = n.author_user_id
             WHERE n.party_id = ? AND n.is_active = 1
             ORDER BY n.created_at DESC, n.id DESC",
            [$partyId]
        );
    }

    public function create(int $partyId, ?int $authorUserId, string $note): int
    {
        $this->database->execute(
            "INSERT INTO party_handover_notes (party_id, author_user_id, note, is_active)
             VALUES (?, ?, ?, 1)",
            [$partyId, $authorUserId, $note]
        );

        return (int)$this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetch(
            "SELECT n.*, u.name AS author_name
             FROM party_handover_notes n
             LEFT JOIN users u ON u.id = n.author_user_id
             WHERE n.id = ?",
            [$id]
        );
    }
}
