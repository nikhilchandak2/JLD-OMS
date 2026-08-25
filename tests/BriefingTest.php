<?php

namespace Tests;

use App\Core\Database;
use App\Services\BriefingAuthorizationException;
use App\Services\BriefingService;
use App\Services\CreditGatePolicy;
use App\Services\VisitService;

class BriefingTest extends DatabaseTestCase
{
    private BriefingService $briefings;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->briefings = new BriefingService();
        $this->admin = $this->actor('admin');
        $this->createCompany();
    }

    public function testEmptyPartySaysNotYetRecordedWithAddLinks(): void
    {
        $partyId = $this->createParty();
        $data = $this->briefings->compose($partyId, $this->admin);

        self::assertFalse($data['contacts']['recorded']);
        self::assertStringContainsString('not yet recorded', $data['contacts']['empty_message']);
        self::assertNotSame('', $data['contacts']['add_url']);

        self::assertFalse($data['competitors']['recorded']);
        self::assertStringContainsString('not yet recorded', $data['competitors']['empty_message']);
        self::assertStringContainsString('no competitor', $data['competitors']['empty_message']);

        self::assertFalse($data['issues']['recorded']);
        self::assertStringContainsString('not yet recorded', $data['issues']['empty_message']);

        self::assertFalse($data['last_visit']['recorded']);
        self::assertStringContainsString('/crm/visits/new', $data['last_visit']['add_url']);

        self::assertFalse($data['forecast']['recorded']);
        self::assertStringContainsString('not yet recorded', $data['forecast']['empty_message']);
    }

    public function testQueryCountDoesNotGrowWithDispatchHistory(): void
    {
        $this->briefings->compose($this->createParty(), $this->admin);

        $companyId = $this->createCompany();
        $small = $this->createParty();
        $this->dispatchN($small, 3, $companyId);
        Database::beginCountingQueries();
        $this->briefings->compose($small, $this->admin);
        $smallCount = Database::takeQueryCount();

        $large = $this->createParty();
        $this->dispatchN($large, 40, $companyId);
        Database::beginCountingQueries();
        $this->briefings->compose($large, $this->admin);
        $largeCount = Database::takeQueryCount();

        self::assertSame($smallCount, $largeCount, "Small={$smallCount} large={$largeCount}");
        self::assertGreaterThan(0, $smallCount);
        fwrite(STDOUT, "\nBriefing query count (does not scale with history): {$smallCount}\n");
    }

    public function testComposeFinishesUnderOneSecondOnRealisticData(): void
    {
        $partyId = $this->createParty(500000);
        $this->dispatchN($partyId, 24);
        $this->addContact($partyId);
        (new VisitService())->log([
            'party_id' => $partyId,
            'visit_date' => '2026-08-10',
            'outcome' => 'Asked for J-11 trial',
            'next_planned_touchpoint' => '2026-08-25',
        ], $this->admin);

        $started = microtime(true);
        $this->briefings->compose($partyId, $this->admin);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(1.0, $elapsed, "Briefing took {$elapsed}s");
        fwrite(STDOUT, "\nBriefing compose time: " . round($elapsed, 4) . "s\n");
    }

    public function testSalesDoesNotReceiveLedgerDetailInJsonOrPdf(): void
    {
        $partyId = $this->createParty(123456.78);
        $this->database->execute(
            "INSERT INTO crm_receivable_entries (party_id, entry_type, amount, entry_date, description, created_by)
             VALUES (?, 'invoice', 44444.00, '2026-01-01', 'Keep private', ?)",
            [$partyId, $this->admin['id']]
        );

        $sales = $this->actor('sales');
        $data = $this->briefings->compose($partyId, $sales);
        $json = json_encode($data);
        self::assertIsString($json);

        foreach (CreditGatePolicy::LEDGER_FIELDS as $field) {
            self::assertDoesNotMatchRegularExpression('/"' . preg_quote($field, '/') . '"\s*:/', $json);
        }
        self::assertStringNotContainsString('123456', $json);
        self::assertStringNotContainsString('44444', $json);
        self::assertArrayHasKey('headroom_band', $data['credit']);
        self::assertArrayHasKey('ledger_as_of', $data['credit']);
        self::assertArrayNotHasKey('headroom', $data['credit']);

        $pdf = $this->briefings->pdfBytes($partyId, $sales);
        self::assertStringStartsWith('%PDF', $pdf['bytes']);
        self::assertStringNotContainsString('123456', $pdf['bytes']);
        self::assertStringNotContainsString('44444', $pdf['bytes']);
    }

    public function testHandoverNoteIsMarkedTransitional(): void
    {
        $partyId = $this->createParty();
        $this->briefings->addHandoverNote($partyId, 'Plant manager prefers WhatsApp after 6pm.', $this->admin);
        $data = $this->briefings->compose($partyId, $this->admin);
        self::assertTrue($data['handover_notes']['transitional']);
        self::assertNotSame('', $data['handover_notes']['review_date']);
        self::assertCount(1, $data['handover_notes']['items']);

        $page = file_get_contents(dirname(__DIR__) . '/templates/crm/briefing.php');
        self::assertStringContainsString('config/briefing.php', $page);
        $js = file_get_contents(dirname(__DIR__) . '/public/js/briefing.js');
        self::assertStringContainsString('not a permanent feature', $js);
    }

    public function testDispatchCannotViewBriefing(): void
    {
        $this->expectException(BriefingAuthorizationException::class);
        $this->briefings->compose($this->createParty(), $this->actor('dispatch'));
    }

    private function actor(string $role): array
    {
        $user = $this->createUser($role);

        return ['id' => $user['id'], 'role' => $role];
    }

    private function addContact(int $partyId): void
    {
        $this->database->execute(
            "INSERT INTO crm_contacts (party_id, name, role, is_primary, influence_level, relationship_strength)
             VALUES (?, 'Ramesh', 'purchase_manager', 1, 'decision_maker', 'strong')",
            [$partyId]
        );
    }

    private function dispatchN(int $partyId, int $count, ?int $companyId = null): void
    {
        $companyId = $companyId ?? $this->createCompany();
        $productId = $this->createProduct();
        for ($i = 0; $i < $count; $i++) {
            $day = sprintf('2026-07-%02d', ($i % 28) + 1);
            $this->database->execute(
                "INSERT INTO orders (company_id, order_no, order_date, product_id, order_qty_trucks, tons_per_truck, party_id, created_by, status)
                 VALUES (?, ?, ?, ?, 1, 40, ?, ?, 'pending')",
                [$companyId, 'BR-' . $this->uniqueSuffix(), $day, $productId, $partyId, $this->admin['id']]
            );
            $orderId = (int)$this->database->lastInsertId();
            try {
                $this->database->execute(
                    "INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, status, loading_weight_tons, dispatched_by)
                     VALUES (?, ?, 1, 'active', 40, ?)",
                    [$orderId, $day, $this->admin['id']]
                );
            } catch (\Throwable $e) {
                $this->database->execute(
                    "INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, loading_weight_tons, dispatched_by)
                     VALUES (?, ?, 1, 40, ?)",
                    [$orderId, $day, $this->admin['id']]
                );
            }
        }
    }
}
