<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DashboardService;

class DashboardController
{
    private AuthService $authService;
    private DashboardService $dashboardService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->dashboardService = new DashboardService();
    }
    
    public function index(): void
    {
        header('Content-Type: application/json');
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        
        // Get query parameters for date range
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        // Validate dates
        if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
            return;
        }
        
        if (strtotime($startDate) > strtotime($endDate)) {
            http_response_code(400);
            echo json_encode(['error' => 'Start date cannot be after end date']);
            return;
        }
        
        try {
            $data = $this->dashboardService->getDashboardData($startDate, $endDate);
            
            echo json_encode([
                'success' => true,
                'data' => $data,
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function summary(): void
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
            $summary = $this->dashboardService->getSummaryStats();
            
            echo json_encode([
                'success' => true,
                'data' => $summary
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}




