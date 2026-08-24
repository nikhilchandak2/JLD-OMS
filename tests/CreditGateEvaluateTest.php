<?php

namespace Tests;

use App\Services\CreditGateService;

class CreditGateEvaluateTest extends CreditGateTestCase
{
    /**
     * @dataProvider boundaryCases
     */
    public function testBoundaryTiers(float $limit, float $outstanding, float $proposed, int $expectedTier): void
    {
        $this->silenceOtherLedgerFeeds();
        $partyId = $this->createParty($limit);
        if ($outstanding > 0) {
            $this->putReceivable($partyId, $outstanding);
        }

        $evaluation = $this->gate->evaluate($partyId, $this->companyId, $proposed);

        self::assertSame($expectedTier, (int)$evaluation['tier'], json_encode($evaluation));
    }

    public static function boundaryCases(): array
    {
        return [
            'exactly at limit' => [1000.00, 1000.00, 0.00, CreditGateService::TIER_AUTO],
            'one rupee over' => [1000.00, 1000.00, 1.00, CreditGateService::TIER_PASSIVE],
            'exactly 10 percent over' => [1000.00, 1100.00, 0.00, CreditGateService::TIER_PASSIVE],
            '10.01 percent over' => [1000.00, 1100.10, 0.00, CreditGateService::TIER_REALTIME],
            'zero limit is tier 3' => [0.00, 0.00, 100.00, CreditGateService::TIER_REALTIME],
        ];
    }

    public function testNullLimitIsTier3(): void
    {
        $this->silenceOtherLedgerFeeds();
        $partyId = $this->createParty(null);
        $evaluation = $this->gate->evaluate($partyId, $this->companyId, 0);

        self::assertSame(CreditGateService::TIER_REALTIME, (int)$evaluation['tier']);
        self::assertSame(CreditGateService::STATUS_BLOCKED, $evaluation['credit_gate_status']);
        self::assertSame(CreditGateService::ACTION_BLOCK_UNTIL_DECISION, $evaluation['required_action']);
    }

    public function testChangingTheTier2ConfigRowChangesRoutingWithoutADeploy(): void
    {
        $this->silenceOtherLedgerFeeds();
        $partyId = $this->createParty(1000.00);
        $this->putReceivable($partyId, 1060.00);

        $before = $this->gate->evaluate($partyId, $this->companyId, 0);
        self::assertSame(CreditGateService::TIER_PASSIVE, (int)$before['tier']);

        $this->tiers->updateTier($this->companyId, 2, ['threshold_percentage' => 5.00]);

        $after = $this->gate->evaluate($partyId, $this->companyId, 0);
        self::assertSame(CreditGateService::TIER_REALTIME, (int)$after['tier']);
    }
}
