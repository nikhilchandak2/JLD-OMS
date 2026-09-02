<?php

namespace App\Repositories;

use App\Core\Database;

class ForecastLineRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findById(int $id): ?array
    {
        if (!\App\Support\TableSchema::hasTable('forecast_lines')) {
            return null;
        }
        return $this->database->fetch("SELECT * FROM forecast_lines WHERE id = ?", [$id]);
    }

    public function findOne(int $periodId, int $partyId, string $gradeCode): ?array
    {
        if (!\App\Support\TableSchema::hasTable('forecast_lines')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT * FROM forecast_lines WHERE period_id = ? AND party_id = ? AND grade_code = ?",
            [$periodId, $partyId, $gradeCode]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function findForPeriod(?int $ownerUserId, int $periodId): array
    {
        if (!\App\Support\TableSchema::hasTable('forecast_lines')) {
            return [];
        }
        $ownerSelect = \App\Support\TableSchema::hasColumn('parties', 'assigned_sales_owner')
            ? 'p.assigned_sales_owner'
            : 'NULL AS assigned_sales_owner';
        $sql = "SELECT l.*, p.name AS party_name, {$ownerSelect}
                FROM forecast_lines l
                JOIN parties p ON p.id = l.party_id
                WHERE l.period_id = ?";
        $params = [$periodId];
        if ($ownerUserId !== null && \App\Support\TableSchema::hasColumn('parties', 'assigned_sales_owner')) {
            $sql .= " AND p.assigned_sales_owner = ?";
            $params[] = $ownerUserId;
        }
        $sql .= " ORDER BY p.name ASC, l.grade_code ASC";

        return $this->database->fetchAll($sql, $params);
    }

    public function countForParty(int $periodId, int $partyId): int
    {
        if (!\App\Support\TableSchema::hasTable('forecast_lines')) {
            return 0;
        }
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM forecast_lines WHERE period_id = ? AND party_id = ?",
            [$periodId, $partyId]
        );

        return (int)($row['c'] ?? 0);
    }

    public function insert(array $data): int
    {
        $this->database->execute(
            "INSERT INTO forecast_lines (
                period_id, party_id, owner_user_id, grade_code,
                qty_low_tonnes, qty_high_tonnes, source, confidence, note
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['period_id'],
                $data['party_id'],
                $data['owner_user_id'],
                $data['grade_code'],
                $data['qty_low_tonnes'],
                $data['qty_high_tonnes'],
                $data['source'],
                $data['confidence'],
                $data['note'],
            ]
        );

        return (int)$this->database->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $this->database->execute(
            "UPDATE forecast_lines
             SET qty_low_tonnes = ?, qty_high_tonnes = ?, source = ?, confidence = ?, note = ?,
                 owner_user_id = COALESCE(?, owner_user_id)
             WHERE id = ?",
            [
                $data['qty_low_tonnes'],
                $data['qty_high_tonnes'],
                $data['source'],
                $data['confidence'],
                $data['note'],
                $data['owner_user_id'] ?? null,
                $id,
            ]
        );
    }

    /** @return array<int,int> party ids with a positive forecast in the period */
    public function partyIdsWithPositiveForecast(int $periodId): array
    {
        if (!\App\Support\TableSchema::hasTable('forecast_lines')) {
            return [];
        }
        $rows = $this->database->fetchAll(
            "SELECT DISTINCT party_id
             FROM forecast_lines
             WHERE period_id = ?
               AND (qty_low_tonnes + qty_high_tonnes) > 0",
            [$periodId]
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[(int)$row['party_id']] = (int)$row['party_id'];
        }

        return $ids;
    }
}
