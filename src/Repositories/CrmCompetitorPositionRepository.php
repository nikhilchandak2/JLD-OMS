<?php

namespace App\Repositories;

use App\Core\Database;

class CrmCompetitorPositionRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findById(int $id): ?array
    {
        if (!\App\Support\TableSchema::hasTable('crm_competitor_positions')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT c.*, p.name AS party_name, u.name AS recorded_by_name
             FROM crm_competitor_positions c
             JOIN parties p ON p.id = c.party_id
             LEFT JOIN users u ON u.id = c.recorded_by_user_id
             WHERE c.id = ?",
            [$id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function findByParty(int $partyId, ?bool $currentOnly = null): array
    {
        if (!\App\Support\TableSchema::hasTable('crm_competitor_positions')) {
            return [];
        }
        $sql = "SELECT c.*, u.name AS recorded_by_name
                FROM crm_competitor_positions c
                LEFT JOIN users u ON u.id = c.recorded_by_user_id
                WHERE c.party_id = ?";
        $params = [$partyId];
        if ($currentOnly === true) {
            $sql .= " AND c.is_current = 1";
        } elseif ($currentOnly === false) {
            $sql .= " AND c.is_current = 0";
        }
        $sql .= " ORDER BY c.is_current DESC, c.recorded_at DESC, c.id DESC";

        return $this->database->fetchAll($sql, $params);
    }

    public function countCurrent(int $partyId): int
    {
        if (!\App\Support\TableSchema::hasTable('crm_competitor_positions')) {
            return 0;
        }
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM crm_competitor_positions WHERE party_id = ? AND is_current = 1",
            [$partyId]
        );

        return (int)($row['c'] ?? 0);
    }

    public function countHistory(int $partyId): int
    {
        if (!\App\Support\TableSchema::hasTable('crm_competitor_positions')) {
            return 0;
        }
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM crm_competitor_positions WHERE party_id = ? AND is_current = 0",
            [$partyId]
        );

        return (int)($row['c'] ?? 0);
    }

    /**
     * Clear is_current on the row(s) this new position supersedes: same party,
     * same competitor (case-insensitive), same grade (NULL matches NULL).
     */
    public function clearCurrent(int $partyId, string $competitorName, ?string $gradeCode): void
    {
        if ($gradeCode === null || $gradeCode === '') {
            $this->database->execute(
                "UPDATE crm_competitor_positions
                 SET is_current = 0
                 WHERE party_id = ?
                   AND LOWER(competitor_name) = LOWER(?)
                   AND (grade_code IS NULL OR grade_code = '')
                   AND is_current = 1",
                [$partyId, $competitorName]
            );
            return;
        }

        $this->database->execute(
            "UPDATE crm_competitor_positions
             SET is_current = 0
             WHERE party_id = ?
               AND LOWER(competitor_name) = LOWER(?)
               AND grade_code = ?
               AND is_current = 1",
            [$partyId, $competitorName, $gradeCode]
        );
    }

    public function create(array $data): int
    {
        $this->database->execute(
            "INSERT INTO crm_competitor_positions (
                party_id, competitor_name, grade_code, application, estimated_share_pct,
                reason_code, reason_note, intelligence_type, recorded_by_user_id, recorded_at, is_current
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['party_id'],
                $data['competitor_name'],
                $data['grade_code'],
                $data['application'],
                $data['estimated_share_pct'],
                $data['reason_code'],
                $data['reason_note'],
                $data['intelligence_type'],
                $data['recorded_by_user_id'],
                $data['recorded_at'],
                !empty($data['is_current']) ? 1 : 0,
            ]
        );

        return (int)$this->database->lastInsertId();
    }
}
