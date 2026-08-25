<?php

namespace Tests;

use App\Services\PipelineDashboardAuthorizationException;
use App\Services\PipelineDashboardService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PipelineDashboardTest extends CrmPipelineTestCase
{
    private PipelineDashboardService $pipeline;
    private string $asOf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipeline = new PipelineDashboardService();
        $this->asOf = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    }

    public function testViewsReadTheSnapshotNotLiveAggregates(): void
    {
        $deal = $this->captureDeal(['value' => 50000], $this->admin);
        $this->pipeline->rebuild($this->asOf);

        $before = $this->stageCount($this->pipeline->dashboard($this->admin), 1);
        self::assertSame(1, $before);

        $this->satisfyExitCriteria((int)$deal['id']);
        $this->stages->advance((int)$deal['id'], $this->admin);

        $after = $this->pipeline->dashboard($this->admin);
        self::assertSame(1, $this->stageCount($after, 1), 'Advancing live must not change the snapshot until rebuild.');
        self::assertSame(0, $this->stageCount($after, 2));

        $this->pipeline->rebuild($this->asOf);
        $rebuilt = $this->pipeline->dashboard($this->admin);
        self::assertSame(0, $this->stageCount($rebuilt, 1));
        self::assertSame(1, $this->stageCount($rebuilt, 2));
    }

    public function testLostAndDroppedAreExcludedFromActiveCounts(): void
    {
        $kept = $this->captureDeal(['value' => 10000], $this->admin);
        $lost = $this->captureDeal(['value' => 99999], $this->admin);
        $this->stages->terminate((int)$lost['id'], $this->admin, 'lost', $this->reasonCodeId('price_too_high'));
        $this->pipeline->rebuild($this->asOf);

        $data = $this->pipeline->dashboard($this->admin);
        self::assertSame(1, $this->stageCount($data, 1));
        $stage1 = $this->stageRow($data['by_stage'], 1);
        self::assertEqualsWithDelta(10000.0, (float)$stage1['indicative_value'], 0.01);
        self::assertSame(1, $this->stageCount($data, 1));
    }

    public function testSalesSeesOwnDealsAndCannotSeeAnotherOwner(): void
    {
        $repA = $this->actor('sales');
        $repB = $this->actor('sales');
        $this->captureDeal(['owner_user_id' => $repA['id'], 'value' => 10], $repA);
        $this->captureDeal(['owner_user_id' => $repB['id'], 'value' => 20], $repB);
        $this->pipeline->rebuild($this->asOf);

        $forA = $this->pipeline->dashboard($repA);
        self::assertSame(1, $this->stageCount($forA, 1));
        self::assertFalse($forA['can_filter_owner']);

        $forAForced = $this->pipeline->dashboard($repA, ['owner_user_id' => $repB['id']]);
        self::assertSame(1, $this->stageCount($forAForced, 1), 'Sales owner filter is ignored — still own deals.');

        $adminAll = $this->pipeline->dashboard($this->admin);
        self::assertSame(2, $this->stageCount($adminAll, 1));
        self::assertTrue($adminAll['can_filter_owner']);

        $adminFiltered = $this->pipeline->dashboard($this->admin, ['owner_user_id' => $repA['id']]);
        self::assertSame(1, $this->stageCount($adminFiltered, 1));
        self::assertEqualsWithDelta(10.0, (float)$this->stageRow($adminFiltered['by_stage'], 1)['indicative_value'], 0.01);
    }

    public function testMarketingDoesNotReceiveIndicativeValue(): void
    {
        $this->captureDeal(['value' => 80000], $this->admin);
        $this->pipeline->rebuild($this->asOf);

        $data = $this->pipeline->dashboard($this->actor('marketing'));
        self::assertFalse($data['can_view_value']);
        self::assertArrayNotHasKey('indicative_value', $this->stageRow($data['by_stage'], 1));

        $xlsx = $this->pipeline->excelBytes($this->actor('marketing'));
        self::assertSame("PK\x03\x04", substr($xlsx['bytes'], 0, 4));
        $byStage = $this->loadSheet($xlsx['bytes'], 'By stage');
        self::assertNotSame('Indicative value', $byStage->getCell('D1')->getValue());
    }

    public function testTimeInStageAccumulatesAcrossBackwardThenForward(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 2);
        $this->stages->moveBack($dealId, $this->admin, 'Wrong grade captured.');
        $this->satisfyExitCriteria($dealId);
        $this->stages->advance($dealId, $this->admin);

        $events = $this->database->fetchAll(
            "SELECT id FROM crm_deal_stage_events WHERE deal_id = ? ORDER BY id ASC",
            [$dealId]
        );
        self::assertCount(4, $events);
        foreach ([0 => 10, 1 => 8, 2 => 5, 3 => 3] as $index => $daysAgo) {
            $this->database->execute(
                "UPDATE crm_deal_stage_events SET occurred_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE id = ?",
                [$daysAgo, (int)$events[$index]['id']]
            );
        }

        $this->pipeline->rebuild($this->asOf);
        $stage1 = $this->pipeline->lifetimeSeconds($dealId, 1, $this->asOf);
        $stage2 = $this->pipeline->lifetimeSeconds($dealId, 2, $this->asOf);
        self::assertNotNull($stage1);
        self::assertNotNull($stage2);
        self::assertEqualsWithDelta(4 * 86400, $stage1, 180, 'Stage 1 visits of 2 + 2 days must add.');
        self::assertEqualsWithDelta(6 * 86400, $stage2, 180, 'Stage 2 visit of 3 days plus 3 days still open.');
    }

    public function testTechnicalHoldIsExcludedFromStallAndReportedSeparately(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $events = $this->database->fetchAll(
            "SELECT id FROM crm_deal_stage_events WHERE deal_id = ? ORDER BY id ASC",
            [$dealId]
        );
        $this->database->execute(
            "UPDATE crm_deal_stage_events SET occurred_at = DATE_SUB(NOW(), INTERVAL 20 DAY) WHERE id = ?",
            [(int)$events[0]['id']]
        );

        $flag = $this->flags->raise([
            'deal_id' => $dealId,
            'nature_of_query' => 'Waiting on lab',
            'routed_to_queue_id' => $this->queueId(),
        ], $this->admin);
        $this->database->execute(
            "UPDATE crm_technical_flags SET created_at = DATE_SUB(NOW(), INTERVAL 20 DAY) WHERE id = ?",
            [(int)$flag['id']]
        );

        $this->pipeline->rebuild($this->asOf);
        $data = $this->pipeline->dashboard($this->admin);
        $tis = $this->stageRow($data['time_in_stage'], 1);
        self::assertSame(1, $tis['deal_count']);
        self::assertLessThan(1.0, (float)$tis['avg_days'], 'Hold time must not count as stall.');
        self::assertGreaterThan(19.0, (float)$tis['avg_hold_days']);
        self::assertSame(0, $tis['over_threshold'], '20 days on the lab is not a stalled rep.');
        self::assertSame([], $data['over_threshold']);
    }

    public function testGradeAndDateFiltersUseTheSnapshot(): void
    {
        $this->captureDeal([
            'grades' => 'J-11',
            'inquiry_date' => '2026-01-05',
            'value' => 1,
        ], $this->admin);
        $this->captureDeal([
            'grades' => 'JJN-1',
            'inquiry_date' => '2026-06-01',
            'value' => 2,
        ], $this->admin);
        $this->pipeline->rebuild($this->asOf);

        $j11 = $this->pipeline->dashboard($this->admin, ['grade_code' => 'J-11']);
        self::assertSame(1, $this->stageCount($j11, 1));

        $inJune = $this->pipeline->dashboard($this->admin, [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);
        self::assertSame(1, $this->stageCount($inJune, 1));
    }

    public function testExcelExportIsANonEmptyWorkbook(): void
    {
        $this->captureDeal(['value' => 1500], $this->admin);
        $this->pipeline->rebuild($this->asOf);
        $file = $this->pipeline->excelBytes($this->admin);

        self::assertNotSame('', $file['bytes']);
        self::assertSame("PK\x03\x04", substr($file['bytes'], 0, 4));
        self::assertStringContainsString('.xlsx', $file['filename']);
        $byStage = $this->loadSheet($file['bytes'], 'By stage');
        self::assertSame('Indicative value', $byStage->getCell('D1')->getValue());
        self::assertSame(1, (int)$byStage->getCell('C2')->getValue());
        self::assertNotNull($this->loadSheet($file['bytes'], 'Time in stage'));
    }

    public function testExplainUsesSnapshotIndexes(): void
    {
        $this->captureDeal();
        $this->pipeline->rebuild($this->asOf);

        $byStage = $this->pipeline->explainByStage();
        $this->assertIndexUsed($byStage, ['pipeline_deal_snapshot', 's'], 'idx_pipeline_snapshot_stage');

        $tis = $this->pipeline->explainTimeInStage();
        $this->assertIndexUsed($tis, ['pipeline_time_in_stage_facts', 'f'], 'idx_pipeline_tis_current');
    }

    public function testUnauthorizedRoleIsRefused(): void
    {
        $this->expectException(PipelineDashboardAuthorizationException::class);
        $this->pipeline->dashboard(['id' => 1, 'role' => 'dispatch']);
    }

    /** @param array<string,mixed> $data */
    private function stageCount(array $data, int $stage): int
    {
        return (int)$this->stageRow($data['by_stage'], $stage)['deal_count'];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function stageRow(array $rows, int $stage): array
    {
        foreach ($rows as $row) {
            if ((int)$row['stage'] === $stage) {
                return $row;
            }
        }
        self::fail("Stage {$stage} missing from dashboard payload.");
    }

    private function loadSheet(string $bytes, string $title): Worksheet
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pipetest_') . '.xlsx';
        file_put_contents($tmp, $bytes);
        $spreadsheet = IOFactory::load($tmp);
        @unlink($tmp);
        $sheet = $spreadsheet->getSheetByName($title);
        self::assertNotNull($sheet, "Workbook is missing sheet {$title}.");

        return $sheet;
    }

    /**
     * @param array<int,array<string,mixed>> $plan
     * @param array<int,string> $tables
     */
    private function assertIndexUsed(array $plan, array $tables, string $index): void
    {
        $hit = null;
        foreach ($plan as $row) {
            $table = (string)($row['table'] ?? '');
            if (in_array($table, $tables, true)) {
                $hit = $row;
                break;
            }
        }
        self::assertNotNull($hit, 'EXPLAIN must include the snapshot table. Plan: ' . json_encode($plan));
        self::assertNotEmpty($hit['key'], "Query must use an index. Plan: " . json_encode($plan));
        self::assertTrue(
            str_contains((string)$hit['key'], 'pipeline')
            || str_contains((string)($hit['possible_keys'] ?? ''), $index)
            || (string)$hit['key'] === $index,
            "Expected {$index}. Got key=" . ($hit['key'] ?? 'null') . ' plan=' . json_encode($plan)
        );
    }
}
