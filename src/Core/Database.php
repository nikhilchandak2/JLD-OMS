<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private ?PDO $connection = null;
    private array $config;
    
    public function __construct()
    {
        $this->config = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'name' => $_ENV['DB_NAME'] ?? 'order_processing',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'pass' => $_ENV['DB_PASS'] ?? '',
        ];
    }
    
    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connect();
        }
        
        return $this->connection;
    }
    
    private function connect(): void
    {
        try {
            $dsn = "mysql:host={$this->config['host']};dbname={$this->config['name']};charset=utf8mb4";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->connection = new PDO($dsn, $this->config['user'], $this->config['pass'], $options);
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    public function beginTransaction(): bool
    {
        return $this->getConnection()->beginTransaction();
    }
    
    public function commit(): bool
    {
        return $this->getConnection()->commit();
    }
    
    public function rollback(): bool
    {
        return $this->getConnection()->rollback();
    }
    
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount() > 0;
    }
    
    public function lastInsertId(): string
    {
        return $this->getConnection()->lastInsertId();
    }
}


