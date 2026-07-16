<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ReportService;
use App\Support\CompanyContext;

class ReportController
{
    private AuthService $authService;
    private ReportService $reportService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->reportService = new ReportService();
    }
    
    public function partywise(): void
    {
        header('Content-Type: application/json');
        
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['view', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }
        
        // Get query parameters
        $filters = [
            'start_date' => $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')),
            'end_date' => $_GET['end_date'] ?? date('Y-m-d'),
            'party_id' => $_GET['party_id'] ?? null,
            'product_id' => $_GET['product_id'] ?? null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 100,
            'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0
        ];
        
        // Validate dates
        if (!$this->isValidDate($filters['start_date']) || !$this->isValidDate($filters['end_date'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
            return;
        }
        
        if (strtotime($filters['start_date']) > strtotime($filters['end_date'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Start date cannot be after end date']);
            return;
        }
        
        try {
            $data = $this->reportService->getPartywiseReport($filters);
            $total = $this->reportService->getPartywiseReportCount($filters);
            
            echo json_encode([
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'total' => $total,
                    'limit' => $filters['limit'],
                    'offset' => $filters['offset'],
                    'has_more' => ($filters['offset'] + $filters['limit']) < $total
                ],
                'filters' => $filters
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function export(): void
    {
        // Check permissions
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['view', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }
        
        $format = $_GET['format'] ?? 'pdf';
        
        if (!in_array($format, ['pdf', 'xlsx'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid format. Use pdf or xlsx']);
            return;
        }
        
        // Get query parameters (same as partywise report)
        $filters = [
            'start_date' => $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')),
            'end_date' => $_GET['end_date'] ?? date('Y-m-d'),
            'party_id' => $_GET['party_id'] ?? null,
            'product_id' => $_GET['product_id'] ?? null
        ];
        
        // Validate dates
        if (!$this->isValidDate($filters['start_date']) || !$this->isValidDate($filters['end_date'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
            return;
        }
        
        try {
            if ($format === 'pdf') {
                $this->exportPdf($filters);
            } else {
                $this->exportExcel($filters);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function exportPdf(array $filters): void
    {
        $data = $this->reportService->getPartywiseReport($filters);
        $filename = 'partywise_report_' . date('Y-m-d_H-i-s') . '.pdf';
        
        $pdf = $this->reportService->generatePdfReport($data, $filters);
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        
        echo $pdf;
    }
    
    private function exportExcel(array $filters): void
    {
        $data = $this->reportService->getPartywiseReport($filters);
        $filename = 'partywise_report_' . date('Y-m-d_H-i-s') . '.xlsx';
        
        $excel = $this->reportService->generateExcelReport($data, $filters);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $excel->save('php://output');
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
            $parties = $this->reportService->getActiveParties();
            
            echo json_encode([
                'success' => true,
                'data' => $parties
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function products(): void
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
            $products = $this->reportService->getActiveProducts();
            
            echo json_encode([
                'success' => true,
                'data' => $products
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function dailyDispatch(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->canViewDispatchReports()) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }

        $filters = $this->parseDailyDispatchFilters($_GET);
        if ($filters === null) {
            return;
        }

        try {
            $data = $this->reportService->getDailyDispatchReport($filters);
            echo json_encode([
                'success' => true,
                'data' => $data,
                'filters' => $filters,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function exportDailyDispatch(): void
    {
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->canViewDispatchReports()) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }

        $format = $_GET['format'] ?? 'xlsx';
        if (!in_array($format, ['xlsx'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid format. Use xlsx']);
            return;
        }

        $filters = $this->parseDailyDispatchFilters($_GET);
        if ($filters === null) {
            return;
        }

        try {
            $data = $this->reportService->getDailyDispatchReport($filters);
            $filename = 'daily_dispatch_' . $filters['start_date'] . '_to_' . $filters['end_date'] . '.xlsx';
            $writer = $this->reportService->generateDailyDispatchExcel($data, $filters);

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            $writer->save('php://output');
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed>|null */
    private function parseDailyDispatchFilters(array $input): ?array
    {
        $startDate = $input['start_date'] ?? $input['date'] ?? date('Y-m-d');
        $endDate = $input['end_date'] ?? $input['date'] ?? $startDate;

        if (!$this->isValidDate((string)$startDate) || !$this->isValidDate((string)$endDate)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
            return null;
        }

        if (strtotime((string)$startDate) > strtotime((string)$endDate)) {
            http_response_code(400);
            echo json_encode(['error' => 'Start date cannot be after end date']);
            return null;
        }

        $filters = [
            'start_date' => (string)$startDate,
            'end_date' => (string)$endDate,
            'party_id' => !empty($input['party_id']) ? (int)$input['party_id'] : null,
            'product_id' => !empty($input['product_id']) ? (int)$input['product_id'] : null,
        ];

        if (isset($input['company_id']) && $input['company_id'] !== '' && $input['company_id'] !== 'all') {
            $filters['company_id'] = (int)$input['company_id'];
        } elseif ($this->authService->hasRole('admin') && ($input['company_id'] ?? '') === 'all') {
            $filters['company_id'] = null;
        } else {
            $filters = CompanyContext::mergeFilter($filters);
        }

        return $filters;
    }

    private function canViewDispatchReports(): bool
    {
        return $this->authService->hasAnyRole(['admin', 'view', 'order_processing', 'dispatch', 'entry']);
    }
    
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}




