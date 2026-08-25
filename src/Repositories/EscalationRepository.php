<?php

namespace App\Repositories;

use App\Core\Database;

class EscalationRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function create(array $data): int
    {
        $snapshot = $data['context_snapshot'];
        if (is_array($snapshot)) {
            $snapshot = json_encode($snapshot);
        }
        $this->database->execute(
            "INSERT INTO escalations (
                company_id, party_id, deal_id, trigger_type, source_table, source_id, episode_key,
                triggered_on, triggered_by, triggered_by_user_id, context_snapshot, status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open')",
            [
                $data['company_id'],
                $data['party_id'],
                $data['deal_id'],
                $data['trigger_type'],
                $data['source_table'],
                $data['source_id'],
                $data['episode_key'],
                $data['triggered_on'],
                $data['triggered_by'],
                $data['triggered_by_user_id'],
                $snapshot,
            ]
        );

        return (int)$this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $row = $this->database->fetch(
            "SELECT e.*, p.name AS party_name, u.name AS triggered_by_name,
                    a.name AS acknowledged_by_name, d.title AS deal_title
             FROM escalations e
             JOIN parties p ON p.id = e.party_id
             LEFT JOIN users u ON u.id = e.triggered_by_user_id
             LEFT JOIN users a ON a.id = e.acknowledged_by_user_id
             LEFT JOIN crm_deals d ON d.id = e.deal_id
             WHERE e.id = ?",
            [$id]
        );

        return $row === null ? null : $this->decode($row);
    }

    public function findEpisode(int $partyId, string $triggerType, string $episodeKey): ?array
    {
        $row = $this->database->fetch(
            "SELECT * FROM escalations
             WHERE party_id = ? AND trigger_type = ? AND episode_key = ?
             ORDER BY id DESC LIMIT 1",
            [$partyId, $triggerType, $episodeKey]
        );

        return $row === null ? null : $this->decode($row);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findInbox(?string $status = null): array
    {
        $sql = "SELECT e.*, p.name AS party_name, u.name AS triggered_by_name,
                       a.name AS acknowledged_by_name
                FROM escalations e
                JOIN parties p ON p.id = e.party_id
                LEFT JOIN users u ON u.id = e.triggered_by_user_id
                LEFT JOIN users a ON a.id = e.acknowledged_by_user_id
                WHERE 1 = 1";
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= " AND e.status = ?";
            $params[] = $status;
        } else {
            $sql .= " AND e.status IN ('open', 'acknowledged')";
        }
        $sql .= " ORDER BY FIELD(e.status, 'open', 'acknowledged'), e.triggered_on ASC, e.id ASC";

        return array_map([$this, 'decode'], $this->database->fetchAll($sql, $params));
    }

    public function acknowledge(int $id, int $userId): void
    {
        $this->database->execute(
            "UPDATE escalations
             SET status = 'acknowledged', acknowledged_by_user_id = ?, acknowledged_at = NOW()
             WHERE id = ? AND status = 'open'",
            [$userId, $id]
        );
    }

    public function close(int $id, string $status, string $note): void
    {
        $this->database->execute(
            "UPDATE escalations
             SET status = ?, resolution_note = ?, resolved_at = NOW()
             WHERE id = ? AND status IN ('open', 'acknowledged')",
            [$status, $note, $id]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findOpenForSource(string $sourceTable, int $sourceId): array
    {
        return array_map(
            [$this, 'decode'],
            $this->database->fetchAll(
                "SELECT * FROM escalations
                 WHERE source_table = ? AND source_id = ? AND status IN ('open', 'acknowledged')",
                [$sourceTable, $sourceId]
            )
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findOpenByType(string $triggerType): array
    {
        return array_map(
            [$this, 'decode'],
            $this->database->fetchAll(
                "SELECT * FROM escalations
                 WHERE trigger_type = ? AND status IN ('open', 'acknowledged')",
                [$triggerType]
            )
        );
    }

    /** @param array<string,mixed> $row */
    private function decode(array $row): array
    {
        if (isset($row['context_snapshot']) && is_string($row['context_snapshot'])) {
            $decoded = json_decode($row['context_snapshot'], true);
            $row['context_snapshot'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }
}
