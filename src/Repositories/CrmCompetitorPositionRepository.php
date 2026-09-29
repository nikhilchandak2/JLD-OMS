<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

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
        $recordedJoin = TableSchema::leftJoinIfColumn(
            'crm_competitor_positions',
            'recorded_by_user_id',
            'users',
            'u',
            'u.id = c.recorded_by_user_id'
        );
        return $this->database->fetch(
            "SELECT c.*, p.name AS party_name, u.name AS recorded_by_name
             FROM crm_competitor_positions c
             JOIN parties p ON p.id = c.party_id
             {$recordedJoin}
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
        $recordedJoin = TableSchema::leftJoinIfColumn(
            'crm_competitor_positions',
            'recorded_by_user_id',
            'users',
            'u',
            'u.id = c.recorded_by_user_id'
        );
        $sql = "SELECT c.*, u.name AS recorded_by_name
                FROM crm_competitor_positions c
                {$recordedJoin}
                WHERE c.party_id = ?";
        $params = [$partyId];
        if ($currentOnly === true && TableSchema::hasColumn('crm_competitor_positions', 'is_current')) {
            $sql .= " AND c.is_current = 1";
        } elseif ($currentOnly === false && TableSchema::hasColumn('crm_competitor_positions', 'is_current')) {
            $sql .= " AND c.is_current = 0";
        }
        $order = [];
        if (TableSchema::hasColumn('crm_competitor_positions', 'is_current')) {
            $order[] = 'c.is_current DESC';
        }
        if (TableSchema::hasColumn('crm_competitor_positions', 'recorded_at')) {
            $order[] = 'c.recorded_at DESC';
        }
        $order[] = 'c.id DESC';
        $sql .= ' ORDER BY ' . implode(', ', $order);

        return $this->database->fetchAll($sql, $params);
    }

    public function countCurrent(int $partyId): int
    {
        if (!TableSchema::hasTable('crm_competitor_positions')) {
            return 0;
        }
        $current = TableSchema::hasColumn('crm_competitor_positions', 'is_current')
            ? 'AND is_current = 1'
            : '';
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM crm_competitor_positions WHERE party_id = ? {$current}",
            [$partyId]
        );

        return (int)($row['c'] ?? 0);
    }

    public function countHistory(int $partyId): int
    {
        if (!TableSchema::hasTable('crm_competitor_positions')) {
            return 0;
        }
        if (!TableSchema::hasColumn('crm_competitor_positions', 'is_current')) {
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
        if (!TableSchema::hasColumn('crm_competitor_positions', 'is_current')) {
            return;
        }
        $gradePred = ($gradeCode === null || $gradeCode === '')
            ? "(grade_code IS NULL OR grade_code = '')"
            : 'grade_code = ?';
        $params = [$partyId, $competitorName];
        if ($gradeCode !== null && $gradeCode !== '') {
            $params[] = $gradeCode;
        }
        $this->database->execute(
            "UPDATE crm_competitor_positions
             SET is_current = 0
             WHERE party_id = ?
               AND LOWER(competitor_name) = LOWER(?)
               AND {$gradePred}
               AND is_current = 1",
            $params
        );
    }

    public function create(array $data): int
    {
        $built = TableSchema::insertSql('crm_competitor_positions', [
            'party_id' => $data['party_id'],
            'competitor_name' => $data['competitor_name'],
            'grade_code' => $data['grade_code'] ?? null,
            'application' => $data['application'] ?? null,
            'estimated_share_pct' => $data['estimated_share_pct'] ?? null,
            'reason_code' => $data['reason_code'] ?? 'other',
            'reason_note' => $data['reason_note'] ?? null,
            'intelligence_type' => $data['intelligence_type'] ?? 'reported',
            'recorded_by_user_id' => $data['recorded_by_user_id'] ?? null,
            'recorded_at' => $data['recorded_at'] ?? date('Y-m-d H:i:s'),
            'is_current' => !empty($data['is_current']) ? 1 : 0,
        ]);
        if ($built === null) {
            throw new \RuntimeException('crm_competitor_positions is not available.');
        }
        $this->database->execute($built[0], $built[1]);

        return (int)$this->database->lastInsertId();
    }
}
