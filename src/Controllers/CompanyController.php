<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\CompanyRepository;

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
}



