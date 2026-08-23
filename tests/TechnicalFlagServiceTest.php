<?php

namespace Tests;

use App\Services\PipelineAuthorizationException;
use App\Services\PipelineException;

/**
 * Technical flags: queue-routed, orthogonal to the pipeline, and usable for repeat customers
 * that have no deal at all.
 */
class TechnicalFlagServiceTest extends CrmPipelineTestCase
{
    public function testRaisingAFlagRoutesToAQueueAndDerivesTheHold(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];

        $flag = $this->flags->raise([
            'deal_id' => $dealId,
            'nature_of_query' => 'Does J-11 hold at 1180C in a double-charge body?',
        ], $this->admin);

        self::assertSame('open', $flag['status']);
        self::assertSame($dealId, (int)$flag['deal_id']);
        self::assertSame((int)$deal['party_id'], (int)$flag['party_id']);
        self::assertSame(1, (int)$flag['raised_from_stage'], 'The flag records the stage it was raised from.');
        self::assertNotNull($flag['expected_turnaround_at']);
        self::assertNull($flag['claimed_by_user_id']);
        self::assertSame(1, $this->auditCount('crm_technical_flags', (int)$flag['id']));

        $shown = $this->deals->show($dealId, $this->admin);
        self::assertTrue($shown['is_on_technical_hold'], 'The hold is derived from the open flag (I2).');
        self::assertCount(1, $shown['open_technical_flags']);
        self::assertSame(1, (int)$shown['stage'], 'A hold is not a stage.');
    }

    public function testTheDefaultTurnaroundIsFortyEightHoursFromNowInIndianTime(): void
    {
        $flag = $this->flags->raise([
            'party_id' => $this->createParty(),
            'nature_of_query' => 'Slip viscosity question.',
        ], $this->admin);

        $expected = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->modify('+48 hours');
        $actual = new \DateTimeImmutable($flag['expected_turnaround_at'], new \DateTimeZone('Asia/Kolkata'));

        self::assertEqualsWithDelta($expected->getTimestamp(), $actual->getTimestamp(), 120);
    }

    public function testAFlagCanBeRaisedForARepeatCustomerWithNoDeal(): void
    {
        $partyId = $this->createParty();

        $flag = $this->flags->raise([
            'party_id' => $partyId,
            'nature_of_query' => 'Long-standing account asking about a new glaze batch.',
        ], $this->admin);

        self::assertNull($flag['deal_id']);
        self::assertSame($partyId, (int)$flag['party_id']);
        self::assertNull($flag['raised_from_stage']);

        $queue = $this->flags->queue(['party_id' => $partyId], $this->actor('technical'));
        self::assertCount(1, $queue);
        self::assertNull($queue[0]['deal_id']);
    }

    public function testAnEmptyQueryOrAnUnknownQueueIsRefused(): void
    {
        $partyId = $this->createParty();

        try {
            $this->flags->raise(['party_id' => $partyId, 'nature_of_query' => '   '], $this->admin);
            self::fail('An empty query should be refused.');
        } catch (PipelineException $e) {
            self::assertStringContainsString('Describe the technical query', $e->getMessage());
        }

        try {
            $this->flags->raise([
                'party_id' => $partyId,
                'nature_of_query' => 'Valid question.',
                'routed_to_queue_id' => 987654,
            ], $this->admin);
            self::fail('An unknown queue should be refused.');
        } catch (PipelineException $e) {
            self::assertStringContainsString('technical queue', $e->getMessage());
        }
    }

    public function testClaimThenResolveClearsTheHoldAndKeepsAReusableAnswer(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $flag = $this->flags->raise([
            'deal_id' => $dealId,
            'nature_of_query' => 'Firing shrinkage query.',
        ], $this->admin);
        $flagId = (int)$flag['id'];
        $technical = $this->actor('technical');

        $claimed = $this->flags->claim($flagId, $technical);
        self::assertSame('claimed', $claimed['status']);
        self::assertSame($technical['id'], (int)$claimed['claimed_by_user_id']);

        try {
            $this->flags->claim($flagId, $technical);
            self::fail('Claiming twice should be refused.');
        } catch (PipelineException $e) {
            self::assertStringContainsString('already claimed', $e->getMessage());
        }

        $resolved = $this->flags->resolve($flagId, $technical, 'remote_answer', 'Shrinkage is 6.2% at 1180C.');
        self::assertSame('resolved', $resolved['status']);
        self::assertSame('remote_answer', $resolved['resolution_type']);
        self::assertSame('Shrinkage is 6.2% at 1180C.', $resolved['resolution_note']);
        self::assertNotNull($resolved['resolved_at']);

        $shown = $this->deals->show($dealId, $this->admin);
        self::assertFalse($shown['is_on_technical_hold'], 'A resolved flag no longer holds the deal.');
    }

    public function testResolvingRequiresAKnownTypeAndANote(): void
    {
        $flag = $this->flags->raise([
            'party_id' => $this->createParty(),
            'nature_of_query' => 'Bulk density question.',
        ], $this->admin);
        $flagId = (int)$flag['id'];
        $technical = $this->actor('technical');

        try {
            $this->flags->resolve($flagId, $technical, 'phone_call', 'Answered.');
            self::fail('An unknown resolution type should be refused.');
        } catch (PipelineException $e) {
            self::assertStringContainsString('remote answer or a site visit', $e->getMessage());
        }

        try {
            $this->flags->resolve($flagId, $technical, 'site_visit', '   ');
            self::fail('An empty resolution note should be refused.');
        } catch (PipelineException $e) {
            self::assertStringContainsString('resolution note is required', $e->getMessage());
        }

        $resolved = $this->flags->resolve($flagId, $technical, 'site_visit', 'Visited the plant on the 12th.');
        self::assertSame('site_visit', $resolved['resolution_type']);

        try {
            $this->flags->resolve($flagId, $technical, 'remote_answer', 'Again.');
            self::fail('Resolving a resolved flag should be refused.');
        } catch (PipelineException $e) {
            self::assertStringContainsString('already resolved', $e->getMessage());
        }
    }

    public function testTheRaiserCanCancelTheirOwnFlagButSalesCannotWorkTheQueue(): void
    {
        $sales = $this->actor('sales');
        $flag = $this->flags->raise([
            'party_id' => $this->createParty(),
            'nature_of_query' => 'Raised in error.',
        ], $sales);
        $flagId = (int)$flag['id'];

        $otherSales = $this->actor('sales');
        try {
            $this->flags->resolve($flagId, $otherSales, 'remote_answer', 'Sales answering technical.');
            self::fail('Sales must not resolve technical flags.');
        } catch (PipelineAuthorizationException $e) {
            // expected
        }

        $cancelled = $this->flags->cancel($flagId, $sales, 'Duplicate of an earlier query.');
        self::assertSame('cancelled', $cancelled['status']);
    }

    public function testOverdueFlagsSortToTheTopOfTheQueue(): void
    {
        $partyId = $this->createParty();
        $fresh = $this->flags->raise([
            'party_id' => $partyId,
            'nature_of_query' => 'Raised just now.',
        ], $this->admin);
        $overdue = $this->flags->raise([
            'party_id' => $partyId,
            'nature_of_query' => 'Raised last week and still open.',
        ], $this->admin);

        $this->database->execute(
            "UPDATE crm_technical_flags
             SET created_at = DATE_SUB(NOW(), INTERVAL 7 DAY),
                 expected_turnaround_at = DATE_SUB(NOW(), INTERVAL 5 DAY)
             WHERE id = ?",
            [(int)$overdue['id']]
        );

        $queue = $this->flags->queue(['party_id' => $partyId, 'open_only' => 1], $this->actor('technical'));
        self::assertCount(2, $queue);
        self::assertSame((int)$overdue['id'], (int)$queue[0]['id'], 'The overdue flag comes first.');
        self::assertTrue($queue[0]['is_overdue']);
        self::assertFalse($queue[1]['is_overdue']);
        self::assertSame((int)$fresh['id'], (int)$queue[1]['id']);
    }

    public function testResolutionStatsCountOpenOverdueAndSiteVisits(): void
    {
        $partyId = $this->createParty();
        $technical = $this->actor('technical');

        $siteVisit = $this->flags->raise(['party_id' => $partyId, 'nature_of_query' => 'A'], $this->admin);
        $this->flags->resolve((int)$siteVisit['id'], $technical, 'site_visit', 'Went there.');
        $this->flags->raise(['party_id' => $partyId, 'nature_of_query' => 'B'], $this->admin);

        $stats = $this->flags->stats($technical);
        self::assertNotEmpty($stats, 'Stats are reported per queue.');

        $totals = ['flags_raised' => 0, 'still_open' => 0, 'resolved' => 0, 'site_visits' => 0];
        foreach ($stats as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int)$row[$key];
            }
        }

        self::assertGreaterThanOrEqual(2, $totals['flags_raised']);
        self::assertGreaterThanOrEqual(1, $totals['still_open']);
        self::assertGreaterThanOrEqual(1, $totals['resolved']);
        self::assertGreaterThanOrEqual(1, $totals['site_visits']);
    }

    public function testARoleWithNoPipelineOrQueueAccessCannotReadTheQueue(): void
    {
        $this->expectException(PipelineAuthorizationException::class);
        $this->flags->queue([], ['id' => null, 'role' => 'driver']);
    }
}
