<?php

namespace App\Services;

use TCPDF;

class BriefingPdfService
{
    /** @param array<string,mixed> $briefing */
    public function render(array $briefing): string
    {
        $name = (string)($briefing['party']['name'] ?? 'Account');
        $pdf = new TCPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('JLD OMS');
        $pdf->SetAuthor('JLD Order Processing');
        $pdf->SetTitle('Account briefing — ' . $name);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->SetFooterMargin(10);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 9);

        $html = '<h2 style="text-align:center;color:#2b235e;margin:0;">' . $this->e($name) . '</h2>';
        $html .= '<p style="text-align:center;font-size:9px;color:#555;">Account briefing for a visit. Credit shows a headroom band and as-of only — never ledger amounts.</p>';

        $html .= $this->sectionHtml('Key contacts', $briefing['contacts'] ?? []);
        if (isset($briefing['competitors'])) {
            $html .= $this->sectionHtml('Competitor situation', $briefing['competitors']);
        }
        $html .= $this->sectionHtml('Open issues and complaint history', $briefing['issues'] ?? []);
        $html .= $this->visitHtml($briefing['last_visit'] ?? []);
        $html .= $this->kvSection('Recent order pattern (last 6 months)', $briefing['order_pattern'] ?? [], function (array $row) {
            return $this->e((string)$row['grade_code']) . ' — ' . $this->e((string)$row['tonnes']) . ' t';
        });
        $html .= $this->kvSection('Current month forecast vs actual', $briefing['forecast'] ?? [], function (array $row) {
            return $this->e((string)$row['grade_code']) . ' — forecast '
                . $this->e((string)$row['forecast_low']) . '–' . $this->e((string)$row['forecast_high'])
                . ' t, actual ' . $this->e((string)$row['actual_tonnes']) . ' t';
        });
        $credit = $briefing['credit'] ?? [];
        $html .= '<h3 style="color:#2b235e;font-size:12px;">Credit status</h3>';
        if (empty($credit['recorded'])) {
            $html .= '<p><em>' . $this->e((string)($credit['empty_message'] ?? 'not yet recorded')) . '</em></p>';
        } else {
            $html .= '<p>' . $this->e((string)($credit['headroom_band_label'] ?? ''))
                . ' · as-of ' . $this->e((string)($credit['ledger_as_of'] ?? 'unknown')) . '</p>';
        }
        $html .= $this->kvSection('Open deals', $briefing['open_deals'] ?? [], function (array $row) {
            return $this->e((string)$row['title']) . ' — ' . $this->e((string)$row['stage_label']);
        });
        $notes = $briefing['handover_notes'] ?? [];
        $html .= '<h3 style="color:#2b235e;font-size:12px;">Handover notes (transitional bridge — review by '
            . $this->e((string)($notes['review_date'] ?? '')) . ')</h3>';
        if (empty($notes['recorded'])) {
            $html .= '<p><em>' . $this->e((string)($notes['empty_message'] ?? 'not yet recorded')) . '</em></p>';
        } else {
            foreach ($notes['items'] ?? [] as $note) {
                $html .= '<p>' . nl2br($this->e((string)($note['note'] ?? ''))) . '<br><span style="color:#666;font-size:8px;">'
                    . $this->e((string)($note['author_name'] ?? '')) . ' · ' . $this->e((string)($note['created_at'] ?? ''))
                    . '</span></p>';
            }
        }

        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    /** @param array<string,mixed> $section */
    private function sectionHtml(string $title, array $section): string
    {
        $html = '<h3 style="color:#2b235e;font-size:12px;">' . $this->e($title) . '</h3>';
        if (empty($section['recorded'])) {
            return $html . '<p><em>' . $this->e((string)($section['empty_message'] ?? 'not yet recorded')) . '</em></p>';
        }
        foreach ($section['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['name'])) {
                $html .= '<p><strong>' . $this->e((string)$item['name']) . '</strong> — '
                    . $this->e((string)($item['influence_label'] ?? '')) . ' · '
                    . $this->e((string)($item['relationship_label'] ?? '')) . '</p>';
            } elseif (isset($item['competitor_name'])) {
                $html .= '<p><strong>' . $this->e((string)$item['competitor_name']) . '</strong>'
                    . ($item['grade_code'] ? ' / ' . $this->e((string)$item['grade_code']) : '')
                    . ' · why: ' . $this->e((string)($item['reason_note'] ?: $item['reason_label'] ?? ''))
                    . ' (' . $this->e((string)($item['intelligence_label'] ?? '')) . ')</p>';
            } elseif (isset($item['issue_type_label'])) {
                $html .= '<p>' . $this->e((string)$item['issue_type_label']) . ' · '
                    . $this->e((string)$item['status_label']) . ' — '
                    . $this->e((string)($item['description'] ?? '')) . '</p>';
            }
        }

        return $html;
    }

    /** @param array<string,mixed> $section */
    private function visitHtml(array $section): string
    {
        $html = '<h3 style="color:#2b235e;font-size:12px;">Last visit</h3>';
        if (empty($section['recorded'])) {
            return $html . '<p><em>' . $this->e((string)($section['empty_message'] ?? 'not yet recorded')) . '</em></p>';
        }
        $v = $section['item'] ?? [];
        $who = [];
        foreach ($v['contacts'] ?? [] as $c) {
            $who[] = (string)($c['name'] ?? '');
        }

        return $html . '<p>' . $this->e((string)($v['visit_date'] ?? ''))
            . ' · met ' . $this->e($who === [] ? 'not recorded' : implode(', ', $who))
            . '<br>Outcome: ' . $this->e((string)($v['outcome'] ?? '—'))
            . '<br>Next touchpoint: ' . $this->e((string)($v['next_planned_touchpoint'] ?? '—'))
            . '</p>';
    }

    /**
     * @param array<string,mixed> $section
     * @param callable(array<string,mixed>):string $line
     */
    private function kvSection(string $title, array $section, callable $line): string
    {
        $html = '<h3 style="color:#2b235e;font-size:12px;">' . $this->e($title) . '</h3>';
        if (empty($section['recorded']) || (($section['items'] ?? []) === [] && ($section['empty_message'] ?? '') !== '')) {
            return $html . '<p><em>' . $this->e((string)($section['empty_message'] ?? 'not yet recorded')) . '</em></p>';
        }
        foreach ($section['items'] ?? [] as $item) {
            if (is_array($item)) {
                $html .= '<p>' . $line($item) . '</p>';
            }
        }

        return $html;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
