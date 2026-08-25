<?php

namespace Tests;

use App\Services\AccountIssueService;
use App\Services\CrmNightlyJobService;
use App\Services\DormancyAuthorizationException;
use App\Services\DormancyService;
use App\Services\EscalationService;

class DormancyTest extends DatabaseTestCase
{
    private const AS_OF = '2026-08-25';

    private CrmNightlyJobService $job;
    private DormancyService $dormancy;
    private EscalationService $escalations;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->job = new CrmNightlyJobService();
        $this->dormancy = new DormancyService();
        $this->escalations = new EscalationService();
        $this->admin = $this->actor('admin');
    }

    public function testNeverOrderedIsUrgentWithNoVisit(): void
    {
        $partyId = $this->createParty();
        $this->job->run(self::AS_OF);

        $row = $this->signalFor($partyId);
        self::assertNotNull($row);
        self::assertSame('urgent', $row['severity']);
        self::assertNull($row['last_order_date']);
        self::assertStringContainsString('going cold silently', $row['reason_summary']);
        self::assertSame(0, (int)$row['forecast_gap_flag']);
    }

    public function testOrderedYesterdayIsNotDormant(): void
    {
        $partyId = $this->createParty();
        $this->placeOrder($partyId, '2026-08-24');
        $this->job->run(self::AS_OF);
        self::assertNull($this->signalFor($partyId));
    }

    public function testOrderedExactlyTwentyDaysAgoIsTheBoundary(): void
    {
        $partyId = $this->createParty();
        $this->placeOrder($partyId, '2026-08-05');
        $this->job->run(self::AS_OF);
        $row = $this->signalFor($partyId);
        self::assertNotNull($row, 'Exactly 20 days without an order is dormant.');
        self::assertSame(20, (int)$row['days_since_last_order']);
    }

    public function testOrderedTwentyOneDaysAgoWithARecentVisitIsWatch(): void
    {
        $partyId = $this->createParty();
        $this->placeOrder($partyId, '2026-08-04');
        $this->logVisit($partyId, '2026-08-22');
        $this->job->run(self::AS_OF);

        $row = $this->signalFor($partyId);
        self::assertNotNull($row);
        self::assertSame('watch', $row['severity']);
        self::assertStringContainsString('normal cycle gap', $row['reason_summary']);
        self::assertSame([], $this->openEscalations($partyId, 'dormant_account'));
    }

    public function testOrderedTwentyOneDaysAgoWithNoVisitIsUrgent(): void
    {
        $partyId = $this->createParty();
        $this->placeOrder($partyId, '2026-08-04');
        $this->job->run(self::AS_OF);

        $row = $this->signalFor($partyId);
        self::assertNotNull($row);
        self::assertSame('urgent', $row['severity']);
        $esc = $this->openEscalations($partyId, 'dormant_account');
        self::assertCount(1, $esc);
        self::assertNotEmpty($esc[0]['context_snapshot']['party_name']);
    }

    public function testDormantThenReactivatedClearsTheSignalAndClosesTheEscalation(): void
    {
        $partyId = $this->createParty();
        $this->placeOrder($partyId, '2026-08-04');
        $this->job->run(self::AS_OF);
        self::assertSame('urgent', $this->signalFor($partyId)['severity']);
        self::assertCount(1, $this->openEscalations($partyId, 'dormant_account'));

        $this->placeOrder($partyId, '2026-08-24');
        $this->job->run(self::AS_OF);
        self::assertNull($this->signalFor($partyId), 'A fresh order clears dormancy.');
        self::assertSame([], $this->openEscalations($partyId, 'dormant_account'));
    }

    public function testAFastPathOrderWithNoDealRecordCounts(): void
    {
        $partyId = $this->createParty();
        $this->placeOrder($partyId, '2026-08-24');
        $deals = $this->database->fetchAll("SELECT id FROM crm_deals WHERE party_id = ?", [$partyId]);
        self::assertSame([], $deals);
        $this->job->run(self::AS_OF);
        self::assertNull($this->signalFor($partyId));
    }

    public function testChangingAConfigRowChangesTheOutputWithNoCodeChange(): void
    {
        $partyId = $this->createParty();
        $this->placeOrder($partyId, '2026-08-10');
        $this->job->run(self::AS_OF);
        self::assertNull($this->signalFor($partyId), '15 days is inside the seeded 20-day rule.');

        $this->database->execute(
            "UPDATE dormancy_rules SET days_no_order = 10, days_no_order_urgent = 10
             WHERE company_id IS NULL AND account_tier IS NULL AND is_active = 1"
        );
        $this->job->run(self::AS_OF);
        self::assertNotNull($this->signalFor($partyId), 'The same account is dormant once the config row says 10 days.');
    }

    public function testAPerTierConfigRowAppliesWithoutACodeChange(): void
    {
        $this->database->execute(
            "INSERT INTO dormancy_rules (company_id, account_tier, days_no_order, days_no_order_urgent, days_no_visit, is_active)
             VALUES (NULL, 'strategic', 5, 5, 20, 1)"
        );
        $hot = $this->createParty();
        $plain = $this->createParty();
        $this->database->execute("UPDATE parties SET account_tier = 'strategic' WHERE id = ?", [$hot]);
        $this->placeOrder($hot, '2026-08-17');
        $this->placeOrder($plain, '2026-08-17');
        $this->job->run(self::AS_OF);

        self::assertNotNull($this->signalFor($hot), 'Strategic tier uses the 5-day row.');
        self::assertNull($this->signalFor($plain), 'Untiered accounts still use the 20-day group row.');
    }

    public function testUnresolvedIssuePastItsWindowEscalatesAndResolvingClosesIt(): void
    {
        $partyId = $this->createParty();
        $this->placeOrder($partyId, '2026-08-24');
        $issues = new AccountIssueService();
        $created = $issues->create($partyId, [
            'description' => 'Quality complaint on the last lot',
            'issue_type' => 'quality_complaint',
            'raised_on' => '2026-08-10',
            'resolution_window_days' => 7,
        ], $this->admin);
        $this->job->run(self::AS_OF);

        $open = $this->openEscalations($partyId, 'unresolved_issue');
        self::assertCount(1, $open);
        self::assertSame((int)$created['id'], (int)$open[0]['source_id']);

        $issues->resolve((int)$created['id'], ['resolution_note' => 'Replacement dispatched'], $this->admin);
        self::assertSame([], $this->openEscalations($partyId, 'unresolved_issue'));
    }

    public function testAcknowledgedEscalationIsNotReRaisedNightly(): void
    {
        $partyId = $this->createParty();
        $this->placeOrder($partyId, '2026-08-04');
        $this->job->run(self::AS_OF);
        $open = $this->openEscalations($partyId, 'dormant_account');
        self::assertCount(1, $open);

        $this->escalations->acknowledge((int)$open[0]['id'], $this->admin);
        $this->job->run(self::AS_OF);

        $again = $this->database->fetchAll(
            "SELECT id, status FROM escalations WHERE party_id = ? AND trigger_type = 'dormant_account'",
            [$partyId]
        );
        self::assertCount(1, $again, 'Nightly must not insert a second row for the same episode.');
        self::assertSame('acknowledged', $again[0]['status']);
    }

    public function testDispatchDelayAndTechnicalOverdueAndManualEachRaise(): void
    {
        $partyId = $this->createParty();
        $this->placeOrder($partyId, '2026-08-24', '2026-08-20');
        $queue = $this->database->fetch("SELECT id FROM crm_technical_queues WHERE is_active = 1 ORDER BY id LIMIT 1");
        self::assertNotNull($queue, '046 seeds a technical queue.');
        $this->database->execute(
            "INSERT INTO crm_technical_flags
                (deal_id, party_id, nature_of_query, routed_to_queue_id, expected_turnaround_at, status)
             VALUES (NULL, ?, 'Past due', ?, '2026-08-20 09:00:00', 'open')",
            [$partyId, (int)$queue['id']]
        );
        $this->job->run(self::AS_OF);

        self::assertCount(1, $this->openEscalations($partyId, 'dispatch_delay'));
        self::assertCount(1, $this->openEscalations($partyId, 'technical_overdue'));

        $manual = $this->escalations->raiseManual([
            'party_id' => $partyId,
            'note' => 'Director asked for a call this week',
        ], $this->admin);
        self::assertSame('manual_flag', $manual['trigger_type']);
        self::assertSame('Director asked for a call this week', $manual['context_snapshot']['reason']);
        self::assertArrayHasKey('contacts', $manual['context_snapshot']);
        self::assertArrayHasKey('competitors', $manual['context_snapshot']);
        self::assertArrayHasKey('open_issues', $manual['context_snapshot']);
    }

    public function testRepListIsScopedToAssignedAccounts(): void
    {
        $rep = $this->actor('sales');
        $mine = $this->createParty();
        $theirs = $this->createParty();
        $this->database->execute(
            "UPDATE parties SET assigned_sales_owner = ? WHERE id = ?",
            [$rep['id'], $mine]
        );
        $this->job->run(self::AS_OF);

        $list = $this->dormancy->listForActor($rep, self::AS_OF);
        $ids = array_map(static fn(array $r) => (int)$r['party_id'], $list);
        self::assertContains($mine, $ids);
        self::assertNotContains($theirs, $ids);

        $all = $this->dormancy->listForActor($this->admin, self::AS_OF);
        $allIds = array_map(static fn(array $r) => (int)$r['party_id'], $all);
        self::assertContains($mine, $allIds);
        self::assertContains($theirs, $allIds);
    }

    public function testSalesCannotOpenTheDirectorInbox(): void
    {
        $this->expectException(DormancyAuthorizationException::class);
        $this->escalations->inbox($this->actor('sales'));
    }

    public function testSecondRunTheSameDayIsIdempotentAndOverlapIsSkipped(): void
    {
        $this->createParty();
        $first = $this->job->run(self::AS_OF);
        self::assertSame('ok', $first['status']);
        $second = $this->job->run(self::AS_OF);
        self::assertSame('ok', $second['status']);
        self::assertSame($first['signals'], $second['signals']);

        $this->database->execute(
            "INSERT INTO crm_job_locks (job_name, locked_at, locked_by) VALUES ('crm_nightly', NOW(), 'test')"
        );
        $skipped = $this->job->run(self::AS_OF);
        self::assertSame('skipped', $skipped['status']);
        self::assertSame('lock_table', $skipped['reason']);
    }

    public function testHeaviestQueryUsesThePartyDateIndexAndFinishesQuickly(): void
    {
        $productId = $this->createProduct();
        $companyId = $this->createCompany();
        for ($i = 0; $i < 80; $i++) {
            $partyId = $this->createParty();
            for ($d = 0; $d < 8; $d++) {
                $date = (new \DateTimeImmutable(self::AS_OF))->modify("-{$d} days")->format('Y-m-d');
                $this->placeOrder($partyId, $date, null, $productId, $companyId);
            }
        }

        $plan = $this->dormancy->explainLastOrderAggregate();
        $visitRow = $plan[0] ?? null;
        self::assertNotNull($visitRow);
        self::assertNotEmpty($visitRow['key'] ?? null, 'Last-order aggregate must use an index. Plan: ' . json_encode($plan));
        self::assertTrue(
            str_contains((string)$visitRow['key'], 'party')
            || str_contains((string)($visitRow['possible_keys'] ?? ''), 'idx_orders_party_date'),
            'Expected idx_orders_party_date. Got key=' . ($visitRow['key'] ?? 'null')
        );

        $seconds = $this->dormancy->timeActivitySnapshot(self::AS_OF);
        self::assertLessThan(2.0, $seconds, "Activity snapshot took {$seconds}s against 80 parties × 8 orders.");
    }

    public function testPagesStateTheReasonInPlainLanguage(): void
    {
        $root = dirname(__DIR__);
        $dormancy = file_get_contents($root . '/templates/crm/dormancy.php');
        self::assertStringContainsString('reason_summary', $dormancy);
        self::assertStringContainsString('/api/crm/dormancy', $dormancy);
        $inbox = file_get_contents($root . '/templates/crm/escalations.php');
        self::assertStringContainsString('acknowledge', $inbox);
        self::assertStringContainsString('context_snapshot', $inbox);
        self::assertFileExists($root . '/scripts/crm_nightly.php');
    }

    private function actor(string $role): array
    {
        $user = $this->createUser($role);

        return ['id' => $user['id'], 'role' => $role];
    }

    private function signalFor(int $partyId): ?array
    {
        return $this->database->fetch(
            "SELECT * FROM account_dormancy_signals WHERE party_id = ? AND computed_on = ?",
            [$partyId, self::AS_OF]
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function openEscalations(int $partyId, string $type): array
    {
        $rows = $this->database->fetchAll(
            "SELECT * FROM escalations
             WHERE party_id = ? AND trigger_type = ? AND status IN ('open', 'acknowledged')",
            [$partyId, $type]
        );
        foreach ($rows as &$row) {
            if (isset($row['context_snapshot']) && is_string($row['context_snapshot'])) {
                $row['context_snapshot'] = json_decode($row['context_snapshot'], true);
            }
        }

        return $rows;
    }

    private function logVisit(int $partyId, string $visitDate): void
    {
        $this->database->execute(
            "INSERT INTO crm_visits (
                party_id, visited_by_user_id, visit_date, next_planned_touchpoint,
                no_followup_needed, no_followup_reason, logged_via
             ) VALUES (?, ?, ?, NULL, 1, 'fixture', 'web')",
            [$partyId, $this->admin['id'], $visitDate]
        );
    }

    private function placeOrder(
        int $partyId,
        string $orderDate,
        ?string $scheduledDispatch = null,
        ?int $productId = null,
        ?int $companyId = null
    ): void {
        $productId = $productId ?? $this->createProduct();
        $companyId = $companyId ?? $this->createCompany();
        $suffix = $this->uniqueSuffix();
        $this->database->execute(
            "INSERT INTO orders (company_id, order_no, order_date, scheduled_dispatch_date, product_id, order_qty_trucks, party_id, created_by, status)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, 'pending')",
            [$companyId, "DM-{$suffix}", $orderDate, $scheduledDispatch, $productId, $partyId, $this->admin['id']]
        );
    }
}
