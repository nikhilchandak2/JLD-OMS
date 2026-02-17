<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Product;

class ProductRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findAll(): array
    {
        $sql = "SELECT * FROM products ORDER BY name ASC";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute();
        
        $products = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $products[] = new Product($row);
        }
        
        return $products;
    }

    public function findById(int $id): ?Product
    {
        $sql = "SELECT * FROM products WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new Product($row) : null;
    }

    public function findByCode(string $code): ?Product
    {
        $sql = "SELECT * FROM products WHERE code = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$code]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new Product($row) : null;
    }

    public function create(Product $product): Product
    {
        $sql = "INSERT INTO products (code, name, is_active, created_at, updated_at) 
                VALUES (?, ?, ?, NOW(), NOW())";
        
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([
            $product->code,
            $product->name,
            $product->isActive ? 1 : 0
        ]);
        
        $product->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($product->id);
    }

    public function update(int $id, array $data): ?Product
    {
        $fields = [];
        $values = [];
        
        $allowedFields = ['code', 'name', 'is_active'];
        
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
        
        $sql = "UPDATE products SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($values);
        
        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        // Check if product has orders
        $checkSql = "SELECT COUNT(*) FROM orders WHERE product_id = ?";
        $checkStmt = $this->database->getConnection()->prepare($checkSql);
        $checkStmt->execute([$id]);
        
        if ($checkStmt->fetchColumn() > 0) {
            throw new \Exception('Cannot delete product with existing orders');
        }
        
        $sql = "DELETE FROM products WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        
        return $stmt->rowCount() > 0;
    }

    public function findActive(): array
    {
        $sql = "SELECT * FROM products WHERE is_active = 1 ORDER BY name ASC";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute();
        
        $products = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $products[] = new Product($row);
        }
        
        return $products;
    }
}



