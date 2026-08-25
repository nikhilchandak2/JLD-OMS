<?php

namespace App\Repositories;

use App\Core\Database;

class CrmDealGradeRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findByDeal(int $dealId): array
    {
        return $this->database->fetchAll(
            "SELECT id, deal_id, grade_code, indicative_qty_tonnes
             FROM crm_deal_grades
             WHERE deal_id = ?
             ORDER BY grade_code ASC",
            [$dealId]
        );
    }

    /** Grades for many deals in one query, so a list view does not go N+1. */
    public function findByDeals(array $dealIds): array
    {
        if (empty($dealIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($dealIds), '?'));
        $rows = $this->database->fetchAll(
            "SELECT deal_id, grade_code, indicative_qty_tonnes
             FROM crm_deal_grades
             WHERE deal_id IN ({$placeholders})
             ORDER BY grade_code ASC",
            array_map('intval', $dealIds)
        );

        $byDeal = [];
        foreach ($rows as $row) {
            $byDeal[(int)$row['deal_id']][] = $row;
        }

        return $byDeal;
    }

    public function upsert(int $dealId, string $gradeCode, ?float $qty): void
    {
        if (strlen($gradeCode) > 64) {
            throw new \InvalidArgumentException('Grade code exceeds 64 characters.');
        }

        $this->database->query(
            "INSERT INTO crm_deal_grades (deal_id, grade_code, indicative_qty_tonnes)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE indicative_qty_tonnes = VALUES(indicative_qty_tonnes), updated_at = NOW()",
            [$dealId, $gradeCode, $qty]
        );
    }

    public function delete(int $dealId, string $gradeCode): void
    {
        $this->database->query(
            "DELETE FROM crm_deal_grades WHERE deal_id = ? AND grade_code = ?",
            [$dealId, $gradeCode]
        );
    }

    public function countByDeal(int $dealId): int
    {
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM crm_deal_grades WHERE deal_id = ?",
            [$dealId]
        );

        return (int)($row['c'] ?? 0);
    }
}
