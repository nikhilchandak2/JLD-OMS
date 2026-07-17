<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Order;
use App\Repositories\OrderRepository;
use App\Repositories\PartyRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\DispatchRepository;
use App\Repositories\BusyDailyInvoiceRepository;
use App\Support\CompanyContext;
use App\Support\IndianDate;
use App\Support\OrderSchema;

class BusyIntegrationService
{
    private Database $database;
    private OrderRepository $orderRepository;
    private PartyRepository $partyRepository;
    private ProductRepository $productRepository;
    private CompanyRepository $companyRepository;
    private DispatchRepository $dispatchRepository;
    private BusyDailyInvoiceRepository $busyDailyInvoiceRepository;
    private DispatchService $dispatchService;

    public function __construct()
    {
        $this->database = new Database();
        $this->orderRepository = new OrderRepository();
        $this->partyRepository = new PartyRepository();
        $this->productRepository = new ProductRepository();
        $this->companyRepository = new CompanyRepository();
        $this->dispatchRepository = new DispatchRepository();
        $this->busyDailyInvoiceRepository = new BusyDailyInvoiceRepository();
        $this->dispatchService = new DispatchService();
    }

    /**
     * Process one Busy invoice and create or update the linked dispatch.
     * Unmatched invoices are still saved to busy_daily_invoices (mapping_status=unmapped).
     *
     * @return array{action: string, invoice_no: string, party_name: string, mapping_status: string, order_id?: int, order_no?: string, dispatch_id?: int}
     */
    public function processInvoice(array $invoiceData, ?int $processedByUserId = null, ?int $defaultCompanyId = null): array
    {
        $logId = $this->logWebhookData($invoiceData, 'processing');

        try {
            $this->validateInvoiceData($invoiceData);
            $mapped = $this->mapInvoiceData($invoiceData);
            $companyId = $this->resolveCompanyId($mapped, $defaultCompanyId);
            $order = $this->findMatchingOrder($mapped, $companyId);

            if (!$order) {
                $party = $this->findPartyByName($mapped['party_name']);
                $pendingCount = $party
                    ? count($this->findPendingOrdersForInvoiceParty((int)$party->id, $companyId, (int)$mapped['quantity']))
                    : 0;
                $billingHint = OrderSchema::hasBillingPartyColumns()
                    ? ' If the order uses bill-to-another-party, ensure the invoice Billed-to name matches the billing party on the order.'
                    : '';
                $hint = $pendingCount > 1
                    ? ' This party has multiple pending orders — include Order No in the invoice or ensure the product name matches.'
                    : ' Create the order first or include Order No in the invoice export.';
                $message = 'No matching pending order found for party "' . $mapped['party_name'] .
                    '", product "' . $mapped['product_name'] . '".' . $hint . $billingHint;

                $dailyId = $this->upsertDailyInvoiceRecord($mapped, $companyId, 'unmapped', $message, null, null, $processedByUserId);
                $this->updateWebhookLog($logId, 'error', $message);

                return [
                    'action' => 'unmapped',
                    'mapping_status' => 'unmapped',
                    'invoice_no' => $mapped['invoice_no'],
                    'party_name' => $mapped['party_name'],
                    'product_name' => $mapped['product_name'],
                    'invoice_date' => $mapped['invoice_date'],
                    'dispatch_qty' => $mapped['quantity'],
                    'daily_invoice_id' => $dailyId,
                    'error' => $message,
                ];
            }

            $result = $this->upsertDispatchFromInvoice($order, $mapped, $processedByUserId);
            $this->upsertDailyInvoiceRecord(
                $mapped,
                $companyId,
                'mapped',
                null,
                (int)$result['order_id'],
                (int)$result['dispatch_id'],
                $processedByUserId
            );
            $this->updateWebhookLog($logId, 'success', null);

            return array_merge($result, ['mapping_status' => 'mapped']);
        } catch (\Throwable $e) {
            $this->updateWebhookLog($logId, 'error', $e->getMessage());
            $this->trySaveDailyInvoiceOnError($invoiceData, $defaultCompanyId, $e->getMessage(), $processedByUserId);
            throw $e;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $invoices
     */
    public function processInvoicesBatch(array $invoices, ?int $processedByUserId = null, ?int $defaultCompanyId = null): array
    {
        $details = [];
        foreach ($invoices as $invoiceData) {
            $invoiceNo = (string)($invoiceData['invoice_no'] ?? 'unknown');
            try {
                $result = $this->processInvoice($invoiceData, $processedByUserId, $defaultCompanyId);
                $status = ($result['mapping_status'] ?? '') === 'unmapped' ? 'unmapped' : 'success';
                $details[] = array_merge(['status' => $status, 'invoice_no' => $invoiceNo], $result);
            } catch (\Throwable $e) {
                $details[] = [
                    'status' => 'error',
                    'invoice_no' => $invoiceNo,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'processed' => count($details),
            'successful' => count(array_filter($details, fn($r) => $r['status'] === 'success')),
            'unmapped' => count(array_filter($details, fn($r) => $r['status'] === 'unmapped')),
            'failed' => count(array_filter($details, fn($r) => $r['status'] === 'error')),
            'details' => $details,
        ];
    }

    /**
     * List Busy invoices for the daily dispatches page (mapped + unmapped).
     *
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, summary: array<string, int|float>, pagination: array<string, int>}
     */
    public function listDailyInvoices(array $filters): array
    {
        if (!$this->busyDailyInvoicesTableExists()) {
            throw new \RuntimeException(
                'Daily invoices table is missing. Run: php scripts/migrate.php'
            );
        }

        $result = $this->busyDailyInvoiceRepository->findDaily($filters);
        $limit = isset($filters['limit']) ? (int)$filters['limit'] : count($result['rows']);
        $offset = isset($filters['offset']) ? (int)$filters['offset'] : 0;

        return [
            'rows' => array_map([$this, 'formatDailyInvoiceRow'], $result['rows']),
            'total' => $result['total'],
            'summary' => $result['summary'],
            'pagination' => [
                'total' => $result['total'],
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /** @deprecated Use processInvoice() */
    public function processInvoiceWebhook(array $invoiceData): array
    {
        return $this->processInvoice($invoiceData, null, CompanyContext::getActiveCompanyId());
    }

    public function syncInvoicesManually(array $filters): array
    {
        return $this->processInvoicesBatch(
            $this->getSampleInvoiceData($filters),
            null,
            CompanyContext::getActiveCompanyId()
        );
    }

    public function getIntegrationStatus(): array
    {
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
            'last_webhook' => null,
        ];

        return [
            'status' => 'active',
            'last_30_days' => $stats,
            'webhook_url' => $this->getWebhookUrl(),
            'upload_endpoint' => '/api/busy/invoices/upload',
            'authentication' => 'API Key required in X-API-KEY header for webhook; session auth for upload',
        ];
    }

    public function getRecentLogs(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $sql = "
            SELECT id, invoice_no, status, error_message, processed_at, created_at
            FROM busy_webhook_logs
            ORDER BY id DESC
            LIMIT ?
        ";
        return $this->database->fetchAll($sql, [$limit]);
    }

    private function validateInvoiceData(array $data): void
    {
        $required = ['invoice_no', 'invoice_date', 'party_name', 'product_name', 'product_rate'];
        $missing = [];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            throw new \InvalidArgumentException('Missing required fields: ' . implode(', ', $missing));
        }

        $qty = isset($data['quantity']) ? (int)$data['quantity'] : 1;
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Quantity (trucks) must be a positive number');
        }

        if (!is_numeric($data['product_rate']) || (float)$data['product_rate'] <= 0) {
            throw new \InvalidArgumentException('Product rate per ton must be a positive number');
        }

        if (!$this->isValidDate((string)$data['invoice_date'])) {
            throw new \InvalidArgumentException('Invalid invoice date format. Expected DD/MM/YYYY or YYYY-MM-DD');
        }
    }

    private function mapInvoiceData(array $invoiceData): array
    {
        return [
            'invoice_no' => trim((string)$invoiceData['invoice_no']),
            'invoice_date' => IndianDate::toStorage((string)$invoiceData['invoice_date']),
            'party_name' => trim((string)$invoiceData['party_name']),
            'product_name' => trim((string)$invoiceData['product_name']),
            'quantity' => max(1, (int)($invoiceData['quantity'] ?? 1)),
            'product_rate' => (float)$invoiceData['product_rate'],
            'loading_weight_tons' => isset($invoiceData['loading_weight_tons']) && $invoiceData['loading_weight_tons'] !== ''
                ? (float)$invoiceData['loading_weight_tons']
                : null,
            'order_no' => !empty($invoiceData['order_no']) ? trim((string)$invoiceData['order_no']) : null,
            'vehicle_no' => !empty($invoiceData['vehicle_no']) ? trim((string)$invoiceData['vehicle_no']) : null,
            'rawana_no' => !empty($invoiceData['rawana_no']) ? trim((string)$invoiceData['rawana_no']) : null,
            'eway_bill_no' => !empty($invoiceData['eway_bill_no']) ? trim((string)$invoiceData['eway_bill_no']) : null,
            'company_name' => !empty($invoiceData['company_name']) ? trim((string)$invoiceData['company_name']) : null,
            'remarks' => $invoiceData['remarks'] ?? ('Imported from Busy invoice #' . $invoiceData['invoice_no']),
        ];
    }

    /**
     * @param array<string, mixed> $mapped
     */
    private function upsertDailyInvoiceRecord(
        array $mapped,
        ?int $companyId,
        string $mappingStatus,
        ?string $errorMessage,
        ?int $orderId,
        ?int $dispatchId,
        ?int $uploadedBy
    ): int {
        if (!$this->busyDailyInvoicesTableExists()) {
            return 0;
        }

        return $this->busyDailyInvoiceRepository->upsert([
            'invoice_no' => $mapped['invoice_no'],
            'invoice_date' => $mapped['invoice_date'],
            'party_name' => $mapped['party_name'],
            'product_name' => $mapped['product_name'] ?? null,
            'product_rate' => $mapped['product_rate'] ?? null,
            'quantity_trucks' => $mapped['quantity'] ?? 1,
            'loading_weight_tons' => $mapped['loading_weight_tons'] ?? null,
            'vehicle_no' => $mapped['vehicle_no'] ?? null,
            'rawana_no' => $mapped['rawana_no'] ?? null,
            'eway_bill_no' => $mapped['eway_bill_no'] ?? null,
            'order_no_from_invoice' => $mapped['order_no'] ?? null,
            'company_id' => $companyId,
            'order_id' => $orderId,
            'dispatch_id' => $dispatchId,
            'mapping_status' => $mappingStatus,
            'error_message' => $errorMessage,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /**
     * Persist a failed invoice row when possible so it still appears on the daily page.
     *
     * @param array<string, mixed> $invoiceData
     */
    private function trySaveDailyInvoiceOnError(
        array $invoiceData,
        ?int $defaultCompanyId,
        string $errorMessage,
        ?int $uploadedBy
    ): void {
        try {
            if (!$this->busyDailyInvoicesTableExists()) {
                return;
            }
            $invoiceNo = trim((string)($invoiceData['invoice_no'] ?? ''));
            $invoiceDate = trim((string)($invoiceData['invoice_date'] ?? ''));
            $partyName = trim((string)($invoiceData['party_name'] ?? ''));
            if ($invoiceNo === '' || $invoiceDate === '' || $partyName === '' || !$this->isValidDate($invoiceDate)) {
                return;
            }

            $mapped = [
                'invoice_no' => $invoiceNo,
                'invoice_date' => IndianDate::toStorage($invoiceDate),
                'party_name' => $partyName,
                'product_name' => trim((string)($invoiceData['product_name'] ?? '')),
                'product_rate' => isset($invoiceData['product_rate']) && is_numeric($invoiceData['product_rate'])
                    ? (float)$invoiceData['product_rate']
                    : null,
                'quantity' => max(1, (int)($invoiceData['quantity'] ?? 1)),
                'loading_weight_tons' => isset($invoiceData['loading_weight_tons']) && $invoiceData['loading_weight_tons'] !== ''
                    ? (float)$invoiceData['loading_weight_tons']
                    : null,
                'vehicle_no' => !empty($invoiceData['vehicle_no']) ? trim((string)$invoiceData['vehicle_no']) : null,
                'rawana_no' => !empty($invoiceData['rawana_no']) ? trim((string)$invoiceData['rawana_no']) : null,
                'eway_bill_no' => !empty($invoiceData['eway_bill_no']) ? trim((string)$invoiceData['eway_bill_no']) : null,
                'order_no' => !empty($invoiceData['order_no']) ? trim((string)$invoiceData['order_no']) : null,
            ];

            $companyId = null;
            try {
                $companyId = $this->resolveCompanyId(
                    array_merge($mapped, [
                        'company_name' => !empty($invoiceData['company_name'])
                            ? trim((string)$invoiceData['company_name'])
                            : null,
                    ]),
                    $defaultCompanyId
                );
            } catch (\Throwable $ignored) {
                $companyId = $defaultCompanyId;
            }

            $this->upsertDailyInvoiceRecord($mapped, $companyId, 'error', $errorMessage, null, null, $uploadedBy);
        } catch (\Throwable $ignored) {
            // Never mask the original processing error
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatDailyInvoiceRow(array $row): array
    {
        $status = (string)($row['mapping_status'] ?? 'unmapped');
        return [
            'id' => (int)$row['id'],
            'invoice_no' => $row['invoice_no'],
            'invoice_date' => $row['invoice_date'],
            'party_name' => $row['party_name'],
            'product_name' => $row['product_name'],
            'product_rate' => $row['product_rate'] !== null ? (float)$row['product_rate'] : null,
            'quantity_trucks' => (int)$row['quantity_trucks'],
            'loading_weight_tons' => $row['loading_weight_tons'] !== null ? (float)$row['loading_weight_tons'] : null,
            'vehicle_no' => $row['vehicle_no'],
            'rawana_no' => $row['rawana_no'] ?? null,
            'eway_bill_no' => $row['eway_bill_no'] ?? null,
            'order_no_from_invoice' => $row['order_no_from_invoice'],
            'company_id' => $row['company_id'] !== null ? (int)$row['company_id'] : null,
            'company_name' => $row['company_name'] ?? null,
            'order_id' => $row['order_id'] !== null ? (int)$row['order_id'] : null,
            'order_no' => $row['order_no'] ?? null,
            'dispatch_id' => $row['dispatch_id'] !== null ? (int)$row['dispatch_id'] : null,
            'mapping_status' => $status,
            'is_mapped' => $status === 'mapped',
            'mapping_label' => $status === 'mapped'
                ? 'Mapped to order'
                : ($status === 'error' ? 'Import error' : 'Dispatch not mapped to any order'),
            'error_message' => $row['error_message'],
            'uploaded_by_name' => $row['uploaded_by_name'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function busyDailyInvoicesTableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'busy_daily_invoices'"
        );
        $exists = ((int)($row['c'] ?? 0)) > 0;
        return $exists;
    }

    private function resolveCompanyId(array $data, ?int $defaultCompanyId): int
    {
        if (!empty($data['company_name'])) {
            foreach ($this->companyRepository->findActive() as $company) {
                if (stripos($company->name, $data['company_name']) !== false
                    || stripos($data['company_name'], $company->name) !== false) {
                    return (int)$company->id;
                }
            }
        }

        if ($defaultCompanyId !== null && $defaultCompanyId > 0) {
            return $defaultCompanyId;
        }

        $active = CompanyContext::getActiveCompanyId();
        if ($active !== null && $active > 0) {
            return $active;
        }

        $companies = $this->companyRepository->findActive();
        if (empty($companies)) {
            throw new \RuntimeException('No active company configured');
        }

        return (int)$companies[0]->id;
    }

    private function findMatchingOrder(array $data, int $companyId): ?Order
    {
        if (!empty($data['order_no'])) {
            $order = $this->orderRepository->findByOrderNo($data['order_no']);
            if ($order && (int)$order->companyId === $companyId) {
                if (!$this->invoicePartyMatchesOrder($order, $data['party_name'])) {
                    throw new \RuntimeException(
                        'Invoice billed-to party "' . $data['party_name'] . '" does not match order ' .
                        $order->orderNo . ' (expected "' . $this->expectedInvoicePartyName($order) . '")'
                    );
                }
                return $order;
            }
        }

        // Bill-to-another-party orders: match invoice Billed-to name against billing party first
        if (OrderSchema::hasBillingPartyColumns()) {
            $billingOrder = $this->findPendingOrderForBillingPartyInvoice(
                $data['party_name'],
                $data['product_name'],
                $companyId,
                (int)$data['quantity']
            );
            if ($billingOrder) {
                return $billingOrder;
            }
        }

        $party = $this->findPartyByName($data['party_name']);
        if (!$party) {
            return null;
        }

        $product = $this->findProductByName($data['product_name']);
        if ($product) {
            $order = $this->findPendingOrderForInvoicePartyProduct(
                (int)$party->id,
                (int)$product->id,
                $companyId,
                (int)$data['quantity']
            );
            if ($order) {
                return $order;
            }
        }

        $pendingOrders = $this->findPendingOrdersForInvoiceParty((int)$party->id, $companyId, (int)$data['quantity']);
        if (empty($pendingOrders)) {
            return null;
        }

        if (count($pendingOrders) === 1) {
            return $pendingOrders[0];
        }

        foreach ($pendingOrders as $order) {
            if ($this->productNamesLooselyMatch($data['product_name'], (string)$order->productName)) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Pending orders where bill-to-another-party is on and invoice Billed-to matches billing party.
     */
    private function findPendingOrderForBillingPartyInvoice(
        string $invoicePartyName,
        string $invoiceProductName,
        int $companyId,
        int $quantity
    ): ?Order {
        $party = $this->findPartyByName($invoicePartyName);
        if (!$party) {
            return null;
        }

        $product = $this->findProductByName($invoiceProductName);
        $partyId = (int)$party->id;

        $sql = "
            SELECT o.id
            FROM orders o
            LEFT JOIN (
                SELECT order_id, COALESCE(SUM(dispatch_qty_trucks), 0) AS total_dispatched
                FROM dispatches
                GROUP BY order_id
            ) d ON d.order_id = o.id
            WHERE o.company_id = ?
              AND COALESCE(o.bill_to_other_party, 0) = 1
              AND o.billing_party_id = ?
              AND o.status IN ('pending', 'partial')
              AND (o.order_qty_trucks - COALESCE(d.total_dispatched, 0)) >= ?
        ";
        $params = [$companyId, $partyId, $quantity];

        if ($product) {
            $sql .= ' AND o.product_id = ?';
            $params[] = (int)$product->id;
        }

        $sql .= "
            ORDER BY
                CASE WHEN o.priority = 'urgent' THEN 0 ELSE 1 END,
                o.order_date ASC,
                o.id ASC
        ";

        $rows = $this->database->fetchAll($sql, $params);
        if (empty($rows)) {
            return null;
        }

        if (count($rows) === 1 || $product) {
            return $this->orderRepository->findById((int)$rows[0]['id']);
        }

        foreach ($rows as $row) {
            $order = $this->orderRepository->findById((int)$row['id']);
            if ($order && $this->productNamesLooselyMatch($invoiceProductName, (string)$order->productName)) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @return Order[]
     */
    private function findPendingOrdersForInvoiceParty(int $partyId, int $companyId, int $quantity): array
    {
        $partyMatch = OrderSchema::invoicePartyMatchWhere('o');
        $partyParams = OrderSchema::invoicePartyMatchParams($partyId);
        [$billingOrderSql, $billingOrderParams] = $this->billingPartyOrderBy($partyId);
        $sql = "
            SELECT o.id
            FROM orders o
            LEFT JOIN (
                SELECT order_id, COALESCE(SUM(dispatch_qty_trucks), 0) AS total_dispatched
                FROM dispatches
                GROUP BY order_id
            ) d ON d.order_id = o.id
            WHERE o.company_id = ?
              AND o.status IN ('pending', 'partial')
              AND (o.order_qty_trucks - COALESCE(d.total_dispatched, 0)) >= ?
              AND {$partyMatch}
            ORDER BY
                {$billingOrderSql}
                CASE WHEN o.priority = 'urgent' THEN 0 ELSE 1 END,
                o.order_date ASC,
                o.id ASC
        ";

        $rows = $this->database->fetchAll($sql, array_merge([$companyId, $quantity], $partyParams, $billingOrderParams));
        $orders = [];
        foreach ($rows as $row) {
            $order = $this->orderRepository->findById((int)$row['id']);
            if ($order) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    private function findPendingOrderForInvoicePartyProduct(int $partyId, int $productId, int $companyId, int $quantity): ?Order
    {
        $partyMatch = OrderSchema::invoicePartyMatchWhere('o');
        $partyParams = OrderSchema::invoicePartyMatchParams($partyId);
        [$billingOrderSql, $billingOrderParams] = $this->billingPartyOrderBy($partyId);
        $sql = "
            SELECT o.id
            FROM orders o
            LEFT JOIN (
                SELECT order_id, COALESCE(SUM(dispatch_qty_trucks), 0) AS total_dispatched
                FROM dispatches
                GROUP BY order_id
            ) d ON d.order_id = o.id
            WHERE o.product_id = ?
              AND o.company_id = ?
              AND o.status IN ('pending', 'partial')
              AND (o.order_qty_trucks - COALESCE(d.total_dispatched, 0)) >= ?
              AND {$partyMatch}
            ORDER BY
                {$billingOrderSql}
                CASE WHEN o.priority = 'urgent' THEN 0 ELSE 1 END,
                o.order_date ASC,
                o.id ASC
            LIMIT 1
        ";

        $row = $this->database->fetch($sql, array_merge([$productId, $companyId, $quantity], $partyParams, $billingOrderParams));
        return $row ? $this->orderRepository->findById((int)$row['id']) : null;
    }

    /** @return array{0: string, 1: array<int, int>} */
    private function billingPartyOrderBy(int $partyId): array
    {
        if (!OrderSchema::hasBillingPartyColumns()) {
            return ['', []];
        }

        return [
            'CASE WHEN COALESCE(o.bill_to_other_party, 0) = 1 AND o.billing_party_id = ? THEN 0
                  WHEN COALESCE(o.bill_to_other_party, 0) = 0 AND o.party_id = ? THEN 1
                  ELSE 2 END,',
            [$partyId, $partyId],
        ];
    }

    private function invoicePartyMatchesOrder(Order $order, string $invoicePartyName): bool
    {
        $invoiceParty = $this->findPartyByName($invoicePartyName);
        if (!$invoiceParty) {
            return false;
        }

        if (OrderSchema::hasBillingPartyColumns() && $order->billToOtherParty && $order->billingPartyId) {
            return (int)$order->billingPartyId === (int)$invoiceParty->id;
        }

        return (int)$order->partyId === (int)$invoiceParty->id;
    }

    private function expectedInvoicePartyName(Order $order): string
    {
        if (OrderSchema::hasBillingPartyColumns() && $order->billToOtherParty && $order->billingPartyName !== '') {
            return $order->billingPartyName;
        }

        return $order->partyName;
    }

    /**
     * @return Order[]
     */
    private function findPendingOrdersForParty(int $partyId, int $companyId, int $quantity): array
    {
        $sql = "
            SELECT o.id
            FROM orders o
            LEFT JOIN (
                SELECT order_id, COALESCE(SUM(dispatch_qty_trucks), 0) AS total_dispatched
                FROM dispatches
                GROUP BY order_id
            ) d ON d.order_id = o.id
            WHERE o.party_id = ?
              AND o.company_id = ?
              AND o.status IN ('pending', 'partial')
              AND (o.order_qty_trucks - COALESCE(d.total_dispatched, 0)) >= ?
            ORDER BY
                CASE WHEN o.priority = 'urgent' THEN 0 ELSE 1 END,
                o.order_date ASC,
                o.id ASC
        ";

        $rows = $this->database->fetchAll($sql, [$partyId, $companyId, $quantity]);
        $orders = [];
        foreach ($rows as $row) {
            $order = $this->orderRepository->findById((int)$row['id']);
            if ($order) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    private function findPendingOrderForPartyProduct(int $partyId, int $productId, int $companyId, int $quantity): ?Order
    {
        $sql = "
            SELECT o.id
            FROM orders o
            LEFT JOIN (
                SELECT order_id, COALESCE(SUM(dispatch_qty_trucks), 0) AS total_dispatched
                FROM dispatches
                GROUP BY order_id
            ) d ON d.order_id = o.id
            WHERE o.party_id = ?
              AND o.product_id = ?
              AND o.company_id = ?
              AND o.status IN ('pending', 'partial')
              AND (o.order_qty_trucks - COALESCE(d.total_dispatched, 0)) >= ?
            ORDER BY
                CASE WHEN o.priority = 'urgent' THEN 0 ELSE 1 END,
                o.order_date ASC,
                o.id ASC
            LIMIT 1
        ";

        $row = $this->database->fetch($sql, [$partyId, $productId, $companyId, $quantity]);
        return $row ? $this->orderRepository->findById((int)$row['id']) : null;
    }

    private function findPartyByName(string $partyName): ?object
    {
        $partyName = trim($partyName);
        if ($partyName === '') {
            return null;
        }

        foreach ($this->partyRepository->findAll() as $party) {
            if (strcasecmp($party->name, $partyName) === 0) {
                return $party;
            }
        }

        $normalizedInvoice = $this->normalizePartyName($partyName);
        if ($normalizedInvoice === '') {
            return null;
        }

        foreach ($this->partyRepository->findAll() as $party) {
            if ($this->normalizePartyName($party->name) === $normalizedInvoice) {
                return $party;
            }
        }

        foreach ($this->partyRepository->findAll() as $party) {
            $normalizedDb = $this->normalizePartyName($party->name);
            if ($normalizedDb !== ''
                && (str_contains($normalizedDb, $normalizedInvoice) || str_contains($normalizedInvoice, $normalizedDb))) {
                return $party;
            }
        }

        return null;
    }

    private function normalizePartyName(string $name): string
    {
        $name = strtoupper(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
        $name = preg_replace('/[.,\-]+/', ' ', $name) ?? $name;
        $name = preg_replace('/\b(PRIVATE\s+LIMITED|PVT\s*\.?\s*LTD\.?|LIMITED|LTD\.?)\b/i', '', $name) ?? $name;
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    private function findProductByName(string $productName): ?object
    {
        $productName = trim($productName);
        if ($productName === '') {
            return null;
        }

        foreach ($this->productRepository->findAll() as $product) {
            if (strcasecmp($product->name, $productName) === 0) {
                return $product;
            }
        }

        $normalizedInvoice = $this->normalizeProductLabel($productName);
        foreach ($this->productRepository->findAll() as $product) {
            $normalizedDb = $this->normalizeProductLabel($product->name);
            if ($normalizedInvoice === $normalizedDb) {
                return $product;
            }
            if ($normalizedInvoice !== '' && $normalizedDb !== ''
                && (str_contains($normalizedDb, $normalizedInvoice) || str_contains($normalizedInvoice, $normalizedDb))) {
                return $product;
            }
        }

        return null;
    }

    private function productNamesLooselyMatch(string $invoiceProduct, string $orderProduct): bool
    {
        $left = $this->normalizeProductLabel($invoiceProduct);
        $right = $this->normalizeProductLabel($orderProduct);
        if ($left === '' || $right === '') {
            return false;
        }
        if ($left === $right) {
            return true;
        }
        return str_contains($right, $left) || str_contains($left, $right);
    }

    private function normalizeProductLabel(string $name): string
    {
        $name = preg_replace('/\bP\d+\s+\d+\b/iu', '', $name) ?? $name;
        $name = preg_replace('/\s*\((PROCESSED|LOOSE)\)\s*/iu', '', $name) ?? $name;
        $name = preg_replace('/[.\-]+$/', '', trim($name)) ?? trim($name);
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);
        return strtoupper($name);
    }

    /**
     * @return array{action: string, order_id: int, order_no: string, dispatch_id: int, invoice_no: string, party_name: string, dispatch_qty: int}
     */
    private function upsertDispatchFromInvoice(Order $order, array $data, ?int $processedByUserId): array
    {
        if (!$this->invoicePartyMatchesOrder($order, $data['party_name'])) {
            throw new \RuntimeException(
                'Invoice billed-to party "' . $data['party_name'] . '" does not match order ' .
                $order->orderNo . ' (expected "' . $this->expectedInvoicePartyName($order) . '")'
            );
        }

        $existing = $this->dispatchRepository->findByBusyInvoiceNo($data['invoice_no']);
        $userId = $processedByUserId ?? 1;

        if ($existing) {
            if ((int)$existing->orderId !== (int)$order->id) {
                throw new \RuntimeException(
                    "Invoice {$data['invoice_no']} is already linked to order {$existing->orderNo}"
                );
            }

            $updated = $this->dispatchService->updateDispatch($existing->id, [
                'dispatch_date' => $data['invoice_date'],
                'dispatch_qty_trucks' => $data['quantity'],
                'product_rate' => $data['product_rate'],
                'loading_weight_tons' => $data['loading_weight_tons'],
                'rawana_no' => $data['rawana_no'] ?? null,
                'eway_bill_no' => $data['eway_bill_no'] ?? null,
                'remarks' => $data['remarks'],
            ]);

            return [
                'action' => 'updated',
                'order_id' => $order->id,
                'order_no' => $order->orderNo,
                'dispatch_id' => $updated->id,
                'invoice_no' => $data['invoice_no'],
                'party_name' => $order->partyName,
                'dispatch_qty' => $updated->dispatchQtyTrucks,
            ];
        }

        if (!$order->canDispatch($data['quantity'])) {
            throw new \RuntimeException(
                "Cannot dispatch {$data['quantity']} trucks for order {$order->orderNo}. " .
                "Remaining: " . max(0, $order->orderQtyTrucks - $order->totalDispatched)
            );
        }

        $dispatch = $this->dispatchService->createDispatch([
            'order_id' => $order->id,
            'dispatch_date' => $data['invoice_date'],
            'dispatch_qty_trucks' => $data['quantity'],
            'product_rate' => $data['product_rate'],
            'loading_weight_tons' => $data['loading_weight_tons'],
            'busy_invoice_no' => $data['invoice_no'],
            'vehicle_no' => $data['vehicle_no'],
            'rawana_no' => $data['rawana_no'] ?? null,
            'eway_bill_no' => $data['eway_bill_no'] ?? null,
            'remarks' => $data['remarks'],
            'dispatched_by' => $userId,
        ]);

        return [
            'action' => 'created',
            'order_id' => $order->id,
            'order_no' => $order->orderNo,
            'dispatch_id' => $dispatch->id,
            'invoice_no' => $data['invoice_no'],
            'party_name' => $order->partyName,
            'dispatch_qty' => $dispatch->dispatchQtyTrucks,
        ];
    }

    private function logWebhookData(array $data, string $status = 'received'): int
    {
        $sql = "
            INSERT INTO busy_webhook_logs (invoice_no, webhook_data, status, created_at)
            VALUES (?, ?, ?, NOW())
        ";
        $this->database->execute($sql, [
            $data['invoice_no'] ?? 'unknown',
            json_encode($data),
            $status,
        ]);
        return (int)$this->database->lastInsertId();
    }

    private function updateWebhookLog(int $logId, string $status, ?string $errorMessage): void
    {
        if ($logId <= 0) {
            return;
        }
        $sql = "
            UPDATE busy_webhook_logs
            SET status = ?, error_message = ?, processed_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ";
        $this->database->execute($sql, [$status, $errorMessage, $logId]);
    }

    private function getWebhookUrl(): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        return "{$protocol}://{$host}/api/busy/webhook";
    }

    private function getSampleInvoiceData(array $filters): array
    {
        return [
            [
                'invoice_no' => 'INV-2025-001',
                'invoice_date' => '2025-10-03',
                'party_name' => 'ABC Construction Ltd',
                'product_name' => 'Sand',
                'quantity' => 1,
                'product_rate' => 850.00,
                'loading_weight_tons' => 12.5,
            ],
        ];
    }

    private function isValidDate(string $date): bool
    {
        return \App\Support\IndianDate::isValid($date);
    }
}
