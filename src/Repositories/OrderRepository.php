<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Order;
use App\Support\DispatchSchema;
use App\Support\OrderSchema;

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
                       SUM(COALESCE(loading_weight_tons, 0)) as total_dispatched_weight
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
        $sql = "
            SELECT o.id,
                   o.order_no,
                   o.order_date,
                   o.scheduled_dispatch_date,
                   o.status,
                   o.priority,
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
                   pt.credit_limit AS party_credit_limit
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
            LEFT JOIN (
                SELECT party_id,
                       SUM(CASE WHEN entry_type = 'payment' THEN -amount ELSE amount END) AS outstanding
                FROM crm_receivable_entries
                GROUP BY party_id
            ) recv ON recv.party_id = o.party_id
            WHERE o.status IN ('pending', 'partial')
        ";

        $params = [];
        if ($companyId !== null && $companyId > 0) {
            $sql .= " AND o.company_id = ?";
            $params[] = $companyId;
        }

        $sql .= " ORDER BY FIELD(o.priority, 'urgent', 'normal'), o.order_date ASC, o.id ASC";

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
                       SUM(COALESCE(loading_weight_tons, 0)) as total_dispatched_weight
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
                       SUM(COALESCE(loading_weight_tons, 0)) as total_dispatched_weight
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
        if (OrderSchema::hasBillingPartyColumns()) {
            $sql = "
                INSERT INTO orders (company_id, order_no, order_date, scheduled_dispatch_date, product_id, order_qty_trucks, order_qty_mode, order_weight_tons, tons_per_truck, party_id, bill_to_other_party, billing_party_id, priority, is_recurring, delivery_frequency_days, trucks_per_delivery, total_deliveries, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $this->database->execute($sql, [
                $order->companyId,
                $order->orderNo,
                $order->orderDate,
                $order->scheduledDispatchDate,
                $order->productId,
                $order->orderQtyTrucks,
                $order->orderQtyMode,
                $order->orderWeightTons,
                $order->tonsPerTruck,
                $order->partyId,
                $order->billToOtherParty ? 1 : 0,
                $order->billingPartyId,
                $order->priority,
                $order->isRecurring ? 1 : 0,
                $order->deliveryFrequencyDays,
                $order->trucksPerDelivery,
                $order->totalDeliveries,
                $order->createdBy
            ]);
        } else {
            $sql = "
                INSERT INTO orders (company_id, order_no, order_date, scheduled_dispatch_date, product_id, order_qty_trucks, order_qty_mode, order_weight_tons, tons_per_truck, party_id, priority, is_recurring, delivery_frequency_days, trucks_per_delivery, total_deliveries, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $this->database->execute($sql, [
                $order->companyId,
                $order->orderNo,
                $order->orderDate,
                $order->scheduledDispatchDate,
                $order->productId,
                $order->orderQtyTrucks,
                $order->orderQtyMode,
                $order->orderWeightTons,
                $order->tonsPerTruck,
                $order->partyId,
                $order->priority,
                $order->isRecurring ? 1 : 0,
                $order->deliveryFrequencyDays,
                $order->trucksPerDelivery,
                $order->totalDeliveries,
                $order->createdBy
            ]);
        }
        
        return (int)$this->database->lastInsertId();
    }
    
    public function update(Order $order): bool
    {
        if (OrderSchema::hasBillingPartyColumns()) {
            $sql = "
                UPDATE orders 
                SET company_id = ?, order_date = ?, product_id = ?, order_qty_trucks = ?, order_qty_mode = ?, order_weight_tons = ?, tons_per_truck = ?, party_id = ?, bill_to_other_party = ?, billing_party_id = ?, priority = ?
                WHERE id = ?
            ";

            return $this->database->execute($sql, [
                $order->companyId,
                $order->orderDate,
                $order->productId,
                $order->orderQtyTrucks,
                $order->orderQtyMode,
                $order->orderWeightTons,
                $order->tonsPerTruck,
                $order->partyId,
                $order->billToOtherParty ? 1 : 0,
                $order->billingPartyId,
                $order->priority,
                $order->id
            ]);
        }

        $sql = "
            UPDATE orders 
            SET company_id = ?, order_date = ?, product_id = ?, order_qty_trucks = ?, order_qty_mode = ?, order_weight_tons = ?, tons_per_truck = ?, party_id = ?, priority = ?
            WHERE id = ?
        ";
        
        return $this->database->execute($sql, [
            $order->companyId,
            $order->orderDate,
            $order->productId,
            $order->orderQtyTrucks,
            $order->orderQtyMode,
            $order->orderWeightTons,
            $order->tonsPerTruck,
            $order->partyId,
            $order->priority,
            $order->id
        ]);
    }
    
    public function updateStatus(int $orderId, string $status): bool
    {
        $sql = "UPDATE orders SET status = ? WHERE id = ?";
        return $this->database->execute($sql, [$status, $orderId]);
    }
    
    public function generateOrderNumber(int $companyId): string
    {
        $company = $this->database->fetch(
            'SELECT id, name, order_prefix FROM companies WHERE id = ? LIMIT 1',
            [$companyId]
        );
        if (!$company) {
            throw new \RuntimeException("Company {$companyId} not found for order number generation");
        }

        $prefix = strtoupper(trim((string)($company['order_prefix'] ?? '')));
        if ($prefix === '') {
            $prefix = \App\Support\OrderPrefix::suggestFromName((string)$company['name']);
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

