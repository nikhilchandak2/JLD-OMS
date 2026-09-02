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
        if (!\App\Support\TableSchema::hasTable('crm_contacts')) {
            return [];
        }
        $introducedJoin = \App\Support\TableSchema::hasColumn('crm_contacts', 'introduced_by_user_id')
            ? 'LEFT JOIN users u ON u.id = c.introduced_by_user_id'
            : 'LEFT JOIN users u ON 1=0';
        $sql = "SELECT c.*, u.name AS introduced_by_name
                FROM crm_contacts c
                {$introducedJoin}
                WHERE c.party_id = ?
                ORDER BY c.is_primary DESC, c.name ASC";
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
        if (!\App\Support\TableSchema::hasTable('crm_contacts')) {
            return null;
        }
        $introducedJoin = \App\Support\TableSchema::hasColumn('crm_contacts', 'introduced_by_user_id')
            ? 'LEFT JOIN users u ON u.id = c.introduced_by_user_id'
            : 'LEFT JOIN users u ON 1=0';
        $sql = "SELECT c.*, u.name AS introduced_by_name
                FROM crm_contacts c
                {$introducedJoin}
                WHERE c.id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new CrmContact($row) : null;
    }

    public function create(CrmContact $contact): CrmContact
    {
        $values = [
            'party_id' => $contact->partyId,
            'name' => $contact->name,
            'role' => $contact->role,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'is_primary' => $contact->isPrimary ? 1 : 0,
        ];
        $optional = [
            'influence_level' => $contact->influenceLevel ?: 'unknown',
            'relationship_strength' => $contact->relationshipStrength ?: 'unknown',
            'introduced_by_user_id' => $contact->introducedByUserId,
            'introduced_on' => $contact->introducedOn,
            'preferred_channel' => $contact->preferredChannel,
            'preferred_language' => $contact->preferredLanguage,
            'context_notes' => $contact->contextNotes,
        ];
        foreach ($optional as $column => $value) {
            if (\App\Support\TableSchema::hasColumn('crm_contacts', $column)) {
                $values[$column] = $value;
            }
        }
        $columns = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO crm_contacts (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute(array_values($values));
        $contact->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($contact->id);
    }

    public function update(int $id, array $data): ?CrmContact
    {
        $allowed = [
            'name', 'role', 'phone', 'email', 'is_primary',
            'influence_level', 'relationship_strength', 'introduced_by_user_id',
            'introduced_on', 'preferred_channel', 'preferred_language', 'context_notes',
        ];
        $fields = [];
        $values = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data) && \App\Support\TableSchema::hasColumn('crm_contacts', $f)) {
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
