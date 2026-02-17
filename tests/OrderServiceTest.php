<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Services\OrderService;
use App\Services\DispatchService;
use App\Core\Database;

class OrderServiceTest extends TestCase
{
    private OrderService $orderService;
    private DispatchService $dispatchService;
    private Database $database;
    
    protected function setUp(): void
    {
        // Load test environment
        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
        
        $this->database = new Database();
        $this->orderService = new OrderService();
        $this->dispatchService = new DispatchService();
        
        // Start transaction for test isolation
        $this->database->beginTransaction();
    }
    
    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        $this->database->rollback();
    }
    
    public function testCreateOrder(): void
    {
        $orderData = [
            'order_date' => '2024-10-01',
            'product_id' => 1,
            'order_qty_trucks' => 50,
            'party_id' => 1,
            'created_by' => 1
        ];
        
        $order = $this->orderService->createOrder($orderData);
        
        $this->assertNotNull($order);
        $this->assertEquals($orderData['order_qty_trucks'], $order->orderQtyTrucks);
        $this->assertEquals('pending', $order->status);
        $this->assertNotEmpty($order->orderNo);
        $this->assertStringStartsWith('ORD-', $order->orderNo);
    }
    
    public function testCreateOrderWithInvalidProduct(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Product not found or inactive');
        
        $orderData = [
            'order_date' => '2024-10-01',
            'product_id' => 999, // Non-existent product
            'order_qty_trucks' => 50,
            'party_id' => 1,
            'created_by' => 1
        ];
        
        $this->orderService->createOrder($orderData);
    }
    
    public function testCreateOrderWithInvalidParty(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Party not found or inactive');
        
        $orderData = [
            'order_date' => '2024-10-01',
            'product_id' => 1,
            'order_qty_trucks' => 50,
            'party_id' => 999, // Non-existent party
            'created_by' => 1
        ];
        
        $this->orderService->createOrder($orderData);
    }
    
    public function testUpdateOrderQuantity(): void
    {
        // Create an order first
        $orderData = [
            'order_date' => '2024-10-01',
            'product_id' => 1,
            'order_qty_trucks' => 50,
            'party_id' => 1,
            'created_by' => 1
        ];
        
        $order = $this->orderService->createOrder($orderData);
        
        // Update the quantity
        $updateData = ['order_qty_trucks' => 75];
        $updatedOrder = $this->orderService->updateOrder($order->id, $updateData);
        
        $this->assertEquals(75, $updatedOrder->orderQtyTrucks);
    }
    
    public function testCannotReduceOrderQuantityBelowDispatched(): void
    {
        // Create an order
        $orderData = [
            'order_date' => '2024-10-01',
            'product_id' => 1,
            'order_qty_trucks' => 50,
            'party_id' => 1,
            'created_by' => 1
        ];
        
        $order = $this->orderService->createOrder($orderData);
        
        // Create a dispatch
        $dispatchData = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-02',
            'dispatch_qty_trucks' => 30,
            'dispatched_by' => 1
        ];
        
        $this->dispatchService->createDispatch($dispatchData);
        
        // Try to reduce order quantity below dispatched amount
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot reduce order quantity below dispatched quantity');
        
        $updateData = ['order_qty_trucks' => 25]; // Less than 30 dispatched
        $this->orderService->updateOrder($order->id, $updateData);
    }
    
    public function testOrderStatusUpdatesCorrectly(): void
    {
        // Create an order
        $orderData = [
            'order_date' => '2024-10-01',
            'product_id' => 1,
            'order_qty_trucks' => 50,
            'party_id' => 1,
            'created_by' => 1
        ];
        
        $order = $this->orderService->createOrder($orderData);
        $this->assertEquals('pending', $order->status);
        
        // Create partial dispatch
        $dispatchData = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-02',
            'dispatch_qty_trucks' => 30,
            'dispatched_by' => 1
        ];
        
        $this->dispatchService->createDispatch($dispatchData);
        
        // Check order status is now partial
        $updatedOrder = $this->orderService->getOrderById($order->id);
        $this->assertEquals('partial', $updatedOrder->status);
        
        // Complete the dispatch
        $dispatchData2 = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-03',
            'dispatch_qty_trucks' => 20,
            'dispatched_by' => 1
        ];
        
        $this->dispatchService->createDispatch($dispatchData2);
        
        // Check order status is now completed
        $completedOrder = $this->orderService->getOrderById($order->id);
        $this->assertEquals('completed', $completedOrder->status);
    }
}




