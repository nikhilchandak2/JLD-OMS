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

    public function findByName(string $name): ?Product
    {
        $sql = "SELECT * FROM products WHERE name = ? LIMIT 1";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$name]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new Product($row) : null;
    }

    public function create(Product $product): Product
    {
        $fields = ['code', 'name', 'is_active'];
        $values = [
            $product->code,
            $product->name,
            $product->isActive ? 1 : 0,
        ];
        if (\App\Support\TableSchema::hasColumn('products', 'hsn_code')) {
            $fields[] = 'hsn_code';
            $values[] = $product->hsnCode !== '' ? $product->hsnCode : null;
        }
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $sql = 'INSERT INTO products (' . implode(', ', $fields) . ', created_at, updated_at) VALUES (' . $placeholders . ', NOW(), NOW())';
        
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($values);
        
        $product->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($product->id);
    }

    public function update(int $id, array $data): ?Product
    {
        $fields = [];
        $values = [];
        
        $allowedFields = ['code', 'name', 'hsn_code', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data) && ($field !== 'hsn_code' || \App\Support\TableSchema::hasColumn('products', 'hsn_code'))) {
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



