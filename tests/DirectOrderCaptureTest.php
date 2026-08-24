<?php

namespace Tests;

use App\Services\CreditGateException;
use App\Services\CreditGateService;
use App\Services\OrderService;

class DirectOrderCaptureTest extends CreditGateTestCase
{
    public function testTier1CaptureClearsWithoutAnOverride(): void
    {
        $this->silenceOtherLedgerFeeds();
        $partyId = $this->createParty(10000.00);
        $productId = $this->createProduct();

        $result = $this->capture->capture([
            'party_id' => $partyId,
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'order_qty_trucks' => 1,
            'proposed_order_value' => 100,
        ], $this->sales);

        self::assertSame(CreditGateService::STATUS_CLEARED, $result['credit_gate']['credit_gate_status']);
        self::assertNull($result['override']);
        self::assertSame(CreditGateService::STATUS_CLEARED, $result['orders'][0]['credit_gate_status']);
        self::assertNull($result['orders'][0]['credit_override_request_id']);
    }

    public function testTier2CaptureCreatesPendingDirectorOrderAndRequiresReason(): void
    {
        $this->silenceOtherLedgerFeeds();
        $partyId = $this->createParty(1000.00);
        $this->putReceivable($partyId, 1050.00);
        $productId = $this->createProduct();

        try {
            $this->capture->capture([
                'party_id' => $partyId,
                'company_id' => $this->companyId,
                'product_id' => $productId,
                'order_qty_trucks' => 1,
                'proposed_order_value' => 0,
            ], $this->sales);
            self::fail('Tier 2 must require a reason');
        } catch (CreditGateException $e) {
            self::assertStringContainsString('reason', strtolower($e->getMessage()));
        }

        $result = $this->capture->capture([
            'party_id' => $partyId,
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'order_qty_trucks' => 1,
            'proposed_order_value' => 0,
            'rep_reason' => 'Customer needs this load today',
        ], $this->sales);

        self::assertSame(CreditGateService::STATUS_PENDING_DIRECTOR, $result['credit_gate']['credit_gate_status']);
        self::assertNotNull($result['override']);
        self::assertSame(CreditGateService::STATUS_PENDING_DIRECTOR, $result['orders'][0]['credit_gate_status']);
        self::assertSame('pending', $result['orders'][0]['status']);
    }

    public function testTier3CaptureBlocksDispatchUntilApproval(): void
    {
        $this->silenceOtherLedgerFeeds();
        $partyId = $this->createParty(1000.00);
        $this->putReceivable($partyId, 1200.00);
        $productId = $this->createProduct();

        $result = $this->capture->capture([
            'party_id' => $partyId,
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'order_qty_trucks' => 2,
            'proposed_order_value' => 0,
            'rep_reason' => 'Urgent mill shutdown',
        ], $this->sales);

        self::assertSame(CreditGateService::STATUS_BLOCKED, $result['credit_gate']['credit_gate_status']);
        $orderId = (int)$result['orders'][0]['id'];

        $dispatch = new \App\Services\DispatchService();
        try {
            $dispatch->createDispatch([
                'order_id' => $orderId,
                'dispatch_date' => '2026-08-21',
                'dispatch_qty_trucks' => 1,
                'dispatched_by' => $this->admin['id'],
            ]);
            self::fail('Blocked orders must not dispatch');
        } catch (\Exception $e) {
            self::assertStringContainsString('credit gate', strtolower($e->getMessage()));
        }

        $this->overrides->decide((int)$result['override']['id'], ['action' => 'approve'], $this->admin);
        $order = (new OrderService())->getOrderById($orderId);
        self::assertSame(CreditGateService::STATUS_CLEARED, $order->creditGateStatus);
    }

    public function testCaptureDoesNotCreateADeal(): void
    {
        $this->silenceOtherLedgerFeeds();
        $partyId = $this->createParty(10000.00);
        $productId = $this->createProduct();
        $before = (int)$this->database->fetch("SELECT COUNT(*) AS c FROM crm_deals WHERE party_id = ?", [$partyId])['c'];

        $this->capture->capture([
            'party_id' => $partyId,
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'order_qty_trucks' => 1,
            'proposed_order_value' => 50,
        ], $this->sales);

        $after = (int)$this->database->fetch("SELECT COUNT(*) AS c FROM crm_deals WHERE party_id = ?", [$partyId])['c'];
        self::assertSame($before, $after);
    }
}
