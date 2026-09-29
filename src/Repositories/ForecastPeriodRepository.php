<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

class ForecastPeriodRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findById(int $id): ?array
    {
        if (!TableSchema::hasTable('forecast_periods')) {
            return null;
        }
        return $this->database->fetch("SELECT * FROM forecast_periods WHERE id = ?", [$id]);
    }

    public function findByYearMonth(string $yearMonth): ?array
    {
        if (!TableSchema::hasTable('forecast_periods')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT * FROM forecast_periods WHERE period_month = ?",
            [$yearMonth]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function listRecent(int $limit = 12): array
    {
        if (!TableSchema::hasTable('forecast_periods')) {
            return [];
        }
        return $this->database->fetchAll(
            "SELECT * FROM forecast_periods ORDER BY period_month DESC LIMIT {$limit}"
        );
    }

    public function create(string $yearMonth, ?int $openedByUserId, ?int $companyId = null): int
    {
        $built = TableSchema::insertSql('forecast_periods', [
            'company_id' => $companyId,
            'period_month' => $yearMonth,
            'status' => 'open',
            'opened_at' => date('Y-m-d H:i:s'),
            'opened_by_user_id' => $openedByUserId,
        ]);
        if ($built === null) {
            throw new \RuntimeException('forecast_periods is not available.');
        }
        $this->database->execute($built[0], $built[1]);

        return (int)$this->database->lastInsertId();
    }

    public function lock(int $id, int $userId): void
    {
        $sets = [];
        $params = [];
        if (TableSchema::hasColumn('forecast_periods', 'status')) {
            $sets[] = "status = 'locked'";
        }
        if (TableSchema::hasColumn('forecast_periods', 'locked_at')) {
            $sets[] = 'locked_at = NOW()';
        }
        if (TableSchema::hasColumn('forecast_periods', 'locked_by_user_id')) {
            $sets[] = 'locked_by_user_id = ?';
            $params[] = $userId;
        }
        if ($sets === []) {
            return;
        }
        $where = TableSchema::hasColumn('forecast_periods', 'status')
            ? "id = ? AND status = 'open'"
            : 'id = ?';
        $params[] = $id;
        $this->database->execute(
            'UPDATE forecast_periods SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            $params
        );
    }

    public function reopen(int $id): void
    {
        $sets = [];
        if (TableSchema::hasColumn('forecast_periods', 'status')) {
            $sets[] = "status = 'open'";
        }
        if (TableSchema::hasColumn('forecast_periods', 'locked_at')) {
            $sets[] = 'locked_at = NULL';
        }
        if (TableSchema::hasColumn('forecast_periods', 'locked_by_user_id')) {
            $sets[] = 'locked_by_user_id = NULL';
        }
        if ($sets === []) {
            return;
        }
        $where = TableSchema::hasColumn('forecast_periods', 'status')
            ? "id = ? AND status = 'locked'"
            : 'id = ?';
        $this->database->execute(
            'UPDATE forecast_periods SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            [$id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function findOpenOrLocked(): array
    {
        if (!TableSchema::hasTable('forecast_periods')) {
            return [];
        }
        return $this->database->fetchAll(
            "SELECT * FROM forecast_periods"
            . (TableSchema::hasColumn('forecast_periods', 'status')
                ? " WHERE status IN ('open', 'locked')"
                : '') . "
             ORDER BY period_month DESC"
        );
    }
}
