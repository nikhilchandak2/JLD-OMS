<?php

namespace App\Core;

class Application
{
    private Database $database;
    private array $config = [];
    
    public function __construct()
    {
        $this->loadConfig();
    }
    
    public function setDatabase(Database $database): void
    {
        $this->database = $database;
    }
    
    public function getDatabase(): Database
    {
        return $this->database;
    }
    
    public function getConfig(string $key = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }
        
        return $this->config[$key] ?? null;
    }
    
    private function loadConfig(): void
    {
        $this->config = [
            'db' => [
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'name' => $_ENV['DB_NAME'] ?? 'order_processing',
                'user' => $_ENV['DB_USER'] ?? 'root',
                'pass' => $_ENV['DB_PASS'] ?? '',
            ],
            'app' => [
                'env' => $_ENV['APP_ENV'] ?? 'production',
                'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'url' => $_ENV['APP_URL'] ?? 'http://localhost',
            ],
            'session' => [
                'secret' => $_ENV['SESSION_SECRET'] ?? 'default-secret-change-me',
            ],
            'csrf' => [
                'secret' => $_ENV['CSRF_SECRET'] ?? 'default-csrf-secret-change-me',
            ]
        ];
    }
}


