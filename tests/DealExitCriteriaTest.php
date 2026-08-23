<?php

namespace Tests;

use App\Services\ExitCriteriaNotMetException;

/**
 * Exit criteria are configuration, not code (I4): changing a row in the configuration table
 * changes what the pipeline demands, with no deploy.
 */
class DealExitCriteriaTest extends CrmPipelineTestCase
{
    public function testStageOneCriteriaAreSatisfiedByCaptureItselfWithoutRetyping(): void
    {
        $deal = $this->captureDeal();
        $evaluation = $this->stages->evaluateExitCriteria((int)$deal['id']);

        self::assertSame([], $evaluation['unmet'], 'Capture answers every Stage 1 criterion.');
        self::assertTrue($evaluation['can_advance']);
        self::assertSame(2, $evaluation['next_stage']);

        $byKey = array_column($evaluation['criteria'], null, 'field_key');
        foreach (['source', 'party', 'grades', 'indicative_quantity', 'inquiry_date'] as $key) {
            self::assertArrayHasKey($key, $byKey);
            self::assertSame('derived', $byKey[$key]['source'], "{$key} is read from the record, never re-typed (I11).");
            self::assertTrue($byKey[$key]['satisfied']);
        }
    }

    public function testANewMandatoryCriterionImmediatelyBlocksTheStageWithNoCodeChange(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        self::assertTrue($this->stages->evaluateExitCriteria($dealId)['can_advance']);

        $this->database->execute(
            "INSERT INTO crm_stage_exit_criteria (stage, field_key, is_mandatory, label, sort_order)
             VALUES (1, 'director_sign_off', 1, 'Director sign-off', 999)"
        );

        $evaluation = $this->stages->evaluateExitCriteria($dealId);
        self::assertFalse($evaluation['can_advance']);
        self::assertSame(['director_sign_off'], array_column($evaluation['unmet'], 'field_key'));

        try {
            $this->stages->advance($dealId, $this->admin);
            self::fail('The new mandatory criterion should block the advance.');
        } catch (ExitCriteriaNotMetException $e) {
            self::assertStringContainsString('Director sign-off', $e->getMessage());
        }

        $this->stages->saveCriteriaValues($dealId, ['director_sign_off' => 'Approved by DK on the 14th.'], $this->admin);
        self::assertTrue($this->stages->evaluateExitCriteria($dealId)['can_advance']);
        self::assertSame(2, (int)$this->stages->advance($dealId, $this->admin)['stage']);
    }

    public function testMakingACriterionOptionalUnblocksTheStage(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 2);

        // Stage 2 asks for four things and nothing has been captured yet.
        self::assertNotEmpty($this->stages->evaluateExitCriteria($dealId)['unmet']);

        $this->database->execute("UPDATE crm_stage_exit_criteria SET is_mandatory = 0 WHERE stage = 2");

        $evaluation = $this->stages->evaluateExitCriteria($dealId);
        self::assertSame([], $evaluation['unmet']);
        self::assertTrue($evaluation['can_advance']);
    }

    public function testDeactivatedCriteriaAreNotAskedFor(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 2);

        $this->database->execute("UPDATE crm_stage_exit_criteria SET is_active = 0 WHERE stage = 2");

        $evaluation = $this->stages->evaluateExitCriteria($dealId);
        self::assertSame([], $evaluation['criteria']);
        self::assertTrue($evaluation['can_advance'], 'A stage with no active criteria has nothing to block on.');
    }

    public function testCapturedValuesAreStoredAgainstTheConfiguredKeyAndUnknownKeysAreIgnored(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 2);

        $this->stages->saveCriteriaValues($dealId, [
            'application_confirmed' => 'Body preparation for GVT',
            'made_up_field' => 'should be ignored',
            'source' => 'email',
        ], $this->admin);

        $rows = $this->database->fetchAll(
            "SELECT field_key, value_text FROM crm_deal_criteria_values WHERE deal_id = ?",
            [$dealId]
        );
        $stored = array_column($rows, 'value_text', 'field_key');

        self::assertSame(['application_confirmed' => 'Body preparation for GVT'], $stored);
        self::assertSame(
            'whatsapp',
            $this->database->fetch("SELECT source FROM crm_deals WHERE id = ?", [$dealId])['source'],
            'A derived criterion cannot be overwritten through the criteria endpoint.'
        );
    }

    public function testTheAdvanceSnapshotRecordsTheValuesItWasAllowedOn(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->stages->advance($dealId, $this->admin);

        $event = $this->database->fetch(
            "SELECT exit_criteria_snapshot FROM crm_deal_stage_events
             WHERE deal_id = ? AND from_stage = 1 ORDER BY id DESC LIMIT 1",
            [$dealId]
        );
        $snapshot = json_decode((string)$event['exit_criteria_snapshot'], true);

        self::assertIsArray($snapshot);
        self::assertSame('whatsapp', $snapshot['source']);
        self::assertSame('2026-01-05', $snapshot['inquiry_date']);
        self::assertStringContainsString('J-11', $snapshot['grades']);
    }

    public function testCriteriaForTheFinalStageGateTheWin(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 7);

        $this->database->execute(
            "INSERT INTO crm_stage_exit_criteria (stage, field_key, is_mandatory, label, sort_order)
             VALUES (7, 'po_number', 1, 'Customer PO number', 998)"
        );

        try {
            $this->stages->markWon($dealId, $this->admin);
            self::fail('Winning should respect the final stage criteria.');
        } catch (ExitCriteriaNotMetException $e) {
            self::assertStringContainsString('Customer PO number', $e->getMessage());
        }

        $this->stages->saveCriteriaValues($dealId, ['po_number' => 'PO/2026/0091'], $this->admin);
        self::assertSame('won', $this->stages->markWon($dealId, $this->admin)['status']);
    }
}
