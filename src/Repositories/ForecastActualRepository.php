<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\DispatchSchema;

class ForecastActualRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function deleteForPeriod(int $periodId): void
    {
        $this->database->execute("DELETE FROM forecast_actuals WHERE period_id = ?", [$periodId]);
    }

    public function insert(array $row): void
    {
        $this->database->execute(
            "INSERT INTO forecast_actuals (
                period_id, party_id, grade_code, forecast_low, forecast_high,
                actual_tonnes, variance_vs_midpoint, as_of
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $row['period_id'],
                $row['party_id'],
                $row['grade_code'],
                $row['forecast_low'],
                $row['forecast_high'],
                $row['actual_tonnes'],
                $row['variance_vs_midpoint'],
                $row['as_of'],
            ]
        );
    }

    /**
     * Dispatched tonnes in [from, to) keyed by party_id|grade_code.
     *
     * @return array<string,float>
     */
    public function dispatchedTonnesByPartyGrade(string $from, string $to): array
    {
        $active = DispatchSchema::activeDispatchWhere('d');
        $rows = $this->database->fetchAll(
            "SELECT o.party_id, UPPER(p.code) AS grade_code,
                    SUM(COALESCE(d.loading_weight_tons, d.dispatch_qty_trucks * COALESCE(o.tons_per_truck, 40))) AS tonnes
             FROM dispatches d
             JOIN orders o ON o.id = d.order_id
             JOIN products p ON p.id = o.product_id
             WHERE d.dispatch_date >= ? AND d.dispatch_date < ?
               AND p.code IS NOT NULL AND p.code <> ''
               AND {$active}
             GROUP BY o.party_id, UPPER(p.code)",
            [$from, $to]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['party_id'] . '|' . $row['grade_code']] = (float)$row['tonnes'];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function byGrade(int $periodId): array
    {
        return $this->database->fetchAll(
            "SELECT grade_code,
                    SUM(forecast_low) AS forecast_low,
                    SUM(forecast_high) AS forecast_high,
                    SUM(actual_tonnes) AS actual_tonnes,
                    SUM(variance_vs_midpoint) AS variance_vs_midpoint,
                    MAX(as_of) AS as_of
             FROM forecast_actuals
             WHERE period_id = ?
             GROUP BY grade_code
             ORDER BY grade_code",
            [$periodId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function byAccount(int $periodId): array
    {
        return $this->database->fetchAll(
            "SELECT a.party_id, p.name AS party_name,
                    SUM(a.forecast_low) AS forecast_low,
                    SUM(a.forecast_high) AS forecast_high,
                    SUM(a.actual_tonnes) AS actual_tonnes,
                    SUM(a.variance_vs_midpoint) AS variance_vs_midpoint,
                    MAX(a.as_of) AS as_of
             FROM forecast_actuals a
             JOIN parties p ON p.id = a.party_id
             WHERE a.period_id = ?
             GROUP BY a.party_id, p.name
             ORDER BY p.name",
            [$periodId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function linesForPeriod(int $periodId): array
    {
        return $this->database->fetchAll(
            "SELECT * FROM forecast_lines WHERE period_id = ?",
            [$periodId]
        );
    }
}
