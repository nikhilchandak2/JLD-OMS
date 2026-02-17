<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Services\OrderService;
use App\Services\DispatchService;
use App\Core\Database;

class DispatchServiceTest extends TestCase
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
    
    private function createTestOrder(): \App\Models\Order
    {
        $orderData = [
            'order_date' => '2024-10-01',
            'product_id' => 1,
            'order_qty_trucks' => 50,
            'party_id' => 1,
            'created_by' => 1
        ];
        
        return $this->orderService->createOrder($orderData);
    }
    
    public function testCreateDispatch(): void
    {
        $order = $this->createTestOrder();
        
        $dispatchData = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-02',
            'dispatch_qty_trucks' => 25,
            'vehicle_no' => 'TRK-001',
            'remarks' => 'Test dispatch',
            'dispatched_by' => 1
        ];
        
        $dispatch = $this->dispatchService->createDispatch($dispatchData);
        
        $this->assertNotNull($dispatch);
        $this->assertEquals($dispatchData['dispatch_qty_trucks'], $dispatch->dispatchQtyTrucks);
        $this->assertEquals($dispatchData['vehicle_no'], $dispatch->vehicleNo);
        $this->assertEquals($dispatchData['remarks'], $dispatch->remarks);
    }
    
    public function testCannotDispatchMoreThanOrdered(): void
    {
        $order = $this->createTestOrder(); // 50 trucks
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot dispatch 60 trucks');
        
        $dispatchData = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-02',
            'dispatch_qty_trucks' => 60, // More than ordered
            'dispatched_by' => 1
        ];
        
        $this->dispatchService->createDispatch($dispatchData);
    }
    
    public function testCannotDispatchMoreThanRemaining(): void
    {
        $order = $this->createTestOrder(); // 50 trucks
        
        // First dispatch
        $dispatchData1 = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-02',
            'dispatch_qty_trucks' => 30,
            'dispatched_by' => 1
        ];
        
        $this->dispatchService->createDispatch($dispatchData1);
        
        // Try to dispatch more than remaining (20)
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot dispatch 25 trucks');
        
        $dispatchData2 = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-03',
            'dispatch_qty_trucks' => 25, // Only 20 remaining
            'dispatched_by' => 1
        ];
        
        $this->dispatchService->createDispatch($dispatchData2);
    }
    
    public function testMultipleDispatchesForSameOrder(): void
    {
        $order = $this->createTestOrder(); // 50 trucks
        
        // First dispatch
        $dispatchData1 = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-02',
            'dispatch_qty_trucks' => 20,
            'dispatched_by' => 1
        ];
        
        $dispatch1 = $this->dispatchService->createDispatch($dispatchData1);
        $this->assertNotNull($dispatch1);
        
        // Second dispatch
        $dispatchData2 = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-03',
            'dispatch_qty_trucks' => 15,
            'dispatched_by' => 1
        ];
        
        $dispatch2 = $this->dispatchService->createDispatch($dispatchData2);
        $this->assertNotNull($dispatch2);
        
        // Third dispatch (completing the order)
        $dispatchData3 = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-04',
            'dispatch_qty_trucks' => 15, // Total: 20 + 15 + 15 = 50
            'dispatched_by' => 1
        ];
        
        $dispatch3 = $this->dispatchService->createDispatch($dispatchData3);
        $this->assertNotNull($dispatch3);
        
        // Verify order is now completed
        $updatedOrder = $this->orderService->getOrderById($order->id);
        $this->assertEquals('completed', $updatedOrder->status);
        $this->assertEquals(50, $updatedOrder->totalDispatched);
    }
    
    public function testUpdateDispatchQuantity(): void
    {
        $order = $this->createTestOrder(); // 50 trucks
        
        $dispatchData = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-02',
            'dispatch_qty_trucks' => 25,
            'dispatched_by' => 1
        ];
        
        $dispatch = $this->dispatchService->createDispatch($dispatchData);
        
        // Update dispatch quantity
        $updateData = ['dispatch_qty_trucks' => 30];
        $updatedDispatch = $this->dispatchService->updateDispatch($dispatch->id, $updateData);
        
        $this->assertEquals(30, $updatedDispatch->dispatchQtyTrucks);
    }
    
    public function testCannotUpdateDispatchToExceedOrderQuantity(): void
    {
        $order = $this->createTestOrder(); // 50 trucks
        
        // Create two dispatches
        $dispatchData1 = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-02',
            'dispatch_qty_trucks' => 25,
            'dispatched_by' => 1
        ];
        
        $dispatch1 = $this->dispatchService->createDispatch($dispatchData1);
        
        $dispatchData2 = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-03',
            'dispatch_qty_trucks' => 20,
            'dispatched_by' => 1
        ];
        
        $dispatch2 = $this->dispatchService->createDispatch($dispatchData2);
        
        // Try to update first dispatch to exceed total order quantity
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update dispatch quantity to 35');
        
        $updateData = ['dispatch_qty_trucks' => 35]; // 35 + 20 = 55 > 50
        $this->dispatchService->updateDispatch($dispatch1->id, $updateData);
    }
    
    public function testDispatchValidation(): void
    {
        $order = $this->createTestOrder();
        
        // Test missing dispatch date
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Validation failed');
        
        $dispatchData = [
            'order_id' => $order->id,
            'dispatch_date' => '', // Empty date
            'dispatch_qty_trucks' => 25,
            'dispatched_by' => 1
        ];
        
        $this->dispatchService->createDispatch($dispatchData);
    }
    
    public function testDispatchWithZeroQuantity(): void
    {
        $order = $this->createTestOrder();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Validation failed');
        
        $dispatchData = [
            'order_id' => $order->id,
            'dispatch_date' => '2024-10-02',
            'dispatch_qty_trucks' => 0, // Zero quantity
            'dispatched_by' => 1
        ];
        
        $this->dispatchService->createDispatch($dispatchData);
    }
}




