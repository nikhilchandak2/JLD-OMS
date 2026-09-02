<?php

namespace App\Repositories;

use App\Core\Database;

class DataFeedRowRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function insertMany(int $runId, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $sql = "INSERT INTO data_feed_rows (run_id, row_number, raw, status, rejection_reason, resolved_party_id)
                VALUES (?, ?, ?, ?, ?, ?)";
        $pdo = $this->database->getConnection();
        $stmt = $pdo->prepare($sql);
        $count = 0;
        foreach ($rows as $row) {
            $stmt->execute([
                $runId,
                $row['row_number'],
                json_encode($row['raw'], JSON_UNESCAPED_UNICODE),
                $row['status'] ?? 'pending',
                $row['rejection_reason'] ?? null,
                $row['resolved_party_id'] ?? null,
            ]);
            $count++;
        }

        return $count;
    }

    public function findByRun(int $runId): array
    {
        if (!\App\Support\TableSchema::hasTable('data_feed_rows')) {
            return [];
        }
        $rows = $this->database->fetchAll(
            "SELECT * FROM data_feed_rows WHERE run_id = ? ORDER BY row_number",
            [$runId]
        );
        foreach ($rows as &$row) {
            $row['raw'] = is_string($row['raw']) ? json_decode($row['raw'], true) : $row['raw'];
        }

        return $rows;
    }

    public function findRejected(int $runId): array
    {
        if (!\App\Support\TableSchema::hasTable('data_feed_rows')) {
            return [];
        }
        $rows = $this->database->fetchAll(
            "SELECT * FROM data_feed_rows WHERE run_id = ? AND status = 'rejected' ORDER BY row_number",
            [$runId]
        );
        foreach ($rows as &$row) {
            $row['raw'] = is_string($row['raw']) ? json_decode($row['raw'], true) : $row['raw'];
        }

        return $rows;
    }

    public function findValid(int $runId): array
    {
        if (!\App\Support\TableSchema::hasTable('data_feed_rows')) {
            return [];
        }
        $rows = $this->database->fetchAll(
            "SELECT * FROM data_feed_rows WHERE run_id = ? AND status = 'valid' ORDER BY row_number",
            [$runId]
        );
        foreach ($rows as &$row) {
            $row['raw'] = is_string($row['raw']) ? json_decode($row['raw'], true) : $row['raw'];
        }

        return $rows;
    }

    public function updateRow(int $id, array $fields): void
    {
        $allowed = ['status', 'rejection_reason', 'resolved_party_id'];
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
        $this->database->execute("UPDATE data_feed_rows SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    }

    public function markPromoted(int $runId): void
    {
        $this->database->execute(
            "UPDATE data_feed_rows SET status = 'promoted' WHERE run_id = ? AND status = 'valid'",
            [$runId]
        );
    }

    public function unmatchedPartyRows(): array
    {
        if (!\App\Support\TableSchema::hasTable('data_feed_rows') || !\App\Support\TableSchema::hasTable('data_feed_runs')) {
            return [];
        }
        $rows = $this->database->fetchAll(
            "SELECT r.id, r.run_id, r.row_number, r.raw, r.rejection_reason,
                    run.feed_key, run.company_id, run.business_date, run.original_filename,
                    c.name AS company_name
             FROM data_feed_rows r
             JOIN data_feed_runs run ON run.id = r.run_id
             JOIN companies c ON c.id = run.company_id
             WHERE r.status = 'rejected' AND r.rejection_reason = 'unknown_party'
               AND run.status IN ('validated', 'failed', 'uploaded')
             ORDER BY run.business_date DESC, r.row_number"
        );
        foreach ($rows as &$row) {
            $row['raw'] = is_string($row['raw']) ? json_decode($row['raw'], true) : $row['raw'];
        }

        return $rows;
    }
}
