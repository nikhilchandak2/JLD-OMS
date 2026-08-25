<?php

namespace Tests;

use App\Services\ExitCriteriaNotMetException;
use App\Services\HandoffAuthorizationException;
use App\Services\HandoffException;
use App\Services\HandoffImmutableException;
use App\Services\HandoffService;

class HandoffTest extends CrmPipelineTestCase
{
    private HandoffService $handoffs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handoffs = new HandoffService();
    }

    public function testEachRequiredSalesFieldAbsenceIsRejectedAndNothingIsPersisted(): void
    {
        $dealId = (int)$this->captureDeal()['id'];
        $base = $this->validSalesPayload();

        foreach (array_keys($base) as $field) {
            $payload = $base;
            unset($payload[$field]);
            $before = $this->packetCount();
            try {
                $this->handoffs->create([
                    'packet_type' => HandoffService::TYPE_SALES_TO_DISPATCH,
                    'deal_id' => $dealId,
                    'payload' => $payload,
                ], $this->admin);
                self::fail("Missing {$field} must not persist.");
            } catch (HandoffException $e) {
                self::assertStringContainsString($field, $e->getMessage());
                self::assertSame($before, $this->packetCount(), "Missing {$field} left a row behind.");
            }
        }
    }

    public function testEachRequiredAccountsFieldAbsenceIsRejectedAndNothingIsPersisted(): void
    {
        $orderId = $this->createOrder();
        $base = $this->validAccountsPayload();

        foreach (array_keys($base) as $field) {
            $payload = $base;
            unset($payload[$field]);
            $before = $this->packetCount();
            try {
                $this->handoffs->create([
                    'packet_type' => HandoffService::TYPE_DISPATCH_TO_ACCOUNTS,
                    'order_id' => $orderId,
                    'payload' => $payload,
                ], $this->admin);
                self::fail("Missing {$field} must not persist.");
            } catch (HandoffException $e) {
                self::assertStringContainsString($field, $e->getMessage());
                self::assertSame($before, $this->packetCount(), "Missing {$field} left a row behind.");
            }
        }
    }

    public function testUnknownSchemaVersionIsRejected(): void
    {
        $this->expectException(HandoffException::class);
        $this->handoffs->create([
            'packet_type' => HandoffService::TYPE_SALES_TO_DISPATCH,
            'deal_id' => (int)$this->captureDeal()['id'],
            'schema_version' => 99,
            'payload' => $this->validSalesPayload(),
        ], $this->admin);
    }

    public function testStageSixToSevenRequiresAValidSalesPacket(): void
    {
        $dealId = (int)$this->captureDeal()['id'];
        $this->advanceTo($dealId, 6);
        $this->stages->saveCriteriaValues($dealId, ['final_terms_agreed' => 'Agreed on site'], $this->admin);
        $deal = $this->database->fetch("SELECT party_id FROM crm_deals WHERE id = ?", [$dealId]);
        $this->database->execute("UPDATE parties SET credit_limit = 10000000 WHERE id = ?", [(int)$deal['party_id']]);

        try {
            $this->stages->advance($dealId, $this->admin);
            self::fail('Stage 6 → 7 must require a Sales→Dispatch packet.');
        } catch (ExitCriteriaNotMetException $e) {
            self::assertStringContainsString('Handoff packet', $e->getMessage());
        }

        $this->handoffs->create([
            'packet_type' => HandoffService::TYPE_SALES_TO_DISPATCH,
            'deal_id' => $dealId,
            'payload' => $this->validSalesPayload(),
        ], $this->admin);

        $moved = $this->stages->advance($dealId, $this->admin);
        self::assertSame(7, (int)$moved['stage']);
    }

    public function testMutatingAnAcknowledgedPacketThrows(): void
    {
        $created = $this->handoffs->create([
            'packet_type' => HandoffService::TYPE_SALES_TO_DISPATCH,
            'deal_id' => (int)$this->captureDeal()['id'],
            'payload' => $this->validSalesPayload(),
        ], $this->admin);
        $this->handoffs->acknowledge((int)$created['id'], $this->actor('dispatch'));

        try {
            $this->handoffs->updatePayload((int)$created['id'], $this->validSalesPayload(), $this->admin);
            self::fail('Acknowledged packets must be immutable.');
        } catch (HandoffImmutableException $e) {
            self::assertStringContainsString('acknowledged', strtolower($e->getMessage()));
        }
    }

    public function testSupersedeCreatesANewPacketAndLinksTheOldOne(): void
    {
        $created = $this->handoffs->create([
            'packet_type' => HandoffService::TYPE_SALES_TO_DISPATCH,
            'deal_id' => (int)$this->captureDeal()['id'],
            'payload' => $this->validSalesPayload(),
        ], $this->admin);
        $this->handoffs->acknowledge((int)$created['id'], $this->actor('dispatch'));

        $replacement = $this->validSalesPayload();
        $replacement['packing'] = '25 kg bags';
        $new = $this->handoffs->supersede((int)$created['id'], [
            'reason' => 'Customer changed packing',
            'payload' => $replacement,
        ], $this->admin);

        self::assertNotSame((int)$created['id'], (int)$new['id']);
        self::assertSame('25 kg bags', $new['payload']['packing']);
        self::assertSame('Customer changed packing', $new['supersession_reason']);
        self::assertNull($new['acknowledged_at']);

        $old = $this->database->fetch("SELECT superseded_by_packet_id FROM handoff_packets WHERE id = ?", [(int)$created['id']]);
        self::assertSame((int)$new['id'], (int)$old['superseded_by_packet_id']);
    }

    public function testCreateAcknowledgeAndSupersedeAreAudited(): void
    {
        $created = $this->handoffs->create([
            'packet_type' => HandoffService::TYPE_SALES_TO_DISPATCH,
            'deal_id' => (int)$this->captureDeal()['id'],
            'payload' => $this->validSalesPayload(),
        ], $this->admin);
        $id = (int)$created['id'];
        self::assertSame(1, $this->auditCount('handoff_packets', $id));

        $this->handoffs->acknowledge($id, $this->actor('dispatch'));
        self::assertSame(2, $this->auditCount('handoff_packets', $id));

        $new = $this->handoffs->supersede($id, [
            'reason' => 'Qty correction',
            'payload' => $this->validSalesPayload(),
        ], $this->admin);
        self::assertSame(3, $this->auditCount('handoff_packets', $id), 'Supersession must audit the old row.');
        self::assertGreaterThanOrEqual(1, $this->auditCount('handoff_packets', (int)$new['id']));
    }

    public function testPdfIsANonEmptyTcpdfDocument(): void
    {
        $created = $this->handoffs->create([
            'packet_type' => HandoffService::TYPE_DISPATCH_TO_ACCOUNTS,
            'order_id' => $this->createOrder(),
            'payload' => $this->validAccountsPayload(),
        ], $this->admin);
        $file = $this->handoffs->pdfBytes((int)$created['id'], $this->admin);

        self::assertNotSame('', $file['bytes']);
        self::assertStringStartsWith('%PDF', $file['bytes']);
        self::assertStringEndsWith('.pdf', $file['filename']);
    }

    public function testDispatchCannotCreateASalesPacket(): void
    {
        $this->expectException(HandoffAuthorizationException::class);
        $this->handoffs->create([
            'packet_type' => HandoffService::TYPE_SALES_TO_DISPATCH,
            'deal_id' => (int)$this->captureDeal()['id'],
            'payload' => $this->validSalesPayload(),
        ], $this->actor('dispatch'));
    }

    public function testDispatchViewTemplateHasNoReentryFieldsForTheReceivingTeam(): void
    {
        $page = file_get_contents(dirname(__DIR__) . '/templates/dispatch-handoffs.php');
        self::assertStringContainsString('No packet field is a re-entry input', $page);
        self::assertStringContainsString('Acknowledge only', $page);
        $js = file_get_contents(dirname(__DIR__) . '/public/js/handoff.js');
        self::assertStringContainsString('No packet field is a re-entry input', $js);
    }

    /** @return array<string,mixed> */
    private function validSalesPayload(): array
    {
        return [
            'grades' => [['grade_code' => 'J-11', 'spec' => '12mm body']],
            'quantity_tonnes' => 40,
            'packing' => '50 kg bags',
            'delivery_timeline' => 'Within 7 days of PO',
            'delivery_terms' => 'ex_works',
            'special_handling_notes' => 'Label bags with batch',
        ];
    }

    /** @return array<string,mixed> */
    private function validAccountsPayload(): array
    {
        return [
            'delivery_date' => '2026-08-20',
            'quote_reference' => 'Q-2026-014',
            'agreed_terms' => 'Ex-works, 30 days',
            'invoice_reference' => 'INV-1001',
        ];
    }

    private function packetCount(): int
    {
        $row = $this->database->fetch("SELECT COUNT(*) AS c FROM handoff_packets");

        return (int)$row['c'];
    }

    private function createOrder(): int
    {
        $companyId = $this->createCompany();
        $productId = $this->createProduct();
        $partyId = $this->createParty();
        $suffix = $this->uniqueSuffix();
        $this->database->execute(
            "INSERT INTO orders (company_id, order_no, order_date, product_id, order_qty_trucks, tons_per_truck, party_id, created_by, status)
             VALUES (?, ?, '2026-08-20', ?, 1, 40, ?, ?, 'pending')",
            [$companyId, "HO-{$suffix}", $productId, $partyId, $this->admin['id']]
        );

        return (int)$this->database->lastInsertId();
    }
}
