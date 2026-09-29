<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

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
        $built = TableSchema::insertSql('escalations', [
            'company_id' => $data['company_id'] ?? null,
            'party_id' => $data['party_id'],
            'deal_id' => $data['deal_id'] ?? null,
            'trigger_type' => $data['trigger_type'],
            'source_table' => $data['source_table'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'episode_key' => $data['episode_key'] ?? null,
            'triggered_on' => $data['triggered_on'] ?? null,
            'triggered_by' => $data['triggered_by'] ?? null,
            'triggered_by_user_id' => $data['triggered_by_user_id'] ?? null,
            'context_snapshot' => $snapshot,
            'status' => 'open',
        ]);
        if ($built === null) {
            throw new \RuntimeException('escalations is not available.');
        }
        $this->database->execute($built[0], $built[1]);

        return (int)$this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        if (!TableSchema::hasTable('escalations')) {
            return null;
        }
        $dealJoin = TableSchema::leftJoinOrStub('crm_deals', 'd', 'd.id = e.deal_id', ['id', 'title']);
        $triggeredJoin = TableSchema::leftJoinIfColumn(
            'escalations',
            'triggered_by_user_id',
            'users',
            'u',
            'u.id = e.triggered_by_user_id'
        );
        $ackedJoin = TableSchema::leftJoinIfColumn(
            'escalations',
            'acknowledged_by_user_id',
            'users',
            'a',
            'a.id = e.acknowledged_by_user_id'
        );
        $row = $this->database->fetch(
            "SELECT e.*, p.name AS party_name, u.name AS triggered_by_name,
                    a.name AS acknowledged_by_name, d.title AS deal_title
             FROM escalations e
             JOIN parties p ON p.id = e.party_id
             {$triggeredJoin}
             {$ackedJoin}
             {$dealJoin}
             WHERE e.id = ?",
            [$id]
        );

        return $row === null ? null : $this->decode($row);
    }

    public function findEpisode(int $partyId, string $triggerType, string $episodeKey): ?array
    {
        if (!TableSchema::hasTable('escalations')) {
            return null;
        }
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
        if (!TableSchema::hasTable('escalations')) {
            return [];
        }
        $triggeredJoin = TableSchema::leftJoinIfColumn(
            'escalations',
            'triggered_by_user_id',
            'users',
            'u',
            'u.id = e.triggered_by_user_id'
        );
        $ackedJoin = TableSchema::leftJoinIfColumn(
            'escalations',
            'acknowledged_by_user_id',
            'users',
            'a',
            'a.id = e.acknowledged_by_user_id'
        );
        $sql = "SELECT e.*, p.name AS party_name, u.name AS triggered_by_name,
                       a.name AS acknowledged_by_name
                FROM escalations e
                JOIN parties p ON p.id = e.party_id
                {$triggeredJoin}
                {$ackedJoin}
                WHERE 1 = 1";
        $params = [];
        if (TableSchema::hasColumn('escalations', 'status')) {
            if ($status !== null && $status !== '') {
                $sql .= " AND e.status = ?";
                $params[] = $status;
            } else {
                $sql .= " AND e.status IN ('open', 'acknowledged')";
            }
            $sql .= TableSchema::hasColumn('escalations', 'triggered_on')
                ? " ORDER BY FIELD(e.status, 'open', 'acknowledged'), e.triggered_on ASC, e.id ASC"
                : " ORDER BY FIELD(e.status, 'open', 'acknowledged'), e.id ASC";
        } else {
            $sql .= " ORDER BY e.id ASC";
        }

        return array_map([$this, 'decode'], $this->database->fetchAll($sql, $params));
    }

    public function acknowledge(int $id, int $userId): void
    {
        $sets = [];
        $params = [];
        if (TableSchema::hasColumn('escalations', 'status')) {
            $sets[] = "status = 'acknowledged'";
        }
        if (TableSchema::hasColumn('escalations', 'acknowledged_by_user_id')) {
            $sets[] = 'acknowledged_by_user_id = ?';
            $params[] = $userId;
        }
        if (TableSchema::hasColumn('escalations', 'acknowledged_at')) {
            $sets[] = 'acknowledged_at = NOW()';
        }
        if ($sets === []) {
            return;
        }
        $where = TableSchema::hasColumn('escalations', 'status')
            ? "id = ? AND status = 'open'"
            : 'id = ?';
        $params[] = $id;
        $this->database->execute(
            'UPDATE escalations SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            $params
        );
    }

    public function close(int $id, string $status, string $note): void
    {
        $sets = [];
        $params = [];
        if (TableSchema::hasColumn('escalations', 'status')) {
            $sets[] = 'status = ?';
            $params[] = $status;
        }
        if (TableSchema::hasColumn('escalations', 'resolution_note')) {
            $sets[] = 'resolution_note = ?';
            $params[] = $note;
        }
        if (TableSchema::hasColumn('escalations', 'resolved_at')) {
            $sets[] = 'resolved_at = NOW()';
        }
        if ($sets === []) {
            return;
        }
        $where = TableSchema::hasColumn('escalations', 'status')
            ? "id = ? AND status IN ('open', 'acknowledged')"
            : 'id = ?';
        $params[] = $id;
        $this->database->execute(
            'UPDATE escalations SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            $params
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findOpenForSource(string $sourceTable, int $sourceId): array
    {
        if (!TableSchema::hasTable('escalations')) {
            return [];
        }
        return array_map(
            [$this, 'decode'],
            $this->database->fetchAll(
                "SELECT * FROM escalations
                 WHERE source_table = ? AND source_id = ?"
                . (TableSchema::hasColumn('escalations', 'status') ? " AND status IN ('open', 'acknowledged')" : ''),
                [$sourceTable, $sourceId]
            )
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findOpenByType(string $triggerType): array
    {
        if (!TableSchema::hasTable('escalations')) {
            return [];
        }
        return array_map(
            [$this, 'decode'],
            $this->database->fetchAll(
                "SELECT * FROM escalations
                 WHERE trigger_type = ?"
                . (TableSchema::hasColumn('escalations', 'status') ? " AND status IN ('open', 'acknowledged')" : ''),
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
