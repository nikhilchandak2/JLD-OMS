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

\App\Support\CrmSchemaEnsure::apply();

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
    
    // GPS Webhooks (public, no auth required) — fuel management uses monthly reports, not webhooks
    $router->post('/gps/webhook', 'GPSFuelWebhookController@receiveGPSData');
    $router->post('/gps/batch', 'GPSFuelWebhookController@receiveGPSData'); // Batch endpoint
    // Providers (e.g. Ashok Leyland iAlert) probe the webhook URL with GET/HEAD before
    // enabling data forwarding; respond 200 so the endpoint validation passes.
    $router->get('/gps/webhook', 'GPSFuelWebhookController@webhookHealth');
    $router->get('/gps/batch', 'GPSFuelWebhookController@webhookHealth');

    // Reminders runner endpoints (public; authenticated using REMINDERS_RUNNER_KEY)
    $router->get('/reminders/jobs/next', 'RemindersJobsController@next');
    $router->get('/reminders/jobs/{id}/download', 'RemindersJobsController@download');
    $router->post('/reminders/jobs/{id}/complete', 'RemindersJobsController@complete');

    // INERT (B1): Busy webhook is not a live ledger path. Batch upload via /data-feeds only.
    // The route stays so a leftover Busy config gets a loud 410 instead of silent success.
    $router->post('/busy/webhook', 'BusyIntegrationController@receiveInvoiceWebhook');
    
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
        
        // Live Tracking
        $router->get('/tracking/live', 'TrackingController@live');
        $router->get('/tracking/vehicle/{id}', 'TrackingController@vehicleHistory');
        $router->get('/tracking/pulled-data', 'TrackingController@pulledData');
        $router->get('/tracking/sync', 'TrackingController@syncFromWheelsEye');
        $router->post('/tracking/sync', 'TrackingController@syncFromWheelsEye');
        $router->get('/tracking/sync-yesterday-trips', 'TrackingController@syncYesterdayTrips');
        $router->post('/tracking/sync-yesterday-trips', 'TrackingController@syncYesterdayTrips');
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
        
        // Fuel Management (monthly vendor reports — Kobelco / JCB / Dumpers)
        $router->get('/fuel/categories', 'FuelController@categories');
        $router->get('/fuel/machines', 'FuelController@machines');
        $router->get('/fuel/machines/{id}/readings/pdf', 'FuelController@machineReadingsPdf');
        $router->get('/fuel/machines/{id}/readings', 'FuelController@machineReadings');
        $router->post('/fuel/reports/upload', 'FuelController@uploadReport');
        $router->delete('/fuel/reports/{id}', 'FuelController@deleteUpload');
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
        
        // Reminders (legacy direct-run) – admin, accounts
        $router->post('/reminders/run', 'RemindersController@run');
        // Reminders jobs (upload -> offline runner) – admin, accounts
        $router->post('/reminders/jobs', 'RemindersJobsController@create');
        $router->get('/reminders/jobs/{id}', 'RemindersJobsController@status');
        $router->post('/reminders/jobs/{id}/cancel', 'RemindersJobsController@cancel');

        // TDS (Accounts) – Busy outward vouchers price-slab classification
        $router->get('/tds/uploads', 'TdsController@uploads');
        $router->get('/tds/uploads/{id}', 'TdsController@show');
        $router->get('/tds/uploads/{id}/export', 'TdsController@export');
        $router->get('/tds/uploads/{id}/export-contractors', 'TdsController@exportContractors');
        $router->post('/tds/upload', 'TdsController@upload');
        $router->delete('/tds/uploads/{id}', 'TdsController@delete');
        
        // Product management API
        $router->get('/products', 'ProductController@index');
        $router->get('/products/{id}', 'ProductController@show');
        $router->post('/products', 'ProductController@create');
        $router->put('/products/{id}', 'ProductController@update');
        $router->delete('/products/{id}', 'ProductController@delete');
        $router->post('/products/import', 'ProductController@importFromCsv');
        
        // Company management API
        $router->get('/companies', 'CompanyController@index');
        $router->get('/companies/active', 'CompanyController@active');
        $router->post('/companies/active', 'CompanyController@setActive');
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
        $router->get('/dispatch-transfers', 'DispatchController@transferRecords');
        $router->get('/dispatches/{id}/eway-bill-file', 'DispatchController@downloadEwayBillFile');
        $router->get('/dispatches/{id}/transfer-targets', 'DispatchController@transferTargets');
        $router->post('/dispatches/{id}/reject-transfer', 'DispatchController@rejectTransfer');
        $router->get('/dispatches', 'DispatchController@index');
        $router->get('/dispatch/pending', 'DispatchController@pending');
        $router->get('/dispatches/{id}', 'DispatchController@show');
        $router->put('/dispatches/{id}', 'DispatchController@update');
        $router->delete('/dispatches/{id}', 'DispatchController@delete');
        $router->post('/orders/{id}/dispatches', 'DispatchController@create');

        // Party credit status & credit requests (max 2 per party per month)
        $router->get('/parties/{id}/credit-status', 'CreditRequestController@creditStatus');
        $router->post('/parties/{id}/credit-requests', 'CreditRequestController@create');

        $router->get('/credit/evaluate', 'CreditGateController@evaluate');
        $router->get('/credit/parties/{id}/prefill', 'CreditGateController@prefill');
        $router->post('/credit/capture', 'CreditGateController@capture');
        $router->get('/credit/overrides', 'CreditGateController@queue');
        $router->get('/credit/overrides/volume', 'CreditGateController@volume');
        $router->post('/credit/overrides/batch-approve', 'CreditGateController@batchApprove');
        $router->post('/credit/expire', 'CreditGateController@expire');
        $router->get('/credit/overrides/{id}', 'CreditGateController@show');
        $router->post('/credit/overrides/{id}/decide', 'CreditGateController@decide');
        $router->post('/credit/overrides/{id}/withdraw', 'CreditGateController@withdraw');

        // Visit requests (marketing -> technical team)
        $router->get('/visit-requests', 'VisitRequestController@index');
        $router->post('/visit-requests', 'VisitRequestController@create');
        $router->put('/visit-requests/{id}', 'VisitRequestController@update');
        
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
        
        // Busy Integration (upload + status — session auth)
        $router->post('/busy/invoices/upload', 'BusyIntegrationController@uploadInvoicesFromCsv');
        $router->post('/busy/invoices/import', 'BusyIntegrationController@importInvoice');
        $router->get('/busy/invoice-uploads', 'BusyIntegrationController@listInvoiceUploads');
        $router->get('/busy/invoice-uploads/{id}/download', 'BusyIntegrationController@downloadInvoiceUpload');
        $router->get('/busy/daily-invoices', 'BusyIntegrationController@dailyInvoices');
        $router->post('/busy/daily-invoices/remap', 'BusyIntegrationController@remapDailyInvoices');
        $router->post('/busy/daily-invoices/fix-misfiled-orders', 'BusyIntegrationController@fixMisfiledAllowlistOrders');
        $router->post('/busy/sync', 'BusyIntegrationController@syncInvoices');
        $router->get('/busy/status', 'BusyIntegrationController@getIntegrationStatus');
        
        // Dashboard
        $router->get('/dashboard', 'DashboardController@index');
        $router->get('/dashboard/summary', 'DashboardController@summary');
        
        // Reports
        $router->get('/reports/partywise', 'ReportController@partywise');
        $router->get('/reports/partywise/export', 'ReportController@export');
        $router->get('/reports/daily-dispatch', 'ReportController@dailyDispatch');
        $router->get('/reports/daily-dispatch/export', 'ReportController@exportDailyDispatch');
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

        // Relationship mapping, competitive intelligence, account issues (TASK 4)
        $router->get('/crm/account-context/meta', 'AccountContextController@meta');
        $router->get('/crm/account-search', 'AccountContextController@search');
        $router->get('/crm/parties/{partyId}/account-context', 'AccountContextController@snapshot');
        $router->put('/crm/parties/{partyId}/account-context', 'AccountContextController@saveContext');
        $router->post('/crm/parties/{partyId}/competitors', 'AccountContextController@recordCompetitor');
        $router->post('/crm/parties/{partyId}/issues', 'AccountContextController@createIssue');
        $router->post('/crm/issues/{id}/resolve', 'AccountContextController@resolveIssue');
        $router->get('/crm/parties/{partyId}/visits', 'CrmVisitController@listByParty');
        $router->post('/crm/visits', 'CrmVisitController@create');
        $router->get('/crm/visits/overdue', 'CrmVisitController@overdue');
        $router->get('/crm/dormancy', 'DormancyController@index');
        $router->get('/crm/escalations', 'EscalationController@index');
        $router->post('/crm/escalations', 'EscalationController@create');
        $router->get('/crm/escalations/{id}', 'EscalationController@show');
        $router->post('/crm/escalations/{id}/acknowledge', 'EscalationController@acknowledge');
        $router->post('/crm/escalations/{id}/resolve', 'EscalationController@resolve');
        $router->post('/crm/escalations/{id}/dismiss', 'EscalationController@dismiss');
        $router->get('/crm/forecasts/meta', 'ForecastController@meta');
        $router->get('/crm/forecasts/worksheet', 'ForecastController@worksheet');
        $router->get('/crm/forecasts/actuals', 'ForecastController@actuals');
        $router->post('/crm/forecasts/periods', 'ForecastController@openPeriod');
        $router->post('/crm/forecasts/periods/{id}/lock', 'ForecastController@lockPeriod');
        $router->put('/crm/forecasts/periods/{periodId}/parties/{partyId}', 'ForecastController@saveParty');
        $router->get('/crm/handoffs/meta', 'HandoffController@meta');
        $router->get('/crm/handoffs', 'HandoffController@index');
        $router->post('/crm/handoffs', 'HandoffController@create');
        $router->get('/crm/handoffs/{id}/pdf', 'HandoffController@pdf');
        $router->post('/crm/handoffs/{id}/acknowledge', 'HandoffController@acknowledge');
        $router->post('/crm/handoffs/{id}/supersede', 'HandoffController@supersede');
        $router->get('/crm/handoffs/{id}', 'HandoffController@show');
        $router->get('/crm/pipeline/export', 'PipelineDashboardController@export');
        $router->get('/crm/pipeline', 'PipelineDashboardController@show');
        $router->get('/crm/parties/{partyId}/briefing', 'BriefingController@show');
        $router->get('/crm/parties/{partyId}/briefing/pdf', 'BriefingController@pdf');
        $router->post('/crm/parties/{partyId}/handover-notes', 'BriefingController@addNote');
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

        // CRM deals - the 7-stage sales pipeline (crm_deals is the only pipeline entity)
        $router->get('/crm/deals', 'CrmDealController@index');
        $router->get('/crm/deals/summary', 'CrmDealController@summary');
        $router->get('/crm/deals/reason-codes', 'CrmDealController@reasonCodes');
        $router->post('/crm/deals', 'CrmDealController@create');
        $router->get('/crm/deals/{id}', 'CrmDealController@show');
        $router->put('/crm/deals/{id}', 'CrmDealController@update');
        $router->delete('/crm/deals/{id}', 'CrmDealController@delete');
        $router->get('/crm/deals/{id}/criteria', 'CrmDealController@criteria');
        $router->post('/crm/deals/{id}/criteria', 'CrmDealController@saveCriteria');
        $router->post('/crm/deals/{id}/advance', 'CrmDealController@advance');
        $router->post('/crm/deals/{id}/move-back', 'CrmDealController@moveBack');
        $router->post('/crm/deals/{id}/win', 'CrmDealController@win');
        $router->post('/crm/deals/{id}/close', 'CrmDealController@close');
        $router->post('/crm/deals/{id}/reopen', 'CrmDealController@reopen');
        $router->post('/crm/deals/{id}/grades', 'CrmDealController@addGrade');
        $router->delete('/crm/deals/{id}/grades/{gradeCode}', 'CrmDealController@removeGrade');

        // CRM technical flags - orthogonal hold, routed to a team queue only
        $router->get('/crm/technical-flags', 'CrmTechnicalFlagController@index');
        $router->get('/crm/technical-flags/queues', 'CrmTechnicalFlagController@queues');
        $router->get('/crm/technical-flags/stats', 'CrmTechnicalFlagController@stats');
        $router->post('/crm/technical-flags', 'CrmTechnicalFlagController@create');
        $router->post('/crm/technical-flags/{id}/claim', 'CrmTechnicalFlagController@claim');
        $router->post('/crm/technical-flags/{id}/resolve', 'CrmTechnicalFlagController@resolve');
        $router->post('/crm/technical-flags/{id}/cancel', 'CrmTechnicalFlagController@cancel');

        // Daily batch ingest — ledger and dispatch files. Authoritative, not live.
        $router->get('/data-feeds', 'DataFeedController@dashboard');
        $router->get('/data-feeds/as-of', 'DataFeedController@asOf');
        $router->get('/data-feeds/unmatched', 'DataFeedController@unmatched');
        $router->get('/data-feeds/template/{feedKey}', 'DataFeedController@template');
        $router->post('/data-feeds/runs', 'DataFeedController@upload');
        $router->get('/data-feeds/runs/{id}', 'DataFeedController@show');
        $router->post('/data-feeds/runs/{id}/validate', 'DataFeedController@validate');
        $router->post('/data-feeds/runs/{id}/promote', 'DataFeedController@promote');
        $router->get('/data-feeds/runs/{id}/rejections', 'DataFeedController@rejections');
        $router->post('/data-feeds/aliases', 'DataFeedController@createAlias');
        $router->put('/data-feeds/{id}', 'DataFeedController@updateFeed');

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
$router->get('/dispatch/handoffs', 'WebController@dispatchHandoffs');
$router->get('/dispatch/history', 'WebController@dispatchHistory');
$router->get('/dispatch/reject-transfers', 'WebController@dispatchRejectTransfers');
$router->get('/dispatch/daily', 'WebController@dispatchDaily');
$router->get('/dispatch/uploads', 'WebController@dispatchUploads');
$router->get('/dispatch/invoices', 'WebController@dispatchInvoices');
$router->get('/dispatch', 'WebController@dispatchDashboard');
$router->get('/visit-requests', 'WebController@visitRequests');
$router->get('/reports', 'WebController@reports');
$router->get('/reports/daily-dispatch', 'WebController@dailyDispatchReport');
$router->get('/admin/users', 'WebController@users');
$router->get('/admin/credit-approvals/{id}', 'WebController@creditOverrideDetail');
$router->get('/admin/credit-approvals', 'WebController@creditApprovalsPage');
$router->get('/admin/parties/import', 'WebController@partiesImport');
$router->get('/admin/parties', 'WebController@parties');
$router->get('/admin/products', 'WebController@products');
$router->get('/admin/products/import', 'WebController@productsImport');
$router->get('/admin/reminders', 'WebController@reminders');
$router->get('/admin/tds', 'WebController@tds');
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
$router->get('/crm/parties/{id}/briefing', 'WebController@crmPartyBriefing');
$router->get('/crm/parties/{id}', 'WebController@crmPartyDetail');
$router->get('/crm/import-receivables', 'WebController@crmImportReceivables');
$router->get('/crm/deals', 'WebController@crmDeals');
$router->get('/crm/deals/new', 'WebController@crmDealNew');
$router->get('/crm/deals/{id}', 'WebController@crmDealDetail');
$router->get('/crm/technical-queue', 'WebController@crmTechnicalQueue');
$router->get('/crm/visits/new', 'WebController@crmVisitNew');
$router->get('/crm/visits/overdue', 'WebController@crmVisitsOverdue');
$router->get('/crm/dormancy', 'WebController@crmDormancy');
$router->get('/crm/escalations', 'WebController@crmEscalations');
$router->get('/crm/forecasts', 'WebController@crmForecasts');
$router->get('/crm/forecasts/actuals', 'WebController@crmForecastActuals');
$router->get('/crm/pipeline', 'WebController@crmPipeline');
$router->get('/data-feeds', 'WebController@dataFeeds');
$router->get('/data-feeds/upload', 'WebController@dataFeedsUpload');
$router->get('/data-feeds/unmatched', 'WebController@dataFeedsUnmatched');
$router->get('/data-feeds/runs/{id}', 'WebController@dataFeedsRun');

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
        // Default CSP: CDNs for Bootstrap/jQuery/Leaflet + HTTPS map tiles/APIs (Geofences, Tracking).
        $csp = "default-src 'self'; "
            . "base-uri 'self'; "
            . "frame-ancestors 'self'; "
            . "object-src 'none'; "
            . "form-action 'self'; "
            . "img-src 'self' data: blob: https:; "
            . "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
            . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com; "
            . "connect-src 'self' https:; "
            . "worker-src 'self' blob:;";
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

