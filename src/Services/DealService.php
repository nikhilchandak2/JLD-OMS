<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CrmDealGradeRepository;
use App\Repositories\CrmDealRepository;
use App\Repositories\CrmDealReasonCodeRepository;
use App\Repositories\CrmDealStageEventRepository;
use App\Repositories\CrmTechnicalFlagRepository;
use App\Repositories\PartyRepository;
use App\Support\TableSchema;

/**
 * Deal lifecycle outside the state machine: capture, grades, listing, soft delete.
 *
 * Deals are for NEW business only - a new customer, or a new grade at an existing customer.
 * Repeat orders are captured directly against the party and never enter this pipeline (I13).
 */
class DealService
{
    private Database $database;
    private CrmDealRepository $deals;
    private CrmDealGradeRepository $grades;
    private CrmDealReasonCodeRepository $reasonCodes;
    private CrmDealStageEventRepository $events;
    private CrmTechnicalFlagRepository $flags;
    private PartyRepository $parties;
    private AuditLogRepository $audit;
    private CrmDealPolicy $policy;
    private DealStageService $stageService;
    private CreditGateService $creditGate;
    private CreditGatePolicy $creditPolicy;
    private AccountContextService $accountContext;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->deals = new CrmDealRepository();
        $this->grades = new CrmDealGradeRepository();
        $this->reasonCodes = new CrmDealReasonCodeRepository();
        $this->events = new CrmDealStageEventRepository();
        $this->flags = new CrmTechnicalFlagRepository();
        $this->parties = new PartyRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new CrmDealPolicy();
        $this->stageService = new DealStageService();
        $this->creditGate = new CreditGateService();
        $this->creditPolicy = new CreditGatePolicy();
        $this->accountContext = new AccountContextService();
        $this->config = require __DIR__ . '/../../config/crm_pipeline.php';
    }

    /**
     * Stage 1 capture. Only the Stage 1 mandatory fields are required, so the mobile form
     * stays short: source, party, grade(s), indicative quantity, enquiry date.
     */
    public function captureInquiry(array $input, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::CREATE_DEAL);

        $partyId = (int)($input['party_id'] ?? 0);
        if ($partyId <= 0 || $this->parties->findById($partyId) === null) {
            throw new PipelineException('A valid customer is required.');
        }

        $source = (string)($input['source'] ?? '');
        if (!isset($this->config['sources'][$source])) {
            throw new PipelineException('A valid enquiry source is required.');
        }

        $grades = $this->normaliseGrades($input['grades'] ?? []);
        if (empty($grades)) {
            throw new PipelineException('At least one grade is required.');
        }

        $inquiryDate = (string)($input['inquiry_date'] ?? '');
        if ($inquiryDate === '') {
            // IST, because the enquiry date is a business date, not a UTC timestamp.
            $inquiryDate = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        }

        $quantity = isset($input['indicative_quantity_tonnes']) && $input['indicative_quantity_tonnes'] !== ''
            ? (float)$input['indicative_quantity_tonnes']
            : null;
        if ($quantity === null) {
            $quantity = array_sum(array_map(fn(array $g) => (float)($g['qty'] ?? 0), $grades)) ?: null;
        }

        $party = $this->parties->findById($partyId);
        $partyName = is_array($party) ? ($party['name'] ?? '') : ($party->name ?? '');
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            $title = $partyName . ' - ' . implode(', ', array_column($grades, 'code'));
        }

        $this->database->beginTransaction();
        try {
            $dealId = $this->deals->create([
                'party_id' => $partyId,
                'company_id' => isset($input['company_id']) && $input['company_id'] !== '' ? (int)$input['company_id'] : null,
                'title' => $title,
                'source' => $source,
                'indicative_quantity_tonnes' => $quantity,
                'inquiry_date' => $inquiryDate,
                'value' => isset($input['value']) && $input['value'] !== '' ? (float)$input['value'] : null,
                'expected_close_date' => !empty($input['expected_close_date']) ? $input['expected_close_date'] : null,
                'owner_user_id' => isset($input['owner_user_id']) && $input['owner_user_id'] !== ''
                    ? (int)$input['owner_user_id']
                    : ($actor['id'] ?? null),
                'notes' => $input['notes'] ?? null,
            ]);

            foreach ($grades as $grade) {
                $this->grades->upsert($dealId, $grade['code'], $grade['qty']);
            }

            // Opening event, so time-in-stage is measurable from creation and not only from
            // the first move.
            $this->events->append([
                'deal_id' => $dealId,
                'from_stage' => null,
                'to_stage' => DealStageService::STAGE_MIN,
                'from_status' => null,
                'to_status' => DealStageService::STATUS_ACTIVE,
                'reason_note' => 'Enquiry captured.',
                'actor_user_id' => $actor['id'] ?? null,
            ]);

            $this->audit->log(
                $actor['id'] ?? null,
                'crm_deals',
                $dealId,
                'CREATE',
                null,
                [
                    'party_id' => $partyId,
                    'stage' => 1,
                    'status' => DealStageService::STATUS_ACTIVE,
                    'source' => $source,
                    'grades' => array_column($grades, 'code'),
                    'inquiry_date' => $inquiryDate,
                ]
            );

            $this->database->commit();
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }

        return $this->show($dealId, $actor);
    }

    public function list(array $filters, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::VIEW_DEAL);

        $deals = $this->deals->findAll($filters);
        $gradesByDeal = $this->grades->findByDeals(array_column($deals, 'id'));

        $rows = [];
        foreach ($deals as $deal) {
            $deal = $this->decorate($deal);
            $deal['grades'] = array_column($gradesByDeal[(int)$deal['id']] ?? [], 'grade_code');
            $rows[] = $deal;
        }

        return $this->policy->serializeDeals($rows, $actor['role'] ?? null);
    }

    public function show(int $dealId, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::VIEW_DEAL);

        $deal = $this->deals->findById($dealId);
        if ($deal === null) {
            throw new PipelineException("Deal {$dealId} not found.");
        }

        $deal = $this->decorate($deal);
        $deal['grades'] = $this->grades->findByDeal($dealId);
        $deal['is_on_technical_hold'] = $this->flags->hasOpenFlag($dealId);
        $deal['open_technical_flags'] = $this->flags->findQueue(['deal_id' => $dealId, 'open_only' => 1]);
        $deal['exit_criteria'] = $this->stageService->evaluateExitCriteria($dealId);
        $deal['history'] = $this->stageService->history($dealId);
        $deal['time_in_stage_seconds'] = $this->stageService->timeInStage($dealId);

        if ((int)$deal['stage'] >= 5) {
            $deal['credit_gate'] = $this->creditSnapshotForDeal($deal, $actor['role'] ?? null);
        }

        $deal['account_context'] = $this->accountContext->snapshotForParty((int)$deal['party_id'], $actor);
        $deal['handoff_packet'] = (new HandoffService())->currentSalesToDispatchForDeal($dealId);

        return $this->policy->serializeDeal($deal, $actor['role'] ?? null);
    }

    public function updateDetails(int $dealId, array $input, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::MOVE_DEAL);

        $existing = $this->deals->findById($dealId);
        if ($existing === null) {
            throw new PipelineException("Deal {$dealId} not found.");
        }

        $data = [];
        foreach (['title', 'value', 'expected_close_date', 'owner_user_id', 'notes', 'indicative_quantity_tonnes', 'inquiry_date', 'company_id'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field] === '' ? null : $input[$field];
            }
        }
        if (array_key_exists('source', $input)) {
            if (!isset($this->config['sources'][$input['source']])) {
                throw new PipelineException('A valid enquiry source is required.');
            }
            $data['source'] = $input['source'];
        }

        if (!empty($data)) {
            $this->deals->updateDetails($dealId, $data);
            $this->audit->log(
                $actor['id'] ?? null,
                'crm_deals',
                $dealId,
                'UPDATE',
                array_intersect_key($existing, $data),
                $data
            );
        }

        return $this->show($dealId, $actor);
    }

    public function addGrade(int $dealId, string $gradeCode, ?float $qty, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::MOVE_DEAL);

        $gradeCode = strtoupper(trim($gradeCode));
        if ($gradeCode === '') {
            throw new PipelineException('A grade code is required.');
        }
        if ($this->deals->findById($dealId) === null) {
            throw new PipelineException("Deal {$dealId} not found.");
        }

        $this->grades->upsert($dealId, $gradeCode, $qty);
        $this->audit->log($actor['id'] ?? null, 'crm_deal_grades', $dealId, 'CREATE', null, [
            'deal_id' => $dealId,
            'grade_code' => $gradeCode,
            'indicative_qty_tonnes' => $qty,
        ]);

        return $this->show($dealId, $actor);
    }

    public function removeGrade(int $dealId, string $gradeCode, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::MOVE_DEAL);

        $this->grades->delete($dealId, strtoupper(trim($gradeCode)));
        $this->audit->log($actor['id'] ?? null, 'crm_deal_grades', $dealId, 'DELETE', [
            'deal_id' => $dealId,
            'grade_code' => $gradeCode,
        ], null);

        return $this->show($dealId, $actor);
    }

    /** Business records soft-delete (I12): the row stays, excluded at repository level. */
    public function softDelete(int $dealId, array $actor): void
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::DELETE_DEAL);

        $existing = $this->deals->findById($dealId);
        if ($existing === null) {
            throw new PipelineException("Deal {$dealId} not found.");
        }

        $this->deals->softDelete($dealId);
        $this->audit->log($actor['id'] ?? null, 'crm_deals', $dealId, 'DELETE', [
            'stage' => $existing['stage'],
            'status' => $existing['status'],
        ], ['deleted_at' => 'now']);
    }

    public function pipelineSummary(array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::VIEW_DEAL);

        $counts = [];
        foreach ($this->deals->countActiveByStage() as $row) {
            $counts[(int)$row['stage']] = (int)$row['deals'];
        }

        $summary = [];
        foreach ($this->config['stages'] as $stage => $label) {
            $summary[] = [
                'stage' => $stage,
                'label' => $label,
                'active_deals' => $counts[$stage] ?? 0,
            ];
        }

        return $summary;
    }

    public function reasonCodes(?string $appliesTo = null, ?array $actor = null): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CrmDealPolicy::VIEW_DEAL);

        return $this->reasonCodes->findActive($appliesTo);
    }

    public function sources(): array
    {
        return $this->config['sources'];
    }

    /**
     * Rep sees status + headroom + as-of from Stage 5. Ledger amounts are stripped.
     *
     * @return array<string,mixed>
     */
    private function creditSnapshotForDeal(array $deal, ?string $role): array
    {
        $companyId = (int)($deal['company_id'] ?? 0);
        if ($companyId <= 0) {
            $row = $this->database->fetch(TableSchema::firstActiveCompanyIdSql());
            $companyId = (int)($row['id'] ?? 0);
        }
        $proposed = isset($deal['value']) && $deal['value'] !== null && $deal['value'] !== ''
            ? (float)$deal['value']
            : 0.0;

        $evaluation = $this->creditGate->evaluate((int)$deal['party_id'], $companyId, $proposed);

        return $this->creditPolicy->serializeForRole($evaluation, $role);
    }

    private function decorate(array $deal): array
    {
        $deal['stage_label'] = $this->stageService->stageLabel((int)$deal['stage']);
        $deal['is_on_technical_hold'] = !empty($deal['is_on_technical_hold']);

        return $deal;
    }

    /** @return array<int,array{code:string,qty:?float}> */
    private function normaliseGrades($grades): array
    {
        if (is_string($grades)) {
            $grades = array_filter(array_map('trim', explode(',', $grades)));
        }
        if (!is_array($grades)) {
            return [];
        }

        $out = [];
        foreach ($grades as $grade) {
            if (is_array($grade)) {
                $code = strtoupper(trim((string)($grade['grade_code'] ?? $grade['code'] ?? '')));
                $qty = isset($grade['indicative_qty_tonnes']) && $grade['indicative_qty_tonnes'] !== ''
                    ? (float)$grade['indicative_qty_tonnes']
                    : (isset($grade['qty']) && $grade['qty'] !== '' ? (float)$grade['qty'] : null);
            } else {
                $code = strtoupper(trim((string)$grade));
                $qty = null;
            }
            if ($code !== '') {
                $out[$code] = ['code' => $code, 'qty' => $qty];
            }
        }

        return array_values($out);
    }
}
