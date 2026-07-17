<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DashboardService;
use App\Support\CompanyContext;
use App\Support\IndianDate;

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
            echo json_encode(['error' => 'Invalid date format. Use DD/MM/YYYY or YYYY-MM-DD']);
            return;
        }

        $startDate = IndianDate::toStorage($startDate);
        $endDate = IndianDate::toStorage($endDate);
        
        if (strtotime($startDate) > strtotime($endDate)) {
            http_response_code(400);
            echo json_encode(['error' => 'Start date cannot be after end date']);
            return;
        }
        
        try {
            $companyId = CompanyContext::getActiveCompanyId();
            $data = $this->dashboardService->getDashboardData($startDate, $endDate, $companyId);
            
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
            $companyId = CompanyContext::getActiveCompanyId();
            $summary = $this->dashboardService->getSummaryStats($companyId);
            
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
        return \App\Support\IndianDate::isValid($date);
    }
}




