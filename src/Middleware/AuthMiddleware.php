<?php

namespace App\Middleware;

use App\Services\AuthService;

class AuthMiddleware
{
    private AuthService $authService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
    }
    
    public function handle(): bool
    {
        if (!$this->authService->isAuthenticated()) {
            http_response_code(401);
            
            if ($this->isApiRequest()) {
                echo json_encode(['error' => 'Authentication required']);
            } else {
                header('Location: /login');
            }
            
            return false;
        }
        
        return true;
    }
    
    private function isApiRequest(): bool
    {
        return strpos($_SERVER['REQUEST_URI'], '/api/') === 0 ||
               (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    }
}


