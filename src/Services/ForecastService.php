<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\ForecastActualRepository;
use App\Repositories\ForecastLineRepository;
use App\Repositories\ForecastPeriodRepository;
use App\Repositories\PartyRepository;

/**
 * Monthly grade-level forecast. Variance is for production planning by grade
 * and account. Do not aggregate this into a per-rep accuracy score.
 */
class ForecastService
{
    private Database $database;
    private ForecastPeriodRepository $periods;
    private ForecastLineRepository $lines;
    private ForecastActualRepository $actuals;
    private ForecastPrefillService $prefill;
    private PartyRepository $parties;
    private AuditLogRepository $audit;
    private ForecastPolicy $policy;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->periods = new ForecastPeriodRepository();
        $this->lines = new ForecastLineRepository();
        $this->actuals = new ForecastActualRepository();
        $this->prefill = new ForecastPrefillService();
        $this->parties = new PartyRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new ForecastPolicy();
        $this->config = require dirname(__DIR__, 2) . '/config/forecast.php';
    }

    public function meta(array $actor = []): array
    {
        $this->policy->assertCan($actor['role'] ?? null, ForecastPolicy::VIEW);

        return [
            'purpose_line' => $this->config['purpose_line'],
            'confidences' => $this->config['confidences'],
            'sources' => $this->config['sources'],
        ];
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function openPeriod(string $yearMonth, array $actor): array
    {
        $this->assertYearMonth($yearMonth);
        $system = ($actor['role'] ?? null) === 'system';
        if (!$system) {
            $this->policy->assertCan($actor['role'] ?? null, ForecastPolicy::MANAGE_PERIOD);
        }
        $existing = $this->periods->findByYearMonth($yearMonth);
        if ($existing !== null) {
            return $this->presentPeriod($existing);
        }
        $id = $this->periods->create($yearMonth, $system ? null : ($actor['id'] ?? null));
        $this->audit->log($actor['id'] ?? null, 'forecast_periods', $id, 'CREATE', null, [
            'year_month' => $yearMonth,
            'status' => 'open',
        ]);
        $row = $this->periods->findById($id);
        if ($row === null) {
            throw new ForecastException('Period could not be reloaded.');
        }

        return $this->presentPeriod($row);
    }

    /**
     * Nightly / first-load helper: current month exists as open.
     *
     * @return array<string,mixed>
     */
    public function ensureCurrentPeriod(string $asOf, array $actor): array
    {
        $ym = substr($asOf, 0, 7);
        $existing = $this->periods->findByYearMonth($ym);
        if ($existing !== null) {
            return $this->presentPeriod($existing);
        }

        return $this->openPeriod($ym, $actor);
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function lockPeriod(int $periodId, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, ForecastPolicy::MANAGE_PERIOD);
        $existing = $this->requirePeriod($periodId);
        if ($existing['status'] === 'locked') {
            return $this->presentPeriod($existing);
        }
        if ($existing['status'] !== 'open') {
            throw new ForecastException('Only an open period can be locked.');
        }
        $this->periods->lock($periodId, (int)($actor['id'] ?? 0));
        $this->audit->log($actor['id'] ?? null, 'forecast_periods', $periodId, 'UPDATE', [
            'status' => 'open',
        ], ['status' => 'locked']);
        $row = $this->periods->findById($periodId);
        if ($row === null) {
            throw new ForecastException('Period could not be reloaded.');
        }

        return $this->presentPeriod($row);
    }

    /**
     * Bulk worksheet: one card per account, grades already prefilled.
     *
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function worksheet(array $actor, ?string $yearMonth, string $asOf): array
    {
        $this->policy->assertCan($actor['role'] ?? null, ForecastPolicy::VIEW);
        $ym = $yearMonth ?: substr($asOf, 0, 7);
        $this->assertYearMonth($ym);
        $period = $this->periods->findByYearMonth($ym);
        if ($period === null) {
            if ($this->policy->can($actor['role'] ?? null, ForecastPolicy::MANAGE_PERIOD)) {
                $period = $this->openPeriod($ym, $actor);
                $periodRow = $this->periods->findById((int)$period['id']);
            } else {
                throw new ForecastException("The {$ym} forecast is not open yet. Ask the Director to open it.");
            }
        } else {
            $periodRow = $period;
        }

        $ownerFilter = $this->policy->can($actor['role'] ?? null, ForecastPolicy::VIEW_ALL)
            ? null
            : (int)($actor['id'] ?? 0);
        $parties = $this->assignedParties($ownerFilter);
        foreach ($parties as $party) {
            $this->prefillPartyIfEmpty((int)$periodRow['id'], (int)$party['id'], (int)($party['assigned_sales_owner'] ?? 0) ?: null, $asOf);
        }

        $lineRows = $this->lines->findForPeriod($ownerFilter, (int)$periodRow['id']);
        $byParty = [];
        foreach ($parties as $party) {
            $byParty[(int)$party['id']] = [
                'party_id' => (int)$party['id'],
                'party_name' => $party['name'],
                'assigned_sales_owner' => $party['assigned_sales_owner'] === null ? null : (int)$party['assigned_sales_owner'],
                'lines' => [],
            ];
        }
        foreach ($lineRows as $line) {
            $pid = (int)$line['party_id'];
            if (!isset($byParty[$pid])) {
                $byParty[$pid] = [
                    'party_id' => $pid,
                    'party_name' => $line['party_name'],
                    'assigned_sales_owner' => $line['assigned_sales_owner'] === null ? null : (int)$line['assigned_sales_owner'],
                    'lines' => [],
                ];
            }
            $byParty[$pid]['lines'][] = $this->presentLine($line);
        }

        return [
            'purpose_line' => $this->config['purpose_line'],
            'as_of' => $asOf,
            'period' => $this->presentPeriod($periodRow),
            'can_edit' => $this->policy->can($actor['role'] ?? null, ForecastPolicy::EDIT) && $periodRow['status'] === 'open',
            'can_manage_period' => $this->policy->can($actor['role'] ?? null, ForecastPolicy::MANAGE_PERIOD),
            'accounts' => array_values($byParty),
            'grades' => $this->gradeCatalogue(),
        ];
    }

    /**
     * Save one party's grades without a page reload.
     *
     * @param array<int,array<string,mixed>> $incoming
     * @param array{id:?int,role:?string} $actor
     * @return array<int,array<string,mixed>>
     */
    public function savePartyLines(int $periodId, int $partyId, array $incoming, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, ForecastPolicy::EDIT);
        $period = $this->requirePeriod($periodId);
        if ($period['status'] !== 'open') {
            throw new ForecastException('This period is locked. Edits are closed.');
        }
        $party = $this->parties->findById($partyId);
        if ($party === null) {
            throw new ForecastException('Party not found.');
        }
        if (!$this->policy->can($actor['role'] ?? null, ForecastPolicy::VIEW_ALL)) {
            if ((int)$party->assignedSalesOwner !== (int)($actor['id'] ?? 0)) {
                throw new ForecastAuthorizationException('You can only forecast your own accounts.');
            }
        }

        $saved = [];
        foreach ($incoming as $row) {
            if (!is_array($row)) {
                continue;
            }
            $saved[] = $this->upsertLine($periodId, $partyId, $row, $actor);
        }

        return $saved;
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function actuals(array $actor, ?string $yearMonth): array
    {
        $this->policy->assertCan($actor['role'] ?? null, ForecastPolicy::VIEW_ACTUALS);
        $ym = $yearMonth ?: (new \DateTimeImmutable('now', new \DateTimeZone($this->config['timezone'])))->format('Y-m');
        $this->assertYearMonth($ym);
        $period = $this->periods->findByYearMonth($ym);
        if ($period === null) {
            return [
                'period' => null,
                'by_grade' => [],
                'by_account' => [],
                'purpose_line' => $this->config['purpose_line'],
            ];
        }

        return [
            'period' => $this->presentPeriod($period),
            'by_grade' => $this->actuals->byGrade((int)$period['id']),
            'by_account' => $this->actuals->byAccount((int)$period['id']),
            'purpose_line' => $this->config['purpose_line'],
        ];
    }

    /**
     * Rebuild forecast_actuals for every open/locked period. Idempotent for a day.
     *
     * @return array{periods:int,rows:int}
     */
    public function rebuildActuals(string $asOf): array
    {
        $this->ensureCurrentPeriod($asOf, ['id' => null, 'role' => 'system']);
        $periods = $this->periods->findOpenOrLocked();
        $rows = 0;
        foreach ($periods as $period) {
            $rows += $this->rebuildPeriodActuals((int)$period['id'], (string)$period['period_month'], $asOf);
        }

        return ['periods' => count($periods), 'rows' => $rows];
    }

    /**
     * Parties with a positive forecast this month and no orders in the month,
     * once day-of-month is at least 20.
     *
     * @return array<int,int>
     */
    public function partyIdsWithForecastGap(string $asOf): array
    {
        $day = (int)substr($asOf, 8, 2);
        if ($day < 20) {
            return [];
        }
        $ym = substr($asOf, 0, 7);
        $period = $this->periods->findByYearMonth($ym);
        if ($period === null) {
            return [];
        }
        $positive = $this->lines->partyIdsWithPositiveForecast((int)$period['id']);
        if ($positive === []) {
            return [];
        }
        $monthStart = $ym . '-01';
        $monthEnd = (new \DateTimeImmutable($monthStart))->modify('first day of next month')->format('Y-m-d');
        $ordered = $this->database->fetchAll(
            "SELECT DISTINCT party_id FROM orders
             WHERE order_date >= ? AND order_date < ?",
            [$monthStart, $monthEnd]
        );
        $orderedIds = [];
        foreach ($ordered as $row) {
            $orderedIds[(int)$row['party_id']] = true;
        }
        $gap = [];
        foreach ($positive as $partyId) {
            if (!isset($orderedIds[$partyId])) {
                $gap[$partyId] = $partyId;
            }
        }

        return $gap;
    }

    /** @param array<string,mixed> $row */
    public function presentPeriod(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['company_id'] = $row['company_id'] === null ? null : (int)$row['company_id'];
        $row['year_month'] = $row['period_month'] ?? $row['year_month'] ?? null;

        return $row;
    }

    /** @param array<string,mixed> $row */
    public function presentLine(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['period_id'] = (int)$row['period_id'];
        $row['party_id'] = (int)$row['party_id'];
        $row['qty_low_tonnes'] = (float)$row['qty_low_tonnes'];
        $row['qty_high_tonnes'] = (float)$row['qty_high_tonnes'];
        $row['source_label'] = $this->config['sources'][$row['source']] ?? $row['source'];

        return $row;
    }

    private function rebuildPeriodActuals(int $periodId, string $yearMonth, string $asOf): int
    {
        $from = $yearMonth . '-01';
        $to = (new \DateTimeImmutable($from))->modify('first day of next month')->format('Y-m-d');
        $dispatched = $this->actuals->dispatchedTonnesByPartyGrade($from, $to);
        $lines = $this->lines->findForPeriod(null, $periodId);
        $this->actuals->deleteForPeriod($periodId);
        $count = 0;
        foreach ($lines as $line) {
            $key = (int)$line['party_id'] . '|' . strtoupper((string)$line['grade_code']);
            $actual = $dispatched[$key] ?? 0.0;
            $low = (float)$line['qty_low_tonnes'];
            $high = (float)$line['qty_high_tonnes'];
            $mid = ($low + $high) / 2;
            $this->actuals->insert([
                'period_id' => $periodId,
                'party_id' => (int)$line['party_id'],
                'grade_code' => strtoupper((string)$line['grade_code']),
                'forecast_low' => $low,
                'forecast_high' => $high,
                'actual_tonnes' => round($actual, 3),
                'variance_vs_midpoint' => round($actual - $mid, 2),
                'as_of' => $asOf,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string,mixed> $input
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    private function upsertLine(int $periodId, int $partyId, array $input, array $actor): array
    {
        $grade = strtoupper(trim((string)($input['grade_code'] ?? '')));
        if ($grade === '') {
            throw new ForecastException('A grade code is required.');
        }
        $low = $this->requireQty($input['qty_low_tonnes'] ?? null, 'qty_low_tonnes');
        $high = $this->requireQty($input['qty_high_tonnes'] ?? null, 'qty_high_tonnes');
        if ($low > $high) {
            throw new ForecastException('Low tonnes must be less than or equal to high tonnes.');
        }
        $confidence = $input['confidence'] ?? null;
        if ($confidence !== null && $confidence !== '' && !isset($this->config['confidences'][$confidence])) {
            throw new ForecastException('Confidence must be high, medium, or low.');
        }
        if ($confidence === '') {
            $confidence = null;
        }
        $note = trim((string)($input['note'] ?? ''));
        if ($note === '') {
            $note = null;
        }

        $existing = $this->lines->findOne($periodId, $partyId, $grade);
        $source = 'added';
        if ($existing !== null) {
            $source = $existing['source'] === 'added' ? 'added' : 'edited';
            $this->lines->update((int)$existing['id'], [
                'qty_low_tonnes' => $low,
                'qty_high_tonnes' => $high,
                'source' => $source,
                'confidence' => $confidence,
                'note' => $note,
                'owner_user_id' => $actor['id'] ?? null,
            ]);
            $id = (int)$existing['id'];
            $this->audit->log($actor['id'] ?? null, 'forecast_lines', $id, 'UPDATE', [
                'qty_low_tonnes' => $existing['qty_low_tonnes'],
                'qty_high_tonnes' => $existing['qty_high_tonnes'],
            ], ['qty_low_tonnes' => $low, 'qty_high_tonnes' => $high, 'source' => $source]);
        } else {
            $id = $this->lines->insert([
                'period_id' => $periodId,
                'party_id' => $partyId,
                'owner_user_id' => $actor['id'] ?? $this->parties->findById($partyId)->assignedSalesOwner ?? null,
                'grade_code' => $grade,
                'qty_low_tonnes' => $low,
                'qty_high_tonnes' => $high,
                'source' => $source,
                'confidence' => $confidence,
                'note' => $note,
            ]);
            $this->audit->log($actor['id'] ?? null, 'forecast_lines', $id, 'CREATE', null, [
                'grade_code' => $grade,
                'qty_low_tonnes' => $low,
                'qty_high_tonnes' => $high,
            ]);
        }
        $row = $this->lines->findById($id);

        return $this->presentLine($row);
    }

    private function prefillPartyIfEmpty(int $periodId, int $partyId, ?int $ownerUserId, string $asOf): void
    {
        if ($this->lines->countForParty($periodId, $partyId) > 0) {
            return;
        }
        foreach ($this->prefill->forParty($partyId, $asOf) as $draft) {
            $this->lines->insert([
                'period_id' => $periodId,
                'party_id' => $partyId,
                'owner_user_id' => $ownerUserId,
                'grade_code' => $draft['grade_code'],
                'qty_low_tonnes' => $draft['qty_low_tonnes'],
                'qty_high_tonnes' => $draft['qty_high_tonnes'],
                'source' => 'prefilled',
                'confidence' => null,
                'note' => null,
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function assignedParties(?int $ownerUserId): array
    {
        $sql = "SELECT id, name, assigned_sales_owner FROM parties WHERE is_active = 1";
        $params = [];
        if ($ownerUserId !== null) {
            $sql .= " AND assigned_sales_owner = ?";
            $params[] = $ownerUserId;
        }
        $sql .= " ORDER BY name ASC";

        return $this->database->fetchAll($sql, $params);
    }

    /** @return array<int,array{code:string,name:string}> */
    private function gradeCatalogue(): array
    {
        return $this->database->fetchAll(
            "SELECT code, name FROM products WHERE is_active = 1 AND code IS NOT NULL AND code <> '' ORDER BY code ASC"
        );
    }

    /** @return array<string,mixed> */
    private function requirePeriod(int $id): array
    {
        $row = $this->periods->findById($id);
        if ($row === null) {
            throw new ForecastException('Forecast period not found.');
        }

        return $row;
    }

    private function requireQty(mixed $value, string $field): float
    {
        if ($value === null || $value === '') {
            throw new ForecastException("{$field} is required.");
        }
        if (!is_numeric($value)) {
            throw new ForecastException("{$field} must be a number.");
        }
        $qty = round((float)$value, 2);
        if ($qty < 0) {
            throw new ForecastException("{$field} cannot be negative.");
        }

        return $qty;
    }

    private function assertYearMonth(string $yearMonth): void
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            throw new ForecastException('year_month must be YYYY-MM.');
        }
        $month = (int)substr($yearMonth, 5, 2);
        if ($month < 1 || $month > 12) {
            throw new ForecastException('year_month must be YYYY-MM.');
        }
    }
}
