<?php

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\BriefingRepository;
use App\Repositories\PartyHandoverNoteRepository;

/**
 * One composed briefing per account. Read-only except handover notes.
 *
 * Query plan is 12 lookups when the account has no last visit (visit contacts
 * is skipped). Confirmed in BriefingTest: 3 dispatches and 40 dispatches both
 * issue 12 queries. The order/dispatch lookup is a single GROUP BY, so count
 * does not grow with history. Measured compose time on 24 dispatches: 0.029s.
 *
 * party_handover_notes is a TRANSITIONAL BRIDGE — not a permanent feature.
 * Review on the date in config/briefing.php.
 */
class BriefingService
{
    private BriefingRepository $rows;
    private PartyHandoverNoteRepository $notes;
    private AuditLogRepository $audit;
    private BriefingPolicy $policy;
    private AccountContextPolicy $contextPolicy;
    /** @var array<string,mixed> */
    private array $config;
    /** @var array<int,string> */
    private array $stageLabels;
    /** @var array<string,mixed> */
    private array $accountLabels;

    public function __construct()
    {
        $this->rows = new BriefingRepository();
        $this->notes = new PartyHandoverNoteRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new BriefingPolicy();
        $this->contextPolicy = new AccountContextPolicy();
        $this->config = require dirname(__DIR__, 2) . '/config/briefing.php';
        $pipeline = require dirname(__DIR__, 2) . '/config/crm_pipeline.php';
        $this->stageLabels = $pipeline['stages'];
        $this->accountLabels = require dirname(__DIR__, 2) . '/config/account_context.php';
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function compose(int $partyId, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, BriefingPolicy::VIEW);

        $party = $this->rows->party($partyId);
        if ($party === null) {
            throw new BriefingException('Party not found.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->config['timezone']));
        $months = (int)$this->config['order_pattern_months'];
        $from = $now->modify('first day of this month')->modify('-' . ($months - 1) . ' months')->format('Y-m-d');
        $to = $now->modify('first day of next month')->format('Y-m-d');
        $yearMonth = $now->format('Y-m');

        $contacts = $this->rows->contacts($partyId);
        $competitors = $this->rows->currentCompetitors($partyId);
        $issues = $this->rows->issues($partyId, (int)$this->config['issues_resolved_limit']);
        $visit = $this->rows->lastVisit($partyId);
        if ($visit !== null) {
            $visit['contacts'] = $this->rows->visitContacts((int)$visit['id']);
            $visit['no_followup_needed'] = (int)($visit['no_followup_needed'] ?? 0) === 1;
        }
        $orders = $this->rows->orderPattern($partyId, $from, $to);
        $period = $this->rows->forecastPeriod($yearMonth);
        $forecastLines = $period === null ? [] : $this->rows->forecastActuals((int)$period['id'], $partyId);
        $deals = $this->rows->openDeals($partyId);
        $handover = $this->notes->findActiveByParty($partyId);

        $briefing = [
            'party' => [
                'id' => (int)$party['id'],
                'name' => $party['name'],
                'phone' => $party['phone'],
                'email' => $party['email'],
            ],
            'contacts' => $this->section(
                $contacts !== [],
                'not yet recorded',
                '/crm/parties/' . $partyId,
                array_map(fn(array $c) => [
                    'name' => $c['name'],
                    'role' => $c['role'],
                    'influence_level' => $c['influence_level'],
                    'influence_label' => $this->accountLabels['influence_levels'][$c['influence_level']] ?? $c['influence_level'],
                    'relationship_strength' => $c['relationship_strength'],
                    'relationship_label' => $this->accountLabels['relationship_strengths'][$c['relationship_strength']] ?? $c['relationship_strength'],
                    'phone' => $c['phone'],
                    'preferred_channel' => $c['preferred_channel'],
                ], $contacts)
            ),
            'issues' => $this->section(
                $issues !== [],
                'not yet recorded',
                '/crm/parties/' . $partyId,
                array_map(fn(array $i) => [
                    'issue_type' => $i['issue_type'],
                    'issue_type_label' => $this->accountLabels['issue_types'][$i['issue_type']] ?? $i['issue_type'],
                    'status' => $i['status'],
                    'status_label' => $this->accountLabels['issue_statuses'][$i['status']] ?? $i['status'],
                    'raised_on' => $i['raised_on'],
                    'description' => $i['description'],
                    'resolved_on' => $i['resolved_on'],
                ], $issues)
            ),
            'last_visit' => $visit === null
                ? $this->section(false, 'not yet recorded', '/crm/visits/new?party_id=' . $partyId, [])
                : array_merge($this->section(true, '', '', []), ['item' => $visit]),
            'order_pattern' => [
                'recorded' => true,
                'empty_message' => $orders === [] ? 'No dispatches in the last ' . $months . ' months.' : '',
                'add_url' => null,
                'from' => $from,
                'to' => $to,
                'items' => array_map(fn(array $r) => [
                    'grade_code' => $r['grade_code'],
                    'tonnes' => round((float)$r['tonnes'], 3),
                ], $orders),
            ],
            'forecast' => $this->forecastSection($forecastLines, $yearMonth, $period),
            'credit' => $this->creditBand($party),
            'open_deals' => [
                'recorded' => true,
                'empty_message' => $deals === [] ? 'No open deals.' : '',
                'add_url' => '/crm/deals/new',
                'items' => array_map(fn(array $d) => [
                    'id' => (int)$d['id'],
                    'title' => $d['title'],
                    'stage' => (int)$d['stage'],
                    'stage_label' => $this->stageLabels[(int)$d['stage']] ?? ('Stage ' . $d['stage']),
                    'url' => '/crm/deals/' . (int)$d['id'],
                ], $deals),
            ],
            'handover_notes' => [
                // TRANSITIONAL BRIDGE — not a permanent feature. Review by handover_notes_review_date.
                'transitional' => true,
                'review_date' => $this->config['handover_notes_review_date'],
                'recorded' => $handover !== [],
                'empty_message' => $handover === [] ? 'No handover notes. This is a temporary dump while structured data is still thin.' : '',
                'add_url' => null,
                'items' => array_map(fn(array $n) => [
                    'id' => (int)$n['id'],
                    'note' => $n['note'],
                    'author_name' => $n['author_name'],
                    'created_at' => $n['created_at'],
                ], $handover),
            ],
        ];

        if ($this->contextPolicy->can($actor['role'] ?? null, AccountContextPolicy::VIEW_COMPETITOR)) {
            $briefing['competitors'] = $this->section(
                $competitors !== [],
                'not yet recorded — this is not the same as “no competitor”',
                '/crm/parties/' . $partyId,
                array_map(fn(array $c) => [
                    'competitor_name' => $c['competitor_name'],
                    'grade_code' => $c['grade_code'],
                    'estimated_share_pct' => $c['estimated_share_pct'] === null ? null : (int)$c['estimated_share_pct'],
                    'reason_code' => $c['reason_code'],
                    'reason_label' => $this->accountLabels['reason_codes'][$c['reason_code']] ?? $c['reason_code'],
                    'reason_note' => $c['reason_note'],
                    'intelligence_type' => $c['intelligence_type'],
                    'intelligence_label' => $this->accountLabels['intelligence_types'][$c['intelligence_type']] ?? $c['intelligence_type'],
                ], $competitors)
            );
        }

        $this->assertNoLedgerLeak($briefing);

        return $briefing;
    }

    /**
     * TRANSITIONAL BRIDGE — not a permanent capture surface.
     *
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function addHandoverNote(int $partyId, string $note, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, BriefingPolicy::WRITE_HANDOVER);
        if ($this->rows->party($partyId) === null) {
            throw new BriefingException('Party not found.');
        }
        $text = trim($note);
        if ($text === '') {
            throw new BriefingException('A handover note cannot be empty.');
        }

        $id = $this->notes->create($partyId, $actor['id'] ?? null, $text);
        $this->audit->log($actor['id'] ?? null, 'party_handover_notes', $id, 'CREATE', null, [
            'party_id' => $partyId,
            'note' => $text,
        ]);

        return $this->notes->findById($id) ?? ['id' => $id, 'note' => $text];
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array{bytes:string,filename:string}
     */
    public function pdfBytes(int $partyId, array $actor): array
    {
        $briefing = $this->compose($partyId, $actor);
        $this->assertNoLedgerLeak($briefing);
        $pdf = (new BriefingPdfService())->render($briefing);

        return [
            'bytes' => $pdf,
            'filename' => 'briefing-' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)$briefing['party']['name']) . '.pdf',
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function section(bool $recorded, string $emptyMessage, string $addUrl, array $items): array
    {
        return [
            'recorded' => $recorded,
            'empty_message' => $recorded ? '' : $emptyMessage,
            'add_url' => $recorded ? null : $addUrl,
            'items' => $items,
        ];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed>|null $period
     * @return array<string,mixed>
     */
    private function forecastSection(array $lines, string $yearMonth, ?array $period): array
    {
        if ($period === null || $lines === []) {
            return $this->section(false, 'not yet recorded', '/crm/forecasts', []);
        }

        return [
            'recorded' => true,
            'empty_message' => '',
            'add_url' => null,
            'year_month' => $yearMonth,
            'items' => array_map(fn(array $r) => [
                'grade_code' => $r['grade_code'],
                'forecast_low' => (float)$r['forecast_low'],
                'forecast_high' => (float)$r['forecast_high'],
                'actual_tonnes' => (float)$r['actual_tonnes'],
            ], $lines),
        ];
    }

    /**
     * Headroom band and as-of only. Outstanding and limit stay in local variables
     * and are never copied onto the payload.
     *
     * @param array<string,mixed> $party
     * @return array<string,mixed>
     */
    private function creditBand(array $party): array
    {
        $limit = $party['credit_limit'] === null || $party['credit_limit'] === ''
            ? null
            : (float)$party['credit_limit'];
        $outstanding = $this->rows->partyOutstanding((int)$party['id']);
        $asOf = $this->rows->oldestLedgerAsOf();
        $ceiling = 10.0;

        if ($limit === null || $limit <= 0) {
            $band = 'not_recorded';
        } elseif ($outstanding <= $limit) {
            $band = 'within_limit';
        } else {
            $overagePct = $limit > 0 ? (($outstanding - $limit) * 100) / $limit : 100;
            $band = $overagePct <= $ceiling ? 'over_band' : 'blocked';
        }

        return [
            'recorded' => $band !== 'not_recorded',
            'empty_message' => $band === 'not_recorded' ? 'not yet recorded' : '',
            'headroom_band' => $band,
            'headroom_band_label' => $this->config['headroom_bands'][$band],
            'ledger_as_of' => $asOf,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function assertNoLedgerLeak(array $payload): void
    {
        $json = json_encode($payload);
        foreach (CreditGatePolicy::LEDGER_FIELDS as $field) {
            if ($json !== false && preg_match('/"' . preg_quote($field, '/') . '"\s*:/', $json)) {
                throw new BriefingException('Briefing refused to include ledger detail.');
            }
        }
    }
}
