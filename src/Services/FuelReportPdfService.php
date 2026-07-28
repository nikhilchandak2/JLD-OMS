<?php

namespace App\Services;

use TCPDF;

/**
 * Monthly fuel readings PDF for Kobelco / JCB / Dumpers.
 */
class FuelReportPdfService
{
    /**
     * @param array<string, mixed> $machine
     * @param list<array<string, mixed>> $readings
     */
    public function generateMachineMonthPdf(array $machine, ?string $month, array $readings): string
    {
        $category = strtolower((string)($machine['category'] ?? ''));
        $categoryLabel = match ($category) {
            'kobelco' => 'Kobelco',
            'jcb' => 'JCB',
            'dumpers' => 'Dumpers',
            default => ucfirst($category !== '' ? $category : 'Fuel'),
        };

        $machineName = trim((string)($machine['name'] ?? ''));
        if ($machineName === '') {
            $machineName = trim((string)($machine['serial_no'] ?? $machine['chassis_no'] ?? 'Machine'));
        }

        $monthLabel = $this->formatMonthLabel($month);
        $title = $categoryLabel . ' — Monthly Fuel Report';

        $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('JLD OMS');
        $pdf->SetAuthor('JLD Order Processing');
        $pdf->SetTitle($title . ' — ' . $machineName);
        $pdf->SetSubject($monthLabel);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->SetFooterMargin(10);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 9);

        $metaBits = array_filter([
            !empty($machine['serial_no']) ? 'Serial: ' . $machine['serial_no'] : null,
            !empty($machine['chassis_no']) ? 'Reg / Chassis: ' . $machine['chassis_no'] : null,
        ]);

        $html = '<h2 style="text-align:center;margin:0 0 4px 0;color:#2b235e;">'
            . $this->e($title) . '</h2>';
        $html .= '<h3 style="text-align:center;margin:0 0 6px 0;">'
            . $this->e($machineName) . '</h3>';
        $html .= '<p style="text-align:center;margin:0 0 12px 0;font-size:10px;color:#555;">'
            . 'Month: <strong>' . $this->e($monthLabel) . '</strong>'
            . ($metaBits !== [] ? ' &nbsp;|&nbsp; ' . $this->e(implode(' · ', $metaBits)) : '')
            . '<br/>Generated: ' . $this->e(date('d-m-Y H:i'))
            . '</p>';

