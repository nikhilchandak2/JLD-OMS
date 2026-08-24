<?php

namespace Tests;

class CreditGateAggregationTest extends CreditGateTestCase
{
    public function testGroupOutstandingSumsAcrossEntitiesAndUsesOldestAsOf(): void
    {
        $companyA = $this->companyId;
        $companyB = $this->createCompany();
        $this->tiers->ensureForCompany($companyB);
        $this->silenceOtherLedgerFeeds($companyA, $companyB);

        $partyId = $this->createParty(100000.00);
        $this->putLedger($companyA, $partyId, 40.00, '2026-08-21 09:05:00', '2026-08-21');
        $this->putLedger($companyB, $partyId, 60.00, '2026-08-19 09:05:00', '2026-08-19');

        $evaluation = $this->gate->evaluate($partyId, $companyA, 0);

        self::assertEquals(100.00, $evaluation['outstanding']);
        self::assertSame('2026-08-19 09:05:00', $evaluation['ledger_as_of']);
        self::assertSame($companyB, (int)$evaluation['lagging_entity']['company_id']);
        self::assertCount(2, $evaluation['outstanding_breakdown']);
    }

    public function testMissingEntityWarnsAndDoesNotBlockEvaluation(): void
    {
        $companyA = $this->companyId;
        $companyB = $this->createCompany();
        $this->tiers->ensureForCompany($companyB);
        $this->silenceOtherLedgerFeeds($companyA, $companyB);

        $partyId = $this->createParty(100000.00);
        $this->putLedger($companyA, $partyId, 25.00, '2026-08-21 09:05:00', '2026-08-21');

        $evaluation = $this->gate->evaluate($partyId, $companyA, 0);

        self::assertEquals(25.00, $evaluation['outstanding']);
        self::assertTrue($evaluation['incomplete_feed']);
        $missingIds = array_map(static fn($e) => (int)$e['company_id'], $evaluation['missing_entities']);
        self::assertContains($companyB, $missingIds);
        self::assertSame(1, (int)$evaluation['tier']);
    }

    public function testIncompleteFeedStateIsPersistedOnTheOverride(): void
    {
        $companyA = $this->companyId;
        $companyB = $this->createCompany();
        $this->tiers->ensureForCompany($companyB);
        $this->silenceOtherLedgerFeeds($companyA, $companyB);

        $partyId = $this->createParty(10.00);
        $this->putLedger($companyA, $partyId, 12.00, '2026-08-21 09:05:00', '2026-08-21');
        $order = $this->createBareOrder($partyId, $companyA);

        $evaluation = $this->gate->evaluate($partyId, $companyA, 0);
        self::assertTrue($evaluation['incomplete_feed']);
        self::assertGreaterThan(1, (int)$evaluation['tier']);

        $request = $this->overrides->raise($evaluation, $this->sales, 'Need this load', null, $order);
        self::assertNotEmpty($request['incomplete_feed_entities']);
        $missingIds = array_map(static fn($e) => (int)$e['company_id'], $request['incomplete_feed_entities']);
        self::assertContains($companyB, $missingIds);
    }

    private function createBareOrder(int $partyId, int $companyId): int
    {
        $productId = $this->createProduct();
        $this->database->execute(
            "INSERT INTO orders (company_id, order_no, order_date, product_id, order_qty_trucks, party_id, created_by, status)
             VALUES (?, ?, '2026-08-21', ?, 1, ?, ?, 'pending')",
            [$companyId, 'T3-' . $this->uniqueSuffix(), $productId, $partyId, $this->admin['id']]
        );

        return (int)$this->database->lastInsertId();
    }
}
