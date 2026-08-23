<?php

namespace Tests;

use App\Services\DealStageService;
use App\Services\ExitCriteriaNotMetException;
use App\Services\IllegalTransitionException;
use App\Services\PipelineAuthorizationException;
use App\Services\StageSkipException;
use App\Services\TransitionReasonRequiredException;

/**
 * Full transition matrix for the deal state machine: every legal transition succeeds and
 * every illegal one throws. classify() is exercised directly for the matrix so the test does
 * not have to build a deal at each of the 7 stages 40 times, and the stage-changing paths are
 * then exercised end-to-end against MySQL.
 */
class DealStageTransitionTest extends CrmPipelineTestCase
{
    // ------------------------------------------------------------------
    // Matrix: legal
    // ------------------------------------------------------------------

    public function testEveryForwardStepIsLegal(): void
    {
        for ($stage = 1; $stage < DealStageService::STAGE_MAX; $stage++) {
            self::assertSame(
                'advance',
                $this->stages->classify('active', $stage, 'active', $stage + 1),
                "active/{$stage} -> active/" . ($stage + 1) . ' should be an advance'
            );
        }
    }

    public function testEveryBackwardStepIsLegal(): void
    {
        for ($stage = 2; $stage <= DealStageService::STAGE_MAX; $stage++) {
            self::assertSame(
                'regress',
                $this->stages->classify('active', $stage, 'active', $stage - 1),
                "active/{$stage} -> active/" . ($stage - 1) . ' should be a regress'
            );
        }
    }

    public function testTerminalStatusesAreLegalFromEveryStage(): void
    {
        for ($stage = 1; $stage <= DealStageService::STAGE_MAX; $stage++) {
            foreach (['lost', 'dropped'] as $status) {
                self::assertSame(
                    'terminate',
                    $this->stages->classify('active', $stage, $status, $stage),
                    "active/{$stage} -> {$status} should be a terminate"
                );
            }
        }
    }

    public function testWinIsLegalOnlyFromTheFinalStage(): void
    {
        self::assertSame('win', $this->stages->classify('active', 7, 'won', 7));

        for ($stage = 1; $stage < DealStageService::STAGE_MAX; $stage++) {
            try {
                $this->stages->classify('active', $stage, 'won', $stage);
                self::fail("Winning from stage {$stage} should be illegal.");
            } catch (IllegalTransitionException $e) {
                self::assertStringContainsString('only be won from stage 7', $e->getMessage());
            }
        }
    }

    public function testEveryTerminalStatusCanReopen(): void
    {
        foreach (['won', 'lost', 'dropped'] as $status) {
            self::assertSame(
                'reopen',
                $this->stages->classify($status, 4, 'active', 4),
                "{$status} -> active should be a reopen"
            );
        }
    }

    // ------------------------------------------------------------------
    // Matrix: illegal
    // ------------------------------------------------------------------

    public function testForwardSkipsAreIllegal(): void
    {
        for ($stage = 1; $stage <= 5; $stage++) {
            $this->expectExceptionOnClassify(
                StageSkipException::class,
                'active',
                $stage,
                'active',
                $stage + 2
            );
        }
    }

    public function testBackwardSkipsAreIllegal(): void
    {
        for ($stage = 3; $stage <= DealStageService::STAGE_MAX; $stage++) {
            $this->expectExceptionOnClassify(
                StageSkipException::class,
                'active',
                $stage,
                'active',
                $stage - 2
            );
        }
    }

    public function testSameStageMoveIsIllegal(): void
    {
        for ($stage = 1; $stage <= DealStageService::STAGE_MAX; $stage++) {
            $this->expectExceptionOnClassify(
                IllegalTransitionException::class,
                'active',
                $stage,
                'active',
                $stage
            );
        }
    }

    public function testStagesOutsideOneToSevenAreIllegal(): void
    {
        $this->expectExceptionOnClassify(IllegalTransitionException::class, 'active', 1, 'active', 0);
        $this->expectExceptionOnClassify(IllegalTransitionException::class, 'active', 7, 'active', 8);
    }

    public function testTerminalStatusesCannotTransitionToEachOther(): void
    {
        foreach ([['won', 'lost'], ['won', 'dropped'], ['lost', 'won'], ['lost', 'dropped'],
                  ['dropped', 'won'], ['dropped', 'lost'], ['won', 'won'], ['lost', 'lost']] as [$from, $to]) {
            $this->expectExceptionOnClassify(IllegalTransitionException::class, $from, 3, $to, 3);
        }
    }

    public function testReopeningCannotAlsoChangeStage(): void
    {
        $this->expectExceptionOnClassify(IllegalTransitionException::class, 'lost', 3, 'active', 4);
    }

