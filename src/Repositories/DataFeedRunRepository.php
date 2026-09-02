<?php

namespace App\Repositories;

use App\Core\Database;

class DataFeedRunRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findById(int $id): ?array
    {
        if (!\App\Support\TableSchema::hasTable('data_feed_runs')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT r.*, c.name AS company_name, c.code AS company_code, u.name AS uploaded_by_name
             FROM data_feed_runs r
             JOIN companies c ON c.id = r.company_id
             LEFT JOIN users u ON u.id = r.uploaded_by_user_id
             WHERE r.id = ?",
            [$id]
        );
    }

    public function findByHash(string $feedKey, int $companyId, string $businessDate, string $fileHash): ?array
    {
        if (!\App\Support\TableSchema::hasTable('data_feed_runs')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT * FROM data_feed_runs
             WHERE feed_key = ? AND company_id = ? AND business_date = ? AND file_hash = ?",
            [$feedKey, $companyId, $businessDate, $fileHash]
        );
    }

    public function findCompletedForDate(string $feedKey, int $companyId, string $businessDate): ?array
    {
        if (!\App\Support\TableSchema::hasTable('data_feed_runs')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT * FROM data_feed_runs
             WHERE feed_key = ? AND company_id = ? AND business_date = ? AND status = 'completed'
             ORDER BY id DESC LIMIT 1",
            [$feedKey, $companyId, $businessDate]
        );
    }

    public function latestCompleted(string $feedKey, int $companyId): ?array
    {
        if (!\App\Support\TableSchema::hasTable('data_feed_runs')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT * FROM data_feed_runs
             WHERE feed_key = ? AND company_id = ? AND status = 'completed'
             ORDER BY business_date DESC, id DESC LIMIT 1",
            [$feedKey, $companyId]
        );
    }

    public function create(array $data): int
    {
        $this->database->execute(
            "INSERT INTO data_feed_runs
                (feed_key, company_id, business_date, uploaded_by_user_id, uploaded_at, original_filename,
                 file_hash, status, rows_total, rows_accepted, rows_rejected, as_of, error_summary, replaces_run_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['feed_key'],
                $data['company_id'],
                $data['business_date'],
                $data['uploaded_by_user_id'] ?? null,
                $data['uploaded_at'],
                $data['original_filename'],
                $data['file_hash'],
                $data['status'] ?? 'uploaded',
                $data['rows_total'] ?? 0,
                $data['rows_accepted'] ?? 0,
                $data['rows_rejected'] ?? 0,
                $data['as_of'] ?? null,
                $data['error_summary'] ?? null,
                $data['replaces_run_id'] ?? null,
            ]
        );

        return (int)$this->database->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = [
            'status', 'rows_total', 'rows_accepted', 'rows_rejected',
            'as_of', 'error_summary', 'replaces_run_id',
        ];
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
        $this->database->execute("UPDATE data_feed_runs SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    }

    public function markSuperseded(int $id): void
    {
        $this->database->execute(
            "UPDATE data_feed_runs SET status = 'superseded' WHERE id = ? AND status = 'completed'",
            [$id]
        );
    }

    public function latestPerFeed(): array
    {
        if (!\App\Support\TableSchema::hasTable('data_feed_runs')) {
            return [];
        }
        return $this->database->fetchAll(
            "SELECT r.*
             FROM data_feed_runs r
             INNER JOIN (
                SELECT feed_key, company_id, MAX(id) AS max_id
                FROM data_feed_runs
                WHERE status IN ('completed', 'failed', 'validated', 'uploaded', 'validating', 'promoting')
                GROUP BY feed_key, company_id
             ) latest ON latest.max_id = r.id"
        );
    }
}
