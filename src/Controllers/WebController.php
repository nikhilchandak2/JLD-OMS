<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Middleware\CsrfMiddleware;

class WebController
{
    private AuthService $authService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
    }
    
    public function loginForm(): void
    {
        // Redirect if already logged in
        if ($this->authService->isAuthenticated()) {
            header('Location: /dashboard');
            return;
        }
        
        $this->renderTemplate('login', [
            'title' => 'Login',
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function dashboard(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('dashboard', [
            'title' => 'Dashboard',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function orders(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('orders', [
            'title' => 'Orders',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function newOrder(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        // Check permissions
        if (!$this->authService->hasAnyRole(['entry', 'admin'])) {
            http_response_code(403);
            $this->renderTemplate('error', [
                'title' => 'Access Denied',
                'message' => 'You do not have permission to create orders.'
            ]);
            return;
        }
        
        $this->renderTemplate('new-order', [
            'title' => 'New Order',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function orderDetail(int $id): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('order-detail', [
            'title' => 'Order Details',
            'user' => $user,
            'order_id' => $id,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function reports(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        // Check permissions
        if (!$this->authService->hasAnyRole(['view', 'admin'])) {
            http_response_code(403);
            $this->renderTemplate('error', [
                'title' => 'Access Denied',
                'message' => 'You do not have permission to view reports.'
            ]);
            return;
        }
        
        $this->renderTemplate('reports', [
            'title' => 'Reports',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function users(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        // Check permissions
        if (!$this->authService->hasRole('admin')) {
            http_response_code(403);
            $this->renderTemplate('error', [
                'title' => 'Access Denied',
                'message' => 'You do not have permission to manage users.'
            ]);
            return;
        }
        
        $this->renderTemplate('users', [
            'title' => 'User Management',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function parties(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        // Check permissions - allow both entry and admin users
        if (!$this->authService->hasAnyRole(['entry', 'admin'])) {
            http_response_code(403);
            $this->renderTemplate('error', [
                'title' => 'Access Denied',
                'message' => 'You do not have permission to manage parties.'
            ]);
            return;
        }
        
        $this->renderTemplate('parties', [
            'title' => 'Party Management',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function products(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        // Check permissions - allow both entry and admin users
        if (!$this->authService->hasAnyRole(['entry', 'admin'])) {
            http_response_code(403);
            $this->renderTemplate('error', [
                'title' => 'Access Denied',
                'message' => 'You do not have permission to manage products.'
            ]);
            return;
        }
        
        $this->renderTemplate('products', [
            'title' => 'Product Management',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function analyticsOrders(): void
    {
        $this->requireAuth();
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('analytics-orders', [
            'title' => 'Orders Analytics - JLD Minerals',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function analyticsDispatches(): void
    {
        $this->requireAuth();
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('analytics-dispatches', [
            'title' => 'Dispatches Analytics - JLD Minerals',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function analyticsPending(): void
    {
        $this->requireAuth();
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('analytics-pending', [
            'title' => 'Pending Orders Analytics - JLD Minerals',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function analyticsParties(): void
    {
        $this->requireAuth();
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('analytics-parties', [
            'title' => 'Parties Analytics - JLD Minerals',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function vehicles(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('vehicles', [
            'title' => 'Vehicles',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function tracking(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('tracking', [
            'title' => 'Live Tracking',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken(),
            'mapbox_token' => $_ENV['MAPBOX_ACCESS_TOKEN'] ?? '',
        ]);
    }
    
    public function trips(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('trips', [
            'title' => 'Trips',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function geofences(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('geofences', [
            'title' => 'Geofences',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function fuel(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('fuel', [
            'title' => 'Fuel Management',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    private function requireAuth(): void
    {
        if (!$this->authService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }
    }
    
    public function busyIntegration(): void
    {
        $this->requireAuth();
        
        // Check if user has admin role for integration management
        if (!$this->authService->hasRole('admin')) {
            http_response_code(403);
            $this->renderTemplate('error', [
                'title' => 'Access Denied',
                'message' => 'Admin access required for integration management'
            ]);
            return;
        }
        
        $this->renderTemplate('busy-integration', [
            'title' => 'Busy Integration',
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function ordersAnalytics(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        $this->renderTemplate('orders-analytics', [
            'title' => 'Orders & Dispatches Analytics',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    private function renderTemplate(string $template, array $data = []): void
    {
        // Extract data to variables
        extract($data);
        
        // Start output buffering
        ob_start();
        
        // Include the template
        $templatePath = __DIR__ . "/../../templates/{$template}.php";
        
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            // Fallback error template
            include __DIR__ . '/../../templates/error.php';
        }
        
        // Get the content and clean the buffer
        $content = ob_get_clean();
        
        // Include the layout
        include __DIR__ . '/../../templates/layout.php';
    }
}

