<?php

namespace App\Repositories;

use App\Core\Database;

class HandoffPacketRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $this->database->execute(
            "INSERT INTO handoff_packets (
                packet_type, deal_id, order_id, dispatch_id, schema_version, payload,
                supersession_reason, created_by_user_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['packet_type'],
                $data['deal_id'],
                $data['order_id'],
                $data['dispatch_id'],
                $data['schema_version'],
                json_encode($data['payload'], JSON_UNESCAPED_UNICODE),
                $data['supersession_reason'],
                $data['created_by_user_id'],
            ]
        );

        return (int)$this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        if (!\App\Support\TableSchema::hasTable('handoff_packets')) {
            return null;
        }
        $dealJoin = \App\Support\TableSchema::leftJoinOrStub('crm_deals', 'd', 'd.id = p.deal_id', ['id', 'title', 'party_id']);
        $row = $this->database->fetch(
            "SELECT p.*,
                    d.title AS deal_title,
                    party.name AS party_name,
                    o.order_no,
                    creator.name AS created_by_name,
                    acker.name AS acknowledged_by_name
             FROM handoff_packets p
             {$dealJoin}
             LEFT JOIN parties party ON party.id = d.party_id
             LEFT JOIN orders o ON o.id = p.order_id
             LEFT JOIN users creator ON creator.id = p.created_by_user_id
             LEFT JOIN users acker ON acker.id = p.acknowledged_by_user_id
             WHERE p.id = ?",
            [$id]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function currentSalesToDispatch(int $dealId): ?array
    {
        if (!\App\Support\TableSchema::hasTable('handoff_packets')) {
            return null;
        }
        $row = $this->database->fetch(
            "SELECT p.*
             FROM handoff_packets p
             WHERE p.packet_type = 'sales_to_dispatch'
               AND p.deal_id = ?
               AND p.superseded_by_packet_id IS NULL
             ORDER BY p.id DESC
             LIMIT 1",
            [$dealId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function findAll(array $filters = []): array
    {
        if (!\App\Support\TableSchema::hasTable('handoff_packets')) {
            return [];
        }
        $dealJoin = \App\Support\TableSchema::leftJoinOrStub('crm_deals', 'd', 'd.id = p.deal_id', ['id', 'title', 'party_id']);
        $sql = "SELECT p.*,
                       d.title AS deal_title,
                       COALESCE(deal_party.name, order_party.name) AS party_name,
                       o.order_no,
                       creator.name AS created_by_name,
                       acker.name AS acknowledged_by_name
                FROM handoff_packets p
                {$dealJoin}
                LEFT JOIN parties deal_party ON deal_party.id = d.party_id
                LEFT JOIN orders o ON o.id = p.order_id
                LEFT JOIN parties order_party ON order_party.id = o.party_id
                LEFT JOIN users creator ON creator.id = p.created_by_user_id
                LEFT JOIN users acker ON acker.id = p.acknowledged_by_user_id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['packet_type'])) {
            $sql .= " AND p.packet_type = ?";
            $params[] = $filters['packet_type'];
        }
        if (!empty($filters['deal_id'])) {
            $sql .= " AND p.deal_id = ?";
            $params[] = (int)$filters['deal_id'];
        }
        if (!empty($filters['order_id'])) {
            $sql .= " AND p.order_id = ?";
            $params[] = (int)$filters['order_id'];
        }
        if (!empty($filters['current_only'])) {
            $sql .= " AND p.superseded_by_packet_id IS NULL";
        }
        if (!empty($filters['pending_ack'])) {
            $sql .= " AND p.acknowledged_at IS NULL AND p.superseded_by_packet_id IS NULL";
        }

        $sql .= " ORDER BY p.id DESC";
        $limit = isset($filters['limit']) ? max(1, min(200, (int)$filters['limit'])) : 100;
        $sql .= " LIMIT {$limit}";

        return array_map([$this, 'hydrate'], $this->database->fetchAll($sql, $params));
    }

    public function markAcknowledged(int $id, int $userId, string $at): void
    {
        $this->database->execute(
            "UPDATE handoff_packets
             SET acknowledged_by_user_id = ?, acknowledged_at = ?
             WHERE id = ?",
            [$userId, $at, $id]
        );
    }

    public function markSuperseded(int $id, int $newPacketId): void
    {
        $this->database->execute(
            "UPDATE handoff_packets SET superseded_by_packet_id = ? WHERE id = ?",
            [$newPacketId, $id]
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $payload = $row['payload'] ?? '{}';
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $row['payload'] = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($payload)) {
            $row['payload'] = [];
        }

        foreach (['id', 'deal_id', 'order_id', 'dispatch_id', 'schema_version', 'created_by_user_id', 'acknowledged_by_user_id', 'superseded_by_packet_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }

        return $row;
    }
}
