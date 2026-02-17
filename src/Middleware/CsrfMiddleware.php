<?php

namespace App\Middleware;

class CsrfMiddleware
{
    public function handle(): bool
    {
        // Skip CSRF check for GET requests, login endpoint, and webhook endpoints
        if ($_SERVER['REQUEST_METHOD'] === 'GET' || 
            $_SERVER['REQUEST_URI'] === '/api/login' ||
            strpos($_SERVER['REQUEST_URI'], '/api/gps/webhook') === 0 ||
            strpos($_SERVER['REQUEST_URI'], '/api/fuel/webhook') === 0 ||
            strpos($_SERVER['REQUEST_URI'], '/api/gps/batch') === 0) {
            return true;
        }
        
        // Generate CSRF token if not exists
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        // Check CSRF token for POST/PUT/DELETE requests
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        
        if (!$token || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            
            if ($this->isApiRequest()) {
                echo json_encode(['error' => 'CSRF token mismatch']);
            } else {
                echo 'CSRF token mismatch';
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
    
    public static function getToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
}

