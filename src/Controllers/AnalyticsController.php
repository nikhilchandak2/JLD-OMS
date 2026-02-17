<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Core\Database;

class AnalyticsController
{
    private AuthService $authService;
    private Database $database;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->database = new Database();
    }
    
    public function orders(): void
    {
        header('Content-Type: application/json');
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        
        try {
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            
            $data = [
                'summary' => $this->getOrdersSummary($startDate, $endDate),
                'status_breakdown' => $this->getOrdersStatusBreakdown($startDate, $endDate),
                'company_breakdown' => $this->getOrdersCompanyBreakdown($startDate, $endDate)
            ];
            
            echo json_encode([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function dispatches(): void
    {
        header('Content-Type: application/json');
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        
        try {
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            
            $data = [
                'summary' => $this->getDispatchesSummary($startDate, $endDate),
                'company_breakdown' => $this->getDispatchesCompanyBreakdown($startDate, $endDate),
                'daily_trend' => $this->getDispatchesDailyTrend($startDate, $endDate)
            ];
            
            echo json_encode([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function pending(): void
    {
        header('Content-Type: application/json');
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        
        try {
            $data = [
                'summary' => $this->getPendingSummary(),
                'priority_breakdown' => $this->getPendingPriorityBreakdown(),
                'company_breakdown' => $this->getPendingCompanyBreakdown(),
                'urgent_orders' => $this->getUrgentOrders()
            ];
            
            echo json_encode([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function parties(): void
    {
        header('Content-Type: application/json');
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        
        try {
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            
            $data = [
                'summary' => $this->getPartiesSummary($startDate, $endDate),
                'parties' => $this->getPartiesWithStats($startDate, $endDate),
                'top_performers' => $this->getTopPerformingParties($startDate, $endDate),
                'top_parties' => $this->getTopPartiesByOrders($startDate, $endDate),
                'activity_distribution' => $this->getPartiesActivityDistribution()
            ];
            
            echo json_encode([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function getOrdersSummary(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_orders,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_orders,
                COUNT(CASE WHEN status IN ('pending', 'partial') THEN 1 END) as pending_orders,
                SUM(order_qty_trucks) as total_trucks
            FROM orders 
            WHERE order_date BETWEEN ? AND ?
        ";
        
        return $this->database->fetch($sql, [$startDate, $endDate]) ?: [];
    }
    
    private function getOrdersStatusBreakdown(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT status, COUNT(*) as count
            FROM orders 
            WHERE order_date BETWEEN ? AND ?
            GROUP BY status
            ORDER BY count DESC
        ";
        
        return $this->database->fetchAll($sql, [$startDate, $endDate]);
    }
    
    private function getOrdersCompanyBreakdown(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT c.name as company_name, COUNT(o.id) as count
            FROM orders o
            JOIN companies c ON o.company_id = c.id
            WHERE o.order_date BETWEEN ? AND ?
            GROUP BY c.id, c.name
            ORDER BY count DESC
        ";
        
        return $this->database->fetchAll($sql, [$startDate, $endDate]);
    }
    
    private function getDispatchesSummary(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_dispatches,
                SUM(dispatch_qty_trucks) as total_trucks,
                ROUND(COUNT(*) / DATEDIFF(?, ?), 1) as avg_per_day,
                COUNT(DISTINCT order_id) as active_orders
            FROM dispatches 
            WHERE dispatch_date BETWEEN ? AND ?
        ";
        
        return $this->database->fetch($sql, [$endDate, $startDate, $startDate, $endDate]) ?: [];
    }
    
    private function getDispatchesCompanyBreakdown(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT c.name as company_name, COUNT(d.id) as count
            FROM dispatches d
            JOIN orders o ON d.order_id = o.id
            JOIN companies c ON o.company_id = c.id
            WHERE d.dispatch_date BETWEEN ? AND ?
            GROUP BY c.id, c.name
            ORDER BY count DESC
        ";
        
        return $this->database->fetchAll($sql, [$startDate, $endDate]);
    }
    
    private function getDispatchesDailyTrend(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT dispatch_date as date, COUNT(*) as count
            FROM dispatches 
            WHERE dispatch_date BETWEEN ? AND ?
            GROUP BY dispatch_date
            ORDER BY dispatch_date ASC
        ";
        
        return $this->database->fetchAll($sql, [$startDate, $endDate]);
    }
    
    private function getPendingSummary(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_pending,
                COUNT(CASE WHEN priority = 'urgent' THEN 1 END) as urgent_pending,
                SUM(order_qty_trucks - COALESCE(d.total_dispatched, 0)) as pending_trucks,
                ROUND(AVG(DATEDIFF(CURDATE(), order_date)), 0) as avg_age
            FROM orders o
            LEFT JOIN (
                SELECT order_id, SUM(dispatch_qty_trucks) as total_dispatched
                FROM dispatches
                GROUP BY order_id
            ) d ON o.id = d.order_id
            WHERE o.status IN ('pending', 'partial')
        ";
        
        return $this->database->fetch($sql) ?: [];
    }
    
    private function getPendingPriorityBreakdown(): array
    {
        $sql = "
            SELECT priority, COUNT(*) as count
            FROM orders 
            WHERE status IN ('pending', 'partial')
            GROUP BY priority
            ORDER BY count DESC
        ";
        
        return $this->database->fetchAll($sql);
    }
    
    private function getPendingCompanyBreakdown(): array
    {
        $sql = "
            SELECT c.name as company_name, COUNT(o.id) as count
            FROM orders o
            JOIN companies c ON o.company_id = c.id
            WHERE o.status IN ('pending', 'partial')
            GROUP BY c.id, c.name
            ORDER BY count DESC
        ";
        
        return $this->database->fetchAll($sql);
    }
    
    private function getUrgentOrders(): array
    {
        $sql = "
            SELECT o.id, o.order_no, p.name as party_name,
                   (o.order_qty_trucks - COALESCE(d.total_dispatched, 0)) as pending_trucks
            FROM orders o
            JOIN parties p ON o.party_id = p.id
            LEFT JOIN (
                SELECT order_id, SUM(dispatch_qty_trucks) as total_dispatched
                FROM dispatches
                GROUP BY order_id
            ) d ON o.id = d.order_id
            WHERE o.status IN ('pending', 'partial') AND o.priority = 'urgent'
            ORDER BY o.order_date ASC
            LIMIT 10
        ";
        
        return $this->database->fetchAll($sql);
    }
    
    private function getPartiesSummary(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                COUNT(DISTINCT p.id) as total_parties,
                COUNT(DISTINCT CASE WHEN o.id IS NOT NULL THEN p.id END) as active_parties,
                COUNT(DISTINCT CASE WHEN o.id IS NULL THEN p.id END) as inactive_parties,
                ROUND(COUNT(o.id) / COUNT(DISTINCT p.id), 1) as avg_orders_per_party
            FROM parties p
            LEFT JOIN orders o ON p.id = o.party_id AND o.order_date BETWEEN ? AND ?
        ";
        
        return $this->database->fetch($sql, [$startDate, $endDate]) ?: [];
    }
    
    private function getPartiesWithStats(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                p.*,
                COUNT(o.id) as total_orders,
                COALESCE(SUM(o.order_qty_trucks), 0) as total_trucks,
                COUNT(CASE WHEN o.status IN ('pending', 'partial') THEN 1 END) as pending_orders,
                MAX(o.order_date) as last_order_date
            FROM parties p
            LEFT JOIN orders o ON p.id = o.party_id
            GROUP BY p.id
            ORDER BY total_orders DESC, p.name ASC
        ";
        
        return $this->database->fetchAll($sql);
    }
    
    private function getTopPerformingParties(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                p.id, p.name,
                COUNT(o.id) as total_orders,
                SUM(o.order_qty_trucks) as total_trucks,
                MAX(o.order_date) as last_order_date
            FROM parties p
            JOIN orders o ON p.id = o.party_id
            WHERE o.order_date BETWEEN ? AND ?
            GROUP BY p.id, p.name
            HAVING total_orders > 0
            ORDER BY total_orders DESC, total_trucks DESC
            LIMIT 10
        ";
        
        return $this->database->fetchAll($sql, [$startDate, $endDate]);
    }
    
    private function getTopPartiesByOrders(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                p.name,
                COUNT(o.id) as total_orders
            FROM parties p
            JOIN orders o ON p.id = o.party_id
            WHERE o.order_date BETWEEN ? AND ?
            GROUP BY p.id, p.name
            ORDER BY total_orders DESC
            LIMIT 10
        ";
        
        return $this->database->fetchAll($sql, [$startDate, $endDate]);
    }
    
    private function getPartiesActivityDistribution(): array
    {
        $sql = "
            SELECT 
                SUM(CASE WHEN last_order_days <= 7 THEN 1 ELSE 0 END) as very_active,
                SUM(CASE WHEN last_order_days > 7 AND last_order_days <= 30 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN last_order_days > 30 THEN 1 ELSE 0 END) as low_activity,
                SUM(CASE WHEN last_order_days IS NULL THEN 1 ELSE 0 END) as inactive
            FROM (
                SELECT 
                    p.id,
                    DATEDIFF(CURDATE(), MAX(o.order_date)) as last_order_days
                FROM parties p
                LEFT JOIN orders o ON p.id = o.party_id
                GROUP BY p.id
            ) party_activity
        ";
        
        return $this->database->fetch($sql) ?: [];
    }
}
