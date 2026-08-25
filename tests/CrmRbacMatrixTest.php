<?php

namespace Tests;

use App\Services\AccountContextPolicy;
use App\Services\AccountContextService;
use App\Services\BriefingPolicy;
use App\Services\BriefingService;
use App\Services\CreditGatePolicy;
use App\Services\CreditGateService;
use App\Services\CrmDealPolicy;
use App\Services\DataFeedPolicy;
use App\Services\DealService;
use App\Services\DormancyPolicy;
use App\Services\DormancyService;
use App\Services\EscalationService;
use App\Services\ForecastPolicy;
use App\Services\ForecastService;
use App\Services\HandoffPolicy;
use App\Services\HandoffService;
use App\Services\PipelineDashboardPolicy;
use App\Services\PipelineDashboardService;
use App\Services\VisitPolicy;
use App\Services\VisitService;

/**
 * TASK 11: every CRM/credit/data-feed endpoint × every role.
 * 200 = permitted, 403 = refused. "filtered" means 200 with fields stripped.
 */
class CrmRbacMatrixTest extends CrmPipelineTestCase
{
    /** @var list<string> */
    private const ROLES = [
        'admin', 'crm', 'sales', 'marketing', 'entry',
        'dispatch', 'accounts', 'technical', 'order_processing', 'view',
    ];

    public function testEveryRoleMatchesThePublishedMatrix(): void
    {
        foreach ($this->matrix() as $row) {
            foreach (self::ROLES as $role) {
                $expected = $this->expected($row, $role);
                $actual = $this->evaluate($row, $role);
                self::assertSame(
                    $expected,
                    $actual,
                    "{$role} {$row['method']} {$row['path']} expected {$expected}, policy returned {$actual}."
                );
            }
        }
    }

    public function testDeniedRolesAreRefusedByServices(): void
    {
        $dispatch = $this->actor('dispatch');
        $view = $this->actor('view');
        $sales = $this->actor('sales');

        $this->expectAuth(fn() => (new DealService())->list([], $dispatch));
        $this->expectAuth(fn() => (new DealService())->reasonCodes(null, $view));
        $this->expectAuth(fn() => (new EscalationService())->inbox($sales));
        $this->expectAuth(fn() => (new ForecastService())->openPeriod('2026-08', $sales));
        $this->expectAuth(fn() => (new PipelineDashboardService())->dashboard($dispatch));
        $this->expectAuth(fn() => (new BriefingService())->compose($this->createParty(), $view));
        $this->expectAuth(fn() => (new DormancyService())->listForActor($view));
        $this->expectAuth(fn() => (new VisitService())->overdue($view));
        $this->expectAuth(fn() => (new HandoffService())->meta($view));
        $this->expectAuth(fn() => (new AccountContextService())->meta($dispatch));
    }