    public function testClosingAndWinningCannotAlsoChangeStage(): void
    {
        $this->expectExceptionOnClassify(IllegalTransitionException::class, 'active', 3, 'lost', 4);
        $this->expectExceptionOnClassify(IllegalTransitionException::class, 'active', 7, 'won', 6);
    }

    // ------------------------------------------------------------------
    // End-to-end against MySQL
    // ------------------------------------------------------------------

    public function testAdvanceWritesOneImmutableEventAndResetsTheStageClock(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $eventsBefore = $this->eventCount($dealId);

        $this->satisfyExitCriteria($dealId);
        $moved = $this->stages->advance($dealId, $this->admin);

        self::assertSame(2, (int)$moved['stage']);
        self::assertSame('active', $moved['status']);
        self::assertSame($eventsBefore + 1, $this->eventCount($dealId));

        $event = $this->database->fetch(
            "SELECT * FROM crm_deal_stage_events WHERE deal_id = ? ORDER BY id DESC LIMIT 1",
            [$dealId]
        );
        self::assertSame(1, (int)$event['from_stage']);
        self::assertSame(2, (int)$event['to_stage']);
        self::assertSame('active', $event['from_status']);
        self::assertSame('active', $event['to_status']);
        self::assertNotNull($event['exit_criteria_snapshot'], 'An advance snapshots the criteria it was allowed on.');
        self::assertGreaterThanOrEqual(2, $this->auditCount('crm_deals', $dealId));
    }

    public function testAdvanceIsRefusedWhileMandatoryCriteriaAreMissing(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->satisfyExitCriteria($dealId);
        $this->stages->advance($dealId, $this->admin);

        // Stage 2 criteria are untouched, so leaving stage 2 must be refused.
        $eventsBefore = $this->eventCount($dealId);
        try {
            $this->stages->advance($dealId, $this->admin);
            self::fail('Advancing with unmet mandatory criteria should be refused.');
        } catch (ExitCriteriaNotMetException $e) {
            self::assertNotEmpty($e->getDetails()['unmet']);
        }

        self::assertSame($eventsBefore, $this->eventCount($dealId), 'A refused transition writes no event.');
        $current = $this->database->fetch("SELECT stage FROM crm_deals WHERE id = ?", [$dealId]);
        self::assertSame(2, (int)$current['stage']);
    }

    public function testMoveBackRequiresAReasonAndRecordsIt(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 3);

        try {
            $this->stages->moveBack($dealId, $this->admin, '   ');
            self::fail('Moving back without a reason should be refused.');
        } catch (TransitionReasonRequiredException $e) {
            self::assertStringContainsString('requires a reason', $e->getMessage());
        }

        $moved = $this->stages->moveBack($dealId, $this->admin, 'Customer asked for a second sample.');
        self::assertSame(2, (int)$moved['stage']);

