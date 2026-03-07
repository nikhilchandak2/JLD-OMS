<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\CrmDeal;

class CrmDealRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findAll(array $filters = []): array
    {
        $sql = "SELECT d.*, p.name AS party_name, u.name AS assigned_to_name
                FROM crm_deals d
                JOIN parties p ON d.party_id = p.id
                LEFT JOIN users u ON d.assigned_to = u.id
                WHERE 1=1";
        $params = [];
        if (!empty($filters['party_id'])) {
            $sql .= " AND d.party_id = ?";
            $params[] = $filters['party_id'];
        }
        if (!empty($filters['stage'])) {
            $sql .= " AND d.stage = ?";
            $params[] = $filters['stage'];
        }
        if (!empty($filters['assigned_to'])) {
            $sql .= " AND d.assigned_to = ?";
            $params[] = $filters['assigned_to'];
        }
        $sql .= " ORDER BY d.expected_close_date ASC, d.created_at DESC";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($params);
        $list = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $list[] = new CrmDeal($row);
        }
        return $list;
    }

    public function findById(int $id): ?CrmDeal
    {
        $sql = "SELECT d.*, p.name AS party_name, u.name AS assigned_to_name
                FROM crm_deals d
                JOIN parties p ON d.party_id = p.id
                LEFT JOIN users u ON d.assigned_to = u.id
                WHERE d.id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new CrmDeal($row) : null;
    }

    public function create(CrmDeal $deal): CrmDeal
    {
        $sql = "INSERT INTO crm_deals (party_id, lead_id, title, value, stage, expected_close_date, assigned_to, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([
            $deal->partyId,
            $deal->leadId,
            $deal->title,
            $deal->value,
            $deal->stage,
            $deal->expectedCloseDate,
            $deal->assignedTo,
            $deal->notes,
        ]);
        $deal->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($deal->id);
    }

    public function update(int $id, array $data): ?CrmDeal
    {
        $allowed = ['party_id', 'lead_id', 'title', 'value', 'stage', 'expected_close_date', 'assigned_to', 'notes'];
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
        $sql = "UPDATE crm_deals SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($values);
        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM crm_deals WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
