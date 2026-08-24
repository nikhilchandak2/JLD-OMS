<?php

namespace Tests;

use App\Services\DataFeedException;
use App\Services\FeedPromotionBlockedException;
use App\Services\FeedSupersedeRequiredException;

class DataFeedIngestTest extends DataFeedTestCase
{
    public function testCleanLedgerFilePromotesAtomically(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];

        $result = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'ledger.csv',
            $this->ledgerCsv([
                ['party_name' => $name, 'outstanding_amount' => '15000.50', 'invoice_no' => 'INV-1', 'invoice_date' => '2026-08-20'],
            ]),
            $this->admin
        );

        $this->assertSame('validated', $result['run']['status']);
        $this->assertTrue($result['can_promote']);
        $this->assertSame(0, $this->liveLedgerCount());

        $promoted = $this->ingest->promote((int)$result['run']['id'], $this->admin);
        $this->assertSame('completed', $promoted['run']['status']);
        $this->assertNotNull($promoted['run']['as_of']);
        $this->assertSame(1, $this->liveLedgerCount());
    }

    public function testExcelUploadIsAccepted(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $xlsx = $this->xlsxFromCsvRows(
            ['party_name', 'outstanding_amount', 'invoice_no'],
            [[$name, '2000', 'INV-X']]
        );

        $result = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'ledger.xlsx',
            $xlsx,
            $this->admin
        );

        $this->assertSame(1, (int)$result['run']['rows_accepted']);
        $this->ingest->promote((int)$result['run']['id'], $this->admin);
        $this->assertSame(1, $this->liveLedgerCount());
    }

    public function testDuplicatesWithinFileAreRejected(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $csv = $this->ledgerCsv([
            ['party_name' => $name, 'outstanding_amount' => '100', 'invoice_no' => 'DUP'],
            ['party_name' => $name, 'outstanding_amount' => '100', 'invoice_no' => 'DUP'],
        ]);

        $result = $this->ingest->upload('ledger', $this->companyId, '2026-08-20', 'dup.csv', $csv, $this->admin);
        $this->assertSame(1, (int)$result['run']['rows_accepted']);
        $this->assertSame(1, (int)$result['run']['rows_rejected']);
        $this->assertSame('duplicate_row', $result['rejected_preview'][0]['rejection_reason']);
        $this->assertFalse($result['can_promote']);
    }

    public function testDuplicateAgainstExistingDataIsRejected(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $first = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-19',
            'a.csv',
            $this->ledgerCsv([['party_name' => $name, 'outstanding_amount' => '10', 'invoice_no' => 'KEEP']]),
            $this->admin
        );
        $this->ingest->promote((int)$first['run']['id'], $this->admin);

        $second = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'b.csv',
            $this->ledgerCsv([['party_name' => $name, 'outstanding_amount' => '11', 'invoice_no' => 'KEEP']]),
            $this->admin
        );
        $this->assertSame('duplicate_existing', $second['rejected_preview'][0]['rejection_reason']);
    }

    public function testUnknownPartyIsQueuedNotAutoCreated(): void
    {
        $before = (int)$this->database->fetch("SELECT COUNT(*) AS c FROM parties")['c'];
        $result = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'unknown.csv',
            $this->ledgerCsv([['party_name' => 'No Such Buyer Pvt Ltd', 'outstanding_amount' => '500']]),
            $this->admin
        );

        $this->assertSame('unknown_party', $result['rejected_preview'][0]['rejection_reason']);
        $this->assertSame($before, (int)$this->database->fetch("SELECT COUNT(*) AS c FROM parties")['c']);

        $queue = $this->aliases->unmatchedQueue();
        $this->assertNotEmpty($queue);
        $this->assertSame('NO SUCH BUYER PVT LTD', $queue[0]['source_identifier']);
    }

    public function testAliasResolutionUnblocksPromote(): void
    {
        $partyId = $this->createParty();
        $result = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'alias.csv',
            $this->ledgerCsv([['party_name' => 'Busy Typo Name', 'outstanding_amount' => '75']]),
            $this->admin
        );
        $this->assertFalse($result['can_promote']);

        $this->aliases->resolveManually('busy', 'Busy Typo Name', $partyId, $this->admin);
        $this->ingest->afterAliasResolved($this->admin);

        $again = $this->ingest->show((int)$result['run']['id']);
        $this->assertTrue($again['can_promote']);
        $this->ingest->promote((int)$result['run']['id'], $this->admin);
        $this->assertSame(1, $this->liveLedgerCount());
    }

    public function testMalformedNumberIsRejected(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $result = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'bad.csv',
            $this->ledgerCsv([['party_name' => $name, 'outstanding_amount' => 'not-a-number']]),
            $this->admin
        );
        $this->assertSame('malformed_number', $result['rejected_preview'][0]['rejection_reason']);
    }

    public function testMissingColumnsFailTheFile(): void
    {
        $this->expectException(DataFeedException::class);
        $this->expectExceptionMessage('Missing required column');
        $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'nocol.csv',
            "foo,bar\n1,2",
            $this->admin
        );
    }

    public function testEmptyFileFails(): void
    {
        $this->expectException(DataFeedException::class);
        $this->expectExceptionMessage('Empty file');
        $this->ingest->upload('ledger', $this->companyId, '2026-08-20', 'empty.csv', "party_name,outstanding_amount\n", $this->admin);
    }

    public function testByteIdenticalReuploadIsNoOp(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $csv = $this->ledgerCsv([['party_name' => $name, 'outstanding_amount' => '9', 'invoice_no' => 'R1']]);

        $first = $this->ingest->upload('ledger', $this->companyId, '2026-08-20', 'a.csv', $csv, $this->admin);
        $second = $this->ingest->upload('ledger', $this->companyId, '2026-08-20', 'a-again.csv', $csv, $this->admin);

        $this->assertTrue($second['already_processed']);
        $this->assertSame((int)$first['run']['id'], (int)$second['run']['id']);
    }

    public function testDifferentFileSameDateRequiresSupersede(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $first = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'one.csv',
            $this->ledgerCsv([['party_name' => $name, 'outstanding_amount' => '1', 'invoice_no' => 'A']]),
            $this->admin
        );
        $this->ingest->promote((int)$first['run']['id'], $this->admin);

        try {
            $this->ingest->upload(
                'ledger',
                $this->companyId,
                '2026-08-20',
                'two.csv',
                $this->ledgerCsv([['party_name' => $name, 'outstanding_amount' => '2', 'invoice_no' => 'B']]),
                $this->admin
            );
            $this->fail('Expected FeedSupersedeRequiredException');
        } catch (FeedSupersedeRequiredException $e) {
            $this->assertTrue($e->getDetails()['supersede_required']);
        }

        $second = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'two.csv',
            $this->ledgerCsv([['party_name' => $name, 'outstanding_amount' => '2', 'invoice_no' => 'B']]),
            $this->admin,
            ['confirm_supersede' => true]
        );
        $this->ingest->promote((int)$second['run']['id'], $this->admin);

        $old = $this->database->fetch("SELECT status FROM data_feed_runs WHERE id = ?", [$first['run']['id']]);
        $this->assertSame('superseded', $old['status']);
        $this->assertSame(1, $this->liveLedgerCount());
    }

    public function testMalformedRowBlocksAllPromotion(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $rows = [];
        for ($i = 1; $i <= 500; $i++) {
            $rows[] = [
                'party_name' => $name,
                'outstanding_amount' => $i === 250 ? 'BAD' : (string)$i,
                'invoice_no' => 'R' . $i,
            ];
        }

        $result = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'partial.csv',
            $this->ledgerCsv($rows),
            $this->admin
        );

        $this->assertSame(499, (int)$result['run']['rows_accepted']);
        $this->assertSame(1, (int)$result['run']['rows_rejected']);
        $this->assertSame('malformed_number', $result['rejected_preview'][0]['rejection_reason']);
        $this->assertSame(251, (int)$result['rejected_preview'][0]['row_number']);

        try {
            $this->ingest->promote((int)$result['run']['id'], $this->admin);
            $this->fail('Promote must be blocked');
        } catch (FeedPromotionBlockedException $e) {
            $this->assertStringContainsString('rejected', $e->getMessage());
        }
        $this->assertSame(0, $this->liveLedgerCount());
    }

    public function testPromoteFailureLeavesZeroLiveRows(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = ['party_name' => $name, 'outstanding_amount' => (string)$i, 'invoice_no' => 'F' . $i];
        }
        $result = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'fail.csv',
            $this->ledgerCsv($rows),
            $this->admin
        );

        try {
            $this->ingest->promote((int)$result['run']['id'], $this->admin, ['fail_after' => 5]);
            $this->fail('Injected failure must throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected promote failure', $e->getMessage());
        }

        $this->assertSame(0, $this->liveLedgerCount(), 'Live table must be empty after a mid-promote failure');
    }

    public function testFiveThousandRowFileCompletesWithinBudget(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $rows = [];
        for ($i = 1; $i <= 5000; $i++) {
            $rows[] = ['party_name' => $name, 'outstanding_amount' => '1.00', 'invoice_no' => 'BIG-' . $i];
        }

        $started = microtime(true);
        $result = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            '5k.csv',
            $this->ledgerCsv($rows),
            $this->admin
        );
        $this->ingest->promote((int)$result['run']['id'], $this->admin);
        $elapsed = microtime(true) - $started;

        $this->assertSame(5000, $this->liveLedgerCount());
        $this->assertLessThan(15, $elapsed, "5,000-row file took {$elapsed}s (budget 15s)");
    }

    public function testDispatchDayFilePromotes(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $result = $this->ingest->upload(
            'dispatch_day_file',
            $this->companyId,
            '2026-08-20',
            'day.csv',
            $this->dispatchCsv([['party_name' => $name, 'grade_code' => 'J-11', 'quantity_tonnes' => '24.5']]),
            $this->admin
        );
        $this->ingest->promote((int)$result['run']['id'], $this->admin);
        $this->assertSame(1, $this->liveDispatchCount());
    }

    public function testTemplateHeadersAreDownloadable(): void
    {
        $ledger = $this->ingest->template('ledger');
        $dispatch = $this->ingest->template('dispatch_day_file');
        $this->assertContains('outstanding_amount', $ledger['headers']);
        $this->assertContains('quantity_tonnes', $dispatch['headers']);
    }

    public function testChangingDeadlineChangesFreshnessWithoutCodeChange(): void
    {
        $partyId = $this->createParty();
        $name = $this->database->fetch("SELECT name FROM parties WHERE id = ?", [$partyId])['name'];
        $result = $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'fresh.csv',
            $this->ledgerCsv([['party_name' => $name, 'outstanding_amount' => '1']]),
            $this->admin
        );
        $this->ingest->promote((int)$result['run']['id'], $this->admin);

        $feed = $this->database->fetch(
            "SELECT id FROM data_feeds WHERE feed_key = 'ledger' AND company_id = ?",
            [$this->companyId]
        );
        $now = new \DateTimeImmutable('2026-08-21 10:00:00', new \DateTimeZone('Asia/Kolkata'));

        $this->ingest->updateFeed((int)$feed['id'], ['deadline_local_time' => '18:00:00'], $this->admin);
        $lateDeadline = $this->freshness->asOf('ledger', $this->companyId, $now);

        $this->ingest->updateFeed((int)$feed['id'], ['deadline_local_time' => '08:00:00'], $this->admin);
        $earlyDeadline = $this->freshness->asOf('ledger', $this->companyId, $now);

        $this->assertNotSame($lateDeadline['expected_business_date'], $earlyDeadline['expected_business_date']);
    }

    public function testSalesRoleCannotUpload(): void
    {
        $this->expectException(\App\Services\DataFeedAuthorizationException::class);
        $this->ingest->upload(
            'ledger',
            $this->companyId,
            '2026-08-20',
            'no.csv',
            $this->ledgerCsv([['party_name' => 'X', 'outstanding_amount' => '1']]),
            $this->actor('sales')
        );
    }
}
