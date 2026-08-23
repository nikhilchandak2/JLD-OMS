<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CrmContactRepository;
use App\Repositories\CrmDealCriteriaValueRepository;
use App\Repositories\CrmDealGradeRepository;
use App\Repositories\CrmDealReasonCodeRepository;
use App\Repositories\CrmDealRepository;
use App\Repositories\CrmDealStageEventRepository;
use App\Repositories\CrmSampleRepository;
use App\Repositories\CrmStageExitCriteriaRepository;
use App\Repositories\CrmTechnicalFlagRepository;

/**
 * The only writer of crm_deals.stage and crm_deals.status.
 *
 * Legality is decided by an explicit transition table, not by if-chains, and every accepted
 * transition writes exactly one crm_deal_stage_events row in the same transaction as the
 * deal update, so time-in-stage is derivable from the event log alone.
 *
 * A deal on technical hold can still transition: the hold changes display, not permission.
 */
class DealStageService
{
    public const STAGE_MIN = 1;
    public const STAGE_MAX = 7;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_WON = 'won';
    public const STATUS_LOST = 'lost';
    public const STATUS_DROPPED = 'dropped';

    /**
     * from status => to status => transition kind. A pair missing from this table is illegal.
     */
    private const STATUS_TRANSITIONS = [
        self::STATUS_ACTIVE => [
            self::STATUS_ACTIVE => 'stage_move',
            self::STATUS_WON => 'win',
            self::STATUS_LOST => 'terminate',
            self::STATUS_DROPPED => 'terminate',
        ],
        self::STATUS_WON => [self::STATUS_ACTIVE => 'reopen'],
        self::STATUS_LOST => [self::STATUS_ACTIVE => 'reopen'],
        self::STATUS_DROPPED => [self::STATUS_ACTIVE => 'reopen'],
    ];

    /** Criteria satisfied by an existing record rather than by a value the rep types. */
    private const DERIVED_CRITERIA = [
        'source',
        'party',
        'grades',
        'indicative_quantity',
        'inquiry_date',
        'decision_maker_contact',
        'sample_sent',
    ];

