<?php

namespace App\Repositories;

use App\Core\Database;

class DataFeedRepository
{
    private Database $database;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->config = require dirname(__DIR__, 2) . '/config/data_feeds.php';
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetch("SELECT * FROM data_feeds WHERE id = ?", [$id]);
    }

    public function findByKeyAndCompany(string $feedKey, int $companyId): ?array
    {
        return $this->database->fetch(
            "SELECT * FROM data_feeds WHERE feed_key = ? AND company_id = ?",
            [$feedKey, $companyId]
        );
    }

    public function listAll(): array
    {
        return $this->database->fetchAll(
            "SELECT f.*, c.name AS company_name, c.code AS company_code, u.name AS owner_name
             FROM data_feeds f
             JOIN companies c ON c.id = f.company_id
             LEFT JOIN users u ON u.id = f.owner_user_id
             ORDER BY f.feed_key, c.name"
        );
    }

    public function listActiveByKey(string $feedKey): array
    {
        return $this->database->fetchAll(
            "SELECT f.*, c.name AS company_name, c.code AS company_code
             FROM data_feeds f
             JOIN companies c ON c.id = f.company_id
             WHERE f.feed_key = ? AND f.is_active = 1
             ORDER BY c.name",
            [$feedKey]
        );
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['owner_user_id', 'deadline_local_time', 'is_active', 'display_name'];
        $sets = [];
        $params = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "{$key} = ?";
                $params[] = $fields[$key];
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->database->execute("UPDATE data_feeds SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    }

    /**
     * Ensure both feed keys exist for every company. Safe to call on every dashboard load.
     */
    public function ensureForAllCompanies(): void
    {
        $companies = $this->database->fetchAll("SELECT id, name FROM companies");
        foreach ($companies as $company) {
            $this->ensureForCompany((int)$company['id'], (string)$company['name']);
        }
    }

    public function ensureForCompany(int $companyId, ?string $companyName = null): void
    {
        if ($companyName === null) {
            $row = $this->database->fetch("SELECT name FROM companies WHERE id = ?", [$companyId]);
            $companyName = $row['name'] ?? ('Company ' . $companyId);
        }

        foreach ($this->config['feeds'] as $feedKey => $meta) {
            $existing = $this->findByKeyAndCompany($feedKey, $companyId);
            if ($existing) {
                continue;
            }
            $this->database->execute(
                "INSERT INTO data_feeds (feed_key, display_name, owner_user_id, deadline_local_time, company_id, is_active)
                 VALUES (?, ?, NULL, ?, ?, 1)",
                [
                    $feedKey,
                    $companyName . ' — ' . $meta['display_name'],
                    $meta['deadline_local_time'],
                    $companyId,
                ]
            );
        }
    }
}
