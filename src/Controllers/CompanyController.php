<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\CompanyRepository;
use App\Support\CompanyContext;

class CompanyController
{
    private AuthService $authService;
    private CompanyRepository $companyRepository;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->companyRepository = new CompanyRepository();
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
        
        try {
            $companies = $this->companyRepository->findActive();
            
            echo json_encode([
                'success' => true,
                'data' => array_map(fn($company) => $company->toArray(), $companies)
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function show(string $id): void
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
            $company = $this->companyRepository->findById((int)$id);
            
            if (!$company) {
                http_response_code(404);
                echo json_encode(['error' => 'Company not found']);
                return;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $company->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /** GET /api/companies/active — current session company */
    public function active(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        echo json_encode([
            'success' => true,
            'data' => CompanyContext::getActiveCompany(),
        ]);
    }

    /** POST /api/companies/active — switch session company */
    public function setActive(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $companyId = isset($input['company_id']) ? (int)$input['company_id'] : 0;
        if ($companyId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid company_id is required']);
            return;
        }

        $company = $this->companyRepository->findById($companyId);
        if (!$company || ($company->status ?? '') !== 'active') {
            http_response_code(404);
            echo json_encode(['error' => 'Company not found or inactive']);
            return;
        }

        CompanyContext::setActiveCompanyId($companyId);
        $_SESSION['active_company_name'] = $company->name;
        $_SESSION['active_company_code'] = $company->code;

        echo json_encode([
            'success' => true,
            'message' => 'Company switched successfully',
            'data' => CompanyContext::getActiveCompany(),
        ]);
    }
}