    public function testAllowedReadsSucceedAndFieldFiltersHold(): void
    {
        $deal = $this->captureDeal(['value' => 88000, 'owner_user_id' => $this->admin['id']], $this->admin);
        (new PipelineDashboardService())->rebuild(
            (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d')
        );

        $adminList = (new DealService())->list([], $this->admin);
        self::assertNotEmpty($adminList);
        self::assertArrayHasKey('value', $adminList[0]);

        $marketing = $this->actor('marketing');
        $mList = (new DealService())->list([], $marketing);
        self::assertArrayNotHasKey('value', $mList[0]);

        $pipeM = (new PipelineDashboardService())->dashboard($marketing);
        self::assertFalse($pipeM['can_view_value']);
        self::assertArrayNotHasKey('indicative_value', $pipeM['by_stage'][0]);

        $briefing = (new BriefingService())->compose((int)$deal['party_id'], $this->actor('sales'));
        foreach (CreditGatePolicy::LEDGER_FIELDS as $field) {
            if (isset($briefing['credit']) && is_array($briefing['credit'])) {
                self::assertArrayNotHasKey($field, $briefing['credit'], "sales briefing leaked {$field}");
            }
        }

        $ctx = (new AccountContextService())->snapshotForParty((int)$deal['party_id'], $marketing);
        self::assertArrayNotHasKey('competitors', $ctx);

        self::assertNotEmpty((new DealService())->reasonCodes(null, $this->actor('sales')));
        self::assertIsArray((new EscalationService())->inbox($this->admin));
        self::assertIsArray((new DormancyService())->listForActor($this->admin));
        self::assertIsArray((new VisitService())->overdue($this->admin));
        self::assertIsArray((new HandoffService())->list([], $this->actor('dispatch')));
        self::assertIsArray((new PipelineDashboardService())->dashboard($this->actor('sales')));
    }

    /**
     * Published matrix for the wrap-up. capability is the policy method name.
     *
     * @return list<array{method:string,path:string,allow:list<string>,capability:array{0:string,1:string},filter:?string}>
     */
    public function matrix(): array
    {
        $crm = ['admin', 'crm', 'sales', 'marketing', 'entry'];
        $dealWrite = ['admin', 'crm', 'sales', 'entry'];
        $terminate = ['admin', 'crm', 'sales'];
        $reopen = ['admin', 'crm'];
        $delete = ['admin'];
        $flagRaise = ['admin', 'crm', 'sales', 'entry', 'marketing'];
        $flagWork = ['admin', 'technical', 'crm'];
        $flagRead = ['admin', 'crm', 'sales', 'marketing', 'entry', 'technical'];
        $escView = ['admin', 'crm'];
        $escRaise = ['admin', 'crm', 'sales'];
        $forecastEdit = ['admin', 'crm', 'sales'];
        $forecastPeriod = ['admin'];
        $forecastActuals = ['admin', 'crm', 'sales', 'dispatch', 'entry'];
        $handoffView = ['admin', 'crm', 'sales', 'entry', 'dispatch', 'order_processing', 'accounts', 'marketing'];
        $handoffCreate = ['admin', 'crm', 'sales', 'entry', 'dispatch', 'order_processing', 'accounts'];
        $handoffAck = ['admin', 'dispatch', 'order_processing', 'accounts'];
        $creditWithdraw = ['admin', 'crm', 'sales', 'entry', 'order_processing'];
        $briefWrite = ['admin', 'crm', 'sales'];
        $pipeAll = ['admin', 'crm'];
        $creditEval = ['admin', 'crm', 'sales', 'entry', 'order_processing', 'accounts'];
        $creditCapture = ['admin', 'crm', 'sales', 'entry', 'order_processing'];
        $creditAdmin = ['admin'];
        $feedView = ['admin', 'entry', 'crm', 'accounts', 'sales'];
        $feedWrite = ['admin', 'entry', 'crm', 'accounts'];
        $feedCfg = ['admin'];
        $competitor = ['admin', 'crm', 'sales'];
        $issueEdit = ['admin', 'crm', 'sales'];
        $contextEdit = ['admin', 'crm', 'sales'];

        $d = static fn(array $allow, string $class, string $cap, ?string $filter = null, ?string $orCap = null): array => [
            'allow' => $allow,
            'capability' => [$class, $cap],
            'or_capability' => $orCap,
            'filter' => $filter,
        ];

        $rows = [
            ['GET', '/api/crm/deals', $d($crm, CrmDealPolicy::class, CrmDealPolicy::VIEW_DEAL, 'value')],
            ['GET', '/api/crm/deals/summary', $d($crm, CrmDealPolicy::class, CrmDealPolicy::VIEW_DEAL)],
            ['GET', '/api/crm/deals/reason-codes', $d($crm, CrmDealPolicy::class, CrmDealPolicy::VIEW_DEAL)],
            ['POST', '/api/crm/deals', $d($dealWrite, CrmDealPolicy::class, CrmDealPolicy::CREATE_DEAL)],
            ['GET', '/api/crm/deals/{id}', $d($crm, CrmDealPolicy::class, CrmDealPolicy::VIEW_DEAL, 'value')],
            ['PUT', '/api/crm/deals/{id}', $d($dealWrite, CrmDealPolicy::class, CrmDealPolicy::MOVE_DEAL)],
            ['DELETE', '/api/crm/deals/{id}', $d($delete, CrmDealPolicy::class, CrmDealPolicy::DELETE_DEAL)],
            ['GET', '/api/crm/deals/{id}/criteria', $d($crm, CrmDealPolicy::class, CrmDealPolicy::VIEW_DEAL)],
            ['POST', '/api/crm/deals/{id}/criteria', $d($dealWrite, CrmDealPolicy::class, CrmDealPolicy::MOVE_DEAL)],
            ['POST', '/api/crm/deals/{id}/advance', $d($dealWrite, CrmDealPolicy::class, CrmDealPolicy::MOVE_DEAL)],
            ['POST', '/api/crm/deals/{id}/move-back', $d($dealWrite, CrmDealPolicy::class, CrmDealPolicy::MOVE_DEAL)],
            ['POST', '/api/crm/deals/{id}/win', $d($dealWrite, CrmDealPolicy::class, CrmDealPolicy::MOVE_DEAL)],
            ['POST', '/api/crm/deals/{id}/close', $d($terminate, CrmDealPolicy::class, CrmDealPolicy::TERMINATE_DEAL)],
            ['POST', '/api/crm/deals/{id}/reopen', $d($reopen, CrmDealPolicy::class, CrmDealPolicy::REOPEN_DEAL)],
            ['POST', '/api/crm/deals/{id}/grades', $d($dealWrite, CrmDealPolicy::class, CrmDealPolicy::MOVE_DEAL)],
            ['DELETE', '/api/crm/deals/{id}/grades/{gradeCode}', $d($dealWrite, CrmDealPolicy::class, CrmDealPolicy::MOVE_DEAL)],
            ['GET', '/api/crm/technical-flags', $d($flagRead, CrmDealPolicy::class, CrmDealPolicy::VIEW_DEAL, null, CrmDealPolicy::WORK_TECHNICAL_QUEUE)],
            ['GET', '/api/crm/technical-flags/queues', $d($flagRead, CrmDealPolicy::class, CrmDealPolicy::VIEW_DEAL, null, CrmDealPolicy::WORK_TECHNICAL_QUEUE)],
            ['GET', '/api/crm/technical-flags/stats', $d($flagRead, CrmDealPolicy::class, CrmDealPolicy::VIEW_DEAL, null, CrmDealPolicy::WORK_TECHNICAL_QUEUE)],
            ['POST', '/api/crm/technical-flags', $d($flagRaise, CrmDealPolicy::class, CrmDealPolicy::RAISE_TECHNICAL_FLAG)],
            ['POST', '/api/crm/technical-flags/{id}/claim', $d($flagWork, CrmDealPolicy::class, CrmDealPolicy::WORK_TECHNICAL_QUEUE)],
            ['POST', '/api/crm/technical-flags/{id}/resolve', $d($flagWork, CrmDealPolicy::class, CrmDealPolicy::WORK_TECHNICAL_QUEUE)],
            ['POST', '/api/crm/technical-flags/{id}/cancel', $d($flagWork, CrmDealPolicy::class, CrmDealPolicy::WORK_TECHNICAL_QUEUE)],
            ['GET', '/api/crm/parties/{id}/contacts', $d($crm, AccountContextPolicy::class, AccountContextPolicy::VIEW_CONTACTS)],
            ['POST', '/api/crm/parties/{id}/contacts', $d($crm, AccountContextPolicy::class, AccountContextPolicy::EDIT_CONTACTS)],
            ['GET', '/api/crm/contacts/{id}', $d($crm, AccountContextPolicy::class, AccountContextPolicy::VIEW_CONTACTS)],
            ['PUT', '/api/crm/contacts/{id}', $d($crm, AccountContextPolicy::class, AccountContextPolicy::EDIT_CONTACTS)],
            ['DELETE', '/api/crm/contacts/{id}', $d($crm, AccountContextPolicy::class, AccountContextPolicy::EDIT_CONTACTS)],
            ['GET', '/api/crm/account-context/meta', $d($crm, AccountContextPolicy::class, AccountContextPolicy::VIEW_CONTEXT)],
            ['GET', '/api/crm/account-search', $d($crm, AccountContextPolicy::class, AccountContextPolicy::SEARCH)],
            ['GET', '/api/crm/parties/{id}/account-context', $d($crm, AccountContextPolicy::class, AccountContextPolicy::VIEW_CONTACTS, 'competitors')],
            ['PUT', '/api/crm/parties/{id}/account-context', $d($contextEdit, AccountContextPolicy::class, AccountContextPolicy::EDIT_CONTEXT)],
            ['POST', '/api/crm/parties/{id}/competitors', $d($competitor, AccountContextPolicy::class, AccountContextPolicy::EDIT_COMPETITOR)],
            ['POST', '/api/crm/parties/{id}/issues', $d($issueEdit, AccountContextPolicy::class, AccountContextPolicy::EDIT_ISSUES)],
            ['POST', '/api/crm/issues/{id}/resolve', $d($issueEdit, AccountContextPolicy::class, AccountContextPolicy::EDIT_ISSUES)],
            ['GET', '/api/crm/parties/{id}/visits', $d($crm, VisitPolicy::class, VisitPolicy::VIEW)],
            ['POST', '/api/crm/visits', $d($crm, VisitPolicy::class, VisitPolicy::LOG)],
            ['GET', '/api/crm/visits/overdue', $d($crm, VisitPolicy::class, VisitPolicy::VIEW)],
            ['GET', '/api/crm/dormancy', $d($crm, DormancyPolicy::class, DormancyPolicy::VIEW_DORMANCY)],
            ['GET', '/api/crm/escalations', $d($escView, DormancyPolicy::class, DormancyPolicy::VIEW_ESCALATIONS)],
            ['POST', '/api/crm/escalations', $d($escRaise, DormancyPolicy::class, DormancyPolicy::RAISE_MANUAL)],
            ['GET', '/api/crm/escalations/{id}', $d($escView, DormancyPolicy::class, DormancyPolicy::VIEW_ESCALATIONS)],
            ['POST', '/api/crm/escalations/{id}/acknowledge', $d($escView, DormancyPolicy::class, DormancyPolicy::ACT_ESCALATIONS)],
            ['POST', '/api/crm/escalations/{id}/resolve', $d($escView, DormancyPolicy::class, DormancyPolicy::ACT_ESCALATIONS)],
            ['POST', '/api/crm/escalations/{id}/dismiss', $d($escView, DormancyPolicy::class, DormancyPolicy::ACT_ESCALATIONS)],
            ['GET', '/api/crm/forecasts/meta', $d($crm, ForecastPolicy::class, ForecastPolicy::VIEW)],
            ['GET', '/api/crm/forecasts/worksheet', $d($crm, ForecastPolicy::class, ForecastPolicy::VIEW)],
            ['GET', '/api/crm/forecasts/actuals', $d($forecastActuals, ForecastPolicy::class, ForecastPolicy::VIEW_ACTUALS)],
            ['POST', '/api/crm/forecasts/periods', $d($forecastPeriod, ForecastPolicy::class, ForecastPolicy::MANAGE_PERIOD)],
            ['POST', '/api/crm/forecasts/periods/{id}/lock', $d($forecastPeriod, ForecastPolicy::class, ForecastPolicy::MANAGE_PERIOD)],
            ['PUT', '/api/crm/forecasts/periods/{id}/parties/{partyId}', $d($forecastEdit, ForecastPolicy::class, ForecastPolicy::EDIT)],
            ['GET', '/api/crm/handoffs/meta', $d($handoffView, HandoffPolicy::class, HandoffPolicy::VIEW)],
            ['GET', '/api/crm/handoffs', $d($handoffView, HandoffPolicy::class, HandoffPolicy::VIEW)],
            ['POST', '/api/crm/handoffs', $d($handoffCreate, HandoffPolicy::class, HandoffPolicy::CREATE_SALES, null, HandoffPolicy::CREATE_ACCOUNTS)],
            ['GET', '/api/crm/handoffs/{id}', $d($handoffView, HandoffPolicy::class, HandoffPolicy::VIEW)],
            ['GET', '/api/crm/handoffs/{id}/pdf', $d($handoffView, HandoffPolicy::class, HandoffPolicy::VIEW)],
            ['POST', '/api/crm/handoffs/{id}/acknowledge', $d($handoffAck, HandoffPolicy::class, HandoffPolicy::ACK_SALES, null, HandoffPolicy::ACK_ACCOUNTS)],
            ['POST', '/api/crm/handoffs/{id}/supersede', $d($handoffCreate, HandoffPolicy::class, HandoffPolicy::CREATE_SALES, null, HandoffPolicy::CREATE_ACCOUNTS)],
            ['GET', '/api/crm/parties/{id}/briefing', $d($crm, BriefingPolicy::class, BriefingPolicy::VIEW, 'ledger')],
            ['GET', '/api/crm/parties/{id}/briefing/pdf', $d($crm, BriefingPolicy::class, BriefingPolicy::VIEW, 'ledger')],
            ['POST', '/api/crm/parties/{id}/handover-notes', $d($briefWrite, BriefingPolicy::class, BriefingPolicy::WRITE_HANDOVER)],
            ['GET', '/api/crm/pipeline', $d($crm, PipelineDashboardPolicy::class, PipelineDashboardPolicy::VIEW, 'value')],
            ['GET', '/api/crm/pipeline/export', $d($crm, PipelineDashboardPolicy::class, PipelineDashboardPolicy::VIEW, 'value')],
            ['GET', '/api/credit/evaluate', $d($creditEval, CreditGatePolicy::class, CreditGatePolicy::EVALUATE, 'ledger')],
            ['GET', '/api/credit/parties/{id}/prefill', $d($creditCapture, CreditGatePolicy::class, CreditGatePolicy::CAPTURE)],
            ['POST', '/api/credit/capture', $d($creditCapture, CreditGatePolicy::class, CreditGatePolicy::CAPTURE)],
            ['GET', '/api/credit/overrides', $d($creditAdmin, CreditGatePolicy::class, CreditGatePolicy::VIEW_QUEUE)],
            ['GET', '/api/credit/overrides/volume', $d($creditAdmin, CreditGatePolicy::class, CreditGatePolicy::VIEW_QUEUE)],
            ['POST', '/api/credit/overrides/batch-approve', $d($creditAdmin, CreditGatePolicy::class, CreditGatePolicy::DECIDE)],
            ['POST', '/api/credit/expire', $d($creditAdmin, CreditGatePolicy::class, CreditGatePolicy::DECIDE)],
            ['GET', '/api/credit/overrides/{id}', $d($creditAdmin, CreditGatePolicy::class, CreditGatePolicy::VIEW_QUEUE)],
            ['POST', '/api/credit/overrides/{id}/decide', $d($creditAdmin, CreditGatePolicy::class, CreditGatePolicy::DECIDE)],
            ['POST', '/api/credit/overrides/{id}/withdraw', $d($creditWithdraw, CreditGatePolicy::class, CreditGatePolicy::WITHDRAW)],
            ['GET', '/api/data-feeds', $d($feedView, DataFeedPolicy::class, DataFeedPolicy::VIEW)],
            ['GET', '/api/data-feeds/as-of', $d($feedView, DataFeedPolicy::class, DataFeedPolicy::VIEW)],
            ['GET', '/api/data-feeds/unmatched', $d($feedView, DataFeedPolicy::class, DataFeedPolicy::VIEW)],
            ['GET', '/api/data-feeds/template/{feedKey}', $d($feedView, DataFeedPolicy::class, DataFeedPolicy::VIEW)],
            ['POST', '/api/data-feeds/runs', $d($feedWrite, DataFeedPolicy::class, DataFeedPolicy::UPLOAD)],
            ['GET', '/api/data-feeds/runs/{id}', $d($feedView, DataFeedPolicy::class, DataFeedPolicy::VIEW)],
            ['POST', '/api/data-feeds/runs/{id}/validate', $d($feedWrite, DataFeedPolicy::class, DataFeedPolicy::UPLOAD)],
            ['POST', '/api/data-feeds/runs/{id}/promote', $d($feedWrite, DataFeedPolicy::class, DataFeedPolicy::PROMOTE)],
            ['GET', '/api/data-feeds/runs/{id}/rejections', $d($feedView, DataFeedPolicy::class, DataFeedPolicy::VIEW)],
            ['POST', '/api/data-feeds/aliases', $d($feedWrite, DataFeedPolicy::class, DataFeedPolicy::RESOLVE_ALIAS)],
            ['PUT', '/api/data-feeds/{id}', $d($feedCfg, DataFeedPolicy::class, DataFeedPolicy::CONFIGURE)],
        ];

        $out = [];
        foreach ($rows as [$method, $path, $meta]) {
            $out[] = [
                'method' => $method,
                'path' => $path,
                'allow' => $meta['allow'],
                'capability' => $meta['capability'],
                'or_capability' => $meta['or_capability'],
                'filter' => $meta['filter'],
            ];
        }

        return $out;
    }

    private function expected(array $row, string $role): string
    {
        if (!in_array($role, $row['allow'], true)) {
            return '403';
        }
        if ($row['filter'] !== null && !$this->seesUnfiltered($row['filter'], $role)) {
            return '200-filtered';
        }

        return '200';
    }

    private function evaluate(array $row, string $role): string
    {
        [$class, $cap] = $row['capability'];
        $policy = new $class();
        $allowed = $policy->can($role, $cap);
        if (!$allowed && !empty($row['or_capability'])) {
            $allowed = $policy->can($role, $row['or_capability']);
        }
        if (!$allowed) {
            return '403';
        }
        if ($row['filter'] !== null && !$this->seesUnfiltered($row['filter'], $role)) {
            return '200-filtered';
        }

        return '200';
    }

    private function seesUnfiltered(string $filter, string $role): bool
    {
        return match ($filter) {
            'value' => (new CrmDealPolicy())->can($role, CrmDealPolicy::VIEW_DEAL_VALUE),
            'ledger' => (new CreditGatePolicy())->can($role, CreditGatePolicy::VIEW_LEDGER_DETAIL),
            'competitors' => (new AccountContextPolicy())->can($role, AccountContextPolicy::VIEW_COMPETITOR),
            default => true,
        };
    }

    private function expectAuth(callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected an authorization exception.');
        } catch (\Throwable $e) {
            $name = $e::class;
            self::assertTrue(
                str_contains($name, 'Authorization') || str_contains($name, 'PipelineAuthorization'),
                'Expected authorization refusal, got ' . $name . ': ' . $e->getMessage()
            );
        }
    }
}
