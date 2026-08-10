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
use App\Support\DispatchSchema;
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
    private OrderService $orderService;

    /** @var array<int, list<Order>> open pending/partial orders keyed by company_id */
    private array $openOrdersByCompanyCache = [];

    /** @var list<string>|null */
    private ?array $autoOrderPartyPatterns = null;

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
        $this->orderService = new OrderService();
    }

    /**
     * Process one Busy invoice and create or update the linked dispatch.
     * Matches open portal orders FIFO (oldest first); never deletes or replaces orders.
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
                $billingHint = OrderSchema::hasBillingPartyColumns()
                    ? ' For bill-to-another-party orders, Busy Party Name may match billing or delivery party; product and remaining trucks must also match.'
                    : '';
                $message = 'No matching pending order found for billed-to "' . $mapped['party_name'] .
                    '", product "' . $mapped['product_name'] . '".'
                    . ' Check company, product, remaining trucks, and party on the order.'
                    . $billingHint;

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

        $unmappedNos = array_values(array_filter(array_map(
            static fn($d) => (($d['status'] ?? '') === 'unmapped') ? (string)($d['invoice_no'] ?? '') : '',
            $details
        )));

        $auto = ['orders_created' => 0, 'mapped' => 0, 'details' => []];
        if ($unmappedNos !== []) {
            $auto = $this->autoCreateOrdersFromAllowlistedUnmapped(
                ['invoice_nos' => $unmappedNos],
                $processedByUserId
            );
            if (($auto['mapped'] ?? 0) > 0) {
                $mappedNos = [];
                foreach ($auto['details'] as $row) {
                    if (($row['status'] ?? '') === 'mapped') {
                        $mappedNos[(string)$row['invoice_no']] = $row;
                    }
                }
                foreach ($details as &$detail) {
                    $no = (string)($detail['invoice_no'] ?? '');
                    if (isset($mappedNos[$no])) {
                        $detail = array_merge($detail, $mappedNos[$no], [
                            'status' => 'success',
                            'mapping_status' => 'mapped',
                            'action' => 'auto_order',
                        ]);
                    }
                }
                unset($detail);
            }
        }

        return [
            'processed' => count($details),
            'successful' => count(array_filter($details, fn($r) => $r['status'] === 'success')),
            'unmapped' => count(array_filter($details, fn($r) => $r['status'] === 'unmapped')),
            'failed' => count(array_filter($details, fn($r) => $r['status'] === 'error')),
            'auto_orders_created' => (int)($auto['orders_created'] ?? 0),
            'auto_mapped' => (int)($auto['mapped'] ?? 0),
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

        // Recover rows older remap marked as error so "All unmapped" shows them again.
        if (($filters['mapping_status'] ?? '') === 'open') {
            $this->busyDailyInvoiceRepository->reopenErrorsAsUnmapped();
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

    /**
     * Re-run order matching for previously unmapped / error Busy invoices
     * (e.g. orders created after the CSV was uploaded).
     *
     * @param array<string, mixed> $filters
     * @return array{processed: int, mapped: int, still_unmapped: int, failed: int, details: list<array<string, mixed>>}
     */
    public function remapUnmappedInvoices(array $filters, ?int $processedByUserId = null): array
    {
        if (!$this->busyDailyInvoicesTableExists()) {
            throw new \RuntimeException(
                'Daily invoices table is missing. Run: php scripts/migrate.php'
            );
        }

        @ini_set('max_execution_time', '300');
        @set_time_limit(300);
        $this->openOrdersByCompanyCache = [];

        // Older remap runs flipped some unmatched rows to "error" via processInvoice.
        // Put them back to unmapped before matching so they stay on the daily ledger.
        $this->busyDailyInvoiceRepository->reopenErrorsAsUnmapped();

        $rows = $this->busyDailyInvoiceRepository->findRemapCandidates($filters);
        $details = [];
        $activeCompanyId = CompanyContext::getActiveCompanyId();

        foreach ($rows as $row) {
            $invoiceNo = (string)($row['invoice_no'] ?? '');
            $rowId = (int)($row['id'] ?? 0);
            $rowCompanyId = !empty($row['company_id']) ? (int)$row['company_id'] : null;

            try {
                $match = $this->findOrderForDailyInvoiceRow($row, $activeCompanyId);
                if ($match === null) {
                    $reason = $this->explainNoMatch($this->mapDailyRowForRemap($row));
                    $this->busyDailyInvoiceRepository->ensureStillUnmapped($rowId, $reason);
                    $details[] = [
                        'status' => 'unmapped',
                        'invoice_no' => $invoiceNo,
                        'mapping_status' => 'unmapped',
                        'error' => $reason,
                    ];
                    continue;
                }

                /** @var Order $order */
                $order = $match['order'];
                $companyId = (int)$match['company_id'];
                $mapped = $this->mapDailyRowForRemap($row);
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
                // Capacity changed — refresh that company's open-order cache
                unset($this->openOrdersByCompanyCache[$companyId]);

                $details[] = array_merge([
                    'status' => 'mapped',
                    'invoice_no' => $invoiceNo,
                    'mapping_status' => 'mapped',
                ], $result);
            } catch (\Throwable $e) {
                // Never flip a stored ledger row to error / never delete — leave as-is.
                $this->busyDailyInvoiceRepository->ensureStillUnmapped($rowId, $e->getMessage());
                $details[] = [
                    'status' => 'unmapped',
                    'invoice_no' => $invoiceNo,
                    'mapping_status' => 'unmapped',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->openOrdersByCompanyCache = [];

        $auto = $this->autoCreateOrdersFromAllowlistedUnmapped($filters, $processedByUserId);
        if (($auto['mapped'] ?? 0) > 0) {
            $mappedNos = [];
            foreach ($auto['details'] as $row) {
                if (($row['status'] ?? '') === 'mapped') {
                    $mappedNos[(string)$row['invoice_no']] = $row;
                }
            }
            foreach ($details as &$detail) {
                $no = (string)($detail['invoice_no'] ?? '');
                if (isset($mappedNos[$no])) {
                    $detail = array_merge($detail, $mappedNos[$no], [
                        'status' => 'mapped',
                        'mapping_status' => 'mapped',
                        'auto_order' => true,
                    ]);
                    unset($mappedNos[$no]);
                }
            }
            unset($detail);
            foreach ($mappedNos as $row) {
                $details[] = $row;
            }
        }

        return [
            'processed' => count($details),
            'mapped' => count(array_filter($details, fn($r) => $r['status'] === 'mapped')),
            'still_unmapped' => count(array_filter($details, fn($r) => $r['status'] === 'unmapped')),
            'failed' => 0,
            'auto_orders_created' => (int)($auto['orders_created'] ?? 0),
            'auto_mapped' => (int)($auto['mapped'] ?? 0),
            'details' => $details,
        ];
    }

    /**
     * For allowlisted parties (see config/busy_auto_order_parties.php): when Busy invoices
     * remain unmapped, create a same-day pending order (qty = total trucks) and map them.
     *
     * @param array<string, mixed> $filters Same shape as findRemapCandidates filters
     * @return array{orders_created: int, mapped: int, skipped: int, details: list<array<string, mixed>>}
     */
    public function autoCreateOrdersFromAllowlistedUnmapped(array $filters, ?int $processedByUserId = null): array
    {
        if (!$this->busyDailyInvoicesTableExists()) {
            return ['orders_created' => 0, 'mapped' => 0, 'skipped' => 0, 'details' => []];
        }

        $rows = $this->busyDailyInvoiceRepository->findRemapCandidates($filters);
        $eligible = [];
        foreach ($rows as $row) {
            $partyName = trim((string)($row['party_name'] ?? ''));
            if ($partyName === '' || !$this->isAutoOrderAllowlistedParty($partyName)) {
                continue;
            }
            $eligible[] = $row;
        }

        if ($eligible === []) {
            return ['orders_created' => 0, 'mapped' => 0, 'skipped' => 0, 'details' => []];
        }

        /** @var array<string, list<array<string, mixed>>> $groups */
        $groups = [];
        $skipped = 0;
        foreach ($eligible as $row) {
            $mapped = $this->mapDailyRowForRemap($row);
            $party = $this->findPartyByName($mapped['party_name']);
            $product = $this->findProductByName($mapped['product_name']);
            if (!$party || !$product) {
                $skipped++;
                continue;
            }

            $companyId = !empty($row['company_id'])
                ? (int)$row['company_id']
                : (CompanyContext::getActiveCompanyId() ?: 0);
            if ($companyId <= 0) {
                $active = $this->companyRepository->findActive();
                $companyId = $active !== [] ? (int)$active[0]->id : 0;
            }
            if ($companyId <= 0) {
                $skipped++;
                continue;
            }

            $key = $companyId . '|' . (int)$party->id . '|' . (int)$product->id . '|' . substr((string)$mapped['invoice_date'], 0, 10);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'company_id' => $companyId,
                    'party_id' => (int)$party->id,
                    'party_name' => (string)$party->name,
                    'product_id' => (int)$product->id,
                    'product_name' => (string)$product->name,
                    'invoice_date' => substr((string)$mapped['invoice_date'], 0, 10),
                    'rows' => [],
                    'mapped_rows' => [],
                ];
            }
            $groups[$key]['rows'][] = $row;
            $groups[$key]['mapped_rows'][] = $mapped;
        }

        $ordersCreated = 0;
        $mappedCount = 0;
        $details = [];
        $userId = $processedByUserId ?? 1;

        foreach ($groups as $group) {
            $qty = 0;
            foreach ($group['mapped_rows'] as $m) {
                $qty += max(1, (int)($m['quantity'] ?? 1));
            }
            if ($qty <= 0) {
                continue;
            }

            try {
                $order = $this->orderService->createOrder([
                    'company_id' => $group['company_id'],
                    'party_id' => $group['party_id'],
                    'product_id' => $group['product_id'],
                    'order_date' => $group['invoice_date'],
                    'order_qty_trucks' => $qty,
                    'created_by' => $userId,
                    'created_by_role' => 'admin',
                    'priority' => 'normal',
                    'bill_to_other_party' => false,
                ]);
                $ordersCreated++;
                unset($this->openOrdersByCompanyCache[$group['company_id']]);

                // Reload with dispatch capacity fields
                $order = $this->orderRepository->findById((int)$order->id) ?? $order;

                foreach ($group['mapped_rows'] as $idx => $mappedInvoice) {
                    $invoiceNo = (string)$mappedInvoice['invoice_no'];
                    try {
                        $mappedInvoice['remarks'] = 'Auto-mapped after order created from Busy unmapped invoices';
                        $result = $this->upsertDispatchFromInvoice($order, $mappedInvoice, $userId);
                        $this->upsertDailyInvoiceRecord(
                            $mappedInvoice,
                            $group['company_id'],
                            'mapped',
                            null,
                            (int)$result['order_id'],
                            (int)$result['dispatch_id'],
                            $userId
                        );
                        // Refresh capacity after each truck
                        $order = $this->orderRepository->findById((int)$order->id) ?? $order;
                        $mappedCount++;
                        $details[] = array_merge([
                            'status' => 'mapped',
                            'mapping_status' => 'mapped',
                            'auto_order' => true,
                            'invoice_no' => $invoiceNo,
                        ], $result);
                    } catch (\Throwable $e) {
                        $details[] = [
                            'status' => 'unmapped',
                            'mapping_status' => 'unmapped',
                            'invoice_no' => $invoiceNo,
                            'error' => 'Auto-order created (' . $order->orderNo . ') but map failed: ' . $e->getMessage(),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                foreach ($group['mapped_rows'] as $mappedInvoice) {
                    $details[] = [
                        'status' => 'unmapped',
                        'mapping_status' => 'unmapped',
                        'invoice_no' => (string)$mappedInvoice['invoice_no'],
                        'error' => 'Auto-order create failed: ' . $e->getMessage(),
                    ];
                }
            }
        }

        $this->openOrdersByCompanyCache = [];

        return [
            'orders_created' => $ordersCreated,
            'mapped' => $mappedCount,
            'skipped' => $skipped,
            'details' => $details,
        ];
    }

    /** @return list<string> */
    private function getAutoOrderPartyPatterns(): array
    {
        if ($this->autoOrderPartyPatterns !== null) {
            return $this->autoOrderPartyPatterns;
        }

        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'busy_auto_order_parties.php';
        $patterns = [];
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                foreach ($loaded as $name) {
                    $name = trim((string)$name);
                    if ($name !== '') {
                        $patterns[] = $name;
                    }
                }
            }
        }
        $this->autoOrderPartyPatterns = $patterns;
        return $patterns;
    }

    private function isAutoOrderAllowlistedParty(string $partyName): bool
    {
        $normalized = $this->normalizePartyName($partyName);
        if ($normalized === '') {
            return false;
        }
        foreach ($this->getAutoOrderPartyPatterns() as $pattern) {
            $p = $this->normalizePartyName($pattern);
            if ($p === '') {
                continue;
            }
            if ($normalized === $p || str_contains($normalized, $p) || str_contains($p, $normalized)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read-only order search for remap. Does not write dispatches or daily rows.
     * Iterates companies once; does not nest multi-company search inside each try.
     *
     * @param array<string, mixed> $row
     * @return array{order: Order, company_id: int}|null
     */
    private function findOrderForDailyInvoiceRow(array $row, ?int $activeCompanyId): ?array
    {
        $mapped = $this->mapDailyRowForRemap($row);
        $rowCompanyId = !empty($row['company_id']) ? (int)$row['company_id'] : null;

        $companyIdsToTry = [];
        if ($rowCompanyId) {
            $companyIdsToTry[] = $rowCompanyId;
        }
        if ($activeCompanyId && $activeCompanyId !== $rowCompanyId) {
            $companyIdsToTry[] = $activeCompanyId;
        }
        // Include inactive companies too — orders may still sit there
        foreach ($this->companyRepository->findAll() as $company) {
            $cid = (int)$company->id;
            if (!in_array($cid, $companyIdsToTry, true)) {
                $companyIdsToTry[] = $cid;
            }
        }

        foreach ($companyIdsToTry as $companyId) {
            try {
                // Single-company match only (avoids O(companies²) during remap)
                $order = $this->findMatchingOrderForCompany($mapped, $companyId);
            } catch (\Throwable $ignored) {
                continue;
            }
            if ($order) {
                return ['order' => $order, 'company_id' => (int)$order->companyId];
            }
        }

        return null;
    }

    /**
     * Build processable invoice fields from a stored daily row without strict import validation.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapDailyRowForRemap(array $row): array
    {
        $rate = $row['product_rate'] !== null && $row['product_rate'] !== ''
            ? (float)$row['product_rate']
            : 0.0;

        return [
            'invoice_no' => trim((string)$row['invoice_no']),
            'invoice_date' => (string)$row['invoice_date'],
            'party_name' => trim((string)$row['party_name']),
            'product_name' => trim((string)($row['product_name'] ?? '')),
            'product_rate' => $rate > 0 ? $rate : 0.01,
            'quantity' => max(1, (int)($row['quantity_trucks'] ?? 1)),
            'loading_weight_tons' => $row['loading_weight_tons'] !== null && $row['loading_weight_tons'] !== ''
                ? (float)$row['loading_weight_tons']
                : null,
            'vehicle_no' => !empty($row['vehicle_no']) ? trim((string)$row['vehicle_no']) : null,
            'rawana_no' => !empty($row['rawana_no']) ? trim((string)$row['rawana_no']) : null,
            'eway_bill_no' => !empty($row['eway_bill_no']) ? trim((string)$row['eway_bill_no']) : null,
            'order_no' => !empty($row['order_no_from_invoice']) ? trim((string)$row['order_no_from_invoice']) : null,
            'remarks' => 'Remapped from Busy daily invoice #' . trim((string)$row['invoice_no']),
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

    public function getRecentLogs(int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
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

    /**
     * Match a Busy invoice to an open portal order.
     *
     * Billed-to on the invoice must match:
     *   - order party (normal), OR
     *   - order billing party when "Bill to another party" is on (e.g. Acecon order, Icon invoice).
     * Product must also match. Among matches, FIFO (oldest order_date / id).
     */
    private function findMatchingOrder(array $data, int $companyId): ?Order
    {
        $matched = $this->findMatchingOrderForCompany($data, $companyId);
        if ($matched) {
            return $matched;
        }

        // Retry other companies — bill-to orders are often under a specific company
        // while the upload uses the header company switcher.
        foreach ($this->companyRepository->findAll() as $company) {
            $cid = (int)$company->id;
            if ($cid === $companyId) {
                continue;
            }
            $matched = $this->findMatchingOrderForCompany($data, $cid);
            if ($matched) {
                return $matched;
            }
        }

        return null;
    }

    private function findMatchingOrderForCompany(array $data, int $companyId): ?Order
    {
        $invoiceDate = (string)($data['invoice_date'] ?? '');
        $invoiceProduct = trim((string)($data['product_name'] ?? ''));
        $invoicePartyName = trim((string)($data['party_name'] ?? ''));
        $quantity = (int)$data['quantity'];

        if (!empty($data['order_no'])) {
            $order = $this->orderRepository->findByOrderNo($data['order_no']);
            if ($order && (int)$order->companyId === $companyId) {
                if (!$this->invoicePartyMatchesOrder($order, $invoicePartyName)) {
                    throw new \RuntimeException(
                        'Invoice billed-to party "' . $invoicePartyName . '" does not match order ' .
                        $order->orderNo . ' (expected "' . $this->expectedInvoicePartyName($order) . '")'
                    );
                }
                return $order;
            }
        }

        if ($invoiceProduct === '' || $invoicePartyName === '') {
            return null;
        }

        // Open orders for this company with capacity; filter by billed-to (delivery or billing party).
        // Prefer orders whose order_date is on/before the invoice (FIFO), but still allow
        // later-created orders — remap exists exactly for "order after CSV upload".
        $dateOk = [];
        $dateFallback = [];
        foreach ($this->getOpenOrdersForCompany($companyId) as $order) {
            $remaining = max(0, (int)$order->orderQtyTrucks - (int)$order->totalDispatched);
            if ($remaining < $quantity) {
                continue;
            }
            if (!$this->invoicePartyMatchesOrder($order, $invoicePartyName)) {
                continue;
            }
            if ($this->orderEligibleForInvoiceDate($order, $invoiceDate)) {
                $dateOk[] = $order;
            } else {
                $dateFallback[] = $order;
            }
        }

        foreach ([$dateOk, $dateFallback] as $pendingOrders) {
            if ($pendingOrders === []) {
                continue;
            }

            $matched = $this->pickOrderByProduct($pendingOrders, $invoiceProduct);
            if ($matched) {
                return $matched;
            }
        }

        return null;
    }

    /**
     * Among party-matched open orders (oldest-first), pick by product id then loose name.
     * If only one open order remains for this billed-to party, use it (product labels often differ slightly).
     *
     * @param list<Order> $pendingOrders
     */
    private function pickOrderByProduct(array $pendingOrders, string $invoiceProduct): ?Order
    {
        $product = $this->findProductByName($invoiceProduct);

        if ($product) {
            foreach ($pendingOrders as $order) {
                if ((int)$order->productId === (int)$product->id) {
                    return $order;
                }
            }
        }

        foreach ($pendingOrders as $order) {
            if ($this->productNamesLooselyMatch($invoiceProduct, (string)$order->productName)) {
                return $order;
            }
        }

        // Sole open order for this party (e.g. Acecon+Icon with one Ball Clay order)
        if (count($pendingOrders) === 1) {
            return $pendingOrders[0];
        }

        return null;
    }

    /**
     * Cached open orders for a company (pending/partial with any remaining trucks).
     *
     * @return list<Order>
     */
    private function getOpenOrdersForCompany(int $companyId): array
    {
        if (isset($this->openOrdersByCompanyCache[$companyId])) {
            return $this->openOrdersByCompanyCache[$companyId];
        }

        $activeWhere = DispatchSchema::activeDispatchWhere('d');
        $sql = "
            SELECT o.id
            FROM orders o
            LEFT JOIN (
                SELECT order_id, COALESCE(SUM(dispatch_qty_trucks), 0) AS total_dispatched
                FROM dispatches d
                WHERE {$activeWhere}
                GROUP BY order_id
            ) d ON d.order_id = o.id
            WHERE o.company_id = ?
              AND o.status IN ('pending', 'partial')
              AND (o.order_qty_trucks - COALESCE(d.total_dispatched, 0)) >= 1
            ORDER BY o.order_date ASC, o.id ASC
        ";

        $rows = $this->database->fetchAll($sql, [$companyId]);
        $orders = [];
        foreach ($rows as $row) {
            $order = $this->orderRepository->findById((int)$row['id']);
            if ($order) {
                $orders[] = $order;
            }
        }

        $this->openOrdersByCompanyCache[$companyId] = $orders;
        return $orders;
    }

    /**
     * Prefer orders whose order_date is on/before invoice_date (+1 day grace).
     * Used as a preference bucket only — later-created orders remain eligible as fallback.
     */
    private function orderEligibleForInvoiceDate(Order $order, string $invoiceDate): bool
    {
        $invoiceDate = trim($invoiceDate);
        $orderDate = trim((string)$order->orderDate);
        if ($invoiceDate === '' || $orderDate === '') {
            return true;
        }

        try {
            $invoice = new \DateTimeImmutable(substr($invoiceDate, 0, 10));
            $orderDt = new \DateTimeImmutable(substr($orderDate, 0, 10));
        } catch (\Exception $e) {
            return true;
        }

        $latestAllowedOrderDate = $invoice->modify('+1 day');
        return $orderDt <= $latestAllowedOrderDate;
    }

    /**
     * Busy "Party Name" (billed-to) must match the order's delivery party and/or billing party.
     * Example: Acecon delivery order billed to Icon → Busy party "ICON GRANITO" matches via billing_party_id.
     */
    private function invoicePartyMatchesOrder(Order $order, string $invoicePartyName): bool
    {
        $invoicePartyName = trim($invoicePartyName);
        if ($invoicePartyName === '') {
            return false;
        }

        $invoiceParty = $this->findPartyByName($invoicePartyName);

        // Delivery party (site)
        if ($invoiceParty && (int)$order->partyId === (int)$invoiceParty->id) {
            return true;
        }
        if ($this->partyNamesLooselyMatch($invoicePartyName, $order->partyName)) {
            return true;
        }

        // Billing party (Busy often shows Icon even when delivery is Acecon)
        if (OrderSchema::hasBillingPartyColumns() && $order->billingPartyId) {
            if ($invoiceParty && (int)$order->billingPartyId === (int)$invoiceParty->id) {
                return true;
            }
            if ($order->billingPartyName !== ''
                && $this->partyNamesLooselyMatch($invoicePartyName, $order->billingPartyName)) {
                return true;
            }
        }

        return false;
    }

    private function expectedInvoicePartyName(Order $order): string
    {
        if (OrderSchema::hasBillingPartyColumns() && $order->billingPartyId && $order->billingPartyName !== '') {
            return $order->billingPartyName . ' (billing party; delivery: ' . $order->partyName . ')';
        }

        return $order->partyName;
    }

    /**
     * Human-readable why an invoice did not match any open order (shown on daily ledger).
     *
     * @param array<string, mixed> $mapped
     */
    private function explainNoMatch(array $mapped): string
    {
        $party = trim((string)($mapped['party_name'] ?? ''));
        $product = trim((string)($mapped['product_name'] ?? ''));
        $qty = max(1, (int)($mapped['quantity'] ?? 1));

        if ($party === '' || $product === '') {
            return 'Invoice missing party or product name — cannot match an order.';
        }

        $partyHits = 0;
        $productMiss = 0;
        $capacityMiss = 0;
        $sampleOrders = [];

        foreach ($this->companyRepository->findAll() as $company) {
            foreach ($this->getOpenOrdersForCompany((int)$company->id) as $order) {
                $remaining = max(0, (int)$order->orderQtyTrucks - (int)$order->totalDispatched);
                if (!$this->invoicePartyMatchesOrder($order, $party)) {
                    // Tip: Acecon open orders while Busy shows Icon
                    if (count($sampleOrders) < 3
                        && $this->productNamesLooselyMatch($product, (string)$order->productName)
                        && $remaining >= $qty) {
                        $billing = $order->billingPartyName !== '' ? $order->billingPartyName : '(none)';
                        $sampleOrders[] = $order->orderNo . ' delivery=' . $order->partyName
                            . ' billing=' . $billing . ' product=' . $order->productName;
                    }
                    continue;
                }
                $partyHits++;
                if ($remaining < $qty) {
                    $capacityMiss++;
                    continue;
                }
                if (!$this->productNamesLooselyMatch($product, (string)$order->productName)) {
                    $resolved = $this->findProductByName($product);
                    if (!$resolved || (int)$order->productId !== (int)$resolved->id) {
                        $productMiss++;
                        continue;
                    }
                }
            }
        }

        if ($partyHits === 0) {
            $tip = ' No open order uses this billed-to party as delivery or billing party.';
            if ($sampleOrders !== []) {
                $tip .= ' Nearby open order(s): ' . implode('; ', $sampleOrders)
                    . '. Confirm that order shows billing="' . $party . '", product matches, status pending/partial, and has remaining trucks; then Remap.';
            } else {
                $tip .= ' Create/open a pending order for this party (or Acecon with billing party "'
                    . $party . '") and matching product "' . $product . '", then Remap.';
            }
            return 'No match for billed-to "' . $party . '", product "' . $product . '".' . $tip;
        }

        if ($partyHits > 0 && $productMiss >= $partyHits) {
            return 'Found open order(s) for "' . $party . '" but product "' . $product
                . '" does not match. Align the order product name, then Remap.';
        }

        if ($capacityMiss > 0) {
            return 'Found order(s) for "' . $party . '" / "' . $product
                . '" but remaining trucks are less than invoice qty (' . $qty . ').';
        }

        return 'No matching pending order found for billed-to "' . $party . '", product "' . $product . '".';
    }

    private function partyNamesLooselyMatch(string $left, string $right): bool
    {
        $a = $this->normalizePartyName($left);
        $b = $this->normalizePartyName($right);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        return str_contains($a, $b) || str_contains($b, $a);
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

        // Prefer the most specific (longest) substring match — avoids short names
        // stealing matches from e.g. ICON vs ICONIC / other parties.
        $best = null;
        $bestLen = 0;
        foreach ($this->partyRepository->findAll() as $party) {
            $normalizedDb = $this->normalizePartyName($party->name);
            if ($normalizedDb === '') {
                continue;
            }
            if (!str_contains($normalizedDb, $normalizedInvoice) && !str_contains($normalizedInvoice, $normalizedDb)) {
                continue;
            }
            $len = strlen($normalizedDb);
            if ($len > $bestLen) {
                $best = $party;
                $bestLen = $len;
            }
        }

        return $best;
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
        if ($normalizedInvoice === '') {
            return null;
        }

        // Prefer longest / most specific product name (avoid "Ball Clay" beating "Ball Clay MJ-1")
        $best = null;
        $bestScore = 0;
        foreach ($this->productRepository->findAll() as $product) {
            $normalizedDb = $this->normalizeProductLabel($product->name);
            if ($normalizedDb === '') {
                continue;
            }
            if ($normalizedInvoice === $normalizedDb) {
                return $product;
            }
            if (!str_contains($normalizedDb, $normalizedInvoice) && !str_contains($normalizedInvoice, $normalizedDb)) {
                continue;
            }
            $score = strlen($normalizedDb);
            if ($score > $bestScore) {
                $best = $product;
                $bestScore = $score;
            }
        }

        return $best;
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
        // MJ-1 / MJ1 / MJ 1 → MJ1
        $name = preg_replace('/([A-Z]+)\s*[\-\.]?\s*(\d+)/iu', '$1$2', $name) ?? $name;
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
