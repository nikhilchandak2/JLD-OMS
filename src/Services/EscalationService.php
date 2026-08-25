<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CrmAccountContextRepository;
use App\Repositories\CrmAccountIssueRepository;
use App\Repositories\CrmCompetitorPositionRepository;
use App\Repositories\CrmContactRepository;
use App\Repositories\CrmDealRepository;
use App\Repositories\EscalationRepository;
use App\Repositories\EscalationRuleRepository;
use App\Repositories\PartyRepository;

/**
 * Escalations freeze context at trigger time. Nightly runs never re-raise an
 * open, acknowledged, resolved, or dismissed episode (no alert spam).
 */
class EscalationService
{
    public const TYPE_DORMANT = 'dormant_account';
    public const TYPE_ISSUE = 'unresolved_issue';
    public const TYPE_DISPATCH = 'dispatch_delay';
    public const TYPE_TECHNICAL = 'technical_overdue';
    public const TYPE_MANUAL = 'manual_flag';

    private Database $database;
    private EscalationRepository $escalations;
    private EscalationRuleRepository $rules;
    private PartyRepository $parties;
    private CrmDealRepository $deals;
    private CrmContactRepository $contacts;
    private CrmCompetitorPositionRepository $positions;
    private CrmAccountContextRepository $context;
    private CrmAccountIssueRepository $issues;
    private AuditLogRepository $audit;
    private DormancyPolicy $policy;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->escalations = new EscalationRepository();
        $this->rules = new EscalationRuleRepository();
        $this->parties = new PartyRepository();
        $this->deals = new CrmDealRepository();
        $this->contacts = new CrmContactRepository();
        $this->positions = new CrmCompetitorPositionRepository();
        $this->context = new CrmAccountContextRepository();
        $this->issues = new CrmAccountIssueRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new DormancyPolicy();
        $this->config = require dirname(__DIR__, 2) . '/config/dormancy.php';
    }

    /**
     * @param array<int,array<string,mixed>> $dormancyRows from DormancyService::rebuild
     * @return array<string,int>
     */
    public function applyNightly(string $asOf, array $dormancyRows): array
    {
        $raised = 0;
        $closed = 0;

        $urgentPartyIds = [];
        foreach ($dormancyRows as $row) {
            if (($row['severity'] ?? '') !== DormancyService::SEVERITY_URGENT) {
                continue;
            }
            $partyId = (int)$row['party_id'];
            $urgentPartyIds[$partyId] = $partyId;
            $episode = $row['last_order_date'] ? (string)$row['last_order_date'] : 'never';
            if ($this->raiseIfNew([
                'party_id' => $partyId,
                'company_id' => $row['company_id'] ?? null,
                'deal_id' => null,
                'trigger_type' => self::TYPE_DORMANT,
                'source_table' => 'parties',
                'source_id' => $partyId,
                'episode_key' => $episode,
                'triggered_on' => $asOf,
                'triggered_by' => 'system',
                'triggered_by_user_id' => null,
                'reason' => $row['reason_summary'] ?? '',
            ])) {
                $raised++;
            }
        }
        $closed += $this->closeStale(self::TYPE_DORMANT, $urgentPartyIds, $asOf, 'Account is ordering again, or a recent visit moved it off the urgent list.');

        $issueIds = [];
        foreach ($this->overdueIssues($asOf) as $issue) {
            $id = (int)$issue['id'];
            $issueIds[$id] = $id;
            if ($this->raiseIfNew([
                'party_id' => (int)$issue['party_id'],
                'company_id' => null,
                'deal_id' => $issue['deal_id'] ?? null,
                'trigger_type' => self::TYPE_ISSUE,
                'source_table' => 'crm_account_issues',
                'source_id' => $id,
                'episode_key' => (string)$id,
                'triggered_on' => $asOf,
                'triggered_by' => 'system',
                'triggered_by_user_id' => null,
                'reason' => 'Issue still open after the resolution window.',
            ])) {
                $raised++;
                if ($issue['status'] === 'open') {
                    $this->issues->update($id, ['status' => 'escalated']);
                }
            }
        }
        $closed += $this->closeStaleBySource(self::TYPE_ISSUE, 'crm_account_issues', $issueIds, 'The issue was resolved.');

        $rule = $this->rules->findActiveByType(self::TYPE_DISPATCH);
        $dispatchThreshold = (int)($rule['threshold_days'] ?? 1);
        $orderIds = [];
        foreach ($this->delayedOrders($asOf, $dispatchThreshold) as $order) {
            $id = (int)$order['id'];
            $orderIds[$id] = $id;
            if ($this->raiseIfNew([
                'party_id' => (int)$order['party_id'],
                'company_id' => $order['company_id'] ?? null,
                'deal_id' => null,
                'trigger_type' => self::TYPE_DISPATCH,
                'source_table' => 'orders',
                'source_id' => $id,
                'episode_key' => (string)$id,
                'triggered_on' => $asOf,
                'triggered_by' => 'system',
                'triggered_by_user_id' => null,
                'reason' => 'Scheduled dispatch date has passed with the order still open.',
            ])) {
                $raised++;
            }
        }
        $closed += $this->closeStaleBySource(self::TYPE_DISPATCH, 'orders', $orderIds, 'The order was dispatched.');

        $techRule = $this->rules->findActiveByType(self::TYPE_TECHNICAL);
        $techThreshold = (int)($techRule['threshold_days'] ?? 0);
        $flagIds = [];
        foreach ($this->overdueFlags($asOf, $techThreshold) as $flag) {
            $id = (int)$flag['id'];
            $flagIds[$id] = $id;
            if ($this->raiseIfNew([
                'party_id' => (int)$flag['party_id'],
                'company_id' => null,
                'deal_id' => $flag['deal_id'] ?? null,
                'trigger_type' => self::TYPE_TECHNICAL,
                'source_table' => 'crm_technical_flags',
                'source_id' => $id,
                'episode_key' => (string)$id,
                'triggered_on' => $asOf,
                'triggered_by' => 'system',
                'triggered_by_user_id' => null,
                'reason' => 'Technical flag is past its turnaround.',
            ])) {
                $raised++;
            }
        }
        $closed += $this->closeStaleBySource(self::TYPE_TECHNICAL, 'crm_technical_flags', $flagIds, 'The technical flag was closed.');

        return ['raised' => $raised, 'closed' => $closed];
    }

    /**
     * @param array<string,mixed> $input
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function raiseManual(array $input, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, DormancyPolicy::RAISE_MANUAL);
        $partyId = (int)($input['party_id'] ?? 0);
        if ($partyId <= 0 || $this->parties->findById($partyId) === null) {
            throw new DormancyException('A valid customer is required.');
        }
        $note = trim((string)($input['note'] ?? $input['resolution_note'] ?? ''));
        if ($note === '') {
            throw new DormancyException('Say why this needs senior attention.');
        }
        $dealId = isset($input['deal_id']) && $input['deal_id'] !== '' && $input['deal_id'] !== null
            ? (int)$input['deal_id']
            : null;
        if ($dealId !== null && $dealId > 0) {
            $deal = $this->deals->findById($dealId);
            if ($deal === null || (int)$deal['party_id'] !== $partyId) {
                throw new DormancyException('Deal not found for this customer.');
            }
        } else {
            $dealId = null;
        }

        $asOf = (new \DateTimeImmutable('now', new \DateTimeZone($this->config['timezone'])))->format('Y-m-d');
        $id = $this->insertEscalation([
            'party_id' => $partyId,
            'company_id' => null,
            'deal_id' => $dealId,
            'trigger_type' => self::TYPE_MANUAL,
            'source_table' => 'parties',
            'source_id' => $partyId,
            'episode_key' => 'manual-' . $asOf . '-' . bin2hex(random_bytes(3)),
            'triggered_on' => $asOf,
            'triggered_by' => 'user',
            'triggered_by_user_id' => $actor['id'] ?? null,
            'reason' => $note,
        ]);
        $this->audit->log($actor['id'] ?? null, 'escalations', $id, 'CREATE', null, [
            'trigger_type' => self::TYPE_MANUAL,
            'party_id' => $partyId,
        ]);

        $row = $this->escalations->findById($id);
        if ($row === null) {
            throw new DormancyException('Escalation could not be reloaded.');
        }

        return $this->present($row);
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<int,array<string,mixed>>
     */
    public function inbox(array $actor, ?string $status = null): array
    {
        $this->policy->assertCan($actor['role'] ?? null, DormancyPolicy::VIEW_ESCALATIONS);

        return array_map([$this, 'present'], $this->escalations->findInbox($status));
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function show(int $id, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, DormancyPolicy::VIEW_ESCALATIONS);
        $row = $this->escalations->findById($id);
        if ($row === null) {
            throw new DormancyException('Escalation not found.');
        }

        return $this->present($row);
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function acknowledge(int $id, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, DormancyPolicy::ACT_ESCALATIONS);
        $existing = $this->requireOpen($id);
        if ($existing['status'] === 'acknowledged') {
            return $this->present($existing);
        }
        $this->escalations->acknowledge($id, (int)($actor['id'] ?? 0));
        $this->audit->log($actor['id'] ?? null, 'escalations', $id, 'UPDATE', [
            'status' => $existing['status'],
        ], ['status' => 'acknowledged']);

        return $this->present($this->escalations->findById($id));
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function resolve(int $id, array $input, array $actor): array
    {
        return $this->finish($id, 'resolved', $input, $actor);
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function dismiss(int $id, array $input, array $actor): array
    {
        return $this->finish($id, 'dismissed', $input, $actor);
    }

    public function closeForSource(string $sourceTable, int $sourceId, string $note): int
    {
        $closed = 0;
        foreach ($this->escalations->findOpenForSource($sourceTable, $sourceId) as $row) {
            $this->escalations->close((int)$row['id'], 'resolved', $note);
            $this->audit->log(null, 'escalations', (int)$row['id'], 'UPDATE', [
                'status' => $row['status'],
            ], ['status' => 'resolved', 'resolution_note' => $note]);
            $closed++;
        }

        return $closed;
    }

    /** @param array<string,mixed> $row */
    public function present(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['party_id'] = (int)$row['party_id'];
        $row['deal_id'] = $row['deal_id'] === null ? null : (int)$row['deal_id'];
        $row['source_id'] = $row['source_id'] === null ? null : (int)$row['source_id'];
        $row['trigger_label'] = $this->config['trigger_types'][$row['trigger_type']] ?? $row['trigger_type'];
        $row['status_label'] = $this->config['statuses'][$row['status']] ?? $row['status'];
        if (!isset($row['context_snapshot']) || !is_array($row['context_snapshot'])) {
            $row['context_snapshot'] = [];
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function raiseIfNew(array $data): bool
    {
        $existing = $this->escalations->findEpisode(
            (int)$data['party_id'],
            (string)$data['trigger_type'],
            (string)$data['episode_key']
        );
        if ($existing !== null) {
            return false;
        }
        $this->insertEscalation($data);

        return true;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insertEscalation(array $data): int
    {
        $snapshot = $this->captureSnapshot((int)$data['party_id'], $data);
        $id = $this->escalations->create([
            'company_id' => $data['company_id'],
            'party_id' => $data['party_id'],
            'deal_id' => $data['deal_id'],
            'trigger_type' => $data['trigger_type'],
            'source_table' => $data['source_table'],
            'source_id' => $data['source_id'],
            'episode_key' => $data['episode_key'],
            'triggered_on' => $data['triggered_on'],
            'triggered_by' => $data['triggered_by'],
            'triggered_by_user_id' => $data['triggered_by_user_id'],
            'context_snapshot' => $snapshot,
        ]);
        $this->audit->log($data['triggered_by_user_id'] ?? null, 'escalations', $id, 'CREATE', null, [
            'trigger_type' => $data['trigger_type'],
            'party_id' => $data['party_id'],
            'episode_key' => $data['episode_key'],
        ]);

        return $id;
    }

    /**
     * @param array<int,int> $activePartyIds
     */
    private function closeStale(string $triggerType, array $activePartyIds, string $asOf, string $note): int
    {
        $closed = 0;
        foreach ($this->escalations->findOpenByType($triggerType) as $row) {
            if (isset($activePartyIds[(int)$row['party_id']])) {
                continue;
            }
            $this->escalations->close((int)$row['id'], 'resolved', $note);
            $this->audit->log(null, 'escalations', (int)$row['id'], 'UPDATE', [
                'status' => $row['status'],
            ], ['status' => 'resolved', 'auto_closed_on' => $asOf]);
            $closed++;
        }

        return $closed;
    }

    /**
     * @param array<int,int> $activeSourceIds
     */
    private function closeStaleBySource(string $triggerType, string $sourceTable, array $activeSourceIds, string $note): int
    {
        $closed = 0;
        foreach ($this->escalations->findOpenByType($triggerType) as $row) {
            if ((string)$row['source_table'] !== $sourceTable) {
                continue;
            }
            if (isset($activeSourceIds[(int)$row['source_id']])) {
                continue;
            }
            $this->escalations->close((int)$row['id'], 'resolved', $note);
            $this->audit->log(null, 'escalations', (int)$row['id'], 'UPDATE', [
                'status' => $row['status'],
            ], ['status' => 'resolved']);
            $closed++;
        }

        return $closed;
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function finish(int $id, string $status, array $input, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, DormancyPolicy::ACT_ESCALATIONS);
        $existing = $this->requireOpen($id);
        $note = trim((string)($input['resolution_note'] ?? $input['note'] ?? ''));
        if ($note === '') {
            throw new DormancyException('A note is required.');
        }
        $this->escalations->close($id, $status, $note);
        $this->audit->log($actor['id'] ?? null, 'escalations', $id, 'UPDATE', [
            'status' => $existing['status'],
        ], ['status' => $status, 'resolution_note' => $note]);

        return $this->present($this->escalations->findById($id));
    }

    /** @return array<string,mixed> */
    private function requireOpen(int $id): array
    {
        $row = $this->escalations->findById($id);
        if ($row === null) {
            throw new DormancyException('Escalation not found.');
        }
        if (!in_array($row['status'], ['open', 'acknowledged'], true)) {
            throw new DormancyException('This escalation is already ' . $row['status'] . '.');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function captureSnapshot(int $partyId, array $data): array
    {
        $party = $this->parties->findById($partyId);
        $contacts = array_map(static fn($c) => [
            'name' => $c->name,
            'role' => $c->role,
            'influence_level' => $c->influenceLevel,
            'relationship_strength' => $c->relationshipStrength,
        ], $this->contacts->findByParty($partyId));

        $competitors = [];
        foreach ($this->positions->findByParty($partyId, true) as $pos) {
            $competitors[] = [
                'competitor_name' => $pos['competitor_name'],
                'grade_code' => $pos['grade_code'],
                'reason_code' => $pos['reason_code'],
                'intelligence_type' => $pos['intelligence_type'],
                'estimated_share_pct' => $pos['estimated_share_pct'],
            ];
        }

        $issues = [];
        foreach ($this->issues->findByParty($partyId) as $issue) {
            if (!in_array($issue['status'], ['open', 'escalated'], true)) {
                continue;
            }
            $issues[] = [
                'id' => (int)$issue['id'],
                'issue_type' => $issue['issue_type'],
                'raised_on' => $issue['raised_on'],
                'description' => $issue['description'],
                'status' => $issue['status'],
            ];
        }

        $ctx = $this->context->findByParty($partyId);

        return [
            'captured_at' => $data['triggered_on'],
            'trigger_type' => $data['trigger_type'],
            'reason' => $data['reason'] ?? '',
            'party_name' => $party->name ?? null,
            'assigned_sales_owner' => $party->assignedSalesOwner ?? null,
            'contacts' => $contacts,
            'competitors' => $competitors,
            'open_issues' => $issues,
            'context' => [
                'production_capacity_note' => $ctx['production_capacity_note'] ?? null,
                'seasonality_note' => $ctx['seasonality_note'] ?? null,
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function overdueIssues(string $asOf): array
    {
        return $this->database->fetchAll(
            "SELECT * FROM crm_account_issues
             WHERE status IN ('open', 'escalated')
               AND DATE_ADD(raised_on, INTERVAL resolution_window_days DAY) <= ?",
            [$asOf]
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function delayedOrders(string $asOf, int $thresholdDays): array
    {
        return $this->database->fetchAll(
            "SELECT id, party_id, company_id, order_no, scheduled_dispatch_date, status
             FROM orders
             WHERE scheduled_dispatch_date IS NOT NULL
               AND status <> 'completed'
               AND DATE_ADD(scheduled_dispatch_date, INTERVAL ? DAY) <= ?",
            [$thresholdDays, $asOf]
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function overdueFlags(string $asOf, int $thresholdDays): array
    {
        return $this->database->fetchAll(
            "SELECT id, party_id, deal_id, expected_turnaround_at, status
             FROM crm_technical_flags
             WHERE status IN ('open', 'claimed')
               AND expected_turnaround_at IS NOT NULL
               AND DATE_ADD(expected_turnaround_at, INTERVAL ? DAY) < DATE_ADD(?, INTERVAL 1 DAY)",
            [$thresholdDays, $asOf]
        );
    }
}
