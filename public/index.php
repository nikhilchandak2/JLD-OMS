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

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Start session
session_start();

// Initialize application
$app = new Application();

// Set up database connection
$database = new Database();
$app->setDatabase($database);

// Set up router
$router = new Router();

// Register middleware
$router->addMiddleware(new CsrfMiddleware());

// API Routes
$router->group('/api', function($router) {
    // Authentication routes
    $router->post('/login', 'AuthController@login');
    $router->post('/logout', 'AuthController@logout');
    
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
        
        // Trips
        $router->get('/trips', 'TripController@index');
        $router->get('/trips/vehicle/{id}', 'TripController@vehicleTrips');
        $router->get('/trips/stockpile/{id}', 'TripController@stockpileTrips');
        
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
        $router->put('/parties/{id}', 'PartyController@update');
        $router->delete('/parties/{id}', 'PartyController@delete');
        
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
        $router->post('/orders', 'OrderController@create');
        $router->put('/orders/{id}', 'OrderController@update');
        $router->delete('/orders/{id}', 'OrderController@delete');
        $router->get('/orders/{id}/scheduled-deliveries', 'OrderController@getScheduledDeliveries');
        
        // Dispatches
        $router->get('/dispatches', 'DispatchController@index');
        $router->post('/orders/{id}/dispatches', 'DispatchController@create');
        
        // Document Generation
        $router->get('/documents/types', 'DocumentController@getTypes');
        $router->post('/documents/generate', 'DocumentController@generate');
        $router->get('/documents/download', 'DocumentController@download');
        
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
$router->get('/admin/parties', 'WebController@parties');
$router->get('/admin/products', 'WebController@products');
$router->get('/analytics/orders', 'WebController@analyticsOrders');
$router->get('/analytics/dispatches', 'WebController@analyticsDispatches');
$router->get('/analytics/pending', 'WebController@analyticsPending');
$router->get('/analytics/parties', 'WebController@analyticsParties');
$router->get('/admin/busy-integration', 'WebController@busyIntegration');
$router->get('/vehicles', 'WebController@vehicles');
$router->get('/tracking', 'WebController@tracking');
$router->get('/trips', 'WebController@trips');
$router->get('/geofences', 'WebController@geofences');
$router->get('/fuel', 'WebController@fuel');

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

