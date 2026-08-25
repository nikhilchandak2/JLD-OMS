<?php

namespace Tests;

use App\Controllers\CrmContactController;
use App\Core\Database;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Services\DealService;
use App\Services\DormancyService;
use App\Services\EscalationService;
use App\Services\HandoffService;
use App\Services\PipelineDashboardService;
use App\Services\VisitService;

class CrmHardeningTest extends CrmPipelineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        http_response_code(200);
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_X_CSRFTOKEN']);
    }

    public function testMutatingRequestsRequireCsrf(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/crm/visits';
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_X_CSRFTOKEN'], $_SESSION['csrf_token']);

        ob_start();
        $ok = (new CsrfMiddleware())->handle();
        ob_end_clean();
        self::assertFalse($ok);
        self::assertSame(403, http_response_code());
    }

    public function testMutatingRequestsPassWithCsrfToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/crm/deals';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'];

        self::assertTrue((new CsrfMiddleware())->handle());
    }

    public function testCrmWritesAreRateLimited(): void
    {
        $_ENV['RATE_LIMIT_ENABLED'] = '1';
        $_ENV['RATE_LIMIT_CRM_WRITE_MAX'] = '3';
        $_ENV['RATE_LIMIT_CRM_WRITE_WINDOW_SECONDS'] = '60';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/crm/visits';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(10, 200);

        $mw = new RateLimitMiddleware();
        ob_start();
        self::assertTrue($mw->handle());
        self::assertTrue($mw->handle());
        self::assertTrue($mw->handle());
        $blocked = $mw->handle();
        ob_end_clean();
        self::assertFalse($blocked);
        self::assertSame(429, http_response_code());
    }

    public function testContactCreateWritesAuditOldAndNew(): void
    {
        $this->login($this->admin);
        $_POST = ['name' => 'Purchase Head', 'role' => 'purchase_manager'];
        $partyId = $this->createParty();

        ob_start();
        (new CrmContactController())->create((string)$partyId);
        ob_end_clean();

        $row = $this->database->fetch(
            "SELECT * FROM audit_logs WHERE table_name = 'crm_contacts' AND action = 'CREATE' ORDER BY id DESC LIMIT 1"
        );
        self::assertNotNull($row, 'Contact create must write audit_logs.');
        self::assertNotNull($row['new_values']);
        self::assertNull($row['old_values']);
        $new = json_decode((string)$row['new_values'], true);
        self::assertSame('Purchase Head', $new['name'] ?? null);
    }

    public function testTenFrequentQueriesUseIndexes(): void
    {
        $this->captureDeal(['value' => 1], $this->admin);
        $asOf = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        (new PipelineDashboardService())->rebuild($asOf);
        (new DormancyService())->rebuild($asOf);

        $plans = [
            'pipeline_by_stage' => (new PipelineDashboardService())->explainByStage(),
            'pipeline_time_in_stage' => (new PipelineDashboardService())->explainTimeInStage(),
            'dormancy_activity' => (new DormancyService())->explainActivitySnapshot($asOf),
            'visits_overdue' => (new VisitService())->explainOverdue($this->admin['id']),
            'deals_list' => $this->explain(
                "SELECT d.id FROM crm_deals d
                 JOIN parties p ON p.id = d.party_id
                 WHERE d.deleted_at IS NULL AND d.status = 'active'
                 ORDER BY d.id DESC LIMIT 50"
            ),
            'escalations_inbox' => $this->explain(
                "SELECT e.id FROM escalations e
                 JOIN parties p ON p.id = e.party_id
                 WHERE e.status IN ('open', 'acknowledged')
                 ORDER BY e.triggered_on ASC LIMIT 50"
            ),
            'handoffs_list' => $this->explain(
                "SELECT p.id FROM handoff_packets p
                 WHERE p.packet_type = 'sales_to_dispatch' AND p.superseded_by_packet_id IS NULL
                 ORDER BY p.id DESC LIMIT 50"
            ),
            'forecast_actuals_grade' => $this->explain(
                "SELECT grade_code, SUM(actual_tonnes) AS t
                 FROM forecast_actuals WHERE period_id = 1 GROUP BY grade_code"
            ),
            'credit_override_queue' => $this->explain(
                "SELECT r.id FROM credit_override_requests r
                 JOIN parties p ON p.id = r.party_id
                 JOIN companies c ON c.id = r.company_id
                 WHERE r.status = 'pending' ORDER BY r.id DESC LIMIT 50"
            ),
            'dormancy_list' => $this->explain(
                "SELECT s.id FROM account_dormancy_signals s
                 JOIN parties p ON p.id = s.party_id
                 WHERE s.computed_on = ? ORDER BY s.id DESC LIMIT 50",
                [$asOf]
            ),
        ];

        foreach ($plans as $name => $plan) {
            self::assertNotEmpty($plan, "EXPLAIN {$name} returned no rows.");
            $scanned = [];
            foreach ($plan as $row) {
                $type = strtolower((string)($row['type'] ?? ''));
                $table = (string)($row['table'] ?? '');
                if ($type === 'all' && !in_array($table, ['null', ''], true) && (int)($row['rows'] ?? 0) > 10000) {
                    $scanned[] = $table;
                }
            }
            self::assertSame([], $scanned, "{$name} full-scanned a large table: " . json_encode($plan));
        }
    }

    public function testListViewsDoNotNplusOne(): void
    {
        $admin = $this->admin;
        for ($i = 0; $i < 3; $i++) {
            $this->captureDeal(['value' => 10 + $i], $admin);
        }
        Database::beginCountingQueries();
        (new DealService())->list([], $admin);
        $smallDeals = Database::takeQueryCount();

        for ($i = 0; $i < 9; $i++) {
            $this->captureDeal(['value' => 20 + $i], $admin);
        }
        Database::beginCountingQueries();
        (new DealService())->list([], $admin);
        $largeDeals = Database::takeQueryCount();
        self::assertSame($smallDeals, $largeDeals, 'Deal list must batch grades, not query per deal.');

        $asOf = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        (new PipelineDashboardService())->rebuild($asOf);
        Database::beginCountingQueries();
        (new PipelineDashboardService())->dashboard($admin);
        $pipeQueries = Database::takeQueryCount();
        self::assertLessThan(8, $pipeQueries, 'Pipeline dashboard must read the snapshot, not walk deals.');

        Database::beginCountingQueries();
        (new EscalationService())->inbox($admin);
        $esc = Database::takeQueryCount();
        Database::beginCountingQueries();
        (new HandoffService())->list([], $admin);
        $hand = Database::takeQueryCount();
        Database::beginCountingQueries();
        (new VisitService())->overdue($admin);
        $visits = Database::takeQueryCount();
        Database::beginCountingQueries();
        (new DormancyService())->listForActor($admin, $asOf);
        $dorm = Database::takeQueryCount();

        self::assertLessThan(6, $esc);
        self::assertLessThan(6, $hand);
        self::assertLessThan(8, $visits);
        self::assertLessThan(6, $dorm);
    }

    /** @param array{id:int,role:string,email?:string} $user */
    private function login(array $user): void
    {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_email'] = $user['email'] ?? 'admin@test.local';
        $_SESSION['user_name'] = 'Test';
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function explain(string $sql, array $params = []): array
    {
        return $this->database->fetchAll('EXPLAIN ' . $sql, $params);
    }
}
