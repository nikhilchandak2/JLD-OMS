<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Order;
use App\Support\DispatchSchema;
use App\Support\OrderSchema;
use App\Support\TableSchema;

class OrderRepository
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function findAll(array $filters = []): array
    {
        $activeWhere = DispatchSchema::activeDispatchWhere();
        $billingJoin = '';
        if (!empty($filters['party_search']) && OrderSchema::hasBillingPartyColumns()) {
            $billingJoin = OrderSchema::billingPartyJoin();
        }

        $sql = "
            SELECT o.*, 
                   c.name as company_name,
                   p.name as product_name,
                   pt.name as party_name,
                   u.name as created_by_name,
                   COALESCE(d.total_dispatched, 0) as total_dispatched,
                   COALESCE(d.total_dispatched_weight, 0) as total_dispatched_weight
            FROM orders o
            JOIN companies c ON o.company_id = c.id
            JOIN products p ON o.product_id = p.id
            JOIN parties pt ON o.party_id = pt.id
            {$billingJoin}
            LEFT JOIN users u ON o.created_by = u.id
            LEFT JOIN (
                SELECT order_id,
                       SUM(dispatch_qty_trucks) as total_dispatched,
                       " . (DispatchSchema::hasLoadingWeightColumn()
                           ? 'SUM(COALESCE(loading_weight_tons, 0)) as total_dispatched_weight'
                           : '0 as total_dispatched_weight') . "
                FROM dispatches
                WHERE {$activeWhere}
                GROUP BY order_id
            ) d ON o.id = d.order_id
            WHERE 1=1
        ";
        
        $params = [];
        
        // Apply filters
        if (!empty($filters['start_date'])) {
            $sql .= " AND o.order_date >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND o.order_date <= ?";
            $params[] = $filters['end_date'];
        }
        
        if (!empty($filters['party_id'])) {
            $sql .= " AND o.party_id = ?";
            $params[] = $filters['party_id'];
        }

        if (!empty($filters['party_search'])) {
            $like = '%' . $filters['party_search'] . '%';
            if (OrderSchema::hasBillingPartyColumns()) {
                $sql .= " AND (pt.name LIKE ? OR bp.name LIKE ?)";
                $params[] = $like;
                $params[] = $like;
            } else {
                $sql .= " AND pt.name LIKE ?";
                $params[] = $like;
            }
        }
        
        if (!empty($filters['product_id'])) {
            $sql .= " AND o.product_id = ?";
            $params[] = $filters['product_id'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND o.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['company_id'])) {
            $sql .= " AND o.company_id = ?";
            $params[] = (int)$filters['company_id'];
        }
        
        $sql .= " ORDER BY o.order_date DESC, o.id DESC";
        
        // Add pagination
        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
            
            if (isset($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int)$filters['offset'];
            }
        }
        
        $results = $this->database->fetchAll($sql, $params);
        
        return array_map(function($row) {
            return new Order($row);
        }, $results);
    }
    
    /**
     * Dispatch queue: pending/partial orders with remaining trucks and party outstanding.
     * Urgent orders first, then oldest orders first.
     */
    public function findDispatchQueue(?int $companyId = null): array
    {
        $activeWhere = DispatchSchema::activeDispatchWhere();
        $transportDoc = DispatchSchema::companyTransportDocSelect('c');
        $sched = TableSchema::hasColumn('orders', 'scheduled_dispatch_date')
            ? 'o.scheduled_dispatch_date'
            : 'NULL AS scheduled_dispatch_date';
        $priority = TableSchema::hasColumn('orders', 'priority')
            ? 'o.priority'
            : "'normal' AS priority";
        $credit = TableSchema::hasColumn('parties', 'credit_limit')
            ? 'pt.credit_limit AS party_credit_limit'
            : 'NULL AS party_credit_limit';
        $recvJoin = TableSchema::hasTable('crm_receivable_entries')
            ? "LEFT JOIN (
                SELECT party_id,
                       SUM(CASE WHEN entry_type = 'payment' THEN -amount ELSE amount END) AS outstanding
                FROM crm_receivable_entries
                GROUP BY party_id
            ) recv ON recv.party_id = o.party_id"
            : 'LEFT JOIN (SELECT NULL AS party_id, 0 AS outstanding) recv ON 1=0';
        $orderBy = TableSchema::hasColumn('orders', 'priority')
            ? "ORDER BY FIELD(o.priority, 'urgent', 'normal'), o.order_date ASC, o.id ASC"
            : 'ORDER BY o.order_date ASC, o.id ASC';
        $sql = "
            SELECT o.id,
                   o.order_no,
                   o.order_date,
                   {$sched},
                   o.status,
                   {$priority},
                   o.order_qty_trucks,
                   c.name AS company_name,
                   {$transportDoc},
                   p.name AS product_name,
                   pt.id AS party_id,
                   pt.name AS party_name,
                   COALESCE(d.total_dispatched, 0) AS total_dispatched,
                   (o.order_qty_trucks - COALESCE(d.total_dispatched, 0)) AS remaining_trucks,
                   DATEDIFF(CURDATE(), o.order_date) AS age_days,
                   COALESCE(recv.outstanding, 0) AS party_outstanding,
                   {$credit}
            FROM orders o
            JOIN companies c ON o.company_id = c.id
            JOIN products p ON o.product_id = p.id
            JOIN parties pt ON o.party_id = pt.id
            LEFT JOIN (
                SELECT order_id, SUM(dispatch_qty_trucks) AS total_dispatched
                FROM dispatches
                WHERE {$activeWhere}
                GROUP BY order_id
            ) d ON o.id = d.order_id
            {$recvJoin}
            WHERE o.status IN ('pending', 'partial')
        ";
        if (OrderSchema::hasCreditGateColumns()) {
            $sql .= " AND o.credit_gate_status <> 'blocked'";
        }

        $params = [];
        if ($companyId !== null && $companyId > 0) {
            $sql .= " AND o.company_id = ?";
            $params[] = $companyId;
        }

        $sql .= " {$orderBy}";

        return $this->database->fetchAll($sql, $params);
    }

    public function findById(int $id): ?Order
    {
        $activeWhere = DispatchSchema::activeDispatchWhere();
        $transportDoc = DispatchSchema::companyTransportDocSelect('c');
        $billingJoin = OrderSchema::billingPartyJoin();
        $billingName = OrderSchema::billingPartyNameSelect();
        $sql = "
            SELECT o.*, 
                   c.name as company_name,
                   {$transportDoc},
                   p.name as product_name,
                   pt.name as party_name,
                   {$billingName},
                   u.name as created_by_name,
                   COALESCE(d.total_dispatched, 0) as total_dispatched,
                   COALESCE(d.total_dispatched_weight, 0) as total_dispatched_weight
            FROM orders o
            JOIN companies c ON o.company_id = c.id
            JOIN products p ON o.product_id = p.id
            JOIN parties pt ON o.party_id = pt.id
            {$billingJoin}
            LEFT JOIN users u ON o.created_by = u.id
            LEFT JOIN (
                SELECT order_id,
                       SUM(dispatch_qty_trucks) as total_dispatched,
                       " . (DispatchSchema::hasLoadingWeightColumn()
                           ? 'SUM(COALESCE(loading_weight_tons, 0)) as total_dispatched_weight'
                           : '0 as total_dispatched_weight') . "
                FROM dispatches
                WHERE {$activeWhere}
                GROUP BY order_id
            ) d ON o.id = d.order_id
            WHERE o.id = ?
        ";
        
        $result = $this->database->fetch($sql, [$id]);
        
        return $result ? new Order($result) : null;
    }
    
    public function findByOrderNo(string $orderNo): ?Order
    {
        $activeWhere = DispatchSchema::activeDispatchWhere();
        $billingJoin = OrderSchema::billingPartyJoin();
        $billingName = OrderSchema::billingPartyNameSelect();
        $sql = "
            SELECT o.*, 
                   p.name as product_name,
                   pt.name as party_name,
                   {$billingName},
                   u.name as created_by_name,
                   COALESCE(d.total_dispatched, 0) as total_dispatched
            FROM orders o
            JOIN products p ON o.product_id = p.id
            JOIN parties pt ON o.party_id = pt.id
            {$billingJoin}
            LEFT JOIN users u ON o.created_by = u.id
            LEFT JOIN (
                SELECT order_id,
                       SUM(dispatch_qty_trucks) as total_dispatched,
                       " . (DispatchSchema::hasLoadingWeightColumn()
                           ? 'SUM(COALESCE(loading_weight_tons, 0)) as total_dispatched_weight'
                           : '0 as total_dispatched_weight') . "
                FROM dispatches
                WHERE {$activeWhere}
                GROUP BY order_id
            ) d ON o.id = d.order_id
            WHERE o.order_no = ?
        ";
        
        $result = $this->database->fetch($sql, [$orderNo]);
        
        return $result ? new Order($result) : null;
    }
    
    public function create(Order $order): int
    {
        $values = [
            'company_id' => $order->companyId,
            'order_no' => $order->orderNo,
            'order_date' => $order->orderDate,
            'product_id' => $order->productId,
            'order_qty_trucks' => $order->orderQtyTrucks,
            'party_id' => $order->partyId,
            'created_by' => $order->createdBy,
        ];
        $optional = [
            'scheduled_dispatch_date' => $order->scheduledDispatchDate,
            'order_qty_mode' => $order->orderQtyMode,
            'order_weight_tons' => $order->orderWeightTons,
            'tons_per_truck' => $order->tonsPerTruck,
            'bill_to_other_party' => $order->billToOtherParty ? 1 : 0,
            'billing_party_id' => $order->billingPartyId,
            'priority' => $order->priority,
            'is_recurring' => $order->isRecurring ? 1 : 0,
            'delivery_frequency_days' => $order->deliveryFrequencyDays,
            'trucks_per_delivery' => $order->trucksPerDelivery,
            'total_deliveries' => $order->totalDeliveries,
        ];
        foreach ($optional as $column => $value) {
            if (TableSchema::hasColumn('orders', $column)) {
                $values[$column] = $value;
            }
        }
        $columns = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $this->database->execute(
            'INSERT INTO orders (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')',
            array_values($values)
        );

        return (int)$this->database->lastInsertId();
    }
    
    public function update(Order $order): bool
    {
        $values = [
            'company_id' => $order->companyId,
            'order_date' => $order->orderDate,
            'product_id' => $order->productId,
            'order_qty_trucks' => $order->orderQtyTrucks,
            'party_id' => $order->partyId,
        ];
        $optional = [
            'order_qty_mode' => $order->orderQtyMode,
            'order_weight_tons' => $order->orderWeightTons,
            'tons_per_truck' => $order->tonsPerTruck,
            'bill_to_other_party' => $order->billToOtherParty ? 1 : 0,
            'billing_party_id' => $order->billingPartyId,
            'priority' => $order->priority,
        ];
        foreach ($optional as $column => $value) {
            if (TableSchema::hasColumn('orders', $column)) {
                $values[$column] = $value;
            }
        }
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            $sets[] = "{$column} = ?";
            $params[] = $value;
        }
        $params[] = $order->id;

        return $this->database->execute(
            'UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }
    
    public function updateStatus(int $orderId, string $status): bool
    {
        $sql = "UPDATE orders SET status = ? WHERE id = ?";
        return $this->database->execute($sql, [$status, $orderId]);
    }
    
    public function generateOrderNumber(int $companyId): string
    {
        $hasPrefix = TableSchema::hasColumn('companies', 'order_prefix');
        $company = $this->database->fetch(
            $hasPrefix
                ? 'SELECT id, name, order_prefix FROM companies WHERE id = ? LIMIT 1'
                : 'SELECT id, name FROM companies WHERE id = ? LIMIT 1',
            [$companyId]
        );
        if (!$company) {
            throw new \RuntimeException("Company {$companyId} not found for order number generation");
        }

        $prefix = $hasPrefix ? strtoupper(trim((string)($company['order_prefix'] ?? ''))) : '';
        if ($prefix === '') {
            $prefix = \App\Support\OrderPrefix::suggestFromName((string)$company['name']);
            if ($hasPrefix) {
                try {
                    $this->database->execute(
                        'UPDATE companies SET order_prefix = ? WHERE id = ? AND (order_prefix IS NULL OR order_prefix = \'\')',
                        [$prefix, $companyId]
                    );
                } catch (\Throwable $ignored) {
                    // Unique collision — keep suggested prefix for this number only
                }
                $reloaded = $this->database->fetch(
                    'SELECT order_prefix FROM companies WHERE id = ? LIMIT 1',
                    [$companyId]
                );
                if (!empty($reloaded['order_prefix'])) {
                    $prefix = strtoupper(trim((string)$reloaded['order_prefix']));
                }
            }
        }

        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefix) ?? $prefix;
        if ($prefix === '') {
            $prefix = 'ORD';
        }

        $like = $prefix . '-%';
        $result = $this->database->fetch(
            "SELECT COALESCE(MAX(
                CAST(SUBSTRING(order_no, LOCATE('-', order_no) + 1) AS UNSIGNED)
             ), 0) + 1 AS next_seq
             FROM orders
             WHERE company_id = ?
               AND order_no LIKE ?
               AND order_no NOT LIKE '__TMP_%'",
            [$companyId, $like]
        );

        $sequence = (int)($result['next_seq'] ?? 1);
        return \App\Support\OrderPrefix::format($prefix, $sequence);
    }
    
    public function getTotalDispatched(int $orderId): int
    {
        $activeWhere = DispatchSchema::activeDispatchWhere('dispatches');
        $sql = "SELECT COALESCE(SUM(dispatch_qty_trucks), 0) as total FROM dispatches WHERE order_id = ? AND {$activeWhere}";
        $result = $this->database->fetch($sql, [$orderId]);
        return (int)$result['total'];
    }
    
    public function count(array $filters = []): int
    {
        $billingJoin = '';
        $partyJoin = '';
        if (!empty($filters['party_search'])) {
            $partyJoin = ' JOIN parties pt ON o.party_id = pt.id';
            if (OrderSchema::hasBillingPartyColumns()) {
                $billingJoin = ' ' . OrderSchema::billingPartyJoin();
            }
        }

        $sql = "SELECT COUNT(*) as count FROM orders o{$partyJoin}{$billingJoin} WHERE 1=1";
        $params = [];
        
        // Apply same filters as findAll
        if (!empty($filters['start_date'])) {
            $sql .= " AND o.order_date >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND o.order_date <= ?";
            $params[] = $filters['end_date'];
        }
        
        if (!empty($filters['party_id'])) {
            $sql .= " AND o.party_id = ?";
            $params[] = $filters['party_id'];
        }

        if (!empty($filters['party_search'])) {
            $like = '%' . $filters['party_search'] . '%';
            if (OrderSchema::hasBillingPartyColumns()) {
                $sql .= " AND (pt.name LIKE ? OR bp.name LIKE ?)";
                $params[] = $like;
                $params[] = $like;
            } else {
                $sql .= " AND pt.name LIKE ?";
                $params[] = $like;
            }
        }
        
        if (!empty($filters['product_id'])) {
            $sql .= " AND o.product_id = ?";
            $params[] = $filters['product_id'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND o.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['company_id'])) {
            $sql .= " AND o.company_id = ?";
            $params[] = (int)$filters['company_id'];
        }
        
        $result = $this->database->fetch($sql, $params);
        return (int)$result['count'];
    }
    
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM orders WHERE id = ?";
        return $this->database->execute($sql, [$id]);
    }
}

