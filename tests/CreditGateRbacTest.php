<?php

namespace Tests;

use App\Services\CreditGatePolicy;
use App\Services\CreditGateService;
use App\Services\DealService;
use App\Services\DealStageService;

class CreditGateRbacTest extends CreditGateTestCase
{
    public function testSalesEvaluateResponseOmitsLedgerDetail(): void
    {
        $this->silenceOtherLedgerFeeds();
        $partyId = $this->createParty(1000.00);
        $this->putReceivable($partyId, 200.00);
        $evaluation = $this->gate->evaluate($partyId, $this->companyId, 0);

        $forSales = $this->gate->serializeForRole($evaluation, 'sales');
        $forAdmin = $this->gate->serializeForRole($evaluation, 'admin');

        foreach (CreditGatePolicy::LEDGER_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $forSales, "sales must not see {$field}");
        }
        self::assertArrayHasKey('headroom', $forSales);
        self::assertArrayHasKey('tier', $forSales);
        self::assertArrayHasKey('ledger_as_of', $forSales);
        self::assertArrayHasKey('outstanding', $forAdmin);
        self::assertArrayHasKey('outstanding_breakdown', $forAdmin);
    }

    public function testDealShowFromStageFiveStripsLedgerForSales(): void
    {
        $deals = new DealService();
        $stages = new DealStageService();
        $partyId = $this->createParty(10000000.00);
        $deal = $deals->captureInquiry([
            'party_id' => $partyId,
            'company_id' => $this->companyId,
            'source' => 'whatsapp',
            'grades' => 'J-11',
            'indicative_quantity_tonnes' => 40,
            'inquiry_date' => '2026-01-05',
            'value' => 1000,
        ], $this->admin);

        $dealId = (int)$deal['id'];
        while ((int)$deal['stage'] < 5) {
            $this->satisfyForDeal($dealId, $stages);
            $deal = $stages->advance($dealId, $this->admin);
        }

        $shown = $deals->show($dealId, $this->sales);
        self::assertArrayHasKey('credit_gate', $shown);
        foreach (CreditGatePolicy::LEDGER_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $shown['credit_gate'], "sales deal payload leaked {$field}");
        }
        self::assertArrayHasKey('headroom', $shown['credit_gate']);
        self::assertSame(CreditGateService::TIER_AUTO, (int)$shown['credit_gate']['tier']);
    }

    private function satisfyForDeal(int $dealId, DealStageService $stages): void
    {
        $evaluation = $stages->evaluateExitCriteria($dealId);
        $deal = $this->database->fetch("SELECT * FROM crm_deals WHERE id = ?", [$dealId]);
        $captured = [];
        foreach ($evaluation['criteria'] as $criterion) {
            if ($criterion['satisfied']) {
                continue;
            }
            switch ($criterion['field_key']) {
                case 'decision_maker_contact':
                    $this->database->execute(
                        "INSERT INTO crm_contacts (party_id, name, role, is_primary) VALUES (?, 'Purchase Head', 'purchase_manager', 1)",
                        [(int)$deal['party_id']]
                    );
                    break;
                case 'sample_sent':
                    $this->database->execute(
                        "INSERT INTO crm_samples (party_id, deal_id, sample_type, status, request_date)
                         VALUES (?, ?, 'J-11', 'sample_sent', CURDATE())",
                        [(int)$deal['party_id'], $dealId]
                    );
                    break;
                case 'credit_gate_cleared':
                    $this->database->execute(
                        "UPDATE parties SET credit_limit = 10000000 WHERE id = ?",
                        [(int)$deal['party_id']]
                    );
                    break;
                default:
                    $captured[$criterion['field_key']] = 'test value';
            }
        }
        if ($captured !== []) {
            $stages->saveCriteriaValues($dealId, $captured, $this->admin);
        }
    }
}
