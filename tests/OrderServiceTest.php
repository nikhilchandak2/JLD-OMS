<?php

namespace Tests;

use App\Services\DispatchService;
use App\Services\OrderService;

class OrderServiceTest extends DatabaseTestCase
{
    private OrderService $orderService;
    private DispatchService $dispatchService;
    private int $companyId;
    private int $partyId;
    private int $productId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderService = new OrderService();
        $this->dispatchService = new DispatchService();

        $this->companyId = $this->createCompany();
        $this->partyId = $this->createParty();
        $this->productId = $this->createProduct();
        $this->userId = $this->createUser('order_processing')['id'];
    }

    private function orderData(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->companyId,
            'order_date' => '2024-10-01',
            'product_id' => $this->productId,
            'order_qty_trucks' => 50,
            'party_id' => $this->partyId,
            'created_by' => $this->userId,
        ], $overrides);
    }

    public function testCreateOrder(): void
    {
        $order = $this->orderService->createOrder($this->orderData());

        $this->assertNotNull($order);
        $this->assertEquals(50, $order->orderQtyTrucks);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals($this->companyId, $order->companyId);
        $this->assertNotEmpty($order->orderNo);
        $this->assertMatchesRegularExpression('/^JLD-\d{8}$/', $order->orderNo);
    }

    public function testCreateOrderWithInvalidProduct(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Product not found or inactive');

        $this->orderService->createOrder($this->orderData(['product_id' => 999999]));
    }

    public function testCreateOrderWithInvalidParty(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Party not found or inactive');

        $this->orderService->createOrder($this->orderData(['party_id' => 999999]));
    }

    public function testCreateOrderInWeightModeDerivesTrucks(): void
    {
        $order = $this->orderService->createOrder($this->orderData([
            'order_qty_mode' => 'weight',
            'order_weight_tons' => 90,
            'tons_per_truck' => 30,
        ]));

        $this->assertEquals('weight', $order->orderQtyMode);
        $this->assertEquals(3, $order->orderQtyTrucks);
        $this->assertEquals(90.0, (float)$order->orderWeightTons);
    }

    public function testUpdateOrderQuantity(): void
    {
        $order = $this->orderService->createOrder($this->orderData());

        $updatedOrder = $this->orderService->updateOrder($order->id, ['order_qty_trucks' => 75]);

        $this->assertEquals(75, $updatedOrder->orderQtyTrucks);
    }

    public function testCannotReduceOrderQuantityBelowDispatched(): void
    {
        $order = $this->orderService->createOrder($this->orderData());

        $this->dispatchService->createDispatch([
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-02',
            'dispatch_qty_trucks' => 30,
            'product_rate' => 1200,
            'dispatched_by' => $this->userId,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot reduce order quantity below dispatched quantity');

        $this->orderService->updateOrder($order->id, ['order_qty_trucks' => 25]);
    }

    public function testOrderStatusUpdatesCorrectly(): void
    {
        $order = $this->orderService->createOrder($this->orderData());
        $this->assertEquals('pending', $order->status);

        $this->dispatchService->createDispatch([
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-02',
            'dispatch_qty_trucks' => 30,
            'product_rate' => 1200,
            'dispatched_by' => $this->userId,
        ]);

        $this->assertEquals('partial', $this->orderService->getOrderById($order->id)->status);

        $this->dispatchService->createDispatch([
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-03',
            'dispatch_qty_trucks' => 20,
            'product_rate' => 1200,
            'dispatched_by' => $this->userId,
        ]);

        $this->assertEquals('completed', $this->orderService->getOrderById($order->id)->status);
    }

    public function testOrderIsBlockedWhenPartyIsOverItsCreditLimit(): void
    {
        $partyId = $this->createParty(1000.0);
        $this->database->execute(
            "INSERT INTO crm_receivable_entries (party_id, entry_type, amount, entry_date, description, created_by)
             VALUES (?, 'invoice', 5000, '2024-09-01', 'Test invoice', ?)",
            [$partyId, $this->userId]
        );

        $this->expectException(\App\Services\CreditLimitExceededException::class);

        $this->orderService->createOrder($this->orderData(['party_id' => $partyId]));
    }

    public function testAdminCanCreateOrderForOverLimitParty(): void
    {
        $partyId = $this->createParty(1000.0);
        $adminId = $this->createUser('admin')['id'];
        $this->database->execute(
            "INSERT INTO crm_receivable_entries (party_id, entry_type, amount, entry_date, description, created_by)
             VALUES (?, 'invoice', 5000, '2024-09-01', 'Test invoice', ?)",
            [$partyId, $adminId]
        );

        $order = $this->orderService->createOrder($this->orderData([
            'party_id' => $partyId,
            'created_by' => $adminId,
        ]));

        $this->assertEquals('pending', $order->status);
    }

    public function testRecurringOrderCreatesScheduledDeliveries(): void
    {
        $order = $this->orderService->createOrder($this->orderData([
            'order_qty_trucks' => 6,
            'is_recurring' => true,
            'delivery_frequency_days' => 7,
            'trucks_per_delivery' => 2,
        ]));

        $deliveries = $this->database->fetchAll(
            "SELECT id FROM scheduled_deliveries WHERE order_id = ?",
            [$order->id]
        );

        $this->assertNotEmpty($deliveries);
    }
}
