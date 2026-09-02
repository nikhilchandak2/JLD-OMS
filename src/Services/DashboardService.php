<?php

namespace App\Services;

use App\Core\Database;
use App\Support\TableSchema;

class DashboardService
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function getDashboardData(string $startDate, string $endDate, ?int $companyId = null): array
    {
        return [
            'company_totals' => $this->getPartyOrderTotals($startDate, $endDate, $companyId),
            'product_totals' => $this->getProductTypeTotals($startDate, $endDate, $companyId),
            'trend_data' => $this->getTrendData($endDate, $companyId),
            'summary' => $this->getPeriodSummary($startDate, $endDate, $companyId)
        ];
    }

    /**
     * Per-client (party) order summary for the selected legal company and date range.
     * Used by the analytics "company-wise" table — shows client names and their orders.
     */
    public function getPartyOrderTotals(string $startDate, string $endDate, ?int $companyId = null): array
    {
        $sql = "
            SELECT pt.id AS party_id,
                   pt.name AS party_name,
                   COUNT(o.id) AS total_orders,
                   SUM(o.order_qty_trucks) AS total_ordered,
                   COALESCE(SUM(d.total_dispatched), 0) AS total_dispatched,
                   GROUP_CONCAT(o.order_no ORDER BY o.order_date DESC, o.id DESC SEPARATOR ', ') AS order_numbers
            FROM orders o
            JOIN parties pt ON o.party_id = pt.id
            LEFT JOIN (
                SELECT order_id, SUM(dispatch_qty_trucks) AS total_dispatched
                FROM dispatches
                GROUP BY order_id
            ) d ON o.id = d.order_id
            WHERE o.order_date BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        if ($companyId !== null && $companyId > 0) {
            $sql .= " AND o.company_id = ?";
            $params[] = $companyId;
        }
        $sql .= " GROUP BY pt.id, pt.name ORDER BY pt.name ASC";

        return $this->database->fetchAll($sql, $params);
    }
    
    public function getCompanyTotals(string $startDate, string $endDate, ?int $companyId = null): array
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
            WHERE " . (TableSchema::hasColumn('companies', 'status') ? "c.status = 'active'" : '1=1') . "
        ";
        $params = [$startDate, $endDate];
        if ($companyId !== null && $companyId > 0) {
            $sql .= " AND c.id = ?";
            $params[] = $companyId;
        }
        $sql .= " GROUP BY c.id, c.name, c.code ORDER BY c.name";
        
        return $this->database->fetchAll($sql, $params);
    }
    
    public function getProductTypeTotals(string $startDate, string $endDate, ?int $companyId = null): array
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
        ";
        $params = [$startDate, $endDate];
        if ($companyId !== null && $companyId > 0) {
            $sql .= " AND o.company_id = ?";
            $params[] = $companyId;
        }
        $sql .= " GROUP BY p.id, p.name ORDER BY p.name";
        
        return $this->database->fetchAll($sql, $params);
    }
    
    public function getTrendData(string $endDate, ?int $companyId = null): array
    {
        $sql = "
            SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS month,
                   SUM(o.order_qty_trucks) AS trucks_ordered
            FROM orders o
            WHERE o.order_date >= DATE_SUB(?, INTERVAL 6 MONTH)
        ";
        $params = [$endDate];
        if ($companyId !== null && $companyId > 0) {
            $sql .= " AND o.company_id = ?";
            $params[] = $companyId;
        }
        $sql .= " GROUP BY month ORDER BY month";
        
        return $this->database->fetchAll($sql, $params);
    }
    
    public function getPeriodSummary(string $startDate, string $endDate, ?int $companyId = null): array
    {
        $orderWhere = "order_date BETWEEN ? AND ?";
        $orderParams = [$startDate, $endDate];
        if ($companyId !== null && $companyId > 0) {
            $orderWhere .= " AND company_id = ?";
            $orderParams[] = $companyId;
        }

        $orderSummary = $this->database->fetch("
            SELECT COUNT(*) as total_orders,
                   SUM(order_qty_trucks) as total_trucks_ordered
            FROM orders
            WHERE {$orderWhere}
        ", $orderParams);

        if ($companyId !== null && $companyId > 0) {
            $dispatchSummary = $this->database->fetch("
                SELECT COUNT(*) as total_dispatches,
                       SUM(d.dispatch_qty_trucks) as total_trucks_dispatched
                FROM dispatches d
                JOIN orders o ON d.order_id = o.id
                WHERE d.dispatch_date BETWEEN ? AND ? AND o.company_id = ?
            ", [$startDate, $endDate, $companyId]);
        } else {
            $dispatchSummary = $this->database->fetch("
                SELECT COUNT(*) as total_dispatches,
                       SUM(dispatch_qty_trucks) as total_trucks_dispatched
                FROM dispatches
                WHERE dispatch_date BETWEEN ? AND ?
            ", [$startDate, $endDate]);
        }
        
        $statusBreakdown = $this->database->fetchAll("
            SELECT status, COUNT(*) as count
            FROM orders
            WHERE {$orderWhere}
            GROUP BY status
        ", $orderParams);
        
        $partySql = "
            SELECT pt.name as party_name,
                   COUNT(o.id) as order_count,
                   SUM(o.order_qty_trucks) as total_trucks
            FROM orders o
            JOIN parties pt ON o.party_id = pt.id
            WHERE o.order_date BETWEEN ? AND ?
        ";
        $partyParams = [$startDate, $endDate];
        if ($companyId !== null && $companyId > 0) {
            $partySql .= " AND o.company_id = ?";
            $partyParams[] = $companyId;
        }
        $partySql .= " GROUP BY pt.id, pt.name ORDER BY total_trucks DESC LIMIT 5";
        $topParties = $this->database->fetchAll($partySql, $partyParams);
        
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
    
    public function getSummaryStats(?int $companyId = null): array
    {
        $orderFilter = $companyId !== null && $companyId > 0 ? " WHERE company_id = {$companyId}" : '';
        $orderAnd = $companyId !== null && $companyId > 0 ? " AND company_id = {$companyId}" : '';

        $totalOrders = $this->database->fetch("SELECT COUNT(*) as count FROM orders{$orderFilter}")['count'];
        if ($companyId !== null && $companyId > 0) {
            $totalDispatches = $this->database->fetch("
                SELECT COUNT(*) as count FROM dispatches d JOIN orders o ON d.order_id = o.id WHERE o.company_id = ?
            ", [$companyId])['count'];
        } else {
            $totalDispatches = $this->database->fetch("SELECT COUNT(*) as count FROM dispatches")['count'];
        }
        $totalParties = $this->database->fetch("SELECT COUNT(*) as count FROM parties WHERE is_active = 1")['count'];
        $totalProducts = $this->database->fetch("SELECT COUNT(*) as count FROM products WHERE is_active = 1")['count'];
        
        $recentOrders = $this->database->fetch("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY){$orderAnd}
        ")['count'];
        
        if ($companyId !== null && $companyId > 0) {
            $recentDispatches = $this->database->fetch("
                SELECT COUNT(*) as count 
                FROM dispatches d
                JOIN orders o ON d.order_id = o.id
                WHERE d.dispatch_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND o.company_id = ?
            ", [$companyId])['count'];
        } else {
            $recentDispatches = $this->database->fetch("
                SELECT COUNT(*) as count 
                FROM dispatches 
                WHERE dispatch_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ")['count'];
        }
        
        $pendingOrders = $this->database->fetch("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE status IN ('pending', 'partial'){$orderAnd}
        ")['count'];
        
        $totalVehicles = 0;
        $activeVehicles = 0;
        $totalTrips = 0;
        $todayTrips = 0;
        try {
            $totalVehicles = (int)($this->database->fetch("SELECT COUNT(*) as count FROM vehicles")['count'] ?? 0);
            $activeVehicles = $totalVehicles;
            $vehicleStatus = $this->database->fetch(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'status'"
            );
            if ((int)($vehicleStatus['c'] ?? 0) > 0) {
                $activeVehicles = (int)($this->database->fetch("SELECT COUNT(*) as count FROM vehicles WHERE status = 'active'")['count'] ?? 0);
            }
            $totalTrips = (int)($this->database->fetch("SELECT COUNT(*) as count FROM vehicle_trips")['count'] ?? 0);
            $startCol = $this->database->fetch(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicle_trips' AND COLUMN_NAME = 'start_time'"
            );
            if ((int)($startCol['c'] ?? 0) > 0) {
                $todayTrips = (int)($this->database->fetch("
                    SELECT COUNT(*) as count
                    FROM vehicle_trips
                    WHERE DATE(start_time) = CURDATE()
                ")['count'] ?? 0);
            }
        } catch (\Throwable $e) {
            // Tracking tables vary by environment; the ops dashboard must still load.
        }
        
        return [
            'totals' => [
                'orders' => (int)$totalOrders,
                'dispatches' => (int)$totalDispatches,
                'parties' => (int)$totalParties,
                'products' => (int)$totalProducts,
                'vehicles' => (int)$totalVehicles,
                'active_vehicles' => (int)$activeVehicles,
                'trips' => (int)$totalTrips
            ],
            'recent_activity' => [
                'orders_last_7_days' => (int)$recentOrders,
                'dispatches_last_7_days' => (int)$recentDispatches
            ],
            'pending' => [
                'orders' => (int)$pendingOrders
            ],
            'vehicle_tracking' => [
                'total_vehicles' => (int)$totalVehicles,
                'active_vehicles' => (int)$activeVehicles,
                'total_trips' => (int)$totalTrips,
                'today_trips' => (int)$todayTrips
            ]
        ];
    }
}
