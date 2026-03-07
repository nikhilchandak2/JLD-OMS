<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\CrmContact;

class CrmContactRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findByParty(int $partyId): array
    {
        $sql = "SELECT * FROM crm_contacts WHERE party_id = ? ORDER BY is_primary DESC, name ASC";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$partyId]);
        $list = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $list[] = new CrmContact($row);
        }
        return $list;
    }

    public function findById(int $id): ?CrmContact
    {
        $sql = "SELECT * FROM crm_contacts WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new CrmContact($row) : null;
    }

    public function create(CrmContact $contact): CrmContact
    {
        $sql = "INSERT INTO crm_contacts (party_id, name, role, phone, email, is_primary) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([
            $contact->partyId,
            $contact->name,
            $contact->role,
            $contact->phone,
            $contact->email,
            $contact->isPrimary ? 1 : 0,
        ]);
        $contact->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($contact->id);
    }

    public function update(int $id, array $data): ?CrmContact
    {
        $allowed = ['name', 'role', 'phone', 'email', 'is_primary'];
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
        $sql = "UPDATE crm_contacts SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($values);
        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM crm_contacts WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
