<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\OrderRepository;
use App\Repositories\PartyRepository;
use App\Repositories\ProductRepository;
use App\Services\DispatchService;
use App\Models\Dispatch;

class BusyIntegrationService
{
    private Database $database;
    private OrderRepository $orderRepository;
    private PartyRepository $partyRepository;
    private ProductRepository $productRepository;
    private DispatchService $dispatchService;
    
    public function __construct()
    {
        $this->database = new Database();
        $this->orderRepository = new OrderRepository();
        $this->partyRepository = new PartyRepository();
        $this->productRepository = new ProductRepository();
        $this->dispatchService = new DispatchService();
    }
    
    /**
     * Process invoice webhook from Busy software and create dispatch
     */
    public function processInvoiceWebhook(array $invoiceData): array
    {
        // Log the incoming webhook for debugging
        $this->logWebhookData($invoiceData);
        
        // Validate required fields
        $this->validateInvoiceData($invoiceData);
        
        // Map invoice data to our system
        $mappedData = $this->mapInvoiceData($invoiceData);
        
        // Find or create the order
        $order = $this->findOrCreateOrder($mappedData);
        
        // Create the dispatch
        $dispatch = $this->createDispatchFromInvoice($order, $mappedData);
        
        return [
            'order_id' => $order->id,
            'order_no' => $order->orderNo,
            'dispatch_id' => $dispatch->id,
            'dispatch_qty' => $dispatch->dispatchQtyTrucks,
            'party_name' => $order->partyName,
            'invoice_no' => $mappedData['invoice_no']
        ];
    }
    
