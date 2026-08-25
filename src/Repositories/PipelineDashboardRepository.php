<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Nightly pipeline dashboard snapshots. Dashboards read only these tables.
 */
class PipelineDashboardRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function replaceAll(
        string $asOf,
        array $deals,
        array $grades,
        array $facts
    ): void {
        $this->database->execute('DELETE FROM pipeline_time_in_stage_facts');
        $this->database->execute('DELETE FROM pipeline_deal_snapshot_grades');
        $this->database->execute('DELETE FROM pipeline_deal_snapshot');

        foreach ($deals as $row) {
            $this->database->execute(
                "INSERT INTO pipeline_deal_snapshot (
                    as_of, deal_id, stage, status, owner_user_id, owner_name,
                    party_id, party_name, title, indicative_value, inquiry_date, stage_entered_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $asOf,
                    $row['deal_id'],
                    $row['stage'],
                    $row['status'],
                    $row['owner_user_id'],
                    $row['owner_name'],
                    $row['party_id'],
                    $row['party_name'],
                    $row['title'],
                    $row['indicative_value'],
                    $row['inquiry_date'],
                    $row['stage_entered_at'],
                ]
            );
        }

        foreach ($grades as $row) {
            $this->database->execute(
                "INSERT INTO pipeline_deal_snapshot_grades (as_of, deal_id, grade_code) VALUES (?, ?, ?)",
                [$asOf, $row['deal_id'], $row['grade_code']]
            );
        }

        foreach ($facts as $row) {
            $this->database->execute(
                "INSERT INTO pipeline_time_in_stage_facts (
                    as_of, deal_id, stage, status, owner_user_id, owner_name,
                    party_id, party_name, title, inquiry_date, is_current,
                    lifetime_seconds, hold_seconds, current_dwell_seconds, current_hold_seconds
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $asOf,
                    $row['deal_id'],
                    $row['stage'],
                    $row['status'],
                    $row['owner_user_id'],
                    $row['owner_name'],
                    $row['party_id'],
                    $row['party_name'],
                    $row['title'],
                    $row['inquiry_date'],
                    $row['is_current'] ? 1 : 0,
                    $row['lifetime_seconds'],
                    $row['hold_seconds'],
                    $row['current_dwell_seconds'],
                    $row['current_hold_seconds'],
                ]
            );
        }
    }

    public function latestAsOf(): ?string
    {
        $row = $this->database->fetch('SELECT MAX(as_of) AS as_of FROM pipeline_deal_snapshot');
        $asOf = $row['as_of'] ?? null;

        return $asOf === null || $asOf === '' ? null : (string)$asOf;
    }

    public function loadDealsForRebuild(): array
    {
        return $this->database->fetchAll(
            "SELECT d.id AS deal_id, d.stage, d.status, d.owner_user_id, u.name AS owner_name,
                    d.party_id, p.name AS party_name, d.title, d.value AS indicative_value,
                    d.inquiry_date
             FROM crm_deals d
             JOIN parties p ON p.id = d.party_id
             LEFT JOIN users u ON u.id = d.owner_user_id
             WHERE d.deleted_at IS NULL
             ORDER BY d.id"
        );
    }

    public function loadEventsForRebuild(): array
    {
        return $this->database->fetchAll(
            "SELECT deal_id, from_stage, to_stage, from_status, to_status, occurred_at
             FROM crm_deal_stage_events
             ORDER BY deal_id ASC, occurred_at ASC, id ASC"
        );
    }

    public function loadFlagsForRebuild(): array
    {
        return $this->database->fetchAll(
            "SELECT deal_id, status, created_at, resolved_at, updated_at
             FROM crm_technical_flags
             WHERE deal_id IS NOT NULL"
        );
    }

    public function loadGradesForRebuild(): array
    {
        return $this->database->fetchAll(
            "SELECT deal_id, grade_code FROM crm_deal_grades ORDER BY deal_id, grade_code"
        );
    }

    /**
     * @param array{as_of:string,owner_user_id:?int,grade_code:?string,date_from:?string,date_to:?string} $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    public function byStageSql(array $filters): array
    {
        $sql = "SELECT s.stage, COUNT(*) AS deal_count, SUM(s.indicative_value) AS indicative_value
                FROM pipeline_deal_snapshot s FORCE INDEX (idx_pipeline_snapshot_stage)
                WHERE s.as_of = ?
                  AND s.status = 'active'";
        $params = [$filters['as_of']];

        if ($filters['owner_user_id'] !== null) {
            $sql .= ' AND s.owner_user_id = ?';
            $params[] = $filters['owner_user_id'];
        }
        if ($filters['date_from'] !== null) {
            $sql .= ' AND s.inquiry_date >= ?';
            $params[] = $filters['date_from'];
        }
        if ($filters['date_to'] !== null) {
            $sql .= ' AND s.inquiry_date <= ?';
            $params[] = $filters['date_to'];
        }
        if ($filters['grade_code'] !== null) {
            $sql .= ' AND EXISTS (
                        SELECT 1 FROM pipeline_deal_snapshot_grades g
                        WHERE g.as_of = s.as_of AND g.deal_id = s.deal_id AND g.grade_code = ?
                      )';
            $params[] = $filters['grade_code'];
        }

        $sql .= ' GROUP BY s.stage ORDER BY s.stage';

        return [$sql, $params];
    }

    /** @return array<int,array<string,mixed>> */
    public function byStage(array $filters): array
    {
        [$sql, $params] = $this->byStageSql($filters);

        return $this->database->fetchAll($sql, $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function explainByStage(array $filters): array
    {
        [$sql, $params] = $this->byStageSql($filters);

        return $this->database->fetchAll('EXPLAIN ' . $sql, $params);
    }

    /**
     * @param array{as_of:string,owner_user_id:?int,grade_code:?string,date_from:?string,date_to:?string} $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    public function timeInStageSql(array $filters): array
    {
        $sql = "SELECT f.deal_id, f.stage, f.title, f.party_id, f.party_name,
                       f.owner_user_id, f.owner_name,
                       f.current_dwell_seconds, f.current_hold_seconds,
                       f.lifetime_seconds, f.hold_seconds
                FROM pipeline_time_in_stage_facts f FORCE INDEX (idx_pipeline_tis_current)
                WHERE f.as_of = ?
                  AND f.is_current = 1
                  AND f.status = 'active'";
        $params = [$filters['as_of']];

        if ($filters['owner_user_id'] !== null) {
            $sql .= ' AND f.owner_user_id = ?';
            $params[] = $filters['owner_user_id'];
        }
        if ($filters['date_from'] !== null) {
            $sql .= ' AND f.inquiry_date >= ?';
            $params[] = $filters['date_from'];
        }
        if ($filters['date_to'] !== null) {
            $sql .= ' AND f.inquiry_date <= ?';
            $params[] = $filters['date_to'];
        }
        if ($filters['grade_code'] !== null) {
            $sql .= ' AND EXISTS (
                        SELECT 1 FROM pipeline_deal_snapshot_grades g
                        WHERE g.as_of = f.as_of AND g.deal_id = f.deal_id AND g.grade_code = ?
                      )';
            $params[] = $filters['grade_code'];
        }

        $sql .= ' ORDER BY f.stage, f.deal_id';

        return [$sql, $params];
    }

    /** @return array<int,array<string,mixed>> */
    public function timeInStageRows(array $filters): array
    {
        [$sql, $params] = $this->timeInStageSql($filters);

        return $this->database->fetchAll($sql, $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function explainTimeInStage(array $filters): array
    {
        [$sql, $params] = $this->timeInStageSql($filters);

        return $this->database->fetchAll('EXPLAIN ' . $sql, $params);
    }

    public function lifetimeSeconds(int $dealId, int $stage, string $asOf): ?int
    {
        $row = $this->database->fetch(
            "SELECT lifetime_seconds FROM pipeline_time_in_stage_facts
             WHERE as_of = ? AND deal_id = ? AND stage = ?",
            [$asOf, $dealId, $stage]
        );

        return $row === null ? null : (int)$row['lifetime_seconds'];
    }
}