    private Database $database;
    private CrmDealRepository $deals;
    private CrmDealStageEventRepository $events;
    private CrmStageExitCriteriaRepository $exitCriteria;
    private CrmDealCriteriaValueRepository $criteriaValues;
    private CrmDealGradeRepository $grades;
    private CrmDealReasonCodeRepository $reasonCodes;
    private CrmContactRepository $contacts;
    private CrmSampleRepository $samples;
    private CrmTechnicalFlagRepository $flags;
    private AuditLogRepository $audit;
    private CrmDealPolicy $policy;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->deals = new CrmDealRepository();
        $this->events = new CrmDealStageEventRepository();
        $this->exitCriteria = new CrmStageExitCriteriaRepository();
        $this->criteriaValues = new CrmDealCriteriaValueRepository();
        $this->grades = new CrmDealGradeRepository();
        $this->reasonCodes = new CrmDealReasonCodeRepository();
        $this->contacts = new CrmContactRepository();
        $this->samples = new CrmSampleRepository();
        $this->flags = new CrmTechnicalFlagRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new CrmDealPolicy();
        $this->config = require __DIR__ . '/../../config/crm_pipeline.php';
    }

    public function stageLabel(?int $stage): ?string
    {
        return $stage === null ? null : ($this->config['stages'][$stage] ?? "Stage {$stage}");
    }

    public function stages(): array
    {
        return $this->config['stages'];
    }

    // -----------------------------------------------------------------------
    // Public transition API
    // -----------------------------------------------------------------------

    public function advance(int $dealId, array $actor): array
    {
        $deal = $this->requireDeal($dealId);

        return $this->transition($dealId, (int)$deal['stage'] + 1, self::STATUS_ACTIVE, [], $actor);
    }

    public function moveBack(int $dealId, array $actor, string $reasonNote): array
    {
        $deal = $this->requireDeal($dealId);

        return $this->transition(
            $dealId,
            (int)$deal['stage'] - 1,
            self::STATUS_ACTIVE,
            ['reason_note' => $reasonNote],
            $actor
        );
    }

    public function markWon(int $dealId, array $actor): array
    {
        $deal = $this->requireDeal($dealId);

        return $this->transition($dealId, (int)$deal['stage'], self::STATUS_WON, [], $actor);
    }

    public function terminate(int $dealId, array $actor, string $status, int $reasonCodeId, ?string $note = null): array
    {
        $deal = $this->requireDeal($dealId);

        return $this->transition(
            $dealId,
            (int)$deal['stage'],
            $status,
            ['reason_code_id' => $reasonCodeId, 'reason_note' => $note],
            $actor
        );
    }

    public function reopen(int $dealId, array $actor, string $reasonNote): array
    {
        $deal = $this->requireDeal($dealId);

        return $this->transition(
            $dealId,
            (int)$deal['stage'],
            self::STATUS_ACTIVE,
            ['reason_note' => $reasonNote],
            $actor
        );
    }

    /**
     * Core transition. Validates against the transition table, then the exit-criteria
     * configuration, then writes the deal and its event row in one transaction.
     *
     * @param array $options reason_code_id, reason_note
     * @param array $actor   ['id' => int|null, 'role' => string|null]
     */
    public function transition(int $dealId, ?int $toStage, string $toStatus, array $options, array $actor): array
    {
        $deal = $this->requireDeal($dealId);
        $fromStage = (int)$deal['stage'];
        $fromStatus = (string)$deal['status'];
        $role = $actor['role'] ?? null;

        $kind = $this->classify($fromStatus, $fromStage, $toStatus, $toStage);

        $this->policy->assertCan($role, match ($kind) {
            'advance', 'regress' => CrmDealPolicy::MOVE_DEAL,
            'win', 'terminate' => CrmDealPolicy::TERMINATE_DEAL,
            'reopen' => CrmDealPolicy::REOPEN_DEAL,
        });

        $reasonNote = isset($options['reason_note']) ? trim((string)$options['reason_note']) : '';
        $reasonCodeId = isset($options['reason_code_id']) && $options['reason_code_id'] !== ''
            ? (int)$options['reason_code_id']
            : null;

        if (in_array($kind, ['regress', 'reopen'], true) && $reasonNote === '') {
            throw new TransitionReasonRequiredException(
                $kind === 'regress'
                    ? 'Moving a deal back a stage requires a reason.'
                    : 'Reopening a deal requires a reason.'
            );
        }

        if ($kind === 'terminate') {
            if ($reasonCodeId === null) {
                throw new TransitionReasonRequiredException(
                    "Marking a deal {$toStatus} requires a reason code."
                );
            }
            $reasonCode = $this->reasonCodes->findById($reasonCodeId);
            if ($reasonCode === null || (int)$reasonCode['is_active'] !== 1) {
                throw new TransitionReasonRequiredException('Unknown or inactive reason code.');
            }
            if (!in_array($reasonCode['applies_to'], [$toStatus, 'both'], true)) {
                throw new TransitionReasonRequiredException(
                    "Reason '{$reasonCode['label']}' cannot be used for a {$toStatus} deal."
                );
            }
        }

        // Advancing (and winning from stage 7) is gated by the configured exit criteria.
        $snapshot = null;
        if (in_array($kind, ['advance', 'win'], true)) {
            $evaluation = $this->evaluateExitCriteria($dealId);
            if (!empty($evaluation['unmet'])) {
                throw new ExitCriteriaNotMetException(
                    'Cannot leave ' . $this->stageLabel($fromStage) . ' yet: '
                    . implode(', ', array_column($evaluation['unmet'], 'label')) . '.',
                    ['unmet' => $evaluation['unmet'], 'stage' => $fromStage]
                );
            }
            $snapshot = [];
            foreach ($evaluation['criteria'] as $criterion) {
                $snapshot[$criterion['field_key']] = $criterion['value'];
            }
        }

        $targetStage = $toStage ?? $fromStage;

        $this->database->beginTransaction();
        try {
            $this->deals->applyTransition(
                $dealId,
                $targetStage,
                $toStatus,
                in_array($toStatus, [self::STATUS_LOST, self::STATUS_DROPPED], true) ? $reasonCodeId : null,
                $targetStage !== $fromStage || $toStatus !== $fromStatus
            );

            $this->events->append([
                'deal_id' => $dealId,
                'from_stage' => $fromStage,
                'to_stage' => $targetStage,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason_code_id' => $reasonCodeId,
                'reason_note' => $reasonNote === '' ? null : $reasonNote,
                'exit_criteria_snapshot' => $snapshot,
                'actor_user_id' => $actor['id'] ?? null,
            ]);

            $this->audit->log(
                $actor['id'] ?? null,
                'crm_deals',
                $dealId,
                'UPDATE',
                ['stage' => $fromStage, 'status' => $fromStatus],
                [
                    'stage' => $targetStage,
                    'status' => $toStatus,
                    'transition' => $kind,
                    'reason_code_id' => $reasonCodeId,
                    'reason_note' => $reasonNote === '' ? null : $reasonNote,
                ]
            );

            $this->database->commit();
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }

        return $this->requireDeal($dealId);
    }

    /**
     * Decide what kind of transition this is, or refuse. Reads the explicit table above.
     *
     * @return string advance|regress|win|terminate|reopen
     */
    public function classify(string $fromStatus, int $fromStage, string $toStatus, ?int $toStage): string
    {
        $kind = self::STATUS_TRANSITIONS[$fromStatus][$toStatus] ?? null;
        if ($kind === null) {
            throw new IllegalTransitionException(
                "A {$fromStatus} deal cannot become {$toStatus}."
            );
        }

        if ($kind === 'stage_move') {
            if ($toStage === null) {
                throw new IllegalTransitionException('A stage move needs a target stage.');
            }
            if ($toStage < self::STAGE_MIN || $toStage > self::STAGE_MAX) {
                throw new IllegalTransitionException(
                    'Stage must be between ' . self::STAGE_MIN . ' and ' . self::STAGE_MAX . '.'
                );
            }

            $delta = $toStage - $fromStage;
            if ($delta === 0) {
                throw new IllegalTransitionException('The deal is already at that stage.');
            }
            if ($delta > 1) {
                throw new StageSkipException(
                    "Stages are sequential: a deal at stage {$fromStage} can only move to stage "
                    . ($fromStage + 1) . '.'
                );
            }
            if ($delta < -1) {
                throw new StageSkipException(
                    "A deal can only be moved back one stage at a time (stage {$fromStage} to "
                    . ($fromStage - 1) . ').'
                );
            }

            return $delta === 1 ? 'advance' : 'regress';
        }

        if ($kind === 'win') {
            if ($fromStage !== self::STAGE_MAX) {
                throw new IllegalTransitionException(
                    'A deal can only be won from stage ' . self::STAGE_MAX . '.'
                );
            }
            if ($toStage !== null && $toStage !== $fromStage) {
                throw new IllegalTransitionException('Winning a deal does not change its stage.');
            }

            return 'win';
        }

        if ($kind === 'reopen' && $toStage !== null && $toStage !== $fromStage) {
            throw new IllegalTransitionException(
                'Reopening a deal returns it to the stage it was at; move it afterwards.'
            );
        }

        if ($kind === 'terminate' && $toStage !== null && $toStage !== $fromStage) {
            throw new IllegalTransitionException('Closing a deal does not change its stage.');
        }

        return $kind;
    }

    // -----------------------------------------------------------------------
    // Exit criteria
    // -----------------------------------------------------------------------

    /**
     * Evaluate the configured exit criteria for the deal's current stage. Nothing about
     * which fields are mandatory is hardcoded: it all comes from crm_stage_exit_criteria.
     */
    public function evaluateExitCriteria(int $dealId): array
    {
        $deal = $this->requireDeal($dealId);
        $stage = (int)$deal['stage'];

        $configured = $this->exitCriteria->findByStage($stage);
        $captured = $this->criteriaValues->findByDeal($dealId);

        $criteria = [];
        $unmet = [];
        foreach ($configured as $row) {
            $fieldKey = $row['field_key'];
            $isDerived = in_array($fieldKey, self::DERIVED_CRITERIA, true);
            $value = $isDerived
                ? $this->derivedValue($fieldKey, $deal)
                : ($captured[$fieldKey] ?? null);

            $satisfied = $value !== null && trim((string)$value) !== '';
            $criterion = [
                'field_key' => $fieldKey,
                'label' => $row['label'],
                'help_text' => $row['help_text'],
                'is_mandatory' => (int)$row['is_mandatory'] === 1,
                'source' => $isDerived ? 'derived' : 'captured',
                'value' => $satisfied ? (string)$value : null,
                'satisfied' => $satisfied,
            ];
            $criteria[] = $criterion;

            if ($criterion['is_mandatory'] && !$satisfied) {
                $unmet[] = ['field_key' => $fieldKey, 'label' => $row['label']];
            }
        }

        $nextStage = $stage < self::STAGE_MAX ? $stage + 1 : null;

        return [
            'deal_id' => $dealId,
            'stage' => $stage,
            'stage_label' => $this->stageLabel($stage),
            'status' => $deal['status'],
            'criteria' => $criteria,
            'unmet' => $unmet,
            'can_advance' => $deal['status'] === self::STATUS_ACTIVE
                && $nextStage !== null
                && empty($unmet),
            'can_win' => $deal['status'] === self::STATUS_ACTIVE
                && $stage === self::STAGE_MAX
                && empty($unmet),
            'next_stage' => $nextStage,
            'next_stage_label' => $this->stageLabel($nextStage),
            'is_on_technical_hold' => $this->flags->hasOpenFlag($dealId),
        ];
    }

    public function saveCriteriaValues(int $dealId, array $values, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::MOVE_DEAL);
        $this->requireDeal($dealId);

        $allowedKeys = array_column($this->exitCriteria->findAllActive(), 'field_key');
        foreach ($values as $fieldKey => $value) {
            if (!in_array($fieldKey, $allowedKeys, true) || in_array($fieldKey, self::DERIVED_CRITERIA, true)) {
                continue;
            }
            $this->criteriaValues->upsert(
                $dealId,
                (string)$fieldKey,
                $value === null || $value === '' ? null : (string)$value,
                $actor['id'] ?? null
            );
        }

        return $this->evaluateExitCriteria($dealId);
    }

    /**
     * Criteria answered by an existing record. The rep never re-types what the system
     * already knows (I11).
     */
    private function derivedValue(string $fieldKey, array $deal): ?string
    {
        switch ($fieldKey) {
            case 'source':
                return $deal['source'] ?? null;
            case 'party':
                return !empty($deal['party_id']) ? (string)$deal['party_id'] : null;
            case 'inquiry_date':
                return $deal['inquiry_date'] ?? null;
            case 'indicative_quantity':
                return isset($deal['indicative_quantity_tonnes']) && (float)$deal['indicative_quantity_tonnes'] > 0
                    ? (string)$deal['indicative_quantity_tonnes']
                    : null;
            case 'grades':
                $codes = array_column($this->grades->findByDeal((int)$deal['id']), 'grade_code');
                return empty($codes) ? null : implode(', ', $codes);
            case 'decision_maker_contact':
                $contacts = $this->contacts->findByParty((int)$deal['party_id']);
                if (empty($contacts)) {
                    return null;
                }
                $first = $contacts[0];
                $name = is_array($first) ? ($first['name'] ?? null) : ($first->name ?? null);
                return $name === null || $name === '' ? null : (string)$name;
            case 'sample_sent':
                $samples = $this->samples->findAll(['deal_id' => (int)$deal['id']]);
                return empty($samples) ? null : 'yes (' . count($samples) . ')';
            default:
                return null;
        }
    }

    // -----------------------------------------------------------------------
    // Derived history
    // -----------------------------------------------------------------------

    /**
     * Time-in-stage from the event log alone: for each stage, the total seconds the deal has
     * spent there across all visits, including the still-open current interval.
     *
     * Capture writes an opening event, so measurement starts when the deal was created.
     * Deals backfilled from the legacy funnel are measured from the backfill run, not from
     * whenever they really entered that stage.
     */
    public function timeInStage(int $dealId): array
    {
        $events = $this->events->findByDeal($dealId);
        $totals = [];
        $previousStage = null;
        $previousAt = null;

        foreach ($events as $event) {
            $occurredAt = strtotime((string)$event['occurred_at']);
            if ($previousStage !== null && $previousAt !== null) {
                $totals[$previousStage] = ($totals[$previousStage] ?? 0) + max(0, $occurredAt - $previousAt);
            }
            $previousStage = $event['to_stage'] === null ? $previousStage : (int)$event['to_stage'];
            $previousAt = $occurredAt;
        }

        if ($previousStage !== null && $previousAt !== null) {
            $totals[$previousStage] = ($totals[$previousStage] ?? 0) + max(0, time() - $previousAt);
        }

        ksort($totals);

        return $totals;
    }

    public function history(int $dealId): array
    {
        return array_map(function (array $event) {
            $event['from_stage_label'] = $this->stageLabel(
                $event['from_stage'] === null ? null : (int)$event['from_stage']
            );
            $event['to_stage_label'] = $this->stageLabel(
                $event['to_stage'] === null ? null : (int)$event['to_stage']
            );
            return $event;
        }, $this->events->findByDeal($dealId));
    }

    private function requireDeal(int $dealId): array
    {
        $deal = $this->deals->findById($dealId);
        if ($deal === null) {
            throw new PipelineException("Deal {$dealId} not found.");
        }

        return $deal;
    }
}