        $vendor = $this->detectVendor($readings, $category);
        $html .= $this->buildTableHtml($vendor, $readings);

        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('', 'S');
    }

    /**
     * @param list<array<string, mixed>> $readings
     */
    private function detectVendor(array $readings, string $category): string
    {
        foreach ($readings as $row) {
            $v = strtolower((string)($row['vendor'] ?? ($row['extra']['vendor'] ?? '')));
            if ($v !== '') {
                return $v;
            }
        }
        return $category;
    }

    /**
     * @param list<array<string, mixed>> $readings
     */
    private function buildTableHtml(string $vendor, array $readings): string
    {
        if ($readings === []) {
            return '<p style="text-align:center;color:#666;">No daily readings for this month.</p>';
        }

        if ($vendor === 'dumpers') {
            return $this->dumpersTable($readings);
        }
        if ($vendor === 'jcb') {
            return $this->jcbTable($readings);
        }
        return $this->kobelcoTable($readings);
    }

    /**
     * @param list<array<string, mixed>> $readings
     */
    private function kobelcoTable(array $readings): string
    {
        $headers = ['Date', 'Total Fuel Consump.', 'Working Hrs', 'Ave. Fuel Consump.'];
        $body = '';
        $totalFuel = 0.0;
        $totalHours = 0.0;
        $hasFuel = false;
        $hasHours = false;

        foreach ($readings as $r) {
            $fuel = $this->cell($r['fuel_display'] ?? null, $this->num($r['fuel_consumed_liters'] ?? null, ' L'));
            $hours = $this->cell($r['working_hrs_display'] ?? null, $this->hhmm($r['working_hours'] ?? null));
            $avg = $this->cell($r['avg_display'] ?? null, $this->num($r['average_usage'] ?? null, ' L/h'));
            $body .= '<tr>'
                . '<td>' . $this->e($this->fmtDate($r['reading_date'] ?? null)) . '</td>'
                . '<td>' . $this->e($fuel) . '</td>'
                . '<td>' . $this->e($hours) . '</td>'
                . '<td>' . $this->e($avg) . '</td>'
                . '</tr>';

            $f = $this->toFloat($r['fuel_consumed_liters'] ?? null);
            $h = $this->toFloat($r['working_hours'] ?? null);
            if ($f !== null) {
                $totalFuel += $f;
                $hasFuel = true;
            }
            if ($h !== null) {
                $totalHours += $h;
                $hasHours = true;
            }
        }

        $overallAvg = ($hasHours && $totalHours > 0) ? round($totalFuel / $totalHours, 2) . ' L/h' : '—';
        $foot = '<tr style="font-weight:bold;background-color:#f3f3f3;">'
            . '<td>Total</td>'
            . '<td>' . $this->e($hasFuel ? $this->num($totalFuel, ' L') : '—') . '</td>'
            . '<td>' . $this->e($hasHours ? ($this->hhmm($totalHours) ?? '—') : '—') . '</td>'
            . '<td>' . $this->e($overallAvg) . '</td>'
            . '</tr>';

        return $this->wrapTable($headers, $body . $foot);
    }

    /**
     * @param list<array<string, mixed>> $readings
     */
    private function jcbTable(array $readings): string
    {
        $headers = [
            'Date', 'FuelUsedInWorking', 'Working Time', 'EngineOn Time',
            'Idle Time', 'DistanceTravelledInRoading', 'Avg',
        ];
        $body = '';
        $totalFuel = 0.0;
        $totalHours = 0.0;
        $totalEngine = 0.0;
        $totalIdle = 0.0;
        $totalDist = 0.0;
        $hasFuel = $hasHours = $hasEngine = $hasIdle = $hasDist = false;

        foreach ($readings as $r) {
            $extra = is_array($r['extra'] ?? null) ? $r['extra'] : [];
            $fuel = $this->cell($r['fuel_display'] ?? null, $this->num($r['fuel_consumed_liters'] ?? null));
            $hours = $this->cell($r['working_hrs_display'] ?? null, $this->num($r['working_hours'] ?? null));
            $engine = $this->cell($r['engine_on_display'] ?? null, $this->num($extra['engine_on_hours'] ?? null));
            $idle = $this->cell($r['idle_display'] ?? null, $this->num($extra['idle_hours'] ?? null));
            $dist = $this->cell($r['distance_display'] ?? null, $this->num($extra['distance_roading'] ?? null));
            $avg = $this->cell($r['avg_display'] ?? null, $this->num($r['average_usage'] ?? null, ' L/h'));

            $body .= '<tr>'
                . '<td>' . $this->e($this->fmtDate($r['reading_date'] ?? null)) . '</td>'
                . '<td>' . $this->e($fuel) . '</td>'
                . '<td>' . $this->e($hours) . '</td>'
                . '<td>' . $this->e($engine) . '</td>'
                . '<td>' . $this->e($idle) . '</td>'
                . '<td>' . $this->e($dist) . '</td>'
                . '<td>' . $this->e($avg) . '</td>'
                . '</tr>';

            $f = $this->toFloat($r['fuel_consumed_liters'] ?? null);
            $h = $this->toFloat($r['working_hours'] ?? null);
            $eng = $this->toFloat($extra['engine_on_hours'] ?? null);
            $idl = $this->toFloat($extra['idle_hours'] ?? null);
            $d = $this->toFloat($extra['distance_roading'] ?? null);
            if ($f !== null) {
                $totalFuel += $f;
                $hasFuel = true;
            }
            if ($h !== null) {
                $totalHours += $h;
                $hasHours = true;
            }
            if ($eng !== null) {
                $totalEngine += $eng;
                $hasEngine = true;
            }
            if ($idl !== null) {
                $totalIdle += $idl;
                $hasIdle = true;
            }
            if ($d !== null) {
                $totalDist += $d;
                $hasDist = true;
            }
        }

        $overallAvg = ($hasHours && $totalHours > 0) ? round($totalFuel / $totalHours, 2) . ' L/h' : '—';
        $foot = '<tr style="font-weight:bold;background-color:#f3f3f3;">'
            . '<td>Total</td>'
            . '<td>' . $this->e($hasFuel ? $this->num($totalFuel) : '—') . '</td>'
            . '<td>' . $this->e($hasHours ? $this->num($totalHours) : '—') . '</td>'
            . '<td>' . $this->e($hasEngine ? $this->num($totalEngine) : '—') . '</td>'
            . '<td>' . $this->e($hasIdle ? $this->num($totalIdle) : '—') . '</td>'
            . '<td>' . $this->e($hasDist ? $this->num($totalDist) : '—') . '</td>'
            . '<td>' . $this->e($overallAvg) . '</td>'
            . '</tr>';

        return $this->wrapTable($headers, $body . $foot);
    }

    /**
     * @param list<array<string, mixed>> $readings
     */
    private function dumpersTable(array $readings): string
    {
        $headers = [
            'Date', 'Fuel Consumed(ltr)', 'Idling Fuel(ltr)', 'Distance Covered(KM)',
            'Mileage', 'Running Time', 'Idling Time',
        ];
        $body = '';
        $totalFuel = 0.0;
        $totalHours = 0.0;
        $totalDist = 0.0;
        $totalIdleFuel = 0.0;
        $totalIdle = 0.0;
        $hasFuel = $hasHours = $hasDist = $hasIdleFuel = $hasIdle = false;

        foreach ($readings as $r) {
            $extra = is_array($r['extra'] ?? null) ? $r['extra'] : [];
            $fuel = $this->cell($r['fuel_display'] ?? null, $this->num($r['fuel_consumed_liters'] ?? null));
            $hours = $this->cell($r['working_hrs_display'] ?? null, $this->hhmmss($r['working_hours'] ?? null));
            $dist = $this->cell($r['distance_display'] ?? null, $this->num($extra['distance_km'] ?? null));
            $mileage = $this->cell(
                $r['mileage_display'] ?? null,
                $this->cell($r['avg_display'] ?? null, $this->num($r['average_usage'] ?? null))
            );
            $idleFuel = $this->cell($r['idle_fuel_display'] ?? null, $this->num($extra['idle_fuel_liters'] ?? null));
            $idle = $this->cell($r['idle_display'] ?? null, $this->hhmmss($extra['idle_hours'] ?? null));

            $body .= '<tr>'
                . '<td>' . $this->e($this->fmtDate($r['reading_date'] ?? null)) . '</td>'
                . '<td>' . $this->e($fuel) . '</td>'
                . '<td>' . $this->e($idleFuel) . '</td>'
                . '<td>' . $this->e($dist) . '</td>'
                . '<td>' . $this->e($mileage) . '</td>'
                . '<td>' . $this->e($hours) . '</td>'
                . '<td>' . $this->e($idle) . '</td>'
                . '</tr>';

            $f = $this->toFloat($r['fuel_consumed_liters'] ?? null);
            $h = $this->toFloat($r['working_hours'] ?? null);
            $d = $this->toFloat($extra['distance_km'] ?? null);
            $idf = $this->toFloat($extra['idle_fuel_liters'] ?? null);
            $idl = $this->toFloat($extra['idle_hours'] ?? null);
            if ($f !== null) {
                $totalFuel += $f;
                $hasFuel = true;
            }
            if ($h !== null) {
                $totalHours += $h;
                $hasHours = true;
            }
            if ($d !== null) {
                $totalDist += $d;
                $hasDist = true;
            }
            if ($idf !== null) {
                $totalIdleFuel += $idf;
                $hasIdleFuel = true;
            }
            if ($idl !== null) {
                $totalIdle += $idl;
                $hasIdle = true;
            }
        }

        $overallMileage = ($hasDist && $hasFuel && $totalFuel > 0)
            ? (string)round($totalDist / $totalFuel, 2)
            : '—';

        $foot = '<tr style="font-weight:bold;background-color:#f3f3f3;">'
            . '<td>Total</td>'
            . '<td>' . $this->e($hasFuel ? $this->num($totalFuel) : '—') . '</td>'
            . '<td>' . $this->e($hasIdleFuel ? $this->num($totalIdleFuel) : '—') . '</td>'
            . '<td>' . $this->e($hasDist ? $this->num($totalDist) : '—') . '</td>'
            . '<td>' . $this->e($overallMileage) . '</td>'
            . '<td>' . $this->e($hasHours ? ($this->hhmmss($totalHours) ?? '—') : '—') . '</td>'
            . '<td>' . $this->e($hasIdle ? ($this->hhmmss($totalIdle) ?? '—') : '—') . '</td>'
            . '</tr>';

        return $this->wrapTable($headers, $body . $foot);
    }

    /**
     * @param list<string> $headers
     */
    private function wrapTable(array $headers, string $bodyRows): string
    {
        $thead = '';
        foreach ($headers as $h) {
            $thead .= '<th style="background-color:#2b235e;color:#fff;font-weight:bold;">'
                . $this->e($h) . '</th>';
        }

        return '<table border="1" cellpadding="4" cellspacing="0" width="100%" '
            . 'style="border-collapse:collapse;font-size:8.5px;text-align:center;">'
            . '<thead><tr>' . $thead . '</tr></thead>'
            . '<tbody>' . $bodyRows . '</tbody>'
            . '</table>';
    }

    private function formatMonthLabel(?string $month): string
    {
        if ($month === null || !preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
            return 'All / Unknown';
        }
        $names = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];
        $mi = (int)$m[2];
        return ($names[$mi] ?? $m[2]) . ' ' . $m[1];
    }

    private function fmtDate(mixed $value): string
    {
        $s = trim((string)$value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return $s !== '' ? $s : '—';
    }

    private function cell(?string $preferred, ?string $fallback): string
    {
        $preferred = $preferred !== null ? trim($preferred) : '';
        if ($preferred !== '') {
            return $preferred;
        }
        $fallback = $fallback !== null ? trim($fallback) : '';
        return $fallback !== '' ? $fallback : '—';
    }

    private function num(mixed $value, string $suffix = ''): ?string
    {
        $f = $this->toFloat($value);
        if ($f === null) {
            return null;
        }
        $formatted = rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
        if ($formatted === '' || $formatted === '-') {
            $formatted = '0';
        }
        return $formatted . $suffix;
    }

    private function hhmm(mixed $hours): ?string
    {
        $f = $this->toFloat($hours);
        if ($f === null) {
            return null;
        }
        $totalMinutes = (int)round($f * 60);
        return sprintf('%d:%02d', intdiv($totalMinutes, 60), $totalMinutes % 60);
    }

    private function hhmmss(mixed $hours): ?string
    {
        $f = $this->toFloat($hours);
        if ($f === null) {
            return null;
        }
        $totalSeconds = (int)round($f * 3600);
        $h = intdiv($totalSeconds, 3600);
        $m = intdiv($totalSeconds % 3600, 60);
        $s = $totalSeconds % 60;
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $f = (float)$value;
            return is_finite($f) ? $f : null;
        }
        return null;
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
