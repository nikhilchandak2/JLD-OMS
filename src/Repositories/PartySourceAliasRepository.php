<?php

namespace App\Repositories;

use App\Core\Database;

class PartySourceAliasRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function find(string $sourceSystem, string $identifier): ?array
    {
        return $this->database->fetch(
            "SELECT * FROM party_source_aliases WHERE source_system = ? AND source_identifier = ?",
            [$sourceSystem, $identifier]
        );
    }

    public function mapForSystem(string $sourceSystem): array
    {
        $rows = $this->database->fetchAll(
            "SELECT source_identifier, party_id FROM party_source_aliases WHERE source_system = ?",
            [$sourceSystem]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row['source_identifier']] = (int)$row['party_id'];
        }

        return $map;
    }

    public function create(array $data): int
    {
        $this->database->execute(
            "INSERT INTO party_source_aliases (source_system, source_identifier, party_id, confidence, created_by_user_id)
             VALUES (?, ?, ?, ?, ?)",
            [
                $data['source_system'],
                $data['source_identifier'],
                $data['party_id'],
                $data['confidence'] ?? 'manual',
                $data['created_by_user_id'] ?? null,
            ]
        );

        return (int)$this->database->lastInsertId();
    }

    public function listAll(): array
    {
        return $this->database->fetchAll(
            "SELECT a.*, p.name AS party_name
             FROM party_source_aliases a
             JOIN parties p ON p.id = a.party_id
             ORDER BY a.source_system, a.source_identifier"
        );
    }
}
