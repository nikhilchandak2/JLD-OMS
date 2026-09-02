<?php

namespace App\Repositories;

use App\Core\Database;

class CrmJobRunRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function tryLock(string $jobName, string $lockedBy, int $staleMinutes): bool
    {
        if (!\App\Support\TableSchema::hasTable('crm_job_locks')) {
            return false;
        }
        $this->database->execute(
            "DELETE FROM crm_job_locks
             WHERE job_name = ? AND locked_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$jobName, $staleMinutes]
        );
        try {
            $this->database->execute(
                "INSERT INTO crm_job_locks (job_name, locked_at, locked_by) VALUES (?, NOW(), ?)",
                [$jobName, $lockedBy]
            );

            return true;
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'Duplicate') !== false) {
                return false;
            }
            throw $e;
        }
    }

    public function unlock(string $jobName): void
    {
        $this->database->execute("DELETE FROM crm_job_locks WHERE job_name = ?", [$jobName]);
    }

    public function start(string $jobName): int
    {
        $this->database->execute(
            "INSERT INTO crm_job_runs (job_name, started_at, status) VALUES (?, NOW(), 'running')",
            [$jobName]
        );

        return (int)$this->database->lastInsertId();
    }

    public function finish(int $id, string $status, ?array $summary, ?string $error = null): void
    {
        $this->database->execute(
            "UPDATE crm_job_runs
             SET finished_at = NOW(), status = ?, summary = ?, error_text = ?
             WHERE id = ?",
            [$status, $summary === null ? null : json_encode($summary), $error, $id]
        );
    }

    public function latest(string $jobName): ?array
    {
        if (!\App\Support\TableSchema::hasTable('crm_job_runs')) {
            return null;
        }
        $row = $this->database->fetch(
            "SELECT * FROM crm_job_runs WHERE job_name = ? ORDER BY id DESC LIMIT 1",
            [$jobName]
        );
        if ($row === null) {
            return null;
        }
        if (isset($row['summary']) && is_string($row['summary'])) {
            $row['summary'] = json_decode($row['summary'], true);
        }

        return $row;
    }
}
