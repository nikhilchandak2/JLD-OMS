<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    /** Shared per connection target, so every service and repository in a request works on one connection. */
    private static array $connections = [];
    private static array $transactionDepth = [];
    private static array $transactionAborted = [];

    private array $config;
    private string $key;
    
    public function __construct()
    {
        $this->config = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'name' => $_ENV['DB_NAME'] ?? 'order_processing',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'pass' => $_ENV['DB_PASS'] ?? '',
        ];
        $this->key = $this->config['host'] . '|' . $this->config['name'] . '|' . $this->config['user'];
    }
    
    public function getConnection(): PDO
    {
        if (!isset(self::$connections[$this->key])) {
            $this->connect();
        }
        
        return self::$connections[$this->key];
    }
    
    private function connect(): void
    {
        try {
            $dsn = "mysql:host={$this->config['host']};dbname={$this->config['name']};charset=utf8mb4";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ];
            
            self::$connections[$this->key] = new PDO($dsn, $this->config['user'], $this->config['pass'], $options);
            self::$transactionDepth[$this->key] = 0;
            self::$transactionAborted[$this->key] = false;
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Transactions nest: only the outermost begin/commit reaches MySQL, so a service that
     * opens a transaction and calls another service or repository that does the same still
     * commits or rolls back as one unit.
     */
    public function beginTransaction(): bool
    {
        $connection = $this->getConnection();

        if (self::$transactionDepth[$this->key] === 0) {
            self::$transactionAborted[$this->key] = false;
            $started = $connection->beginTransaction();
            self::$transactionDepth[$this->key] = 1;
            return $started;
        }

        self::$transactionDepth[$this->key]++;
        return true;
    }
    
    public function commit(): bool
    {
        $connection = $this->getConnection();

        if (self::$transactionDepth[$this->key] > 1) {
            self::$transactionDepth[$this->key]--;
            return true;
        }

        self::$transactionDepth[$this->key] = 0;

        if (!$connection->inTransaction()) {
            return false;
        }

        // An inner block already rolled back, so the whole unit is unsafe to commit.
        if (self::$transactionAborted[$this->key]) {
            self::$transactionAborted[$this->key] = false;
            $connection->rollBack();
            return false;
        }

        return $connection->commit();
    }
    
    public function rollback(): bool
    {
        $connection = $this->getConnection();

        if (self::$transactionDepth[$this->key] > 1) {
            self::$transactionDepth[$this->key]--;
            self::$transactionAborted[$this->key] = true;
            return true;
        }

        self::$transactionDepth[$this->key] = 0;
        self::$transactionAborted[$this->key] = false;

        if (!$connection->inTransaction()) {
            return false;
        }

        return $connection->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->getConnection()->inTransaction();
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


