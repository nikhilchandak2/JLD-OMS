<?php

namespace Tests;

use App\Services\CreditOverrideService;
use App\Services\IllegalOverrideTransitionException;

class CreditOverrideTransitionTest extends CreditGateTestCase
{
    public function testLegalAndIllegalTransitions(): void
    {
        $requestId = $this->openRequest();

        $this->overrides->decide($requestId, ['action' => 'call'], $this->admin);
        $call = $this->overrides->present($requestId, $this->admin);
        self::assertSame(CreditOverrideService::STATUS_CALL_REQUESTED, $call['status']);

        $this->expectException(IllegalOverrideTransitionException::class);
        $this->overrides->decide($requestId, ['action' => 'call'], $this->admin);
    }

    public function testApprovedWithModifiedLimitRequiresTheNewLimit(): void
    {
        $requestId = $this->openRequest();
        try {
            $this->overrides->decide($requestId, ['action' => 'approve_modified'], $this->admin);
            self::fail('modified_limit_value is required');
        } catch (\App\Services\CreditGateException $e) {
            self::assertStringContainsString('modified_limit_value', $e->getMessage());
        }

        $done = $this->overrides->decide($requestId, [
            'action' => 'approve_modified',
            'modified_limit_value' => 2500,
        ], $this->admin);
        self::assertSame(CreditOverrideService::STATUS_APPROVED_MODIFIED, $done['status']);

        $this->expectException(IllegalOverrideTransitionException::class);
        $this->overrides->decide($requestId, ['action' => 'reject', 'decision_note' => 'too late'], $this->admin);
    }

    public function testRejectRequiresANoteAndIsTerminal(): void
    {
        $requestId = $this->openRequest();
        try {
            $this->overrides->decide($requestId, ['action' => 'reject'], $this->admin);
            self::fail('decision_note is required');
        } catch (\App\Services\CreditGateException $e) {
            self::assertStringContainsString('decision note', strtolower($e->getMessage()));
        }

        $done = $this->overrides->decide($requestId, [
            'action' => 'reject',
            'decision_note' => 'Hold this account.',
        ], $this->admin);
        self::assertSame(CreditOverrideService::STATUS_REJECTED, $done['status']);

        $this->expectException(IllegalOverrideTransitionException::class);
        $this->overrides->decide($requestId, ['action' => 'approve'], $this->admin);
    }

    public function testRepCanWithdrawAndAdminCannotRedecide(): void
    {
        $requestId = $this->openRequest();
        $done = $this->overrides->decide($requestId, ['action' => 'withdraw'], $this->sales);
        self::assertSame(CreditOverrideService::STATUS_WITHDRAWN, $done['status']);

        $this->expectException(IllegalOverrideTransitionException::class);
        $this->overrides->decide($requestId, ['action' => 'approve'], $this->admin);
    }

    public function testSnapshotDoesNotChangeWhenLiveLedgerMoves(): void
    {
        $this->silenceOtherLedgerFeeds();
        $partyId = $this->createParty(1000.00);
        $this->putReceivable($partyId, 1050.00);
        $orderId = $this->createBareOrder($partyId);

        $evaluation = $this->gate->evaluate($partyId, $this->companyId, 0);
        $request = $this->overrides->raise($evaluation, $this->sales, 'Need trucks tomorrow', null, $orderId);
        $stored = $this->overrides->present((int)$request['id'], $this->admin);
        $original = (float)$stored['outstanding_snapshot'];

        $this->putReceivable($partyId, 400.00);

        $again = $this->overrides->present((int)$request['id'], $this->admin);
        self::assertEquals($original, (float)$again['outstanding_snapshot']);
        self::assertNotEquals(
            $original,
            $this->gate->evaluate($partyId, $this->companyId, 0)['outstanding']
        );
    }

    public function testExpireOverdueMovesPendingToExpired(): void
    {
        $requestId = $this->openRequest();
        $this->database->execute(
            "UPDATE credit_override_requests SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?",
            [$requestId]
        );
        $count = $this->overrides->expireOverdue();
        self::assertSame(1, $count);
        $row = $this->overrides->present($requestId, $this->admin);
        self::assertSame(CreditOverrideService::STATUS_EXPIRED, $row['status']);
    }

    public function testVolumeByTierIsQueryable(): void
    {
        $this->openRequest();
        $volume = $this->overrides->volumeByTier();
        self::assertArrayHasKey(2, $volume);
        self::assertGreaterThanOrEqual(1, $volume[2]['total']);
    }

    private function openRequest(): int
    {
        $this->silenceOtherLedgerFeeds();
        $partyId = $this->createParty(1000.00);
        $this->putReceivable($partyId, 1050.00);
        $orderId = $this->createBareOrder($partyId);
        $evaluation = $this->gate->evaluate($partyId, $this->companyId, 0);
        self::assertSame(2, (int)$evaluation['tier']);
        $request = $this->overrides->raise($evaluation, $this->sales, 'Need this load', null, $orderId);

        return (int)$request['id'];
    }

    private function createBareOrder(int $partyId): int
    {
        $productId = $this->createProduct();
        $this->database->execute(
            "INSERT INTO orders (company_id, order_no, order_date, product_id, order_qty_trucks, party_id, created_by, status)
             VALUES (?, ?, '2026-08-21', ?, 1, ?, ?, 'pending')",
            [$this->companyId, 'OV-' . $this->uniqueSuffix(), $productId, $partyId, $this->admin['id']]
        );

        return (int)$this->database->lastInsertId();
    }
}