        $event = $this->database->fetch(
            "SELECT * FROM crm_deal_stage_events WHERE deal_id = ? ORDER BY id DESC LIMIT 1",
            [$dealId]
        );
        self::assertSame('Customer asked for a second sample.', $event['reason_note']);
    }

    public function testMovingBackDoesNotRequireExitCriteria(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 2);

        // Stage 2's own criteria are unmet, but going backwards is still allowed.
        $moved = $this->stages->moveBack($dealId, $this->admin, 'Captured against the wrong customer.');
        self::assertSame(1, (int)$moved['stage']);
    }

    public function testTerminatingRequiresAnApplicableReasonCode(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];

        try {
            $this->stages->terminate($dealId, $this->admin, 'lost', 0);
            self::fail('Closing without a reason code should be refused.');
        } catch (TransitionReasonRequiredException $e) {
            self::assertStringContainsString('reason code', $e->getMessage());
        }

        // 'no_response' is configured for dropped only.
        try {
            $this->stages->terminate($dealId, $this->admin, 'lost', $this->reasonCodeId('no_response'));
            self::fail('A dropped-only reason code should not be usable for a lost deal.');
        } catch (TransitionReasonRequiredException $e) {
            self::assertStringContainsString('cannot be used', $e->getMessage());
        }

        $lost = $this->stages->terminate(
            $dealId,
            $this->admin,
            'lost',
            $this->reasonCodeId('price_too_high'),
            'Undercut by a local supplier.'
        );
        self::assertSame('lost', $lost['status']);
        self::assertSame(1, (int)$lost['stage'], 'Closing a deal keeps the stage it died at.');
        self::assertSame($this->reasonCodeId('price_too_high'), (int)$lost['lost_reason_code_id']);
    }

    public function testReopeningRequiresAReasonAndReturnsToTheSameStage(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 2);
        $this->stages->terminate($dealId, $this->admin, 'dropped', $this->reasonCodeId('no_response'));

        try {
            $this->stages->reopen($dealId, $this->admin, '');
            self::fail('Reopening without a reason should be refused.');
        } catch (TransitionReasonRequiredException $e) {
            self::assertStringContainsString('Reopening', $e->getMessage());
        }

        $reopened = $this->stages->reopen($dealId, $this->admin, 'Customer came back in April.');
        self::assertSame('active', $reopened['status']);
        self::assertSame(2, (int)$reopened['stage']);
    }

    public function testWinningIsOnlyPossibleFromTheFinalStageEndToEnd(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 6);

        try {
            $this->stages->markWon($dealId, $this->admin);
            self::fail('Winning before stage 7 should be refused.');
        } catch (IllegalTransitionException $e) {
            self::assertStringContainsString('stage 7', $e->getMessage());
        }

        $this->advanceTo($dealId, 7);
        $won = $this->stages->markWon($dealId, $this->admin);
        self::assertSame('won', $won['status']);
        self::assertSame(7, (int)$won['stage']);
    }

    public function testTechnicalHoldDoesNotBlockAStageMove(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->flags->raise([
            'deal_id' => $dealId,
            'nature_of_query' => 'Customer asks whether J-11 suits a 12mm slab body.',
        ], $this->admin);

        $evaluation = $this->stages->evaluateExitCriteria($dealId);
        self::assertTrue($evaluation['is_on_technical_hold']);

        $this->satisfyExitCriteria($dealId);
        $moved = $this->stages->advance($dealId, $this->admin);
        self::assertSame(2, (int)$moved['stage'], 'A technical hold changes display, not permission.');
    }

    // ------------------------------------------------------------------
    // RBAC
    // ------------------------------------------------------------------

    public function testMarketingCannotMoveOrCloseADeal(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $marketing = $this->actor('marketing');
        $this->satisfyExitCriteria($dealId);

        $this->expectException(PipelineAuthorizationException::class);
        $this->stages->advance($dealId, $marketing);
    }

    public function testSalesCannotReopenAClosedDeal(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $sales = $this->actor('sales');
        $this->stages->terminate($dealId, $sales, 'dropped', $this->reasonCodeId('no_response'));

        $this->expectException(PipelineAuthorizationException::class);
        $this->stages->reopen($dealId, $sales, 'Customer called back.');
    }

    // ------------------------------------------------------------------
    // Time in stage
    // ------------------------------------------------------------------

    public function testTimeInStageIsDerivedFromTheEventLog(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 3);

        // Backdate the log: captured 10 days ago, stage 2 entered 6 days ago, stage 3 4 days ago.
        $events = $this->database->fetchAll(
            "SELECT id FROM crm_deal_stage_events WHERE deal_id = ? ORDER BY id ASC",
            [$dealId]
        );
        self::assertCount(3, $events, 'Capture plus two advances is three events.');
        foreach ([0 => 10, 1 => 6, 2 => 4] as $index => $daysAgo) {
            $this->database->execute(
                "UPDATE crm_deal_stage_events SET occurred_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE id = ?",
                [$daysAgo, (int)$events[$index]['id']]
            );
        }

        $totals = $this->stages->timeInStage($dealId);
        self::assertSame([1, 2, 3], array_keys($totals));
        self::assertEqualsWithDelta(4 * 86400, $totals[1], 120, 'Stage 1 held the deal for 4 days.');
        self::assertEqualsWithDelta(2 * 86400, $totals[2], 120, 'Stage 2 held the deal for 2 days.');
        self::assertEqualsWithDelta(4 * 86400, $totals[3], 120, 'Stage 3 is still open, 4 days and counting.');
    }

    public function testTimeInStageAccumulatesAcrossRevisits(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 2);
        $this->stages->moveBack($dealId, $this->admin, 'Wrong grade captured.');
        $this->satisfyExitCriteria($dealId);
        $this->stages->advance($dealId, $this->admin);

        $totals = $this->stages->timeInStage($dealId);
        self::assertArrayHasKey(1, $totals);
        self::assertArrayHasKey(2, $totals);
        self::assertCount(4, $this->database->fetchAll(
            "SELECT id FROM crm_deal_stage_events WHERE deal_id = ?",
            [$dealId]
        ));
    }

    private function expectExceptionOnClassify(
        string $exceptionClass,
        string $fromStatus,
        int $fromStage,
        string $toStatus,
        ?int $toStage
    ): void {
        try {
            $this->stages->classify($fromStatus, $fromStage, $toStatus, $toStage);
            self::fail("{$fromStatus}/{$fromStage} -> {$toStatus}/{$toStage} should be illegal.");
        } catch (\Throwable $e) {
            self::assertInstanceOf($exceptionClass, $e);
        }
    }
}
