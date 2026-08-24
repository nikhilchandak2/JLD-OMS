<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CreditOverrideEventRepository;
use App\Repositories\CreditOverrideRequestRepository;
use App\Support\OrderSchema;

/**
 * Override state machine. Legality is the table below, not if-chains. Every
 * accepted transition writes exactly one credit_override_events row in the same
 * transaction as the request update. Snapshots are never rewritten.
 */
class CreditOverrideService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_APPROVED_MODIFIED = 'approved_with_modified_limit';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CALL_REQUESTED = 'call_requested';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_EXPIRED = 'expired';

    /**
     * from => to => who. A pair missing from this table is illegal.
     * 'director' is the admin role; 'rep' is the requesting user; 'system' is cron.
     *
     * @var array<string,array<string,string>>
     */
    private const TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_APPROVED => 'director',
            self::STATUS_APPROVED_MODIFIED => 'director',
            self::STATUS_REJECTED => 'director',
            self::STATUS_CALL_REQUESTED => 'director',
            self::STATUS_WITHDRAWN => 'rep',
            self::STATUS_EXPIRED => 'system',
        ],
        self::STATUS_CALL_REQUESTED => [
            self::STATUS_APPROVED => 'director',
            self::STATUS_APPROVED_MODIFIED => 'director',
            self::STATUS_REJECTED => 'director',
            self::STATUS_WITHDRAWN => 'rep',
            self::STATUS_EXPIRED => 'system',
        ],
    ];

    private Database $database;
    private CreditOverrideRequestRepository $requests;
    private CreditOverrideEventRepository $events;
    private CreditGateService $gate;
    private CreditGatePolicy $policy;
    private AuditLogRepository $audit;

    public function __construct()
    {
        $this->database = new Database();
        $this->requests = new CreditOverrideRequestRepository();
        $this->events = new CreditOverrideEventRepository();
        $this->gate = new CreditGateService();
        $this->policy = new CreditGatePolicy();
        $this->audit = new AuditLogRepository();
    }

    /**
     * Persist an override against the evaluation snapshot. Does not re-read the ledger.
     *
     * @param array<string,mixed> $evaluation from CreditGateService::evaluate()
     * @param array{id:?int,role:?string} $actor
     */
    public function raise(array $evaluation, array $actor, string $reason, ?int $dealId, ?int $orderId): array
    {
        if (($dealId === null || $dealId <= 0) === ($orderId === null || $orderId <= 0)) {
            throw new CreditGateException('An override must attach to exactly one deal or order.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new CreditGateException('A reason is required when the order is over the credit limit.');
        }

        $open = $dealId
            ? $this->requests->findOpenForDeal($dealId)
            : $this->requests->findOpenForOrder($orderId);
        if ($open) {
            return $this->present((int)$open['id'], $actor);
        }

        $now = $this->gate->now();
        $expires = $now->modify('+' . $this->gate->expireAfterDays() . ' days');

        $this->database->beginTransaction();
        try {
            $id = $this->requests->create([
                'company_id' => (int)$evaluation['company_id'],
                'deal_id' => $dealId,
                'order_id' => $orderId,
                'party_id' => (int)$evaluation['party_id'],
                'requested_by_user_id' => $actor['id'] ?? null,
                'requested_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expires->format('Y-m-d H:i:s'),
                'tier' => (int)$evaluation['tier'],
                'credit_limit_snapshot' => $evaluation['credit_limit'],
                'outstanding_snapshot' => $evaluation['outstanding'],
                'outstanding_breakdown' => json_encode($evaluation['outstanding_breakdown']),
                'ledger_as_of' => $evaluation['ledger_as_of'],
                'incomplete_feed_entities' => $evaluation['incomplete_feed']
                    ? json_encode($evaluation['missing_entities'])
                    : null,
                'proposed_order_value' => $evaluation['proposed_order_value'],
                'computed_overage' => $evaluation['computed_overage'],
                'rep_reason' => $reason,
            ]);

            $this->events->append($id, null, self::STATUS_PENDING, $actor['id'] ?? null, $reason, $now->format('Y-m-d H:i:s'));
            $this->audit->log($actor['id'] ?? null, 'credit_override_requests', $id, 'CREATE', null, [
                'tier' => $evaluation['tier'],
                'party_id' => $evaluation['party_id'],
                'deal_id' => $dealId,
                'order_id' => $orderId,
                'incomplete_feed' => $evaluation['incomplete_feed'],
            ]);

            $this->database->commit();
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }

        return $this->present($id, $actor);
    }

    /**
     * Called when a deal leaves Stage 6. T1 is logged; T2 raises a request;
     * T3 never reaches here because the exit criterion is unmet.
     */
    public function ensureForDealAdvance(array $deal, array $actor): void
    {
        $companyId = (int)($deal['company_id'] ?? 0);
        if ($companyId <= 0) {
            $row = $this->database->fetch("SELECT id FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
            $companyId = (int)($row['id'] ?? 0);
        }
        if ($companyId <= 0) {
            return;
        }

        $proposed = isset($deal['value']) && $deal['value'] !== null && $deal['value'] !== ''
            ? (float)$deal['value']
            : 0.0;
        $evaluation = $this->gate->evaluate((int)$deal['party_id'], $companyId, $proposed);

        if ((int)$evaluation['tier'] === CreditGateService::TIER_AUTO) {
            $this->audit->log($actor['id'] ?? null, 'crm_deals', (int)$deal['id'], 'UPDATE', null, [
                'credit_gate' => 'auto_cleared',
                'tier' => 1,
                'ledger_as_of' => $evaluation['ledger_as_of'],
            ]);
            return;
        }

        if ($this->requests->findApprovedForDeal((int)$deal['id'])) {
            return;
        }

        $this->raise(
            $evaluation,
            $actor,
            'Stage 6 credit gate — negotiation confirmed.',
            (int)$deal['id'],
            null
        );
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @param array{action:string,modified_limit_value?:float|null,decision_note?:string|null} $input
     */
    public function decide(int $requestId, array $input, array $actor): array
    {
        $action = (string)($input['action'] ?? '');
        $toStatus = $this->actionToStatus($action);
        $request = $this->requireRequest($requestId);
        $from = (string)$request['status'];

        $who = self::TRANSITIONS[$from][$toStatus] ?? null;
        if ($who === null) {
            throw new IllegalOverrideTransitionException(
                "A {$from} override cannot become {$toStatus}."
            );
        }

        if ($who === 'director') {
            $this->policy->assertCan($actor['role'] ?? null, CreditGatePolicy::DECIDE);
        } elseif ($who === 'rep') {
            $this->policy->assertCan($actor['role'] ?? null, CreditGatePolicy::WITHDRAW);
            if ((int)$request['requested_by_user_id'] !== (int)($actor['id'] ?? 0)
                && ($actor['role'] ?? '') !== 'admin') {
                throw new CreditGateAuthorizationException('Only the requesting rep can withdraw this override.');
            }
        } elseif ($who === 'system' && ($actor['role'] ?? '') !== 'system') {
            throw new CreditGateAuthorizationException('Expiry is a system action.');
        }

        $modified = null;
        $note = isset($input['decision_note']) ? trim((string)$input['decision_note']) : '';

        if ($toStatus === self::STATUS_APPROVED_MODIFIED) {
            $modified = isset($input['modified_limit_value']) ? (float)$input['modified_limit_value'] : 0;
            if ($modified <= 0) {
                throw new CreditGateException('Approve with modified limit requires a modified_limit_value.');
            }
        }
        if ($toStatus === self::STATUS_REJECTED && $note === '') {
            throw new CreditGateException('Rejecting an override requires a decision note.');
        }

        $now = $this->gate->now()->format('Y-m-d H:i:s');
        $actorId = $who === 'system' ? null : ($actor['id'] ?? null);

        $this->database->beginTransaction();
        try {
            if (in_array($toStatus, [self::STATUS_APPROVED, self::STATUS_APPROVED_MODIFIED, self::STATUS_REJECTED], true)) {
                $this->requests->applyDecision(
                    $requestId,
                    $toStatus,
                    (int)($actorId ?? 0),
                    $now,
                    $modified,
                    $note !== '' ? $note : null
                );
            } else {
                $this->requests->applyStatus($requestId, $toStatus, $note !== '' ? $note : null);
            }

            $this->events->append($requestId, $from, $toStatus, $actorId, $note !== '' ? $note : null, $now);

            if ($toStatus === self::STATUS_APPROVED_MODIFIED && $modified !== null) {
                $this->database->execute(
                    "UPDATE parties SET credit_limit = ? WHERE id = ?",
                    [$modified, (int)$request['party_id']]
                );
            }

            $this->applyToLinkedOrders($request, $toStatus);
            $this->audit->log($actorId, 'credit_override_requests', $requestId, 'UPDATE', ['status' => $from], [
                'status' => $toStatus,
                'modified_limit_value' => $modified,
            ]);

            $this->database->commit();
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }

        return $this->present($requestId, $actor);
    }

    public function batchApprove(array $ids, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CreditGatePolicy::DECIDE);
        $results = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            $row = $this->requireRequest($id);
            if ((int)$row['tier'] !== CreditGateService::TIER_PASSIVE) {
                throw new CreditGateException("Override {$id} is not Tier 2 and cannot be batch-approved.");
            }
            $results[] = $this->decide($id, ['action' => 'approve'], $actor);
        }

        return $results;
    }

    public function expireOverdue(?array $actor = null): int
    {
        $actor = $actor ?? ['id' => null, 'role' => 'system'];
        $now = $this->gate->now()->format('Y-m-d H:i:s');
        $rows = $this->requests->findExpirable($now);
        $count = 0;
        foreach ($rows as $row) {
            $this->decide((int)$row['id'], ['action' => 'expire', 'decision_note' => 'Expired after the configurable window.'], $actor);
            $count++;
        }

        return $count;
    }

    public function present(int $requestId, array $actor): array
    {
        $row = $this->requireRequest($requestId);
        $payload = $this->decode($row);
        $payload['events'] = $this->events->findByRequest($requestId);
        $payload['prior_overrides'] = array_map(
            fn(array $r) => $this->decode($r),
            $this->requests->historyForParty((int)$row['party_id'], $requestId)
        );

        return $this->policy->serializeForRole($payload, $actor['role'] ?? null);
    }

    public function queue(array $filters, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CreditGatePolicy::VIEW_QUEUE);
        $rows = $this->requests->listQueue($filters);
        $presented = array_map(fn(array $r) => $this->decode($r), $rows);

        $tier2 = array_values(array_filter($presented, static fn($r) => (int)$r['tier'] === 2 && in_array($r['status'], ['pending', 'call_requested'], true)));
        $tier3 = array_values(array_filter($presented, static fn($r) => (int)$r['tier'] === 3 && in_array($r['status'], ['pending', 'call_requested'], true)));

        return [
            'tier2' => $tier2,
            'tier3' => $tier3,
            'all' => $presented,
        ];
    }

    public function volumeByTier(): array
    {
        $rows = $this->requests->volumeByTier();
        $out = [];
        foreach ($rows as $row) {
            $tier = (int)$row['tier'];
            $out[$tier][$row['status']] = (int)$row['count'];
            $out[$tier]['total'] = ($out[$tier]['total'] ?? 0) + (int)$row['count'];
        }

        return $out;
    }

    public function findApprovedForDeal(int $dealId): ?array
    {
        return $this->requests->findApprovedForDeal($dealId);
    }

    public function findOpenForDeal(int $dealId): ?array
    {
        return $this->requests->findOpenForDeal($dealId);
    }

    /**
     * @return array<string,string>
     */
    public static function transitionTable(): array
    {
        return self::TRANSITIONS;
    }

    private function applyToLinkedOrders(array $request, string $toStatus): void
    {
        if (!OrderSchema::hasCreditGateColumns()) {
            return;
        }

        $gateStatus = match ($toStatus) {
            self::STATUS_APPROVED, self::STATUS_APPROVED_MODIFIED => CreditGateService::STATUS_CLEARED,
            self::STATUS_CALL_REQUESTED => (int)$request['tier'] === CreditGateService::TIER_PASSIVE
                ? CreditGateService::STATUS_PENDING_DIRECTOR
                : CreditGateService::STATUS_BLOCKED,
            default => CreditGateService::STATUS_BLOCKED,
        };

        $this->database->execute(
            "UPDATE orders SET credit_gate_status = ? WHERE credit_override_request_id = ?",
            [$gateStatus, (int)$request['id']]
        );

        if (!empty($request['order_id'])) {
            $this->database->execute(
                "UPDATE orders SET credit_gate_status = ?, credit_override_request_id = ? WHERE id = ?",
                [$gateStatus, (int)$request['id'], (int)$request['order_id']]
            );
        }
    }

    private function actionToStatus(string $action): string
    {
        return match ($action) {
            'approve' => self::STATUS_APPROVED,
            'approve_modified', 'approved_with_modified_limit' => self::STATUS_APPROVED_MODIFIED,
            'reject' => self::STATUS_REJECTED,
            'call', 'call_requested' => self::STATUS_CALL_REQUESTED,
            'withdraw' => self::STATUS_WITHDRAWN,
            'expire' => self::STATUS_EXPIRED,
            default => throw new CreditGateException("Unknown override action '{$action}'."),
        };
    }

    private function requireRequest(int $id): array
    {
        $row = $this->requests->findById($id);
        if ($row === null) {
            throw new CreditGateException("Override {$id} not found.");
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['tier'] = (int)$row['tier'];
        $row['party_id'] = (int)$row['party_id'];
        $row['company_id'] = (int)$row['company_id'];
        $row['deal_id'] = $row['deal_id'] !== null ? (int)$row['deal_id'] : null;
        $row['order_id'] = $row['order_id'] !== null ? (int)$row['order_id'] : null;
        $row['credit_limit_snapshot'] = $row['credit_limit_snapshot'] !== null ? (float)$row['credit_limit_snapshot'] : null;
        $row['outstanding_snapshot'] = (float)$row['outstanding_snapshot'];
        $row['proposed_order_value'] = (float)$row['proposed_order_value'];
        $row['computed_overage'] = (float)$row['computed_overage'];
        $row['outstanding_breakdown'] = $this->decodeJson($row['outstanding_breakdown'] ?? null);
        $row['incomplete_feed_entities'] = $this->decodeJson($row['incomplete_feed_entities'] ?? null);
        $row['headroom'] = $row['credit_limit_snapshot'] !== null
            ? round((float)$row['credit_limit_snapshot'] - (float)$row['outstanding_snapshot'], 2)
            : null;

        return $row;
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
