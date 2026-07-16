<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Dispatch;
use App\Repositories\CreditNoteRepository;
use App\Repositories\DispatchRepository;
use App\Repositories\DispatchTransferRepository;
use App\Repositories\OrderRepository;

class DispatchTransferService
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private Database $database;
    private DispatchRepository $dispatchRepository;
    private OrderRepository $orderRepository;
    private CreditNoteRepository $creditNoteRepository;
    private DispatchTransferRepository $transferRepository;
    private DispatchService $dispatchService;
    private BusyInvoiceImportService $busyInvoiceImportService;

    public function __construct()
    {
        $this->database = new Database();
        $this->dispatchRepository = new DispatchRepository();
        $this->orderRepository = new OrderRepository();
        $this->creditNoteRepository = new CreditNoteRepository();
        $this->transferRepository = new DispatchTransferRepository();
        $this->dispatchService = new DispatchService();
        $this->busyInvoiceImportService = new BusyInvoiceImportService();
    }

    /**
     * Handle party rejection: credit note and/or transfer truck to another party's order.
     *
     * @param array<string, mixed>|null $invoiceFile Uploaded invoice PDF/CSV ($_FILES entry)
     * @return array<string, mixed>
     */
    public function handleRejection(int $dispatchId, array $data, int $userId, ?array $invoiceFile = null): array
    {
        $action = (string)($data['action'] ?? '');
        if (!in_array($action, ['transfer', 'credit_note', 'replacement'], true)) {
            throw new \InvalidArgumentException('action must be transfer, credit_note, or replacement');
        }

        $source = $this->dispatchRepository->findById($dispatchId);
        if (!$source) {
            throw new \RuntimeException('Dispatch not found');
        }
        if (($source->status ?? 'active') !== 'active') {
            throw new \RuntimeException('Only active dispatches can be rejected or transferred');
        }

        $sourceOrder = $this->orderRepository->findById($source->orderId);
        if (!$sourceOrder) {
            throw new \RuntimeException('Source order not found');
        }

        $reason = trim((string)($data['reason'] ?? ''));
        $issueCreditNote = !isset($data['issue_credit_note']) || filter_var($data['issue_credit_note'], FILTER_VALIDATE_BOOLEAN);

        try {
            $this->database->beginTransaction();

            $result = [
                'action' => $action,
                'source_dispatch_id' => $dispatchId,
                'source_order_no' => $sourceOrder->orderNo,
            ];

            if ($action === 'transfer') {
                $targetOrderId = (int)($data['target_order_id'] ?? 0);
                if ($targetOrderId <= 0) {
                    throw new \InvalidArgumentException('target_order_id is required for transfer');
                }
                if ($targetOrderId === (int)$source->orderId) {
                    throw new \InvalidArgumentException('Cannot transfer to the same order');
                }

                $targetOrder = $this->orderRepository->findById($targetOrderId);
                if (!$targetOrder) {
                    throw new \RuntimeException('Target order not found');
                }
                if ((int)$targetOrder->companyId !== (int)$sourceOrder->companyId) {
                    throw new \RuntimeException('Target order must belong to the same company');
                }
                if (!in_array($targetOrder->status, ['pending', 'partial'], true)) {
                    throw new \RuntimeException('Target order must be pending or partial');
                }
                if (!$targetOrder->canDispatch($source->dispatchQtyTrucks)) {
                    throw new \RuntimeException(
                        'Target order does not have enough remaining truck capacity (' . $targetOrder->pendingTrucks . ' left)'
                    );
                }

                $transferDate = $this->resolveTransferDate($data);

                $this->dispatchRepository->updateLifecycle($dispatchId, [
                    'status' => 'transferred',
                    'rejection_reason' => $reason !== '' ? $reason : 'Transferred to another party',
                ]);

                $newDispatch = new Dispatch();
                $newDispatch->orderId = $targetOrderId;
                $newDispatch->dispatchDate = $transferDate;
                $newDispatch->dispatchQtyTrucks = $source->dispatchQtyTrucks;
                $newDispatch->productRate = $source->productRate;
                $newDispatch->loadingWeightTons = $source->loadingWeightTons;
                $newDispatch->vehicleNo = $source->vehicleNo;
                $newDispatch->rawanaNo = $source->rawanaNo;
                $newDispatch->ewayBillNo = $source->ewayBillNo;
                $newDispatch->ewayBillFilePath = $source->ewayBillFilePath;
                $newDispatch->remarks = trim(
                    'Transferred from order ' . $sourceOrder->orderNo .
                    ($source->busyInvoiceNo ? ' (invoice ' . $source->busyInvoiceNo . ')' : '') .
                    ($reason !== '' ? ' — ' . $reason : '')
                );
                $newDispatch->dispatchedBy = $userId;
                $newDispatch->status = 'active';
                $newDispatch->sourceDispatchId = $dispatchId;

                $targetDispatchId = $this->dispatchRepository->create($newDispatch);
                $targetDispatch = $this->dispatchRepository->findById($targetDispatchId);
                if (!$targetDispatch) {
                    throw new \RuntimeException('Failed to create target dispatch');
                }

                $this->dispatchRepository->updateLifecycle($dispatchId, [
                    'transferred_to_dispatch_id' => $targetDispatchId,
                ]);

                $newInvoiceNo = $this->resolveNewInvoiceForTransfer($data, $invoiceFile);
                if ($newInvoiceNo !== null) {
                    $existing = $this->dispatchRepository->findByBusyInvoiceNo($newInvoiceNo);
                    if ($existing && (int)$existing->id !== $targetDispatchId) {
                        throw new \RuntimeException(
                            'Invoice ' . $newInvoiceNo . ' is already linked to another dispatch'
                        );
                    }
                    $targetDispatch->busyInvoiceNo = $newInvoiceNo;
                    $this->dispatchRepository->update($targetDispatch);
                }

                $transferId = $this->transferRepository->create([
                    'source_dispatch_id' => $dispatchId,
                    'target_dispatch_id' => $targetDispatch->id,
                    'source_order_id' => $source->orderId,
                    'target_order_id' => $targetOrderId,
                    'source_party_id' => $sourceOrder->partyId,
                    'target_party_id' => $targetOrder->partyId,
                    'trucks_transferred' => $source->dispatchQtyTrucks,
                    'weight_tons' => $source->loadingWeightTons,
                    'action_type' => 'transfer',
                    'reason' => $reason,
                    'created_by' => $userId,
                ]);

                $creditNoteId = null;
                if ($issueCreditNote) {
                    $creditNoteId = $this->createCreditNoteForDispatch($source, $sourceOrder, $data, $userId, $reason);
                }

                $this->dispatchService->recalculateOrderStatus($source->orderId);
                $this->dispatchService->recalculateOrderStatus($targetOrderId);

                $result = array_merge($result, [
                    'target_dispatch_id' => $targetDispatchId,
                    'target_order_id' => $targetOrderId,
                    'target_order_no' => $targetOrder->orderNo,
                    'target_party_name' => $targetOrder->partyName,
                    'transfer_id' => $transferId,
                    'credit_note_id' => $creditNoteId,
                    'new_invoice_no' => $newInvoiceNo,
                    'transfer_date' => $transferDate,
                ]);
            } else {
                $status = $action === 'replacement' ? 'rejected' : 'rejected';
                $this->dispatchRepository->updateLifecycle($dispatchId, [
                    'status' => $status,
                    'rejection_reason' => $reason !== '' ? $reason : ($action === 'replacement'
                        ? 'Rejected — replacement truck to be sent'
                        : 'Rejected by party'),
                ]);

                $transferId = $this->transferRepository->create([
                    'source_dispatch_id' => $dispatchId,
                    'source_order_id' => $source->orderId,
                    'source_party_id' => $sourceOrder->partyId,
                    'trucks_transferred' => $source->dispatchQtyTrucks,
                    'weight_tons' => $source->loadingWeightTons,
                    'action_type' => $action,
                    'reason' => $reason,
                    'created_by' => $userId,
                ]);

                $creditNoteId = null;
                if ($issueCreditNote) {
                    $creditNoteId = $this->createCreditNoteForDispatch($source, $sourceOrder, $data, $userId, $reason);
                }

                $this->dispatchService->recalculateOrderStatus($source->orderId);

                $result = array_merge($result, [
                    'transfer_id' => $transferId,
                    'credit_note_id' => $creditNoteId,
                    'message' => $action === 'replacement'
                        ? 'Dispatch rejected. Order capacity restored — create a new dispatch when replacement truck is sent.'
                        : 'Dispatch rejected and credit note recorded.',
                ]);
            }

            $this->database->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }
    }

    /**
     * Pending/partial orders available as transfer targets (excluding source order).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTransferTargets(int $sourceOrderId, ?int $companyId = null): array
    {
        $orders = $this->orderRepository->findDispatchQueue($companyId);
        $targets = [];
        foreach ($orders as $row) {
            if ((int)$row['id'] === $sourceOrderId) {
                continue;
            }
            if ((int)($row['remaining_trucks'] ?? 0) <= 0) {
                continue;
            }
            $targets[] = [
                'order_id' => (int)$row['id'],
                'order_no' => $row['order_no'],
                'party_name' => $row['party_name'],
                'product_name' => $row['product_name'],
                'remaining_trucks' => (int)$row['remaining_trucks'],
                'status' => $row['status'],
            ];
        }
        return $targets;
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listTransferRecords(array $filters): array
    {
        $limit = max(1, min((int)($filters['limit'] ?? 50), 200));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $queryFilters = array_merge($filters, [
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $rows = $this->transferRepository->findAll($queryFilters);
        $total = $this->transferRepository->count($filters);

        return [
            'rows' => array_map(function (array $row): array {
                $eventDate = $row['transfer_date'] ?? null;
                if ($eventDate === null || $eventDate === '') {
                    $eventDate = isset($row['created_at']) ? substr((string)$row['created_at'], 0, 10) : null;
                }

                return [
                    'id' => (int)$row['id'],
                    'action_type' => $row['action_type'],
                    'event_date' => $eventDate,
                    'trucks_transferred' => (int)($row['trucks_transferred'] ?? 0),
                    'weight_tons' => $row['weight_tons'] !== null ? (float)$row['weight_tons'] : null,
                    'reason' => $row['reason'],
                    'source_dispatch_id' => (int)$row['source_dispatch_id'],
                    'target_dispatch_id' => $row['target_dispatch_id'] !== null ? (int)$row['target_dispatch_id'] : null,
                    'source_order_id' => (int)$row['source_order_id'],
                    'target_order_id' => $row['target_order_id'] !== null ? (int)$row['target_order_id'] : null,
                    'source_order_no' => $row['source_order_no'],
                    'target_order_no' => $row['target_order_no'],
                    'source_party_name' => $row['source_party_name'],
                    'target_party_name' => $row['target_party_name'],
                    'source_invoice_no' => $row['source_invoice_no'],
                    'target_invoice_no' => $row['target_invoice_no'],
                    'credit_note_no' => $row['busy_credit_note_no'],
                    'credit_amount' => $row['credit_amount'] !== null ? (float)$row['credit_amount'] : null,
                    'credit_note_date' => $row['credit_note_date'],
                    'created_by_name' => $row['created_by_name'],
                    'created_at' => $row['created_at'],
                ];
            }, $rows),
            'total' => $total,
        ];
    }

    private function createCreditNoteForDispatch(Dispatch $dispatch, object $order, array $data, int $userId, string $reason): int
    {
        $amount = isset($data['credit_amount']) && is_numeric($data['credit_amount'])
            ? (float)$data['credit_amount']
            : $this->calculateCreditAmount($dispatch);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit note amount could not be determined. Enter credit_amount or ensure weight and rate are on the dispatch.');
        }

        return $this->creditNoteRepository->create([
            'party_id' => $this->resolveCreditNotePartyId($order),
            'dispatch_id' => $dispatch->id,
            'order_id' => $order->id,
            'busy_credit_note_no' => !empty($data['credit_note_no']) ? trim((string)$data['credit_note_no']) : null,
            'original_invoice_no' => $dispatch->busyInvoiceNo,
            'amount' => $amount,
            'weight_tons' => $dispatch->loadingWeightTons,
            'rate_per_ton' => $dispatch->productRate,
            'note_date' => $data['credit_note_date'] ?? date('Y-m-d'),
            'reason' => $reason,
            'created_by' => $userId,
        ]);
    }

    private function resolveTransferDate(array $data): string
    {
        $transferDate = trim((string)($data['transfer_date'] ?? ''));
        if ($transferDate === '') {
            return date('Y-m-d');
        }

        $parsed = \DateTime::createFromFormat('Y-m-d', $transferDate);
        if (!$parsed || $parsed->format('Y-m-d') !== $transferDate) {
            throw new \InvalidArgumentException('transfer_date must be a valid date (YYYY-MM-DD)');
        }

        return $transferDate;
    }

    private function resolveCreditNotePartyId(object $order): int
    {
        if (!empty($order->billToOtherParty) && !empty($order->billingPartyId)) {
            return (int)$order->billingPartyId;
        }
        return (int)$order->partyId;
    }

    private function resolveNewInvoiceForTransfer(array $data, ?array $file): ?string
    {
        $manual = trim((string)($data['new_invoice_no'] ?? ''));

        if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException('Invoice file upload failed.');
            }

            $tmpName = (string)($file['tmp_name'] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new \InvalidArgumentException('Invalid uploaded invoice file.');
            }

            $size = (int)($file['size'] ?? 0);
            if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
                throw new \InvalidArgumentException('Invoice file must be between 1 byte and 5MB.');
            }

            $content = file_get_contents($tmpName);
            if ($content === false) {
                throw new \InvalidArgumentException('Could not read invoice file.');
            }

            $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (strncmp($content, '%PDF-', 5) === 0) {
                $ext = 'pdf';
            }
            if (!in_array($ext, ['csv', 'pdf'], true)) {
                throw new \InvalidArgumentException('Upload a Busy tax invoice PDF or CSV export.');
            }

            $parsed = $this->busyInvoiceImportService->parseUpload($content, $ext, $tmpName);
            if (!empty($parsed['errors']) && empty($parsed['invoices'])) {
                $summary = $parsed['errors'][0] ?? 'Unknown parse error';
                throw new \InvalidArgumentException('Could not parse invoice file: ' . $summary);
            }

            $fromFile = trim((string)($parsed['invoices'][0]['invoice_no'] ?? ''));
            if ($fromFile === '') {
                throw new \InvalidArgumentException('Could not find invoice number in uploaded file.');
            }

            if ($manual !== '' && strcasecmp($manual, $fromFile) !== 0) {
                throw new \InvalidArgumentException(
                    'Invoice number in file (' . $fromFile . ') does not match entered number (' . $manual . ')'
                );
            }

            return $fromFile;
        }

        return $manual !== '' ? $manual : null;
    }

    private function calculateCreditAmount(Dispatch $dispatch): float
    {
        if ($dispatch->loadingWeightTons !== null && $dispatch->loadingWeightTons > 0
            && $dispatch->productRate !== null && $dispatch->productRate > 0) {
            return round($dispatch->loadingWeightTons * $dispatch->productRate, 2);
        }
        return 0.0;
    }
}