    /**
     * Manual sync for processing multiple invoices
     */
    public function syncInvoicesManually(array $filters): array
    {
        // This would connect to Busy software's database or API
        // For now, we'll create a placeholder that can be customized
        
        $results = [];
        
        // Example: If Busy has an API endpoint to get invoices
        // $invoices = $this->fetchInvoicesFromBusy($filters);
        
        // For demonstration, we'll show how it would work
        $sampleInvoices = $this->getSampleInvoiceData($filters);
        
        foreach ($sampleInvoices as $invoiceData) {
            try {
                $result = $this->processInvoiceWebhook($invoiceData);
                $results[] = [
                    'status' => 'success',
                    'invoice_no' => $invoiceData['invoice_no'],
                    'result' => $result
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'status' => 'error',
                    'invoice_no' => $invoiceData['invoice_no'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return [
            'processed' => count($results),
            'successful' => count(array_filter($results, fn($r) => $r['status'] === 'success')),
            'failed' => count(array_filter($results, fn($r) => $r['status'] === 'error')),
            'details' => $results
        ];
    }
    
    /**
     * Get integration status and statistics
     */
    public function getIntegrationStatus(): array
    {
        // Get statistics about webhook processing
        $sql = "
            SELECT 
                COUNT(*) as total_webhooks,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as successful,
                COUNT(CASE WHEN status = 'error' THEN 1 END) as failed,
                MAX(created_at) as last_webhook
            FROM busy_webhook_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";
        
        $stats = $this->database->fetch($sql) ?: [
            'total_webhooks' => 0,
            'successful' => 0,
            'failed' => 0,
            'last_webhook' => null
        ];
        
        return [
            'status' => 'active',
            'last_30_days' => $stats,
            'webhook_url' => $this->getWebhookUrl(),
            'authentication' => 'API Key required in X-API-KEY header'
        ];
    }
    
    /**
     * Validate incoming invoice data
     */
    private function validateInvoiceData(array $data): void
    {
        $requiredFields = [
            'invoice_no',
            'invoice_date',
            'party_name',
            'product_name',
            'quantity',
            'vehicle_no' // Optional but recommended
        ];
        
        $missing = [];
        foreach ($requiredFields as $field) {
            if (empty($data[$field]) && $field !== 'vehicle_no') {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            throw new \Exception('Missing required fields: ' . implode(', ', $missing));
        }
        
        // Validate data types
        if (!is_numeric($data['quantity']) || $data['quantity'] <= 0) {
            throw new \Exception('Quantity must be a positive number');
        }
        
        if (!$this->isValidDate($data['invoice_date'])) {
            throw new \Exception('Invalid invoice date format. Expected YYYY-MM-DD');
        }
    }
    
    /**
     * Map Busy invoice data to our system format
     */
    private function mapInvoiceData(array $invoiceData): array
    {
        return [
            'invoice_no' => $invoiceData['invoice_no'],
            'invoice_date' => $invoiceData['invoice_date'],
            'party_name' => trim($invoiceData['party_name']),
            'product_name' => trim($invoiceData['product_name']),
            'quantity' => (int)$invoiceData['quantity'],
            'vehicle_no' => $invoiceData['vehicle_no'] ?? null,
            'remarks' => $invoiceData['remarks'] ?? "Auto-created from Busy invoice #{$invoiceData['invoice_no']}",
            'company_name' => $invoiceData['company_name'] ?? null
        ];
    }
    
    /**
     * Find existing order or create new one based on invoice data
     */
    private function findOrCreateOrder(array $data): object
    {
        // Try to find existing order by party and product
        $party = $this->findOrCreateParty($data['party_name']);
        $product = $this->findOrCreateProduct($data['product_name']);
        
        // Look for recent pending orders for this party and product
        $existingOrder = $this->findRecentPendingOrder($party->id, $product->id);
        
        if ($existingOrder) {
            return $existingOrder;
        }
        
        // Create new order
        return $this->createOrderFromInvoice($data, $party, $product);
    }
    
    /**
     * Find or create party
     */
    private function findOrCreateParty(string $partyName): object
    {
        $parties = $this->partyRepository->findAll(['search' => $partyName]);
        
        foreach ($parties as $party) {
            if (strcasecmp($party->name, $partyName) === 0) {
                return $party;
            }
        }
        
        // Create new party
        $partyData = [
            'name' => $partyName,
            'contact_person' => '',
            'phone' => '',
            'email' => '',
            'address' => '',
            'created_by' => 1 // System user
        ];
        
        return $this->partyRepository->create($partyData);
    }
    
    /**
     * Find or create product
     */
    private function findOrCreateProduct(string $productName): object
    {
        $products = $this->productRepository->findAll(['search' => $productName]);
        
        foreach ($products as $product) {
            if (strcasecmp($product->name, $productName) === 0) {
                return $product;
            }
        }
        
        // Create new product
        $productData = [
            'name' => $productName,
            'description' => "Auto-created from Busy integration",
            'unit' => 'Trucks',
            'created_by' => 1 // System user
        ];
        
        return $this->productRepository->create($productData);
    }
    
    /**
     * Find recent pending order for party and product
     */
    private function findRecentPendingOrder(int $partyId, int $productId): ?object
    {
        $sql = "
            SELECT * FROM orders 
            WHERE party_id = ? 
            AND product_id = ? 
            AND status IN ('pending', 'partial') 
            AND order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY order_date DESC 
            LIMIT 1
        ";
        
        $orderData = $this->database->fetch($sql, [$partyId, $productId]);
        
        if ($orderData) {
            return $this->orderRepository->findById($orderData['id']);
        }
        
        return null;
    }
    
    /**
     * Create new order from invoice data
     */
    private function createOrderFromInvoice(array $data, object $party, object $product): object
    {
        $orderData = [
            'company_id' => 1, // Default company, can be mapped from invoice data
            'party_id' => $party->id,
            'product_id' => $product->id,
            'order_qty_trucks' => $data['quantity'],
            'priority' => 'normal',
            'is_recurring' => false,
            'created_by' => 1 // System user
        ];
        
        return $this->orderRepository->create($orderData);
    }
    
    /**
     * Create dispatch from invoice data
     */
    private function createDispatchFromInvoice(object $order, array $data): object
    {
        $dispatchData = [
            'order_id' => $order->id,
            'dispatch_date' => $data['invoice_date'],
            'dispatch_qty_trucks' => $data['quantity'],
            'vehicle_no' => $data['vehicle_no'],
            'remarks' => $data['remarks'],
            'dispatched_by' => 1 // System user
        ];
        
        return $this->dispatchService->createDispatch($dispatchData);
    }
    
    /**
     * Log webhook data for debugging and audit
     */
    private function logWebhookData(array $data): void
    {
        $sql = "
            INSERT INTO busy_webhook_logs (
                invoice_no, 
                webhook_data, 
                status, 
                created_at
            ) VALUES (?, ?, 'received', NOW())
        ";
        
        try {
            $this->database->execute($sql, [
                $data['invoice_no'] ?? 'unknown',
                json_encode($data)
            ]);
        } catch (\Exception $e) {
            // Don't fail the webhook if logging fails
            error_log("Failed to log webhook data: " . $e->getMessage());
        }
    }
    
    /**
     * Get webhook URL for configuration
     */
    private function getWebhookUrl(): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        return "{$protocol}://{$host}/api/busy/webhook";
    }
    
    /**
     * Sample invoice data for testing
     */
    private function getSampleInvoiceData(array $filters): array
    {
        return [
            [
                'invoice_no' => 'INV-2025-001',
                'invoice_date' => '2025-10-03',
                'party_name' => 'ABC Construction Ltd',
                'product_name' => 'Sand',
                'quantity' => 5,
                'vehicle_no' => 'MH12AB1234',
                'company_name' => 'JLD Minerals Pvt. Ltd.'
            ],
            [
                'invoice_no' => 'INV-2025-002',
                'invoice_date' => '2025-10-03',
                'party_name' => 'XYZ Builders',
                'product_name' => 'Gravel',
                'quantity' => 3,
                'vehicle_no' => 'MH12CD5678',
                'company_name' => 'JLD Minerals Pvt. Ltd.'
            ]
        ];
    }
    
    /**
     * Validate date format
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}


