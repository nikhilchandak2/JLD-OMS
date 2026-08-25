<?php

namespace App\Repositories;

use App\Core\Database;

class ForecastPeriodRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetch("SELECT * FROM forecast_periods WHERE id = ?", [$id]);
    }

    public function findByYearMonth(string $yearMonth): ?array
    {
        return $this->database->fetch(
            "SELECT * FROM forecast_periods WHERE period_month = ?",
            [$yearMonth]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function listRecent(int $limit = 12): array
    {
        return $this->database->fetchAll(
            "SELECT * FROM forecast_periods ORDER BY period_month DESC LIMIT {$limit}"
        );
    }

    public function create(string $yearMonth, ?int $openedByUserId, ?int $companyId = null): int
    {
        $this->database->execute(
            "INSERT INTO forecast_periods (company_id, period_month, status, opened_at, opened_by_user_id)
             VALUES (?, ?, 'open', NOW(), ?)",
            [$companyId, $yearMonth, $openedByUserId]
        );

        return (int)$this->database->lastInsertId();
    }

    public function lock(int $id, int $userId): void
    {
        $this->database->execute(
            "UPDATE forecast_periods SET status = 'locked', locked_at = NOW(), locked_by_user_id = ?
             WHERE id = ? AND status = 'open'",
            [$userId, $id]
        );
    }

    public function reopen(int $id): void
    {
        $this->database->execute(
            "UPDATE forecast_periods SET status = 'open', locked_at = NULL, locked_by_user_id = NULL
             WHERE id = ? AND status = 'locked'",
            [$id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function findOpenOrLocked(): array
    {
        return $this->database->fetchAll(
            "SELECT * FROM forecast_periods WHERE status IN ('open', 'locked') ORDER BY period_month DESC"
        );
    }
}
