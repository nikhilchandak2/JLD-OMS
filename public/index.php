<?php
/**
 * Order Processing System - Entry Point
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Application;
use App\Core\Router;
use App\Core\Database;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

applySecurityHeaders();

// Set timezone (default: India)
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

// Session cookie: ensure it works over HTTPS (set SESSION_SECURE=1 or APP_URL=https://... in .env)
if (session_status() === PHP_SESSION_NONE) {
    $appUrl = $_ENV['APP_URL'] ?? '';
    $forceSecure = ($_ENV['SESSION_SECURE'] ?? '') === '1' || str_starts_with(strtolower($appUrl), 'https://');
    $isSecure = $forceSecure || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Initialize application
$app = new Application();

// Set up database connection
$database = new Database();
$app->setDatabase($database);

// Set up router
$router = new Router();

// Register middleware
$router->addMiddleware(new CsrfMiddleware());
$router->addMiddleware(new RateLimitMiddleware());

// API Routes
$router->group('/api', function($router) {
    // Authentication routes
    $router->post('/login', 'AuthController@login');
    $router->post('/logout', 'AuthController@logout');
    $router->get('/session-status', 'AuthController@sessionStatus');
    
    // GPS/Fuel Webhooks (public, no auth required)
    $router->post('/gps/webhook', 'GPSFuelWebhookController@receiveGPSData');
    $router->post('/fuel/webhook', 'GPSFuelWebhookController@receiveFuelData');
    $router->post('/gps/batch', 'GPSFuelWebhookController@receiveGPSData'); // Batch endpoint
    
    // Protected routes
    $router->group('', function($router) {
        // Vehicle management
        $router->get('/vehicles', 'VehicleController@index');
        $router->get('/vehicles/{id}', 'VehicleController@show');
        $router->post('/vehicles', 'VehicleController@create');
        $router->put('/vehicles/{id}', 'VehicleController@update');
        $router->delete('/vehicles/{id}', 'VehicleController@delete');
        
        // GPS Devices
        $router->get('/gps/devices', 'VehicleController@gpsDevices');
        
        // Fuel Sensors
        $router->get('/fuel/sensors', 'VehicleController@fuelSensors');
        
        // Live Tracking
        $router->get('/tracking/live', 'TrackingController@live');
        $router->get('/tracking/vehicle/{id}', 'TrackingController@vehicleHistory');
        $router->get('/tracking/sync', 'TrackingController@syncFromWheelsEye');
        $router->post('/tracking/sync', 'TrackingController@syncFromWheelsEye');
        $router->get('/tracking/sync-status', 'TrackingController@syncStatus');
        $router->get('/tracking/rebuild-trips', 'TrackingController@rebuildTrips');
        $router->post('/tracking/rebuild-trips', 'TrackingController@rebuildTrips');
        
        // Trips
        $router->get('/trips', 'TripController@index');
        $router->get('/trips/vehicle/{id}', 'TripController@vehicleTrips');
        $router->get('/trips/vehicle/{id}/stoppage-timeline', 'TripController@vehicleStoppageTimeline');
        $router->get('/trips/stockpile/{id}', 'TripController@stockpileTrips');
        $router->get('/trips/{id}/stoppages', 'TripController@tripStoppages');
        
        // Geofences
        $router->get('/geofences', 'GeofenceController@index');
        $router->get('/geofences/{id}', 'GeofenceController@show');
        $router->post('/geofences', 'GeofenceController@create');
        $router->put('/geofences/{id}', 'GeofenceController@update');
        $router->delete('/geofences/{id}', 'GeofenceController@delete');
        
        // Fuel Management
        $router->get('/fuel/vehicles', 'FuelController@vehicles');
        $router->get('/fuel/vehicle/{id}', 'FuelController@vehicleFuel');
        $router->get('/fuel/alerts', 'FuelController@alerts');
        // Excavating machines & daily dumper assignments
        $router->get('/excavating-machines', 'DumperAssignmentController@listMachines');
        $router->put('/excavating-machines/{id}', 'DumperAssignmentController@updateMachine');
        $router->get('/dumper-assignments', 'DumperAssignmentController@getAssignments');
        $router->post('/dumper-assignments', 'DumperAssignmentController@addAssignment');
        $router->delete('/dumper-assignments/{id}', 'DumperAssignmentController@removeAssignment');
        // User management (admin only)
        $router->get('/users', 'UserController@index');
        $router->get('/users/roles', 'UserController@roles');
        $router->get('/users/{id}', 'UserController@show');
        $router->post('/users', 'UserController@create');
        $router->put('/users/{id}', 'UserController@update');
        $router->delete('/users/{id}', 'UserController@delete');
        
        
        // Party management API
        $router->get('/parties', 'PartyController@index');
        $router->get('/parties/{id}', 'PartyController@show');
        $router->post('/parties', 'PartyController@create');
        $router->post('/parties/import', 'PartyController@importFromCsv');
        $router->put('/parties/{id}', 'PartyController@update');
        $router->delete('/parties/{id}', 'PartyController@delete');
        
        // Reminders (Python script) – admin, accounts
        $router->post('/reminders/run', 'RemindersController@run');
        
        // Product management API
        $router->get('/products', 'ProductController@index');
        $router->get('/products/{id}', 'ProductController@show');
        $router->post('/products', 'ProductController@create');
        $router->put('/products/{id}', 'ProductController@update');
        $router->delete('/products/{id}', 'ProductController@delete');
        
        // Company management API
        $router->get('/companies', 'CompanyController@index');
        $router->get('/companies/{id}', 'CompanyController@show');
        
        // Orders
        $router->get('/orders', 'OrderController@index');
        $router->get('/orders/{id}', 'OrderController@show');
        $router->get('/orders/credit-approvals/pending', 'OrderController@creditApprovalsPending');
        $router->post('/orders/credit-approvals/{id}/decide', 'OrderController@decideCreditApproval');
        $router->post('/orders', 'OrderController@create');
        $router->put('/orders/{id}', 'OrderController@update');
        $router->delete('/orders/{id}', 'OrderController@delete');
        $router->get('/orders/{id}/scheduled-deliveries', 'OrderController@getScheduledDeliveries');
        
        // Dispatches
        $router->get('/dispatches', 'DispatchController@index');
        $router->post('/orders/{id}/dispatches', 'DispatchController@create');
        
        // Document Generation (OMS – orders/dispatches)
        $router->get('/documents/types', 'DocumentController@getTypes');
        $router->post('/documents/generate', 'DocumentController@generate');
        $router->get('/documents/download', 'DocumentController@download');
        
        // Export Documents (Nepal) – separate module; own data, no link to OMS orders/tracking
        $router->get('/export/check-setup', 'ExportDocumentsController@checkSetup');
        $router->get('/export/orders', 'ExportDocumentsController@listExportOrders');
        $router->post('/export/orders', 'ExportDocumentsController@createExportOrder');
        $router->get('/export/orders/{id}', 'ExportDocumentsController@showExportOrder');
        $router->post('/export/upload-template', 'ExportDocumentsController@uploadTemplate');
        $router->post('/export/dispatch-pack', 'ExportDocumentsController@generateDispatchPack');
        $router->get('/export/download', 'ExportDocumentsController@download');
        
        // Busy Integration
        $router->post('/busy/webhook', 'BusyIntegrationController@receiveInvoiceWebhook');
        $router->post('/busy/sync', 'BusyIntegrationController@syncInvoices');
        $router->get('/busy/status', 'BusyIntegrationController@getIntegrationStatus');
        
        // Dashboard
        $router->get('/dashboard', 'DashboardController@index');
        $router->get('/dashboard/summary', 'DashboardController@summary');
        
        // Reports
        $router->get('/reports/partywise', 'ReportController@partywise');
        $router->get('/reports/partywise/export', 'ReportController@export');
        $router->get('/reports/parties', 'ReportController@parties');
        $router->get('/reports/products', 'ReportController@products');
        
        // Analytics API
        $router->get('/analytics/orders', 'AnalyticsController@orders');
        $router->get('/analytics/dispatches', 'AnalyticsController@dispatches');
        $router->get('/analytics/pending', 'AnalyticsController@pending');
        $router->get('/analytics/parties', 'AnalyticsController@parties');
        
        // CRM API
        $router->get('/crm/summary', 'CrmController@summary');
        $router->get('/crm/stages', 'CrmController@stages');
        $router->get('/crm/funnel', 'CrmController@funnel');
        $router->get('/crm/users/options', 'CrmController@userOptions');
        $router->get('/crm/parties/{partyId}/contacts', 'CrmContactController@listByParty');
        $router->post('/crm/parties/{partyId}/contacts', 'CrmContactController@create');
        $router->get('/crm/contacts/{id}', 'CrmContactController@show');
        $router->put('/crm/contacts/{id}', 'CrmContactController@update');
        $router->delete('/crm/contacts/{id}', 'CrmContactController@delete');
        $router->get('/crm/activities', 'CrmActivityController@index');
        $router->get('/crm/activities/{id}', 'CrmActivityController@show');
        $router->post('/crm/activities', 'CrmActivityController@create');
        $router->put('/crm/activities/{id}', 'CrmActivityController@update');
        $router->delete('/crm/activities/{id}', 'CrmActivityController@delete');
        // CRM tasks (sales-owner task panel)
        $router->get('/crm/tasks', 'CrmTaskController@index');
        $router->post('/crm/tasks', 'CrmTaskController@create');
        $router->put('/crm/tasks/{id}', 'CrmTaskController@update');
        $router->delete('/crm/tasks/{id}', 'CrmTaskController@delete');
        $router->get('/crm/samples', 'CrmSampleController@index');
        $router->get('/crm/samples/{id}', 'CrmSampleController@show');
        $router->post('/crm/samples', 'CrmSampleController@create');
        $router->put('/crm/samples/{id}', 'CrmSampleController@update');
        $router->delete('/crm/samples/{id}', 'CrmSampleController@delete');
        $router->get('/crm/parties/{partyId}/receivables', 'CrmReceivableController@listByParty');
        $router->post('/crm/receivables', 'CrmReceivableController@addEntry');
        $router->delete('/crm/receivables/{id}', 'CrmReceivableController@deleteEntry');
        $router->get('/crm/receivables/aging', 'CrmReceivableController@agingSummary');
        $router->post('/crm/receivables/import', 'CrmReceivableController@importFromCsv');
        
    }, [new AuthMiddleware()]);
});

// Web Routes
$router->get('/', 'WebController@dashboard');
$router->get('/login', 'WebController@loginForm');
$router->get('/dashboard', 'WebController@dashboard');
$router->get('/orders', 'WebController@orders');
$router->get('/orders/analytics', 'WebController@ordersAnalytics');
$router->get('/orders/new', 'WebController@newOrder');
$router->get('/orders/{id}', 'WebController@orderDetail');
$router->get('/reports', 'WebController@reports');
$router->get('/admin/users', 'WebController@users');
$router->get('/admin/credit-approvals', 'WebController@creditApprovalsPage');
$router->get('/admin/parties/import', 'WebController@partiesImport');
$router->get('/admin/parties', 'WebController@parties');
$router->get('/admin/products', 'WebController@products');
$router->get('/admin/reminders', 'WebController@reminders');
$router->get('/admin/bills/import', 'WebController@adminImportBills');
$router->get('/analytics/orders', 'WebController@analyticsOrders');
$router->get('/analytics/dispatches', 'WebController@analyticsDispatches');
$router->get('/analytics/pending', 'WebController@analyticsPending');
$router->get('/analytics/parties', 'WebController@analyticsParties');
$router->get('/admin/busy-integration', 'WebController@busyIntegration');
$router->get('/vehicles', 'WebController@vehicles');
$router->get('/tracking', 'WebController@tracking');
$router->get('/tracking/wheelseye-data', 'WebController@wheelseyeData');
$router->get('/trips', 'WebController@trips');
$router->get('/geofences', 'WebController@geofences');
$router->get('/fuel', 'WebController@fuel');
$router->get('/dumper-assignment', 'WebController@dumperAssignment');
// Export Documents (Nepal) - separate module, not linked to OMS orders/tracking
$router->get('/export', 'WebController@exportDocuments');

// CRM – Customer Relationship Management (funnel, contacts, activities, samples, receivables)
$router->get('/crm', 'WebController@crm');
$router->get('/crm/funnel', 'WebController@crmFunnel');
$router->get('/crm/parties/new', 'WebController@crmPartyNew');
$router->get('/crm/parties/{id}', 'WebController@crmPartyDetail');
$router->get('/crm/import-receivables', 'WebController@crmImportReceivables');

// Handle the request
try {
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (Exception $e) {
    http_response_code(500);
    if ($_ENV['APP_DEBUG'] ?? false) {
        echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    } else {
        echo json_encode(['error' => 'Internal Server Error']);
    }
}

function applySecurityHeaders(): void
{
    $enabledRaw = strtolower(trim((string)($_ENV['SECURITY_HEADERS_ENABLED'] ?? '1')));
    $enabled = !in_array($enabledRaw, ['0', 'false', 'off', 'no'], true);
    if (!$enabled) {
        return;
    }

    // Basic hardening headers.
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Cross-Origin-Resource-Policy: same-origin');

    $csp = trim((string)($_ENV['SECURITY_CSP'] ?? ''));
    if ($csp === '') {
        // Compatible default with current external CDNs and inline scripts/styles.
        $csp = "default-src 'self'; "
            . "base-uri 'self'; "
            . "frame-ancestors 'self'; "
            . "object-src 'none'; "
            . "form-action 'self'; "
            . "img-src 'self' data: https:; "
            . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com data:; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
            . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com; "
            . "connect-src 'self';";
    }
    header('Content-Security-Policy: ' . $csp);

    $secureRequest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($secureRequest) {
        $hstsMaxAge = (int)($_ENV['SECURITY_HSTS_MAX_AGE'] ?? 31536000);
        $includeSubdomainsRaw = strtolower(trim((string)($_ENV['SECURITY_HSTS_INCLUDE_SUBDOMAINS'] ?? '1')));
        $preloadRaw = strtolower(trim((string)($_ENV['SECURITY_HSTS_PRELOAD'] ?? '0')));
        $includeSubdomains = !in_array($includeSubdomainsRaw, ['0', 'false', 'off', 'no'], true);
        $preload = !in_array($preloadRaw, ['0', 'false', 'off', 'no'], true);

        $hsts = 'max-age=' . max($hstsMaxAge, 300);
        if ($includeSubdomains) {
            $hsts .= '; includeSubDomains';
        }
        if ($preload) {
            $hsts .= '; preload';
        }
        header('Strict-Transport-Security: ' . $hsts);
    }
}

