<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\CrmActivity;

class CrmActivityRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findAll(array $filters = []): array
    {
        $sql = "SELECT a.*, u.name AS created_by_name FROM crm_activities a
                LEFT JOIN users u ON a.created_by = u.id
                WHERE 1=1";
        $params = [];
        if (!empty($filters['party_id'])) {
            $sql .= " AND a.party_id = ?";
            $params[] = $filters['party_id'];
        }
        if (!empty($filters['deal_id'])) {
            $sql .= " AND a.deal_id = ?";
            $params[] = $filters['deal_id'];
        }
        if (!empty($filters['type'])) {
            $sql .= " AND a.type = ?";
            $params[] = $filters['type'];
        }
        if (!empty($filters['from_date'])) {
            $sql .= " AND DATE(a.activity_date) >= ?";
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $sql .= " AND DATE(a.activity_date) <= ?";
            $params[] = $filters['to_date'];
        }
        $sql .= " ORDER BY a.activity_date DESC";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($params);
        $list = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $list[] = new CrmActivity($row);
        }
        return $list;
    }

    public function findById(int $id): ?CrmActivity
    {
        $sql = "SELECT a.*, u.name AS created_by_name FROM crm_activities a
                LEFT JOIN users u ON a.created_by = u.id
                WHERE a.id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new CrmActivity($row) : null;
    }

    public function create(CrmActivity $activity): CrmActivity
    {
        $sql = "INSERT INTO crm_activities (party_id, deal_id, contact_id, type, subject, description, activity_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([
            $activity->partyId,
            $activity->dealId,
            $activity->contactId,
            $activity->type,
            $activity->subject,
            $activity->description,
            $activity->activityDate,
            $activity->createdBy,
        ]);
        $activity->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($activity->id);
    }

    public function update(int $id, array $data): ?CrmActivity
    {
        $allowed = ['deal_id', 'contact_id', 'type', 'subject', 'description', 'activity_date'];
        $fields = [];
        $values = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $values[] = $data[$f];
            }
        }
        if (empty($fields)) {
            return $this->findById($id);
        }
        $values[] = $id;
        $sql = "UPDATE crm_activities SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($values);
        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM crm_activities WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
