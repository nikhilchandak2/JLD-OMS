<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\CrmDealRepository;
use App\Support\OrderSchema;
use App\Support\TableSchema;

/**
 * Repeat-order fast path (B7). No deal record is created. The credit gate is
 * the only gate and the result is never shown until the server has confirmed it.
 */
class DirectOrderCaptureService
{
    private Database $database;
    private OrderService $orders;
    private CreditGateService $gate;
    private CreditOverrideService $overrides;
    private CreditGatePolicy $policy;
    private CrmDealRepository $deals;

    public function __construct()
    {
        $this->database = new Database();
        $this->orders = new OrderService();
        $this->gate = new CreditGateService();
        $this->overrides = new CreditOverrideService();
        $this->policy = new CreditGatePolicy();
        $this->deals = new CrmDealRepository();
    }

    /**
     * Prefill recently ordered grades, last quantities, and last rate for a party.
     *
     * @return array<string,mixed>
     */
    public function prefill(int $partyId, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CreditGatePolicy::CAPTURE);

        $history = $this->database->fetchAll(
            "SELECT p.id AS product_id, p.code, p.name,
                    o.order_qty_trucks, o.order_qty_mode, o.order_weight_tons, o.tons_per_truck,
                    o.order_date,
                    (
                        SELECT d.product_rate
                        FROM dispatches d
                        WHERE d.order_id = o.id AND d.product_rate IS NOT NULL
                        ORDER BY d.id DESC
                        LIMIT 1
                    ) AS last_rate
             FROM orders o
             JOIN products p ON p.id = o.product_id
             WHERE o.party_id = ?
             ORDER BY o.order_date DESC, o.id DESC
             LIMIT 40",
            [$partyId]
        );

        $grades = [];
        foreach ($history as $row) {
            $pid = (int)$row['product_id'];
            if (isset($grades[$pid])) {
                continue;
            }
            $grades[$pid] = [
                'product_id' => $pid,
                'code' => $row['code'],
                'name' => $row['name'],
                'order_qty_trucks' => (int)$row['order_qty_trucks'],
                'order_qty_mode' => $row['order_qty_mode'],
                'order_weight_tons' => $row['order_weight_tons'] !== null ? (float)$row['order_weight_tons'] : null,
                'tons_per_truck' => (float)($row['tons_per_truck'] ?? 40),
                'last_rate' => $row['last_rate'] !== null ? (float)$row['last_rate'] : null,
                'last_order_date' => $row['order_date'],
            ];
        }

        $statusSelect = TableSchema::hasColumn('crm_deals', 'status') ? 'd.status' : "'active' AS status";
        $where = ['d.party_id = ?'];
        if (TableSchema::hasColumn('crm_deals', 'status')) {
            $where[] = "d.status = 'active'";
        }
        if (TableSchema::hasColumn('crm_deals', 'deleted_at')) {
            $where[] = 'd.deleted_at IS NULL';
        }

        $gradeJoin = TableSchema::hasTable('crm_deal_grades')
            ? 'LEFT JOIN crm_deal_grades g ON g.deal_id = d.id'
            : 'LEFT JOIN (SELECT NULL AS deal_id, NULL AS grade_code) g ON 1=0';
        $openDeals = $this->database->fetchAll(
            "SELECT d.id, d.title, d.stage, {$statusSelect}, d.value,
                    GROUP_CONCAT(g.grade_code ORDER BY g.grade_code SEPARATOR ', ') AS grades
             FROM crm_deals d
             {$gradeJoin}
             WHERE " . implode(' AND ', $where) . "
             GROUP BY d.id
             ORDER BY d.stage DESC, d.id DESC",
            [$partyId]
        );

