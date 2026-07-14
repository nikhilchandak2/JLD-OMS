<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Order;

class OrderRepository
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function findAll(array $filters = []): array
    {
        $sql = "
            SELECT o.*, 
                   c.name as company_name,
                   p.name as product_name,
                   pt.name as party_name,
                   u.name as created_by_name,
                   COALESCE(d.total_dispatched, 0) as total_dispatched
            FROM orders o
            JOIN companies c ON o.company_id = c.id
            JOIN products p ON o.product_id = p.id
            JOIN parties pt ON o.party_id = pt.id
            LEFT JOIN users u ON o.created_by = u.id
            LEFT JOIN (
                SELECT order_id,
                       SUM(dispatch_qty_trucks) as total_dispatched,
                       SUM(COALESCE(loading_weight_tons, 0)) as total_dispatched_weight
                FROM dispatches
                WHERE status = 'active'
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
        $sql = "
            SELECT o.id,
                   o.order_no,
                   o.order_date,
                   o.scheduled_dispatch_date,
                   o.status,
                   o.priority,
                   o.order_qty_trucks,
                   c.name AS company_name,
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
                WHERE status = 'active'
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
        $sql = "
            SELECT o.*, 
                   p.name as product_name,
                   pt.name as party_name,
                   u.name as created_by_name,
                   COALESCE(d.total_dispatched, 0) as total_dispatched
            FROM orders o
            JOIN products p ON o.product_id = p.id
            JOIN parties pt ON o.party_id = pt.id
            LEFT JOIN users u ON o.created_by = u.id
            LEFT JOIN (
                SELECT order_id,
                       SUM(dispatch_qty_trucks) as total_dispatched,
                       SUM(COALESCE(loading_weight_tons, 0)) as total_dispatched_weight
                FROM dispatches
                WHERE status = 'active'
                GROUP BY order_id
            ) d ON o.id = d.order_id
            WHERE o.id = ?
        ";
        
        $result = $this->database->fetch($sql, [$id]);
        
        return $result ? new Order($result) : null;
    }
    
    public function findByOrderNo(string $orderNo): ?Order
    {
        $sql = "
            SELECT o.*, 
                   p.name as product_name,
                   pt.name as party_name,
                   u.name as created_by_name,
                   COALESCE(d.total_dispatched, 0) as total_dispatched
            FROM orders o
            JOIN products p ON o.product_id = p.id
            JOIN parties pt ON o.party_id = pt.id
            LEFT JOIN users u ON o.created_by = u.id
            LEFT JOIN (
                SELECT order_id,
                       SUM(dispatch_qty_trucks) as total_dispatched,
                       SUM(COALESCE(loading_weight_tons, 0)) as total_dispatched_weight
                FROM dispatches
                WHERE status = 'active'
                GROUP BY order_id
            ) d ON o.id = d.order_id
            WHERE o.order_no = ?
        ";
        
        $result = $this->database->fetch($sql, [$orderNo]);
        
        return $result ? new Order($result) : null;
    }
    
    public function create(Order $order): int
    {
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
        
        return (int)$this->database->lastInsertId();
    }
    
    public function update(Order $order): bool
    {
        $sql = "
            UPDATE orders 
            SET order_date = ?, product_id = ?, order_qty_trucks = ?, order_qty_mode = ?, order_weight_tons = ?, tons_per_truck = ?, party_id = ?, priority = ?
            WHERE id = ?
        ";
        
        return $this->database->execute($sql, [
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
    
    public function generateOrderNumber(): string
    {
        $year = date('Y');
        
        // Get the highest sequence number for this year with JLD format
        $sql = "
            SELECT COALESCE(MAX(CAST(SUBSTRING(order_no, 9) AS UNSIGNED)), 0) + 1 as next_seq
            FROM orders 
            WHERE order_no LIKE ? AND LENGTH(order_no) = 12
        ";
        
        $pattern = "JLD-{$year}%";
        $result = $this->database->fetch($sql, [$pattern]);
        $sequence = str_pad($result['next_seq'], 4, '0', STR_PAD_LEFT);
        
        return "JLD-{$year}{$sequence}";
    }
    
    public function getTotalDispatched(int $orderId): int
    {
        $sql = "SELECT COALESCE(SUM(dispatch_qty_trucks), 0) as total FROM dispatches WHERE order_id = ? AND status = 'active'";
        $result = $this->database->fetch($sql, [$orderId]);
        return (int)$result['total'];
    }
    
    public function count(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM orders o WHERE 1=1";
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

