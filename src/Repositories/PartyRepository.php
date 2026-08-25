<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Party;
use App\Support\TableSchema;

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

    public function findByGstNumber(string $gstNumber, ?int $excludeId = null): ?Party
    {
        $gstNumber = Party::normalizeGstNumber($gstNumber);
        if ($gstNumber === '') {
            return null;
        }

        $sql = 'SELECT * FROM parties WHERE gst_number = ?';
        $params = [$gstNumber];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new Party($row) : null;
    }

    public function create(Party $party): Party
    {
        $sql = "INSERT INTO parties (name, contact_person, gst_number, phone, email, address, is_active, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([
            $party->name,
            $party->contactPerson,
            $party->gstNumber !== '' ? $party->gstNumber : null,
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
        
        $allowedFields = [
            'name', 'contact_person', 'gst_number', 'phone', 'email', 'address', 'is_active',
            'region', 'product_category', 'production_capacity', 'factory_locations',
            'credit_limit', 'payment_terms_days', 'technical_notes',
            'products_introduced', 'monthly_consumption', 'year_of_association',
            'order_frequency', 'last_order_date', 'last_visit_date', 'payment_track',
            'target_volume', 'next_followup_date', 'assigned_sales_owner',
            'number_of_plants', 'general_notes',
            'funnel_stage', 'industry_type', 'tiles_subtype',
            'monthly_consumption_ton', 'avg_price_per_ton', 'current_supplier_details',
            'relation_with_purchase', 'relation_with_internal_team', 'probability_of_conversion',
            'visit_description', 'followup_notes', 'visit_samples_provided',
            'account_tier',
        ];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data) && TableSchema::hasColumn('parties', $field)) {
                $fields[] = "$field = ?";
                $val = $data[$field];
                if ($field === 'visit_samples_provided' && is_array($val)) {
                    $val = json_encode($val);
                }
                $values[] = $val;
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



