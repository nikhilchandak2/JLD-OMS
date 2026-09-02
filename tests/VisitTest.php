<?php

namespace Tests;

use App\Services\VisitException;
use App\Services\VisitService;

class VisitTest extends DatabaseTestCase
{
    private VisitService $visits;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->visits = new VisitService();
        $this->admin = $this->actor('admin');
    }

    public function testLogRequiresATouchpointUnlessNoFollowupIsChosen(): void
    {
        $partyId = $this->createParty();
        try {
            $this->visits->log([
                'party_id' => $partyId,
                'visit_date' => '2026-08-01',
                'purpose' => 'Plant walk',
            ], $this->admin);
            self::fail('A visit without a touchpoint or a no-follow-up reason must be refused.');
        } catch (VisitException $e) {
            self::assertStringContainsString('touchpoint', $e->getMessage());
        }
    }

    public function testNoFollowupBypassRequiresAReason(): void
    {
        $this->expectException(VisitException::class);
        $this->visits->log([
            'party_id' => $this->createParty(),
            'visit_date' => '2026-08-01',
            'no_followup_needed' => true,
        ], $this->admin);
    }

    public function testLogCreatesAVisitWithContactsAndATouchpoint(): void
    {
        $partyId = $this->createParty();
        $contactId = $this->addContact($partyId, 'Ramesh Purchase');
        $logged = $this->visits->log([
            'party_id' => $partyId,
            'visit_date' => '2026-08-10',
            'purpose' => 'Quarterly review',
            'outcome' => 'Asked for a trial of J-11',
            'next_planned_touchpoint' => '2026-08-25',
            'next_action' => 'Send quote',
            'contact_ids' => [$contactId],
            'logged_via' => 'mobile',
        ], $this->admin);

        self::assertSame('2026-08-10', $logged['visit_date']);
        self::assertSame('2026-08-25', $logged['next_planned_touchpoint']);
        self::assertFalse($logged['no_followup_needed']);
        self::assertSame('mobile', $logged['logged_via']);
        self::assertCount(1, $logged['contacts']);
        self::assertSame('Ramesh Purchase', $logged['contacts'][0]['name']);

        $party = $this->database->fetch("SELECT last_visit_date FROM parties WHERE id = ?", [$partyId]);
        self::assertSame('2026-08-10', $party['last_visit_date']);
    }

    public function testNoFollowupBypassPersistsWithoutATouchpoint(): void
    {
        $logged = $this->visits->log([
            'party_id' => $this->createParty(),
            'visit_date' => '2026-08-10',
            'outcome' => 'Account closed',
            'no_followup_needed' => true,
            'no_followup_reason' => 'Plant shut down',
        ], $this->admin);

        self::assertTrue($logged['no_followup_needed']);
        self::assertNull($logged['next_planned_touchpoint']);
        self::assertSame('Plant shut down', $logged['no_followup_reason']);
        self::assertSame([], $this->visits->overdue($this->admin));
    }

    public function testInlineNewContactIsCreatedAndAttached(): void
    {
        $partyId = $this->createParty();
        $logged = $this->visits->log([
            'party_id' => $partyId,
            'visit_date' => '2026-08-10',
            'next_planned_touchpoint' => '2026-08-20',
            'new_contacts' => [['name' => 'Suresh Stores', 'role' => 'stores', 'phone' => '9000000001']],
        ], $this->admin);

        self::assertSame('Suresh Stores', $logged['contacts'][0]['name']);
        $row = $this->database->fetch(
            "SELECT name FROM crm_contacts WHERE party_id = ? AND name = ?",
            [$partyId, 'Suresh Stores']
        );
        self::assertNotNull($row);
    }

    public function testOverdueIsThePassedTouchpointWithNoLaterVisitOrOrder(): void
    {
        $rep = $this->actor('sales');
        $partyId = $this->createParty();
        $tz = new \DateTimeZone('Asia/Kolkata');
        $firstVisit = (new \DateTimeImmutable('-40 days', $tz))->format('Y-m-d');
        $firstTouch = (new \DateTimeImmutable('-20 days', $tz))->format('Y-m-d');
        $laterVisit = (new \DateTimeImmutable('-10 days', $tz))->format('Y-m-d');
        $laterTouch = (new \DateTimeImmutable('+30 days', $tz))->format('Y-m-d');
        $this->visits->log([
            'party_id' => $partyId,
            'visit_date' => $firstVisit,
            'outcome' => 'Will order next week',
            'next_planned_touchpoint' => $firstTouch,
        ], $rep);

        $overdue = $this->visits->overdue($rep);
        self::assertCount(1, $overdue);
        self::assertSame($firstTouch, $overdue[0]['next_planned_touchpoint']);

        $this->visits->log([
            'party_id' => $partyId,
            'visit_date' => $laterVisit,
            'next_planned_touchpoint' => $laterTouch,
        ], $rep);
        self::assertSame([], $this->visits->overdue($rep), 'A later visit closes the overdue follow-up.');
    }

    public function testASubsequentOrderClearsTheOverdueTouchpoint(): void
    {
        $rep = $this->actor('sales');
        $partyId = $this->createParty();
        $this->visits->log([
            'party_id' => $partyId,
            'visit_date' => '2026-07-01',
            'next_planned_touchpoint' => '2026-07-15',
        ], $rep);
        self::assertCount(1, $this->visits->overdue($rep));

        $this->placeOrder($partyId, '2026-07-10');
        self::assertSame([], $this->visits->overdue($rep), 'An order after the visit closes the overdue follow-up.');
    }

    public function testOverdueQueryUsesTheOwnerTouchpointIndex(): void
    {
        $rep = $this->actor('sales');
        $this->visits->log([
            'party_id' => $this->createParty(),
            'visit_date' => '2026-07-01',
            'next_planned_touchpoint' => '2026-07-15',
        ], $rep);

        $plan = $this->visits->explainOverdue($rep['id']);
        $visitRow = null;
        foreach ($plan as $row) {
            $table = (string)($row['table'] ?? '');
            if ($table === 'v' || $table === 'crm_visits') {
                $visitRow = $row;
                break;
            }
        }
        self::assertNotNull($visitRow, 'EXPLAIN must include the crm_visits row.');
        self::assertNotEmpty($visitRow['key'], 'Overdue query must use an index on crm_visits. Plan: ' . json_encode($plan));
        self::assertTrue(
            str_contains((string)$visitRow['key'], 'overdue')
            || str_contains((string)$visitRow['key'], 'owner')
            || str_contains((string)$visitRow['possible_keys'] ?? '', 'idx_visits_overdue'),
            'Expected idx_visits_overdue (or a covering owner/date index). Got key=' . ($visitRow['key'] ?? 'null')
        );
    }

    public function testPartyHistoryIsNewestFirst(): void
    {
        $partyId = $this->createParty();
        $this->visits->log([
            'party_id' => $partyId,
            'visit_date' => '2026-06-01',
            'next_planned_touchpoint' => '2026-06-15',
            'outcome' => 'First',
        ], $this->admin);
        $this->visits->log([
            'party_id' => $partyId,
            'visit_date' => '2026-08-01',
            'next_planned_touchpoint' => '2026-08-15',
            'outcome' => 'Latest',
        ], $this->admin);

        $list = $this->visits->listForParty($partyId, $this->admin);
        self::assertSame('Latest', $list[0]['outcome']);
        self::assertSame('First', $list[1]['outcome']);
    }

    public function testVisitLogPageIsMobileFirstAndHasOfflineAndVoiceHooks(): void
    {
        $root = dirname(__DIR__);
        $page = file_get_contents($root . '/templates/crm/visit-log.php');
        self::assertStringContainsString('Tap count', $page);
        self::assertStringContainsString('visitNoFollowup', $page);
        self::assertStringContainsString('btnInlineContact', $page);

        $js = file_get_contents($root . '/public/js/visit-log.js');
        self::assertStringContainsString('localStorage', $js);
        self::assertStringContainsString('SpeechRecognition', $js);
        self::assertStringContainsString('jld.visit-draft', $js);
    }

    private function actor(string $role): array
    {
        $user = $this->createUser($role);

        return ['id' => $user['id'], 'role' => $role];
    }

    private function addContact(int $partyId, string $name): int
    {
        $this->database->execute(
            "INSERT INTO crm_contacts (party_id, name, role) VALUES (?, ?, 'buyer')",
            [$partyId, $name]
        );

        return (int)$this->database->lastInsertId();
    }

    private function placeOrder(int $partyId, string $orderDate): void
    {
        $productId = $this->createProduct();
        $companyId = $this->createCompany();
        $suffix = $this->uniqueSuffix();
        $this->database->execute(
            "INSERT INTO orders (company_id, order_no, order_date, product_id, order_qty_trucks, party_id, created_by, status)
             VALUES (?, ?, ?, ?, 1, ?, ?, 'pending')",
            [$companyId, "VT-{$suffix}", $orderDate, $productId, $partyId, $this->admin['id']]
        );
    }
}
