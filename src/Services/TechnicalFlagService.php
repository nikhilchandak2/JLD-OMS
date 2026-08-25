<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CrmDealRepository;
use App\Repositories\CrmTechnicalFlagRepository;
use App\Repositories\CrmTechnicalQueueRepository;
use App\Repositories\PartyRepository;

/**
 * Technical support as an orthogonal hold, routed to a team queue and never to a person.
 *
 * A flag can be raised against a party with no deal at all, because most JLD accounts are
 * repeat customers that never enter the pipeline (I13).
 *
 * The hold is derived from an open flag; it is never a column on crm_deals (I2), and it never
 * blocks a stage transition - it changes display, not permission.
 */
class TechnicalFlagService
{
    private Database $database;
    private CrmTechnicalFlagRepository $flags;
    private CrmTechnicalQueueRepository $queues;
    private CrmDealRepository $deals;
    private PartyRepository $parties;
    private AuditLogRepository $audit;
    private CrmDealPolicy $policy;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->flags = new CrmTechnicalFlagRepository();
        $this->queues = new CrmTechnicalQueueRepository();
        $this->deals = new CrmDealRepository();
        $this->parties = new PartyRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new CrmDealPolicy();
        $this->config = require __DIR__ . '/../../config/crm_pipeline.php';
    }

    public function queues(array $actor, ?int $companyId = null): array
    {
        $this->assertMayRead($actor);

        return $this->queues->findActive($companyId);
    }

    public function raise(array $input, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::RAISE_TECHNICAL_FLAG);

        $nature = trim((string)($input['nature_of_query'] ?? ''));
        if ($nature === '') {
            throw new PipelineException('Describe the technical query before raising it.');
        }

        $dealId = isset($input['deal_id']) && $input['deal_id'] !== '' ? (int)$input['deal_id'] : null;
        $partyId = isset($input['party_id']) && $input['party_id'] !== '' ? (int)$input['party_id'] : null;
        $raisedFromStage = null;

        if ($dealId !== null) {
            $deal = $this->deals->findById($dealId);
            if ($deal === null) {
                throw new PipelineException("Deal {$dealId} not found.");
            }
            $partyId = (int)$deal['party_id'];
            $raisedFromStage = (int)$deal['stage'];
        }

        if ($partyId === null || $this->parties->findById($partyId) === null) {
            throw new PipelineException('A valid customer is required.');
        }

        $queueId = isset($input['routed_to_queue_id']) && $input['routed_to_queue_id'] !== ''
            ? (int)$input['routed_to_queue_id']
            : (int)($this->queues->findDefault()['id'] ?? 0);
        $queue = $queueId > 0 ? $this->queues->findById($queueId) : null;
        if ($queue === null || (int)$queue['is_active'] !== 1) {
            throw new PipelineException('A valid technical queue is required.');
        }

        $turnaround = $input['expected_turnaround_at'] ?? null;
        if ($turnaround === null || $turnaround === '') {
            $hours = (int)($this->config['technical_flag_turnaround_hours'] ?? 48);
            $turnaround = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))
                ->modify("+{$hours} hours")
                ->format('Y-m-d H:i:s');
        }

        $flagId = $this->flags->create([
            'deal_id' => $dealId,
            'party_id' => $partyId,
            'raised_from_stage' => $raisedFromStage,
            'raised_by_user_id' => $actor['id'] ?? null,
            'nature_of_query' => $nature,
            'routed_to_queue_id' => (int)$queue['id'],
            'expected_turnaround_at' => $turnaround,
        ]);

        $this->audit->log($actor['id'] ?? null, 'crm_technical_flags', $flagId, 'CREATE', null, [
            'deal_id' => $dealId,
            'party_id' => $partyId,
            'routed_to_queue_id' => (int)$queue['id'],
            'status' => 'open',
            'expected_turnaround_at' => $turnaround,
        ]);

        return $this->requireFlag($flagId);
    }

    /**
     * Claim-on-open (B4): attribution, not assignment. It does not lock anyone else out, and
     * it lets the rep see that someone is looking at the query.
     */
    public function claim(int $flagId, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::WORK_TECHNICAL_QUEUE);

        $flag = $this->requireFlag($flagId);
        if ($flag['status'] !== 'open') {
            throw new PipelineException("Flag {$flagId} is already {$flag['status']}.");
        }

        $this->flags->claim($flagId, (int)($actor['id'] ?? 0));
        $this->audit->log(
            $actor['id'] ?? null,
            'crm_technical_flags',
            $flagId,
            'UPDATE',
            ['status' => 'open', 'claimed_by_user_id' => null],
            ['status' => 'claimed', 'claimed_by_user_id' => $actor['id'] ?? null]
        );

        return $this->requireFlag($flagId);
    }

    public function resolve(int $flagId, array $actor, string $resolutionType, string $note): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::WORK_TECHNICAL_QUEUE);

        $flag = $this->requireFlag($flagId);
        if (!in_array($flag['status'], ['open', 'claimed'], true)) {
            throw new PipelineException("Flag {$flagId} is already {$flag['status']}.");
        }
        if (!in_array($resolutionType, ['remote_answer', 'site_visit'], true)) {
            throw new PipelineException('Resolution type must be a remote answer or a site visit.');
        }
        $note = trim($note);
        if ($note === '') {
            throw new PipelineException('A resolution note is required so the answer is reusable.');
        }

        $this->flags->resolve($flagId, (int)($actor['id'] ?? 0), $resolutionType, $note);
        (new EscalationService())->closeForSource(
            'crm_technical_flags',
            $flagId,
            'The technical flag was resolved.'
        );
        $this->audit->log(
            $actor['id'] ?? null,
            'crm_technical_flags',
            $flagId,
            'UPDATE',
            ['status' => $flag['status']],
            ['status' => 'resolved', 'resolution_type' => $resolutionType, 'resolution_note' => $note]
        );

        return $this->requireFlag($flagId);
    }

    public function cancel(int $flagId, array $actor, string $note): array
    {
        $flag = $this->requireFlag($flagId);
        $isRaiser = ($actor['id'] ?? null) !== null && (int)$flag['raised_by_user_id'] === (int)$actor['id'];
        if (!$isRaiser) {
            $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::WORK_TECHNICAL_QUEUE);
        }
        if (!in_array($flag['status'], ['open', 'claimed'], true)) {
            throw new PipelineException("Flag {$flagId} is already {$flag['status']}.");
        }

        $this->flags->cancel($flagId, trim($note));
        (new EscalationService())->closeForSource(
            'crm_technical_flags',
            $flagId,
            'The technical flag was cancelled.'
        );
        $this->audit->log(
            $actor['id'] ?? null,
            'crm_technical_flags',
            $flagId,
            'UPDATE',
            ['status' => $flag['status']],
            ['status' => 'cancelled', 'resolution_note' => trim($note)]
        );

        return $this->requireFlag($flagId);
    }

    /** Queue view with overdue flags first (B4 ageing visibility). */
    public function queue(array $filters, array $actor): array
    {
        $this->assertMayRead($actor);

        return array_map(static function (array $flag) {
            $flag['is_overdue'] = !empty($flag['is_overdue']);
            return $flag;
        }, $this->flags->findQueue($filters));
    }

    public function stats(array $actor, ?string $fromDate = null, ?string $toDate = null): array
    {
        $this->assertMayRead($actor);

        return $this->flags->resolutionStats($fromDate, $toDate);
    }

    /**
     * Technical staff work the queue but have no pipeline view, and sales can see the flags
     * on their own deals, so either capability grants read.
     */
    private function assertMayRead(array $actor): void
    {
        $role = $actor['role'] ?? null;
        if ($this->policy->can($role, CrmDealPolicy::WORK_TECHNICAL_QUEUE)) {
            return;
        }

        $this->policy->assertCan($role, CrmDealPolicy::VIEW_DEAL);
    }

    private function requireFlag(int $flagId): array
    {
        $flag = $this->flags->findById($flagId);
        if ($flag === null) {
            throw new PipelineException("Technical flag {$flagId} not found.");
        }

        return $flag;
    }
}
