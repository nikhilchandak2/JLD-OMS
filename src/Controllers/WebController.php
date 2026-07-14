<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Middleware\CsrfMiddleware;
use App\Support\CompanyContext;
use App\Repositories\CompanyRepository;

class WebController
{
    private AuthService $authService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
    }
    
    public function loginForm(): void
    {
        if ($this->authService->isAuthenticated()) {
            $user = $this->authService->getCurrentUser();
            $home = ($user['role'] ?? '') === 'admin' ? '/dashboard' : $this->getDefaultHomeForRole($user['role'] ?? '');
            header('Location: ' . $home);
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
        // Main dashboard is for admin only; others redirect to their default section
        if (!$this->authService->hasRole('admin')) {
            header('Location: ' . $this->getDefaultHomeForRole($user['role'] ?? ''));
            return;
        }
        $this->renderTemplate('dashboard', [
            'title' => 'Dashboard',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    private function getDefaultHomeForRole(string $role): string
    {
        $homes = [
            'order_processing' => '/orders',
            'entry' => '/orders',
            'view' => '/orders',
            'accounts' => '/admin/parties',
            'operator' => '/vehicles',
            'crm' => '/crm',
            'sales' => '/orders',
            'dispatch' => '/dispatch',
            'marketing' => '/crm',
            'technical' => '/visit-requests',
        ];
        return $homes[$role] ?? '/orders';
    }
    
    public function orders(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['admin', 'order_processing', 'entry', 'view', 'sales', 'dispatch'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Orders access required']);
            return;
        }
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
        
        if (!$this->authService->hasAnyRole(['entry', 'admin', 'order_processing', 'sales'])) {
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
        if (!$this->authService->hasAnyRole(['admin', 'order_processing', 'entry', 'view', 'sales', 'dispatch'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Orders access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('order-detail', [
            'title' => 'Order Details',
            'user' => $user,
            'order_id' => $id,
            'can_edit_orders' => $this->authService->hasAnyRole(['admin', 'order_processing', 'entry', 'sales']),
            'can_delete_orders' => $this->authService->hasAnyRole(['admin', 'order_processing', 'sales']),
            'can_force_delete_orders' => $this->authService->hasAnyRole(['admin', 'order_processing']),
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    /**
     * Dispatch dashboard – queue of pending/partial orders awaiting dispatch.
     */
    public function dispatchDashboard(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['admin', 'dispatch', 'order_processing'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Dispatch access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('dispatch-dashboard', [
            'title' => 'Dispatch Dashboard',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    /** Full dispatch history with filters, pagination, and reject/transfer actions. */
    public function dispatchHistory(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['admin', 'dispatch', 'order_processing', 'entry'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Dispatch access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('dispatch-history', [
            'title' => 'Dispatch History',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    /**
     * Visit requests – marketing raises client visit requests, technical team executes them.
     */
    public function visitRequests(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['admin', 'marketing', 'technical', 'crm'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Visit requests access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('visit-requests', [
            'title' => 'Visit Requests',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    public function reports(): void
    {
        $this->requireAuth();
        if ($this->redirectOrderProcessingIfNeeded()) {
            return;
        }
        
        $user = $this->authService->getCurrentUser();
        
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

    public function creditApprovalsPage(): void
    {
        $this->requireAuth();

        $user = $this->authService->getCurrentUser();

        if (!$this->authService->hasRole('admin')) {
            http_response_code(403);
            $this->renderTemplate('error', [
                'title' => 'Access Denied',
                'message' => 'Admin access required'
            ]);
            return;
        }

        $this->renderTemplate('admin/credit-approvals', [
            'title' => 'Credit Approvals',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function parties(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        if (!$this->authService->hasAnyRole(['entry', 'admin', 'accounts', 'crm', 'sales', 'marketing'])) {
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

    public function partiesImport(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['entry', 'admin', 'accounts', 'crm'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'You do not have permission.']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('parties-import', [
            'title' => 'Import parties',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    public function adminImportBills(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['admin', 'accounts'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Admin or Accounts access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('admin/import-bills', [
            'title' => 'Import bills (Busy)',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function products(): void
    {
        $this->requireAuth();
        
        $user = $this->authService->getCurrentUser();
        
        if (!$this->authService->hasAnyRole(['entry', 'admin', 'accounts'])) {
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
            'is_admin' => $this->authService->hasRole('admin'),
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    public function productsImport(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasRole('admin')) {
            http_response_code(403);
            $this->renderTemplate('error', [
                'title' => 'Access Denied',
                'message' => 'Admin access required to import products.',
            ]);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('products-import', [
            'title' => 'Import products',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken(),
        ]);
    }
    
    public function analyticsOrders(): void
    {
        $this->requireAuth();
        if ($this->redirectOrderProcessingIfNeeded()) {
            return;
        }
        if (!$this->authService->hasAnyRole(['admin', 'entry', 'view'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Orders analytics access required']);
            return;
        }
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
        if ($this->redirectOrderProcessingIfNeeded()) {
            return;
        }
        if (!$this->authService->hasAnyRole(['admin', 'entry', 'view'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Orders analytics access required']);
            return;
        }
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
        if ($this->redirectOrderProcessingIfNeeded()) {
            return;
        }
        if (!$this->authService->hasAnyRole(['admin', 'entry', 'view'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Orders analytics access required']);
            return;
        }
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
        if ($this->redirectOrderProcessingIfNeeded()) {
            return;
        }
        if (!$this->authService->hasAnyRole(['admin', 'entry', 'view'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Orders analytics access required']);
            return;
        }
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
        if (!$this->authService->hasAnyRole(['admin', 'operator'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Vehicle tracking access required']);
            return;
        }
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
        if (!$this->authService->hasAnyRole(['admin', 'operator'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Vehicle tracking access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('tracking', [
            'title' => 'Live Tracking',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken(),
            'mapbox_token' => $_ENV['MAPBOX_ACCESS_TOKEN'] ?? '',
        ]);
    }

    public function wheelseyeData(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['admin', 'operator'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Vehicle tracking access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('tracking-wheelseye-data', [
            'title' => 'WheelsEye Pulled Data',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    public function trips(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['admin', 'operator'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Vehicle tracking access required']);
            return;
        }
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
        if (!$this->authService->hasAnyRole(['admin', 'operator'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Vehicle tracking access required']);
            return;
        }
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
        if (!$this->authService->hasAnyRole(['admin', 'operator'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Vehicle tracking access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('fuel', [
            'title' => 'Fuel Management',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    public function dumperAssignment(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['admin', 'operator'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Vehicle tracking access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('dumper-assignment', [
            'title' => 'Dumper Assignment',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    /**
     * Export Documents (Nepal) – separate module from OMS orders/tracking/admin.
     * Used only for Nepal export: Commercial Invoice, Tax Invoice, Packing List, etc.
     */
    public function exportDocuments(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['admin', 'accounts'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Export documents access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('export/index', [
            'title' => 'Nepal Export Documents',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    /**
     * Email & WhatsApp reminders – runs external Python script. Accounts + admin only.
     */
    public function reminders(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['admin', 'accounts'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Reminders access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        // Always offer both BusyPayBot companies; backend chooses script path per selection.
        $reminderCompanies = [
            ['id' => 'jld_minerals', 'label' => 'JLD Minerals Private Limited'],
            ['id' => 'jaichand', 'label' => 'Jaichand Lal Daga'],
        ];
        $this->renderTemplate('admin/reminders', [
            'title' => 'Email & WhatsApp Reminders',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken(),
            'reminder_companies' => $reminderCompanies,
        ]);
    }

    /**
     * CRM – Customer Relationship Management (funnel, contacts, activities, samples, receivables).
     * Uses Parties as accounts; no separate leads/deals.
     */
    public function crm(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['admin', 'crm', 'entry', 'sales', 'marketing'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'CRM access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('crm/index', [
            'title' => 'CRM',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    public function crmFunnel(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['entry', 'admin', 'crm', 'sales', 'marketing'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'CRM access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('crm/funnel', [
            'title' => 'CRM Funnel',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    public function crmPartyNew(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['entry', 'admin', 'crm', 'sales', 'marketing'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'CRM access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('crm/party-new', [
            'title' => 'Add new company - CRM',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    public function crmPartyDetail(string $id): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['entry', 'admin', 'crm', 'sales', 'marketing'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'CRM access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('crm/party-detail', [
            'title' => 'Party - CRM',
            'user' => $user,
            'party_id' => (int)$id,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    public function crmImportReceivables(): void
    {
        $this->requireAuth();
        if (!$this->authService->hasAnyRole(['entry', 'admin', 'crm'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'CRM access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('crm/import-receivables', [
            'title' => 'Import receivables - CRM',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }

    private function requireAuth(): void
    {
        if (!$this->authService->isAuthenticated()) {
            $returnUrl = $_SERVER['REQUEST_URI'] ?? '/dashboard';
            $loginUrl = '/login?redirect=' . urlencode($returnUrl);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Location: ' . $loginUrl);
            exit;
        }

        CompanyContext::initializeForUser();
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
        if ($this->redirectOrderProcessingIfNeeded()) {
            return;
        }
        if (!$this->authService->hasAnyRole(['admin', 'entry', 'view'])) {
            http_response_code(403);
            $this->renderTemplate('error', ['title' => 'Access Denied', 'message' => 'Orders analytics access required']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $this->renderTemplate('orders-analytics', [
            'title' => 'Orders & Dispatches Analytics',
            'user' => $user,
            'csrf_token' => CsrfMiddleware::getToken()
        ]);
    }
    
    private function redirectOrderProcessingIfNeeded(): bool
    {
        if ($this->authService->hasRole('order_processing')) {
            header('Location: /orders');
            return true;
        }
        return false;
    }
    
    private function renderTemplate(string $template, array $data = []): void
    {
        if ($this->authService->isAuthenticated()) {
            CompanyContext::initializeForUser();
            $companyRepo = new CompanyRepository();
            $data['companies_list'] = array_map(
                static fn($company) => $company->toArray(),
                $companyRepo->findActive()
            );
            $data['active_company'] = CompanyContext::getActiveCompany();
        }

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

