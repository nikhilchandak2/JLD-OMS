<?php

namespace App\Services;

use App\Core\Database;
use App\Support\DispatchSchema;

/**
 * Prefill a party's forecast from dispatched tonnage in the last N completed
 * months. Historical prefill is min/max of monthly totals — not a trend fit.
 */
class ForecastPrefillService
{
    private Database $database;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->config = require dirname(__DIR__, 2) . '/config/forecast.php';
    }

    /**
     * Inclusive start date and exclusive end date of the completed-month window.
     *
     * @return array{0:string,1:string,2:array<int,string>}
     */
    public function completedMonthWindow(string $asOf, ?int $months = null): array
    {
        $months = $months ?? (int)$this->config['prefill_completed_months'];
        $end = (new \DateTimeImmutable($asOf, new \DateTimeZone($this->config['timezone'])))
            ->modify('first day of this month');
        $start = $end->modify("-{$months} months");
        $labels = [];
        $cursor = $start;
        while ($cursor < $end) {
            $labels[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return [$start->format('Y-m-d'), $end->format('Y-m-d'), $labels];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forParty(int $partyId, string $asOf): array
    {
        [$from, $to] = $this->completedMonthWindow($asOf);
        $active = DispatchSchema::activeDispatchWhere('d');
        $rows = $this->database->fetchAll(
            "SELECT UPPER(p.code) AS grade_code,
                    DATE_FORMAT(d.dispatch_date, '%Y-%m') AS ym_key,
                    SUM(" . DispatchSchema::tonnesExpr('d', 'o') . ") AS tonnes
             FROM dispatches d
             JOIN orders o ON o.id = d.order_id
             JOIN products p ON p.id = o.product_id
             WHERE o.party_id = ?
               AND d.dispatch_date >= ?
               AND d.dispatch_date < ?
               AND p.code IS NOT NULL AND p.code <> ''
               AND {$active}
             GROUP BY UPPER(p.code), DATE_FORMAT(d.dispatch_date, '%Y-%m')",
            [$partyId, $from, $to]
        );

        $byGrade = [];
        foreach ($rows as $row) {
            $code = (string)$row['grade_code'];
            $byGrade[$code][] = (float)$row['tonnes'];
        }

        $out = [];
        foreach ($byGrade as $code => $months) {
            $low = $this->roundQty(min($months));
            $high = $this->roundQty(max($months));
            if ($low > $high) {
                $tmp = $low;
                $low = $high;
                $high = $tmp;
            }
            $out[] = [
                'grade_code' => $code,
                'qty_low_tonnes' => $low,
                'qty_high_tonnes' => $high,
                'source' => 'prefilled',
                'months_observed' => count($months),
            ];
        }
        usort($out, static fn(array $a, array $b) => strcmp($a['grade_code'], $b['grade_code']));

        return $out;
    }

    public function roundQty(float $tonnes): float
    {
        return round($tonnes, 1);
    }
}
