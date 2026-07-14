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
    private Database $database;
    private DispatchRepository $dispatchRepository;
    private OrderRepository $orderRepository;
    private CreditNoteRepository $creditNoteRepository;
    private DispatchTransferRepository $transferRepository;
    private DispatchService $dispatchService;

    public function __construct()
    {
        $this->database = new Database();
        $this->dispatchRepository = new DispatchRepository();
        $this->orderRepository = new OrderRepository();
        $this->creditNoteRepository = new CreditNoteRepository();
        $this->transferRepository = new DispatchTransferRepository();
        $this->dispatchService = new DispatchService();
    }

    /**
     * Handle party rejection: credit note and/or transfer truck to another party's order.
     *
     * @return array<string, mixed>
     */
    public function handleRejection(int $dispatchId, array $data, int $userId): array
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

                $this->dispatchRepository->updateLifecycle($dispatchId, [
                    'status' => 'transferred',
                    'rejection_reason' => $reason !== '' ? $reason : 'Transferred to another party',
                ]);

                $newDispatch = new Dispatch();
                $newDispatch->orderId = $targetOrderId;
                $newDispatch->dispatchDate = date('Y-m-d');
                $newDispatch->dispatchQtyTrucks = $source->dispatchQtyTrucks;
                $newDispatch->productRate = $source->productRate;
                $newDispatch->loadingWeightTons = $source->loadingWeightTons;
                $newDispatch->vehicleNo = $source->vehicleNo;
                $newDispatch->rawanaNo = $source->rawanaNo;
                $newDispatch->ewayBillNo = $source->ewayBillNo;
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

    private function createCreditNoteForDispatch(Dispatch $dispatch, object $order, array $data, int $userId, string $reason): int
    {
        $amount = isset($data['credit_amount']) && is_numeric($data['credit_amount'])
            ? (float)$data['credit_amount']
            : $this->calculateCreditAmount($dispatch);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit note amount could not be determined. Enter credit_amount or ensure weight and rate are on the dispatch.');
        }

        return $this->creditNoteRepository->create([
            'party_id' => $order->partyId,
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

    private function calculateCreditAmount(Dispatch $dispatch): float
    {
        if ($dispatch->loadingWeightTons !== null && $dispatch->loadingWeightTons > 0
            && $dispatch->productRate !== null && $dispatch->productRate > 0) {
            return round($dispatch->loadingWeightTons * $dispatch->productRate, 2);
        }
        return 0.0;
    }
}
