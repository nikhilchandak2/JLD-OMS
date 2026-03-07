<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\CrmLead;

class CrmLeadRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findAll(array $filters = []): array
    {
        $sql = "SELECT l.*, u.name AS assigned_to_name FROM crm_leads l
                LEFT JOIN users u ON l.assigned_to = u.id
                WHERE 1=1";
        $params = [];
        if (!empty($filters['stage'])) {
            $sql .= " AND l.stage = ?";
            $params[] = $filters['stage'];
        }
        if (!empty($filters['assigned_to'])) {
            $sql .= " AND l.assigned_to = ?";
            $params[] = $filters['assigned_to'];
        }
        $sql .= " ORDER BY l.created_at DESC";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($params);
        $list = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $list[] = new CrmLead($row);
        }
        return $list;
    }

    public function findById(int $id): ?CrmLead
    {
        $sql = "SELECT l.*, u.name AS assigned_to_name FROM crm_leads l
                LEFT JOIN users u ON l.assigned_to = u.id
                WHERE l.id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new CrmLead($row) : null;
    }

    public function create(CrmLead $lead): CrmLead
    {
        $sql = "INSERT INTO crm_leads (title, company_name, contact_name, phone, email, source, value, stage, party_id, assigned_to, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([
            $lead->title,
            $lead->companyName,
            $lead->contactName,
            $lead->phone,
            $lead->email,
            $lead->source,
            $lead->value,
            $lead->stage,
            $lead->partyId,
            $lead->assignedTo,
            $lead->notes,
        ]);
        $lead->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($lead->id);
    }

    public function update(int $id, array $data): ?CrmLead
    {
        $allowed = ['title', 'company_name', 'contact_name', 'phone', 'email', 'source', 'value', 'stage', 'party_id', 'assigned_to', 'notes'];
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
        $sql = "UPDATE crm_leads SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($values);
        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM crm_leads WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
