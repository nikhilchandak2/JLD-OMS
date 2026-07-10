<?php

namespace App\Repositories;

use App\Core\Database;

class VisitRequestRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findAll(array $filters = []): array
    {
        $sql = "
            SELECT vr.*,
                   p.name AS party_name,
                   req.name AS requested_by_name,
                   tech.name AS assigned_to_name
            FROM visit_requests vr
            JOIN parties p ON vr.party_id = p.id
            JOIN users req ON vr.requested_by = req.id
            LEFT JOIN users tech ON vr.assigned_to = tech.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND vr.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['party_id'])) {
            $sql .= " AND vr.party_id = ?";
            $params[] = (int)$filters['party_id'];
        }

        if (!empty($filters['requested_by'])) {
            $sql .= " AND vr.requested_by = ?";
            $params[] = (int)$filters['requested_by'];
        }

        if (!empty($filters['assigned_to'])) {
            $sql .= " AND vr.assigned_to = ?";
            $params[] = (int)$filters['assigned_to'];
        }

        $sql .= " ORDER BY FIELD(vr.status, 'pending', 'accepted', 'scheduled', 'completed', 'cancelled'),
                           FIELD(vr.priority, 'urgent', 'normal'),
                           vr.created_at DESC";

        return $this->database->fetchAll($sql, $params);
    }

    public function findById(int $id): ?array
    {
        $sql = "
            SELECT vr.*,
                   p.name AS party_name,
                   req.name AS requested_by_name,
                   tech.name AS assigned_to_name
            FROM visit_requests vr
            JOIN parties p ON vr.party_id = p.id
            JOIN users req ON vr.requested_by = req.id
            LEFT JOIN users tech ON vr.assigned_to = tech.id
            WHERE vr.id = ?
        ";
        $row = $this->database->fetch($sql, [$id]);
        return $row ?: null;
    }

    public function create(
        int $partyId,
        int $requestedBy,
        string $purpose,
        ?string $preferredDate,
        string $priority
    ): int {
        $sql = "
            INSERT INTO visit_requests (party_id, requested_by, purpose, preferred_date, priority)
            VALUES (?, ?, ?, ?, ?)
        ";

        $this->database->execute($sql, [
            $partyId,
            $requestedBy,
            $purpose,
            $preferredDate,
            $priority
        ]);

        return (int)$this->database->lastInsertId();
    }

    /** @param array $fields whitelisted column => value pairs */
    public function update(int $id, array $fields): bool
    {
        $allowed = ['status', 'assigned_to', 'scheduled_date', 'visit_outcome'];
        $set = [];
        $params = [];

        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $set[] = "{$column} = ?";
                $params[] = $fields[$column];
            }
        }

        if (empty($set)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE visit_requests SET " . implode(', ', $set) . " WHERE id = ?";
        return $this->database->execute($sql, $params);
    }
}
