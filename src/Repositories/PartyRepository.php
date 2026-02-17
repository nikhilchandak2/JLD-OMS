<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Party;

class PartyRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findAll(): array
    {
        $sql = "SELECT * FROM parties ORDER BY name ASC";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute();
        
        $parties = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $parties[] = new Party($row);
        }
        
        return $parties;
    }

    public function findById(int $id): ?Party
    {
        $sql = "SELECT * FROM parties WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new Party($row) : null;
    }

    public function findByName(string $name): ?Party
    {
        $sql = "SELECT * FROM parties WHERE name = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$name]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new Party($row) : null;
    }

    public function create(Party $party): Party
    {
        $sql = "INSERT INTO parties (name, contact_person, phone, email, address, is_active, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([
            $party->name,
            $party->contactPerson,
            $party->phone,
            $party->email,
            $party->address,
            $party->isActive ? 1 : 0
        ]);
        
        $party->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($party->id);
    }

    public function update(int $id, array $data): ?Party
    {
        $fields = [];
        $values = [];
        
        $allowedFields = ['name', 'contact_person', 'phone', 'email', 'address', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return $this->findById($id);
        }
        
        $fields[] = "updated_at = NOW()";
        $values[] = $id;
        
        $sql = "UPDATE parties SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($values);
        
        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        // Check if party has orders
        $checkSql = "SELECT COUNT(*) FROM orders WHERE party_id = ?";
        $checkStmt = $this->database->getConnection()->prepare($checkSql);
        $checkStmt->execute([$id]);
        
        if ($checkStmt->fetchColumn() > 0) {
            throw new \Exception('Cannot delete party with existing orders');
        }
        
        $sql = "DELETE FROM parties WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        
        return $stmt->rowCount() > 0;
    }

    public function findActive(): array
    {
        $sql = "SELECT * FROM parties WHERE is_active = 1 ORDER BY name ASC";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute();
        
        $parties = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $parties[] = new Party($row);
        }
        
        return $parties;
    }
}



