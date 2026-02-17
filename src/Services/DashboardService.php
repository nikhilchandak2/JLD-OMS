<?php

namespace App\Services;

use App\Core\Database;

class DashboardService
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function getDashboardData(string $startDate, string $endDate): array
    {
        return [
            'company_totals' => $this->getCompanyTotals($startDate, $endDate),
            'product_totals' => $this->getProductTypeTotals($startDate, $endDate),
            'trend_data' => $this->getTrendData($endDate),
            'summary' => $this->getPeriodSummary($startDate, $endDate)
        ];
    }
    
    public function getCompanyTotals(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT c.id,
                   c.name,
                   c.code,
                   COUNT(o.id) AS total_orders,
                   SUM(o.order_qty_trucks) AS total_ordered,
                   COALESCE(SUM(d.total_dispatched), 0) AS total_dispatched
            FROM companies c
            LEFT JOIN orders o ON c.id = o.company_id AND o.order_date BETWEEN ? AND ?
            LEFT JOIN (
                SELECT order_id, SUM(dispatch_qty_trucks) AS total_dispatched
                FROM dispatches
                GROUP BY order_id
            ) d ON o.id = d.order_id
            WHERE c.status = 'active'
            GROUP BY c.id, c.name, c.code
            ORDER BY c.name
        ";
        
        return $this->database->fetchAll($sql, [$startDate, $endDate]);
    }
    
    public function getProductTypeTotals(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT p.name AS product_name,
                   SUM(o.order_qty_trucks) AS total_ordered,
                   COALESCE(SUM(d.total_dispatched), 0) AS total_dispatched,
                   (SUM(o.order_qty_trucks) - COALESCE(SUM(d.total_dispatched), 0)) AS pending_trucks
            FROM orders o
            JOIN products p ON o.product_id = p.id
            LEFT JOIN (
                SELECT order_id, SUM(dispatch_qty_trucks) AS total_dispatched
                FROM dispatches
                GROUP BY order_id
            ) d ON o.id = d.order_id
            WHERE o.order_date BETWEEN ? AND ?
            GROUP BY p.id, p.name
            ORDER BY p.name
        ";
        
        return $this->database->fetchAll($sql, [$startDate, $endDate]);
    }
    
    public function getTrendData(string $endDate): array
    {
        $sql = "
            SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS month,
                   SUM(o.order_qty_trucks) AS trucks_ordered
            FROM orders o
            WHERE o.order_date >= DATE_SUB(?, INTERVAL 6 MONTH)
            GROUP BY month
            ORDER BY month
        ";
        
        return $this->database->fetchAll($sql, [$endDate]);
    }
    
    public function getPeriodSummary(string $startDate, string $endDate): array
    {
        // Total orders and trucks in period
        $orderSummary = $this->database->fetch("
            SELECT COUNT(*) as total_orders,
                   SUM(order_qty_trucks) as total_trucks_ordered
            FROM orders
            WHERE order_date BETWEEN ? AND ?
        ", [$startDate, $endDate]);
        
        // Total dispatches in period
        $dispatchSummary = $this->database->fetch("
            SELECT COUNT(*) as total_dispatches,
                   SUM(dispatch_qty_trucks) as total_trucks_dispatched
            FROM dispatches
            WHERE dispatch_date BETWEEN ? AND ?
        ", [$startDate, $endDate]);
        
        // Order status breakdown
        $statusBreakdown = $this->database->fetchAll("
            SELECT status, COUNT(*) as count
            FROM orders
            WHERE order_date BETWEEN ? AND ?
            GROUP BY status
        ", [$startDate, $endDate]);
        
        // Top parties by order volume
        $topParties = $this->database->fetchAll("
            SELECT pt.name as party_name,
                   COUNT(o.id) as order_count,
                   SUM(o.order_qty_trucks) as total_trucks
            FROM orders o
            JOIN parties pt ON o.party_id = pt.id
            WHERE o.order_date BETWEEN ? AND ?
            GROUP BY pt.id, pt.name
            ORDER BY total_trucks DESC
            LIMIT 5
        ", [$startDate, $endDate]);
        
        return [
            'orders' => [
                'total_count' => (int)$orderSummary['total_orders'],
                'total_trucks' => (int)$orderSummary['total_trucks_ordered']
            ],
            'dispatches' => [
                'total_count' => (int)$dispatchSummary['total_dispatches'],
                'total_trucks' => (int)$dispatchSummary['total_trucks_dispatched']
            ],
            'pending_trucks' => (int)$orderSummary['total_trucks_ordered'] - (int)$dispatchSummary['total_trucks_dispatched'],
            'status_breakdown' => $statusBreakdown,
            'top_parties' => $topParties
        ];
    }
    
    public function getSummaryStats(): array
    {
        // Overall system statistics
        $totalOrders = $this->database->fetch("SELECT COUNT(*) as count FROM orders")['count'];
        $totalDispatches = $this->database->fetch("SELECT COUNT(*) as count FROM dispatches")['count'];
        $totalParties = $this->database->fetch("SELECT COUNT(*) as count FROM parties WHERE is_active = 1")['count'];
        $totalProducts = $this->database->fetch("SELECT COUNT(*) as count FROM products WHERE is_active = 1")['count'];
        
        // Recent activity (last 7 days)
        $recentOrders = $this->database->fetch("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ")['count'];
        
        $recentDispatches = $this->database->fetch("
            SELECT COUNT(*) as count 
            FROM dispatches 
            WHERE dispatch_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ")['count'];
        
        // Pending orders
        $pendingOrders = $this->database->fetch("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE status IN ('pending', 'partial')
        ")['count'];
        
        return [
            'totals' => [
                'orders' => (int)$totalOrders,
                'dispatches' => (int)$totalDispatches,
                'parties' => (int)$totalParties,
                'products' => (int)$totalProducts
            ],
            'recent_activity' => [
                'orders_last_7_days' => (int)$recentOrders,
                'dispatches_last_7_days' => (int)$recentDispatches
            ],
            'pending' => [
                'orders' => (int)$pendingOrders
            ]
        ];
    }
}

