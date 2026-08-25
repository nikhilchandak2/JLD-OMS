<?php

namespace App\Services;

use TCPDF;

/**
 * Print view of a handoff packet for the person doing the Busy (or other) manual entry.
 * Receiving teams use this instead of re-typing fields.
 */
class HandoffPdfService
{
    /** @param array<string,mixed> $packet */
    public function render(array $packet): string
    {
        $type = (string)($packet['packet_type'] ?? '');
        $title = $type === HandoffService::TYPE_SALES_TO_DISPATCH
            ? 'Sales → Dispatch handoff'
            : 'Dispatch → Accounts handoff';

        $pdf = new TCPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('JLD OMS');
        $pdf->SetAuthor('JLD Order Processing');
        $pdf->SetTitle($title . ' #' . (int)$packet['id']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->SetFooterMargin(10);
        $pdf->SetMargins(14, 14, 14);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);

        $html = '<h2 style="text-align:center;color:#2b235e;margin:0 0 4px 0;">' . $this->e($title) . '</h2>';
        $html .= '<p style="text-align:center;font-size:9px;color:#555;margin:0 0 12px 0;">'
            . 'Packet #' . (int)$packet['id']
            . ' · schema v' . (int)$packet['schema_version']
            . ' · Source of truth for the manual bridge — do not re-type these fields.'
            . '</p>';

        $html .= $this->metaTable($packet);
        $html .= '<h3 style="color:#2b235e;font-size:12px;">Packet fields</h3>';
        $html .= $this->payloadTable($type, is_array($packet['payload'] ?? null) ? $packet['payload'] : []);

        if (!empty($packet['supersession_reason'])) {
            $html .= '<p><strong>Supersession reason:</strong> ' . $this->e((string)$packet['supersession_reason']) . '</p>';
        }

        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    /** @param array<string,mixed> $packet */
    private function metaTable(array $packet): string
    {
        $acked = $packet['acknowledged_at']
            ? $this->e((string)$packet['acknowledged_at']) . ' by ' . $this->e((string)($packet['acknowledged_by_name'] ?? ''))
            : 'Not yet acknowledged';

        $rows = [
            ['Deal', $packet['deal_title'] ? '#' . (int)$packet['deal_id'] . ' ' . $packet['deal_title'] : ($packet['deal_id'] ? '#' . $packet['deal_id'] : '—')],
            ['Party', $packet['party_name'] ?? '—'],
            ['Order', $packet['order_no'] ?? ($packet['order_id'] ? '#' . $packet['order_id'] : '—')],
            ['Created', ($packet['created_at'] ?? '—') . ' by ' . ($packet['created_by_name'] ?? '—')],
            ['Acknowledged', $acked],
        ];

        $html = '<table border="1" cellpadding="5" cellspacing="0" width="100%">';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><td width="32%" bgcolor="#f3f1fa"><strong>' . $this->e((string)$label)
                . '</strong></td><td width="68%">' . $this->e((string)$value) . '</td></tr>';
        }
        $html .= '</table>';

        return $html;
    }

    /** @param array<string,mixed> $payload */
    private function payloadTable(string $type, array $payload): string
    {
        $labels = $type === HandoffService::TYPE_SALES_TO_DISPATCH
            ? [
                'grades' => 'Confirmed grade(s) + spec',
                'quantity_tonnes' => 'Quantity (tonnes)',
                'packing' => 'Packing',
                'delivery_timeline' => 'Agreed delivery timeline',
                'delivery_terms' => 'Delivery terms',
                'special_handling_notes' => 'Special handling notes',
            ]
            : [
                'delivery_date' => 'Dispatch / delivery date',
                'quote_reference' => 'Linked quote',
                'agreed_terms' => 'Agreed terms',
                'invoice_reference' => 'Invoice reference',
            ];

        $config = require __DIR__ . '/../../config/handoff.php';
        $termLabels = $config['delivery_terms'] ?? [];

        $html = '<table border="1" cellpadding="5" cellspacing="0" width="100%">';
        foreach ($labels as $key => $label) {
            $value = $payload[$key] ?? '';
            if ($key === 'grades' && is_array($value)) {
                $bits = [];
                foreach ($value as $grade) {
                    if (!is_array($grade)) {
                        continue;
                    }
                    $bits[] = trim((string)($grade['grade_code'] ?? '') . ' — ' . (string)($grade['spec'] ?? ''));
                }
                $value = implode("\n", $bits);
            } elseif ($key === 'delivery_terms' && is_string($value)) {
                $value = $termLabels[$value] ?? $value;
            }
            $html .= '<tr><td width="32%" bgcolor="#f3f1fa"><strong>' . $this->e($label)
                . '</strong></td><td width="68%">' . nl2br($this->e((string)$value)) . '</td></tr>';
        }
        $html .= '</table>';

        return $html;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
