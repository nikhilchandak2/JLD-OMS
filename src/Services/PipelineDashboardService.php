<?php

namespace App\Services;

use App\Repositories\PipelineDashboardRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Two pipeline views over a nightly snapshot. Time-in-stage revisits add.
 * Technical-hold overlap is stored separately and is not stall time.
 */
class PipelineDashboardService
{
    private PipelineDashboardRepository $snapshots;
    private PipelineDashboardPolicy $policy;
    private CrmDealPolicy $dealPolicy;
    private array $pipelineConfig;
    private array $config;

    public function __construct()
    {
        $this->snapshots = new PipelineDashboardRepository();
        $this->policy = new PipelineDashboardPolicy();
        $this->dealPolicy = new CrmDealPolicy();
        $this->pipelineConfig = require dirname(__DIR__, 2) . '/config/crm_pipeline.php';
        $this->config = require dirname(__DIR__, 2) . '/config/pipeline_dashboard.php';
    }

    /**
     * Replace the read model for $asOf. Safe to run twice: the previous snapshot is deleted.
     *
     * @return array{as_of:string,deals:int,facts:int}
     */
    public function rebuild(string $asOf): array
    {
        $asOfTs = $this->asOfTimestamp($asOf);
        $deals = $this->snapshots->loadDealsForRebuild();
        $eventsByDeal = [];
        foreach ($this->snapshots->loadEventsForRebuild() as $event) {
            $eventsByDeal[(int)$event['deal_id']][] = $event;
        }
        $flagsByDeal = [];
        foreach ($this->snapshots->loadFlagsForRebuild() as $flag) {
            $flagsByDeal[(int)$flag['deal_id']][] = $flag;
        }
        $gradesByDeal = [];
        foreach ($this->snapshots->loadGradesForRebuild() as $grade) {
            $gradesByDeal[(int)$grade['deal_id']][] = (string)$grade['grade_code'];
        }

        $snapshotDeals = [];
        $snapshotGrades = [];
        $facts = [];

        foreach ($deals as $deal) {
            $dealId = (int)$deal['deal_id'];
            $holds = $this->holdWindows($flagsByDeal[$dealId] ?? [], $asOfTs);
            $computed = $this->accumulate($eventsByDeal[$dealId] ?? [], $holds, $asOfTs, (string)$deal['status'], (int)$deal['stage']);

            $snapshotDeals[] = [
                'deal_id' => $dealId,
                'stage' => (int)$deal['stage'],
                'status' => (string)$deal['status'],
                'owner_user_id' => $deal['owner_user_id'] === null || $deal['owner_user_id'] === '' ? null : (int)$deal['owner_user_id'],
                'owner_name' => $deal['owner_name'],
                'party_id' => (int)$deal['party_id'],
                'party_name' => (string)$deal['party_name'],
                'title' => (string)$deal['title'],
                'indicative_value' => $deal['indicative_value'] === null || $deal['indicative_value'] === '' ? null : (float)$deal['indicative_value'],
                'inquiry_date' => $deal['inquiry_date'],
                'stage_entered_at' => $computed['stage_entered_at'],
            ];

            foreach ($gradesByDeal[$dealId] ?? [] as $code) {
                $snapshotGrades[] = ['deal_id' => $dealId, 'grade_code' => $code];
            }

            foreach ($computed['stages'] as $stage => $row) {
                $facts[] = [
                    'deal_id' => $dealId,
                    'stage' => $stage,
                    'status' => (string)$deal['status'],
                    'owner_user_id' => $deal['owner_user_id'] === null || $deal['owner_user_id'] === '' ? null : (int)$deal['owner_user_id'],
                    'owner_name' => $deal['owner_name'],
                    'party_id' => (int)$deal['party_id'],
                    'party_name' => (string)$deal['party_name'],
                    'title' => (string)$deal['title'],
                    'inquiry_date' => $deal['inquiry_date'],
                    'is_current' => $row['is_current'],
                    'lifetime_seconds' => $row['lifetime_seconds'],
                    'hold_seconds' => $row['hold_seconds'],
                    'current_dwell_seconds' => $row['current_dwell_seconds'],
                    'current_hold_seconds' => $row['current_hold_seconds'],
                ];
            }
        }

        $this->snapshots->replaceAll($asOf, $snapshotDeals, $snapshotGrades, $facts);

        return ['as_of' => $asOf, 'deals' => count($snapshotDeals), 'facts' => count($facts)];
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function dashboard(array $actor, array $filters = []): array
    {
        $this->policy->assertCan($actor['role'] ?? null, PipelineDashboardPolicy::VIEW);
        $resolved = $this->resolveFilters($actor, $filters);
        $asOf = $this->snapshots->latestAsOf();
        $canValue = $this->dealPolicy->can($actor['role'] ?? null, CrmDealPolicy::VIEW_DEAL_VALUE);
        $stages = $this->pipelineConfig['stages'];
        $empty = [
            'as_of' => $asOf,
            'refreshed' => $asOf !== null,
            'can_filter_owner' => $this->policy->can($actor['role'] ?? null, PipelineDashboardPolicy::VIEW_ALL),
            'can_view_value' => $canValue,
            'stall_days_default' => (int)$this->config['default_stall_days'],
            'by_stage' => [],
            'time_in_stage' => [],
            'over_threshold' => [],
        ];
        if ($asOf === null) {
            foreach ($stages as $stage => $label) {
                $row = ['stage' => $stage, 'label' => $label, 'deal_count' => 0];
                if ($canValue) {
                    $row['indicative_value'] = 0.0;
                }
                $empty['by_stage'][] = $row;
                $empty['time_in_stage'][] = [
                    'stage' => $stage,
                    'label' => $label,
                    'deal_count' => 0,
                    'avg_days' => null,
                    'median_days' => null,
                    'avg_hold_days' => null,
                    'over_threshold' => 0,
                    'stall_days' => $this->stallDays((int)$stage),
                ];
            }

            return $empty;
        }

        $resolved['as_of'] = $asOf;
        $byStageRows = [];
        foreach ($this->snapshots->byStage($resolved) as $row) {
            $byStageRows[(int)$row['stage']] = $row;
        }
        $byStage = [];
        foreach ($stages as $stage => $label) {
            $found = $byStageRows[$stage] ?? null;
            $row = [
                'stage' => $stage,
                'label' => $label,
                'deal_count' => $found ? (int)$found['deal_count'] : 0,
            ];
            if ($canValue) {
                $row['indicative_value'] = $found ? (float)($found['indicative_value'] ?? 0) : 0.0;
            }
            $byStage[] = $row;
        }

        $factRows = $this->snapshots->timeInStageRows($resolved);
        $byStageFacts = [];
        $over = [];
        foreach ($factRows as $row) {
            $stage = (int)$row['stage'];
            $dwell = (int)($row['current_dwell_seconds'] ?? 0);
            $hold = (int)($row['current_hold_seconds'] ?? 0);
            $byStageFacts[$stage][] = ['dwell' => $dwell, 'hold' => $hold];
            $threshold = $this->stallDays($stage) * 86400;
            if ($dwell > $threshold) {
                $over[] = [
                    'deal_id' => (int)$row['deal_id'],
                    'stage' => $stage,
                    'label' => $stages[$stage] ?? "Stage {$stage}",
                    'title' => (string)$row['title'],
                    'party_id' => (int)$row['party_id'],
                    'party_name' => (string)$row['party_name'],
                    'owner_name' => $row['owner_name'],
                    'dwell_days' => round($dwell / 86400, 1),
                    'hold_days' => round($hold / 86400, 1),
                    'stall_days' => $this->stallDays($stage),
                ];
            }
        }

        $timeInStage = [];
        foreach ($stages as $stage => $label) {
            $items = $byStageFacts[$stage] ?? [];
            $dwells = array_column($items, 'dwell');
            $holds = array_column($items, 'hold');
            $overCount = 0;
            $threshold = $this->stallDays((int)$stage) * 86400;
            foreach ($dwells as $dwell) {
                if ($dwell > $threshold) {
                    $overCount++;
                }
            }
            $timeInStage[] = [
                'stage' => $stage,
                'label' => $label,
                'deal_count' => count($items),
                'avg_days' => $this->avgDays($dwells),
                'median_days' => $this->medianDays($dwells),
                'avg_hold_days' => $this->avgDays($holds),
                'over_threshold' => $overCount,
                'stall_days' => $this->stallDays((int)$stage),
            ];
        }

        return [
            'as_of' => $asOf,
            'refreshed' => true,
            'can_filter_owner' => $this->policy->can($actor['role'] ?? null, PipelineDashboardPolicy::VIEW_ALL),
            'can_view_value' => $canValue,
            'stall_days_default' => (int)$this->config['default_stall_days'],
            'by_stage' => $byStage,
            'time_in_stage' => $timeInStage,
            'over_threshold' => $over,
        ];
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @param array<string,mixed> $filters
     * @return array{filename:string,bytes:string}
     */
    public function excelBytes(array $actor, array $filters = []): array
    {
        $data = $this->dashboard($actor, $filters);
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setTitle('Pipeline dashboards')->setCreator('JLD CRM');

        $byStage = $spreadsheet->getActiveSheet();
        $byStage->setTitle('By stage');
        $headers = ['Stage', 'Label', 'Deal count'];
        if ($data['can_view_value']) {
            $headers[] = 'Indicative value';
        }
        $byStage->fromArray($headers, null, 'A1');
        $r = 2;
        foreach ($data['by_stage'] as $row) {
            $line = [$row['stage'], $row['label'], $row['deal_count']];
            if ($data['can_view_value']) {
                $line[] = $row['indicative_value'] ?? 0;
            }
            $byStage->fromArray($line, null, 'A' . $r);
            $r++;
        }
        $byStage->setCellValue('A' . ($r + 1), 'As of ' . ($data['as_of'] ?? 'not yet refreshed'));

        $tis = $spreadsheet->createSheet();
        $tis->setTitle('Time in stage');
        $tis->fromArray(
            ['Stage', 'Label', 'Deal count', 'Avg days (ex-hold)', 'Median days (ex-hold)', 'Avg hold days', 'Over threshold', 'Stall days'],
            null,
            'A1'
        );
        $r = 2;
        foreach ($data['time_in_stage'] as $row) {
            $tis->fromArray([
                $row['stage'],
                $row['label'],
                $row['deal_count'],
                $row['avg_days'],
                $row['median_days'],
                $row['avg_hold_days'],
                $row['over_threshold'],
                $row['stall_days'],
            ], null, 'A' . $r);
            $r++;
        }

        $over = $spreadsheet->createSheet();
        $over->setTitle('Over threshold');
        $over->fromArray(
            ['Deal', 'Party', 'Stage', 'Dwell days (ex-hold)', 'Hold days', 'Stall days'],
            null,
            'A1'
        );
        $r = 2;
        foreach ($data['over_threshold'] as $row) {
            $over->fromArray([
                $row['title'],
                $row['party_name'],
                $row['label'],
                $row['dwell_days'],
                $row['hold_days'],
                $row['stall_days'],
            ], null, 'A' . $r);
            $r++;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'jldpipe_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);

        return [
            'filename' => 'pipeline_' . ($data['as_of'] ?? 'empty') . '.xlsx',
            'bytes' => $bytes,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function explainByStage(array $filters = []): array
    {
        $asOf = $this->snapshots->latestAsOf();
        if ($asOf === null) {
            return [];
        }
        $filters['as_of'] = $asOf;
        $filters += ['owner_user_id' => null, 'grade_code' => null, 'date_from' => null, 'date_to' => null];

        return $this->snapshots->explainByStage($filters);
    }

    /** @return array<int,array<string,mixed>> */
    public function explainTimeInStage(array $filters = []): array
    {
        $asOf = $this->snapshots->latestAsOf();
        if ($asOf === null) {
            return [];
        }
        $filters['as_of'] = $asOf;
        $filters += ['owner_user_id' => null, 'grade_code' => null, 'date_from' => null, 'date_to' => null];

        return $this->snapshots->explainTimeInStage($filters);
    }

    public function lifetimeSeconds(int $dealId, int $stage, string $asOf): ?int
    {
        return $this->snapshots->lifetimeSeconds($dealId, $stage, $asOf);
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @param array<string,mixed> $filters
     * @return array{as_of:?string,owner_user_id:?int,grade_code:?string,date_from:?string,date_to:?string}
     */
    private function resolveFilters(array $actor, array $filters): array
    {
        $owner = isset($filters['owner_user_id']) && $filters['owner_user_id'] !== ''
            ? (int)$filters['owner_user_id']
            : null;
        if (!$this->policy->can($actor['role'] ?? null, PipelineDashboardPolicy::VIEW_ALL)) {
            $owner = (int)($actor['id'] ?? 0);
            if ($owner <= 0) {
                $owner = -1;
            }
        }

        $grade = isset($filters['grade_code']) && $filters['grade_code'] !== ''
            ? (string)$filters['grade_code']
            : null;
        $from = isset($filters['date_from']) && $filters['date_from'] !== ''
            ? (string)$filters['date_from']
            : null;
        $to = isset($filters['date_to']) && $filters['date_to'] !== ''
            ? (string)$filters['date_to']
            : null;

        return [
            'as_of' => null,
            'owner_user_id' => $owner,
            'grade_code' => $grade,
            'date_from' => $from,
            'date_to' => $to,
        ];
    }

    private function stallDays(int $stage): int
    {
        $byStage = $this->config['stall_days_by_stage'] ?? [];

        return isset($byStage[$stage]) ? (int)$byStage[$stage] : (int)$this->config['default_stall_days'];
    }

    private function asOfTimestamp(string $asOf): int
    {
        $tz = new \DateTimeZone($this->config['timezone'] ?? 'Asia/Kolkata');
        $endOfDay = (new \DateTimeImmutable($asOf, $tz))->setTime(23, 59, 59);
        $now = new \DateTimeImmutable('now', $tz);

        return ($endOfDay < $now ? $endOfDay : $now)->getTimestamp();
    }

    private function toTs(?string $datetime): ?int
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        $tz = new \DateTimeZone($this->config['timezone'] ?? 'Asia/Kolkata');

        return (new \DateTimeImmutable($datetime, $tz))->getTimestamp();
    }

    /**
     * @param array<int,array<string,mixed>> $flags
     * @return array<int,array{0:int,1:int}>
     */
    private function holdWindows(array $flags, int $asOfTs): array
    {
        $windows = [];
        foreach ($flags as $flag) {
            $start = $this->toTs((string)$flag['created_at']);
            if ($start === null) {
                continue;
            }
            $resolved = $flag['resolved_at'] ?? null;
            if ($resolved !== null && $resolved !== '') {
                $end = $this->toTs((string)$resolved);
            } elseif (in_array((string)$flag['status'], ['cancelled', 'resolved'], true)) {
                $end = $this->toTs((string)($flag['updated_at'] ?? $flag['created_at']));
            } else {
                $end = $asOfTs;
            }
            if ($end === null) {
                $end = $asOfTs;
            }
            $windows[] = [$start, max($start, $end)];
        }

        return $windows;
    }

    /**
     * Walk the event log the same way as DealStageService::timeInStage, then subtract
     * hold overlap so revisits add and a lab wait is not a stalled rep.
     *
     * @param array<int,array<string,mixed>> $events
     * @param array<int,array{0:int,1:int}> $holds
     * @return array{stages:array<int,array<string,mixed>>,stage_entered_at:?string}
     */
    private function accumulate(array $events, array $holds, int $asOfTs, string $dealStatus, int $currentStage): array
    {
        $totals = [];
        $previousStage = null;
        $previousAt = null;
        $previousStatus = DealStageService::STATUS_ACTIVE;
        $enteredAt = [];

        foreach ($events as $event) {
            $occurredAt = $this->toTs((string)$event['occurred_at']);
            if ($occurredAt === null) {
                continue;
            }
            if ($previousStage !== null && $previousAt !== null) {
                $this->addInterval($totals, $previousStage, $previousAt, $occurredAt, $holds, false);
            }
            if ($event['to_stage'] !== null && $event['to_stage'] !== '') {
                $previousStage = (int)$event['to_stage'];
                $enteredAt[$previousStage] = date('Y-m-d H:i:s', $occurredAt);
            }
            if ($event['to_status'] !== null && $event['to_status'] !== '') {
                $previousStatus = (string)$event['to_status'];
            }
            $previousAt = $occurredAt;
        }

        if ($previousStage !== null && $previousAt !== null && $previousStatus === DealStageService::STATUS_ACTIVE) {
            $this->addInterval($totals, $previousStage, $previousAt, $asOfTs, $holds, true);
        }

        $stages = [];
        foreach ($totals as $stage => $row) {
            $isCurrent = $dealStatus === DealStageService::STATUS_ACTIVE && $stage === $currentStage;
            $stages[$stage] = [
                'lifetime_seconds' => $row['lifetime'],
                'hold_seconds' => $row['hold'],
                'is_current' => $isCurrent,
                'current_dwell_seconds' => $isCurrent ? $row['current_dwell'] : null,
                'current_hold_seconds' => $isCurrent ? $row['current_hold'] : null,
            ];
        }

        return [
            'stages' => $stages,
            'stage_entered_at' => $enteredAt[$currentStage] ?? null,
        ];
    }

    /**
     * @param array<int,array{lifetime:int,hold:int,current_dwell:int,current_hold:int}> $totals
     * @param array<int,array{0:int,1:int}> $holds
     */
    private function addInterval(array &$totals, int $stage, int $from, int $to, array $holds, bool $open): void
    {
        $wall = max(0, $to - $from);
        $hold = 0;
        foreach ($holds as [$h0, $h1]) {
            $hold += max(0, min($to, $h1) - max($from, $h0));
        }
        $hold = min($wall, $hold);
        $stall = max(0, $wall - $hold);
        if (!isset($totals[$stage])) {
            $totals[$stage] = ['lifetime' => 0, 'hold' => 0, 'current_dwell' => 0, 'current_hold' => 0];
        }
        $totals[$stage]['lifetime'] += $stall;
        $totals[$stage]['hold'] += $hold;
        if ($open) {
            $totals[$stage]['current_dwell'] = $stall;
            $totals[$stage]['current_hold'] = $hold;
        }
    }

    /** @param array<int,int> $seconds */
    private function avgDays(array $seconds): ?float
    {
        if ($seconds === []) {
            return null;
        }

        return round(array_sum($seconds) / count($seconds) / 86400, 1);
    }

    /** @param array<int,int> $seconds */
    private function medianDays(array $seconds): ?float
    {
        if ($seconds === []) {
            return null;
        }
        sort($seconds, SORT_NUMERIC);
        $n = count($seconds);
        $mid = intdiv($n, 2);
        $value = $n % 2 === 1
            ? $seconds[$mid]
            : ($seconds[$mid - 1] + $seconds[$mid]) / 2;

        return round($value / 86400, 1);
    }
}
