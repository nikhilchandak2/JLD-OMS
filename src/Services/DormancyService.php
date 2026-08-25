<?php

namespace App\Services;

use App\Repositories\AccountDormancySignalRepository;
use App\Repositories\DormancyRuleRepository;

/**
 * Nightly dormancy read model. Orders are the truth; visits only set severity.
 * Thresholds come from dormancy_rules rows, not from this class.
 */
class DormancyService
{
    public const SEVERITY_WATCH = 'watch';
    public const SEVERITY_URGENT = 'urgent';

    private AccountDormancySignalRepository $signals;
    private DormancyRuleRepository $rules;
    private DormancyPolicy $policy;
    private array $config;

    public function __construct()
    {
        $this->signals = new AccountDormancySignalRepository();
        $this->rules = new DormancyRuleRepository();
        $this->policy = new DormancyPolicy();
        $this->config = require dirname(__DIR__, 2) . '/config/dormancy.php';
    }

    /**
     * Rebuild today's (or $asOf's) snapshot. Safe to run twice: today's rows are replaced.
     *
     * @return array{computed_on:string,signals:int,urgent:int,watch:int,rows:array<int,array<string,mixed>>}
     */
    public function rebuild(string $asOf): array
    {
        $rules = $this->rules->findActive();
        if ($rules === []) {
            throw new DormancyException('No active dormancy rule is configured.');
        }

        $this->signals->deleteForDate($asOf);
        $activity = $this->signals->activitySnapshot($asOf);
        $forecastGaps = (new ForecastService())->partyIdsWithForecastGap($asOf);
        $inserted = [];
        foreach ($activity as $row) {
            $row['forecast_gap_flag'] = isset($forecastGaps[(int)$row['party_id']]);
            $signal = $this->evaluateParty($row, $rules, $asOf);
            if ($signal === null) {
                continue;
            }
            $id = $this->signals->insert($signal);
            $signal['id'] = $id;
            $inserted[] = $signal;
        }

        $urgent = 0;
        $watch = 0;
        foreach ($inserted as $row) {
            if ($row['severity'] === self::SEVERITY_URGENT) {
                $urgent++;
            } else {
                $watch++;
            }
        }

        return [
            'computed_on' => $asOf,
            'signals' => count($inserted),
            'urgent' => $urgent,
            'watch' => $watch,
            'rows' => $inserted,
        ];
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<int,array<string,mixed>>
     */
    public function listForActor(array $actor, ?string $computedOn = null): array
    {
        $this->policy->assertCan($actor['role'] ?? null, DormancyPolicy::VIEW_DORMANCY);
        $day = $computedOn ?: (new \DateTimeImmutable('now', new \DateTimeZone($this->config['timezone'])))->format('Y-m-d');
        $ownerId = null;
        if (!$this->policy->can($actor['role'] ?? null, DormancyPolicy::VIEW_ALL_DORMANCY)) {
            $ownerId = (int)($actor['id'] ?? 0);
            if ($ownerId <= 0) {
                return [];
            }
        }

        return array_map([$this, 'present'], $this->signals->findForDate($day, $ownerId));
    }

    /** @return array<int,array<string,mixed>> */
    public function explainActivitySnapshot(string $asOf): array
    {
        return $this->signals->explainActivitySnapshot($asOf);
    }

    /** @return array<int,array<string,mixed>> */
    public function explainLastOrderAggregate(): array
    {
        return $this->signals->explainLastOrderAggregate();
    }

    public function timeActivitySnapshot(string $asOf): float
    {
        $start = microtime(true);
        $this->signals->activitySnapshot($asOf);
        return microtime(true) - $start;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $rules
     * @return array<string,mixed>|null
     */
    public function evaluateParty(array $row, array $rules, string $asOf): ?array
    {
        $companyId = $row['last_order_company_id'] !== null && $row['last_order_company_id'] !== ''
            ? (int)$row['last_order_company_id']
            : null;
        $tier = $row['account_tier'] ?? null;
        $rule = $this->rules->match($rules, $companyId, $tier === null || $tier === '' ? null : (string)$tier);

        $daysNoOrder = (int)$rule['days_no_order'];
        $daysNoOrderUrgent = (int)$rule['days_no_order_urgent'];
        $daysNoVisit = (int)$rule['days_no_visit'];

        $daysSinceOrder = $row['days_since_last_order'] === null ? null : (int)$row['days_since_last_order'];
        $daysSinceVisit = $row['days_since_last_visit'] === null ? null : (int)$row['days_since_last_visit'];
        $lastOrder = $row['last_order_date'] ?? null;
        $lastVisit = $row['last_visit_date'] ?? null;

        $orderDormant = $lastOrder === null || $daysSinceOrder >= $daysNoOrder;
        $forecastGap = !empty($row['forecast_gap_flag']);
        if (!$orderDormant && !$forecastGap) {
            return null;
        }

        $visitStale = $lastVisit === null || $daysSinceVisit >= $daysNoVisit;
        $severity = $visitStale ? self::SEVERITY_URGENT : self::SEVERITY_WATCH;
        if ($daysNoOrderUrgent > $daysNoOrder && $daysSinceOrder !== null && $daysSinceOrder >= $daysNoOrderUrgent) {
            $severity = self::SEVERITY_URGENT;
        }

        return [
            'party_id' => (int)$row['party_id'],
            'company_id' => $companyId,
            'computed_on' => $asOf,
            'days_since_last_order' => $daysSinceOrder,
            'last_order_date' => $lastOrder,
            'days_since_last_visit' => $daysSinceVisit,
            'last_visit_date' => $lastVisit,
            'severity' => $severity,
            'reason_summary' => $this->reasonSummary($daysSinceOrder, $lastOrder, $daysSinceVisit, $lastVisit, $severity, $forecastGap),
            'forecast_gap_flag' => $forecastGap ? 1 : 0,
            'party_name' => $row['party_name'] ?? null,
            'assigned_sales_owner' => $row['assigned_sales_owner'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row */
    public function present(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['party_id'] = (int)$row['party_id'];
        $row['days_since_last_order'] = $row['days_since_last_order'] === null ? null : (int)$row['days_since_last_order'];
        $row['days_since_last_visit'] = $row['days_since_last_visit'] === null ? null : (int)$row['days_since_last_visit'];
        $row['forecast_gap_flag'] = (int)($row['forecast_gap_flag'] ?? 0) === 1;
        $row['severity_label'] = $this->config['severities'][$row['severity']] ?? $row['severity'];

        return $row;
    }

    private function reasonSummary(
        ?int $daysSinceOrder,
        ?string $lastOrder,
        ?int $daysSinceVisit,
        ?string $lastVisit,
        string $severity,
        bool $forecastGap
    ): string {
        if ($forecastGap && ($lastOrder === null || ($daysSinceOrder !== null && $daysSinceOrder < 20))) {
            return 'Forecast for this month with no orders by day 20.';
        }

        if ($lastOrder === null) {
            $orderBit = 'No orders on record';
        } else {
            $orderBit = "No order in {$daysSinceOrder} days";
        }

        if ($severity === self::SEVERITY_URGENT) {
            $visitBit = $lastVisit === null
                ? 'no visit logged'
                : "last visit {$daysSinceVisit} days ago";
            return "{$orderBit} and {$visitBit} — going cold silently.";
        }

        $visitBit = $lastVisit === null
            ? 'a recent visit is on file'
            : "visited {$daysSinceVisit} day" . ($daysSinceVisit === 1 ? '' : 's') . ' ago';
        return "{$orderBit}, but {$visitBit} — possibly a normal cycle gap.";
    }
}
