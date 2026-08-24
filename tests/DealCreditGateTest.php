<?php

namespace Tests;

use App\Services\ExitCriteriaNotMetException;

class DealCreditGateTest extends CrmPipelineTestCase
{
    public function testTier3DealCannotLeaveStageSixUntilApproved(): void
    {
        $partyId = $this->createParty(1000.00);
        $this->database->execute(
            "INSERT INTO crm_receivable_entries (party_id, entry_type, amount, entry_date, description, created_by)
             VALUES (?, 'invoice', 2000, '2026-01-01', 'Over', ?)",
            [$partyId, $this->admin['id']]
        );
        $this->database->execute("UPDATE data_feeds SET is_active = 0 WHERE feed_key = 'ledger'");

        $deal = $this->captureDeal(['party_id' => $partyId, 'value' => 0]);
        $dealId = (int)$deal['id'];
        $this->advanceTo($dealId, 6);

        $evaluation = $this->stages->evaluateExitCriteria($dealId);
        $byKey = array_column($evaluation['criteria'], null, 'field_key');
        self::assertArrayHasKey('credit_gate_cleared', $byKey);
        self::assertTrue($byKey['credit_gate_cleared']['is_mandatory']);
        self::assertFalse($byKey['credit_gate_cleared']['satisfied']);

        try {
            $this->stages->advance($dealId, $this->admin);
            self::fail('Tier 3 must block Stage 6 → 7');
        } catch (ExitCriteriaNotMetException $e) {
            self::assertStringContainsString('Credit gate', $e->getMessage());
        }
    }
}
