<?php

namespace Tests;

use App\Services\DataFreshnessService;
use DateTimeImmutable;
use DateTimeZone;

class DataFreshnessServiceTest extends DataFeedTestCase
{
    public function testGroupAsOfUsesOldestEntityAndNamesTheLagger(): void
    {
        $companyA = $this->companyId;
        $companyB = $this->createCompany();
        $this->activateOnlyLedgerFeeds($companyA, $companyB);
        $partyA = $this->createParty();
        $partyB = $this->createParty();
        $nameA = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyA])['name'];
        $nameB = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyB])['name'];

        $runA = $this->ingest->upload(
            'ledger',
            $companyA,
            '2026-08-21',
            'a.csv',
            $this->ledgerCsv([['party_name' => $nameA, 'outstanding_amount' => '10']]),
            $this->admin
        );
        $this->ingest->promote((int)$runA['run']['id'], $this->admin);

        $runB = $this->ingest->upload(
            'ledger',
            $companyB,
            '2026-08-19',
            'b.csv',
            $this->ledgerCsv([['party_name' => $nameB, 'outstanding_amount' => '20']]),
            $this->admin
        );
        $this->ingest->promote((int)$runB['run']['id'], $this->admin);

        $this->database->execute(
            "UPDATE data_feed_runs SET as_of = '2026-08-21 09:05:00' WHERE id = ?",
            [$runA['run']['id']]
        );
        $this->database->execute(
            "UPDATE data_feed_runs SET as_of = '2026-08-19 09:05:00' WHERE id = ?",
            [$runB['run']['id']]
        );

        $now = new DateTimeImmutable('2026-08-21 10:00:00', new DateTimeZone('Asia/Kolkata'));
        $group = $this->freshness->groupAsOf('ledger', $now);

        $this->assertSame('2026-08-19 09:05:00', $group['as_of']);
        $this->assertSame($companyB, (int)$group['lagging_entity']['company_id']);
        $this->assertSame(DataFreshnessService::STATE_STALE, $group['lagging_entity']['state']);
        $this->assertSame(DataFreshnessService::STATE_STALE, $group['state']);
        $this->assertSame([], $group['missing_entities']);
    }

    public function testGroupAsOfReportsMissingEntityInsteadOfOmittingIt(): void
    {
        $companyA = $this->companyId;
        $companyB = $this->createCompany();
        $this->activateOnlyLedgerFeeds($companyA, $companyB);
        $partyA = $this->createParty();
        $nameA = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyA])['name'];

        $runA = $this->ingest->upload(
            'ledger',
            $companyA,
            '2026-08-21',
            'a.csv',
            $this->ledgerCsv([['party_name' => $nameA, 'outstanding_amount' => '10']]),
            $this->admin
        );
        $this->ingest->promote((int)$runA['run']['id'], $this->admin);

        $now = new DateTimeImmutable('2026-08-21 10:00:00', new DateTimeZone('Asia/Kolkata'));
        $group = $this->freshness->groupAsOf('ledger', $now);

        $missingIds = array_map(static fn($e) => (int)$e['company_id'], $group['missing_entities']);
        $this->assertContains($companyB, $missingIds);
        $this->assertSame(DataFreshnessService::STATE_MISSING_ENTITY, $group['state']);
        $this->assertNotNull($group['lagging_entity']);
    }

    public function testBannerTonesMatchTheThreeVisibleStates(): void
    {
        $fresh = $this->freshness->bannerPayload('ledger', $this->companyId, false);
        $this->assertSame('stale', $fresh['tone']);
        $this->assertStringContainsString('not been uploaded', strtolower($fresh['message']));

        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $run = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-21',
            'today.csv',
            $this->ledgerCsv([['party_name' => $name, 'outstanding_amount' => '1']]),
            $this->admin
        );
        $this->ingest->promote((int)$run['run']['id'], $this->admin);

        $nowFresh = new DateTimeImmutable('2026-08-21 08:30:00', new DateTimeZone('Asia/Kolkata'));
        $payloadFresh = $this->freshness->bannerPayload('ledger', $this->companyId, false, $nowFresh);
        $this->assertSame('fresh', $payloadFresh['tone']);

        $this->database->execute(
            "UPDATE data_feed_runs SET business_date = '2026-08-20', as_of = '2026-08-20 09:00:00' WHERE id = ?",
            [$run['run']['id']]
        );
        $nowLate = new DateTimeImmutable('2026-08-21 10:00:00', new DateTimeZone('Asia/Kolkata'));
        $payloadLate = $this->freshness->bannerPayload('ledger', $this->companyId, false, $nowLate);
        $this->assertSame('late', $payloadLate['tone']);

        $this->database->execute(
            "UPDATE data_feed_runs SET business_date = '2026-08-18', as_of = '2026-08-18 09:00:00' WHERE id = ?",
            [$run['run']['id']]
        );
        $payloadStale = $this->freshness->bannerPayload('ledger', $this->companyId, false, $nowLate);
        $this->assertSame('stale', $payloadStale['tone']);
    }

    private function activateOnlyLedgerFeeds(int ...$companyIds): void
    {
        $this->freshness->groupAsOf('ledger', new DateTimeImmutable('2026-08-21 10:00:00', new DateTimeZone('Asia/Kolkata')));
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        $this->database->execute(
            "UPDATE data_feeds SET is_active = 0 WHERE feed_key = 'ledger' AND company_id NOT IN ({$placeholders})",
            $companyIds
        );
        $this->database->execute(
            "UPDATE data_feeds SET is_active = 1 WHERE feed_key = 'ledger' AND company_id IN ({$placeholders})",
            $companyIds
        );
    }
}