        return [
            'party_id' => $partyId,
            'recent_grades' => array_values($grades),
            'open_deals' => $openDeals,
        ];
    }

    /**
     * Capture one or more grade lines for an existing party. No deal is created.
     *
     * @param array<string,mixed> $input
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function capture(array $input, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, CreditGatePolicy::CAPTURE);

        $partyId = (int)($input['party_id'] ?? 0);
        $companyId = (int)($input['company_id'] ?? 0);
        if ($partyId <= 0 || $companyId <= 0) {
            throw new CreditGateException('Party and company are required.');
        }

        $lines = $this->normaliseLines($input);
        if ($lines === []) {
            throw new CreditGateException('At least one grade and quantity is required.');
        }

        $proposed = isset($input['proposed_order_value']) && $input['proposed_order_value'] !== ''
            ? (float)$input['proposed_order_value']
            : $this->estimateValue($lines);
        $reason = trim((string)($input['rep_reason'] ?? $input['reason'] ?? ''));

        $evaluation = $this->gate->evaluate($partyId, $companyId, $proposed);
        $tier = (int)$evaluation['tier'];
        if ($tier > CreditGateService::TIER_AUTO && $reason === '') {
            throw new CreditGateException(
                'A reason is required when the order is over the credit limit.',
                ['evaluation' => $this->gate->serializeForRole($evaluation, $actor['role'] ?? null)]
            );
        }

        $optionalDealId = !empty($input['deal_id']) ? (int)$input['deal_id'] : null;
        if ($optionalDealId) {
            $deal = $this->deals->findById($optionalDealId);
            if ($deal === null || (int)$deal['party_id'] !== $partyId) {
                throw new CreditGateException('The linked deal does not belong to this party.');
            }
        }

        $created = [];
        $this->database->beginTransaction();
        try {
            foreach ($lines as $line) {
                $created[] = $this->orders->createOrder(array_merge($line, [
                    'company_id' => $companyId,
                    'party_id' => $partyId,
                    'created_by' => $actor['id'] ?? null,
                    'created_by_role' => $actor['role'] ?? '',
                    'skip_credit_gate' => true,
                    'order_date' => $input['order_date'] ?? $this->gate->now()->format('Y-m-d'),
                    'priority' => $input['priority'] ?? 'normal',
                    'bill_to_other_party' => $input['bill_to_other_party'] ?? false,
                    'billing_party_id' => $input['billing_party_id'] ?? null,
                    'is_recurring' => $input['is_recurring'] ?? false,
                    'delivery_frequency_days' => $input['delivery_frequency_days'] ?? null,
                    'trucks_per_delivery' => $input['trucks_per_delivery'] ?? null,
                    'total_deliveries' => $input['total_deliveries'] ?? null,
                ]));
            }

            $primary = $created[0];
            $request = null;
            $status = $evaluation['credit_gate_status'];

            if ($tier === CreditGateService::TIER_AUTO) {
                $this->stampOrders($created, $status, null);
            } else {
                $request = $this->overrides->raise($evaluation, $actor, $reason, null, (int)$primary->id);
                $this->stampOrders($created, $status, (int)$request['id']);
            }

            $this->database->commit();
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }

        return [
            'orders' => array_map(static fn($o) => $o->toArray(), $created),
            'credit_gate' => $this->gate->serializeForRole($evaluation, $actor['role'] ?? null),
            'override' => $request,
            'linked_deal_id' => $optionalDealId,
        ];
    }

    /**
     * @param array<int,\App\Models\Order> $orders
     */
    private function stampOrders(array $orders, string $status, ?int $requestId): void
    {
        if (!OrderSchema::hasCreditGateColumns()) {
            return;
        }
        foreach ($orders as $order) {
            $this->database->execute(
                "UPDATE orders SET credit_gate_status = ?, credit_override_request_id = ? WHERE id = ?",
                [$status, $requestId, $order->id]
            );
            $order->creditGateStatus = $status;
            $order->creditOverrideRequestId = $requestId;
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<int,array<string,mixed>>
     */
    private function normaliseLines(array $input): array
    {
        $raw = $input['lines'] ?? null;
        if (!is_array($raw) || $raw === []) {
            if (empty($input['product_id'])) {
                return [];
            }
            $raw = [$input];
        }

        $lines = [];
        foreach ($raw as $line) {
            if (!is_array($line) || empty($line['product_id'])) {
                continue;
            }
            $qty = OrderService::resolveOrderQuantities($line);
            $scheduled = $line['scheduled_dispatch_date'] ?? $input['scheduled_dispatch_date'] ?? null;
            $lines[] = [
                'product_id' => (int)$line['product_id'],
                'order_qty_mode' => $qty['order_qty_mode'],
                'order_qty_trucks' => $qty['order_qty_trucks'],
                'order_weight_tons' => $qty['order_weight_tons'],
                'tons_per_truck' => $qty['tons_per_truck'],
                'scheduled_dispatch_date' => $scheduled ?: null,
            ];
        }

        return $lines;
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     */
    private function estimateValue(array $lines): float
    {
        $total = 0.0;
        foreach ($lines as $line) {
            $rate = isset($line['last_rate']) ? (float)$line['last_rate'] : 0.0;
            $tonnes = (float)($line['order_weight_tons'] ?? 0);
            if ($rate > 0 && $tonnes > 0) {
                $total += $rate * $tonnes;
            }
        }

        return round($total, 2);
    }
}
