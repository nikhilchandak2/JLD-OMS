<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\WheelsEyeApiService;
use App\Services\WheelsEyeHistoricalTripsService;
use App\Services\TripDetectionService;
use App\Repositories\VehicleRepository;
use App\Repositories\GPSTrackingRepository;
use App\Support\WheelsEyeSyncLock;

class TrackingController
{
    private AuthService $authService;
    private VehicleRepository $vehicleRepository;
    private GPSTrackingRepository $gpsTrackingRepository;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->vehicleRepository = new VehicleRepository();
        $this->gpsTrackingRepository = new GPSTrackingRepository();
    }
    
    /**
     * Get live tracking data for all vehicles
     * GET /api/tracking/live
     */
    public function live(): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $syncResult = null;
            // Live map reads DB only; pull sync runs via CLI/systemd or manual Sync button.
            $allowLivePageSync = ($_ENV['WHEELSEYE_ALLOW_LIVE_PAGE_SYNC'] ?? '0') === '1';
            if ($allowLivePageSync && isset($_GET['sync_now']) && (int)$_GET['sync_now'] === 1) {
                try {
                    $service = new WheelsEyeApiService();
                    $syncResult = $service->syncCurrentLocations();
                    $this->saveSyncStatus(array_merge($syncResult, ['last_run' => date('Y-m-d H:i:s')]));
                } catch (\Exception $syncException) {
                    $syncResult = [
                        'success' => false,
                        'message' => $syncException->getMessage(),
                        'synced' => 0,
                        'skipped' => 0,
                        'errors' => []
                    ];
                }
            }

            $vehicles = $this->vehicleRepository->findAll(['status' => 'active']);
            $latestTracking = $this->gpsTrackingRepository->getLatestForAllVehicles();
            
            $trackingMap = [];
            foreach ($latestTracking as $tracking) {
                $trackingMap[$tracking->vehicleId] = $tracking->toArray();
            }
            
            $pathHours = isset($_GET['path_hours']) ? (int)$_GET['path_hours'] : 24;
            $pathLimit = isset($_GET['path_limit']) ? min((int)$_GET['path_limit'], 2000) : 500;
            $pathHours = max(1, min(168, $pathHours)); // 1h to 7 days
            
            $result = [];
            foreach ($vehicles as $vehicle) {
                $vehicleData = $vehicle->toArray();
                $latest = $trackingMap[$vehicle->id] ?? null;
                $vehicleData['latest_tracking'] = $latest;
                $vehicleData['path_points'] = [];
                $pathPoints = $this->gpsTrackingRepository->getRecentPathForVehicle($vehicle->id, $pathHours, $pathLimit);
                foreach ($pathPoints as $p) {
                    $vehicleData['path_points'][] = [
                        'lat' => (float)$p->latitude,
                        'lng' => (float)$p->longitude,
                        'timestamp' => $p->timestamp,
                    ];
                }
                // Ensure path ends at current position so the line connects to the live marker
                if ($latest && isset($latest['latitude']) && isset($latest['longitude'])) {
                    $lat = (float)$latest['latitude'];
                    $lng = (float)$latest['longitude'];
                    $last = end($vehicleData['path_points']);
                    if ($last === false || $last['lat'] != $lat || $last['lng'] != $lng) {
                        $vehicleData['path_points'][] = [
                            'lat' => $lat,
                            'lng' => $lng,
                            'timestamp' => $latest['timestamp'] ?? date('Y-m-d H:i:s'),
                        ];
                    }
                }
                $result[] = $vehicleData;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result,
                'timestamp' => date('Y-m-d H:i:s'),
                'sync_result' => $syncResult
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get tracking history for a specific vehicle
     * GET /api/tracking/vehicle/{id}
     */
    public function vehicleHistory(int $id): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $vehicle = $this->vehicleRepository->findById($id);
            if (!$vehicle) {
                http_response_code(404);
                echo json_encode(['error' => 'Vehicle not found']);
                return;
            }
            
            $startDate = $_GET['start_date'] ?? date('Y-m-d 00:00:00');
            $endDate = $_GET['end_date'] ?? date('Y-m-d 23:59:59');
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 1000;
            
            $history = $this->gpsTrackingRepository->getHistoryForVehicle(
                $id,
                $startDate,
                $endDate,
                $limit
            );
            
            echo json_encode([
                'success' => true,
                'vehicle' => $vehicle->toArray(),
                'data' => array_map(fn($t) => $t->toArray(), $history)
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Sync current locations from WheelsEye vendor API into the database.
     * GET or POST /api/tracking/sync
     * Auth: logged-in user, OR valid TRACKING_SYNC_KEY (for cron: GET /api/tracking/sync?key=your-secret)
     */
    public function syncFromWheelsEye(): void
    {
        header('Content-Type: application/json');

        $syncKey = $_GET['key'] ?? $_SERVER['HTTP_X_SYNC_KEY'] ?? null;
        $validSyncKey = $_ENV['TRACKING_SYNC_KEY'] ?? null;
        $allowedByKey = $validSyncKey !== null && $validSyncKey !== '' && hash_equals((string)$validSyncKey, (string)$syncKey);
        $user = $this->authService->getCurrentUser();

        if (!$allowedByKey && !$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        // Block URL/cron sync via HTTP in production — use CLI systemd loop instead.
        $allowHttpSync = ($_ENV['WHEELSEYE_ALLOW_HTTP_SYNC'] ?? '0') === '1';
        if ($allowedByKey && !$allowHttpSync) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'HTTP pull sync is disabled. Use scripts/auto_sync_wheelseye.php via systemd, or set WHEELSEYE_ALLOW_HTTP_SYNC=1 only for debugging.',
                'synced' => 0,
                'skipped' => 0,
                'errors' => [],
            ]);
            return;
        }

        $lock = WheelsEyeSyncLock::tryAcquire();
        if ($lock === null) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'WheelsEye sync already running (CLI loop or another request). Try again shortly.',
                'synced' => 0,
                'skipped' => 0,
                'errors' => [],
            ]);
            return;
        }

        try {
            $service = new WheelsEyeApiService();
            $result = $service->syncCurrentLocations();
            $this->saveSyncStatus(array_merge($result, ['last_run' => date('Y-m-d H:i:s')]));
            echo json_encode(array_merge(['success' => $result['success']], $result));
        } catch (\Exception $e) {
            $this->saveSyncStatus([
                'success' => false,
                'message' => $e->getMessage(),
                'synced' => 0,
                'skipped' => 0,
                'errors' => [],
                'last_run' => date('Y-m-d H:i:s'),
            ]);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'synced' => 0,
                'skipped' => 0,
                'errors' => [],
            ]);
        } finally {
            WheelsEyeSyncLock::release($lock);
        }
    }

    /**
     * Sync historical (yesterday) trip segments from WheelsEye into `vehicle_trips`.
     * GET/POST /api/tracking/sync-yesterday-trips?vehicle_id=ID&key=YOUR_SECRET
     *
     * Auth: logged-in user OR valid TRACKING_SYNC_KEY.
     */
    public function syncYesterdayTrips(): void
    {
        header('Content-Type: application/json');

        $syncKey = $_GET['key'] ?? $_SERVER['HTTP_X_SYNC_KEY'] ?? null;
        $validSyncKey = $_ENV['TRACKING_SYNC_KEY'] ?? null;
        $allowedByKey = $validSyncKey !== null && $validSyncKey !== '' && hash_equals((string)$validSyncKey, (string)$syncKey);

        $user = $this->authService->getCurrentUser();
        if (!$allowedByKey && !$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $payload = $_POST;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $raw = file_get_contents('php://input');
            if ($raw !== false && trim($raw) !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $payload = array_merge($payload, $json);
                }
            }
        }

        $vehicleId = isset($payload['vehicle_id']) ? (int)$payload['vehicle_id'] : (isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0);
        $vehicleNumber = isset($payload['vehicle_number']) ? trim((string)$payload['vehicle_number']) : (isset($_GET['vehicle_number']) ? trim((string)$_GET['vehicle_number']) : '');
        if ($vehicleId <= 0 && $vehicleNumber !== '') {
            $vehicle = $this->vehicleRepository->findByVehicleNumber($vehicleNumber);
            $vehicleId = $vehicle ? (int)$vehicle->id : 0;
        }

        if ($vehicleId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'vehicle_id is required (or provide vehicle_number)']);
            return;
        }

        try {
            $service = new WheelsEyeHistoricalTripsService();
            $result = $service->syncYesterdayTrips($vehicleId);
            echo json_encode($result);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get last route/tracking sync status.
     * GET /api/tracking/sync-status
     */
    public function syncStatus(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $path = dirname(__DIR__, 2) . '/storage/last_tracking_sync.json';
        $data = null;
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $data = json_decode($raw, true) ?: null;
            }
        }

        echo json_encode([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get historical pulled tracking rows from DB (not only latest per vehicle).
     * GET /api/tracking/pulled-data?limit=50&offset=0&vehicle=RJ07
     */
    public function pulledData(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $vehicleFilter = isset($_GET['vehicle']) ? trim((string)$_GET['vehicle']) : null;

            $rows = $this->gpsTrackingRepository->getPulledDataHistory($vehicleFilter, $limit, $offset);
            $total = $this->gpsTrackingRepository->countPulledDataHistory($vehicleFilter);

            $data = array_map(static function (array $row): array {
                $rawData = null;
                if (isset($row['raw_data']) && $row['raw_data'] !== null && $row['raw_data'] !== '') {
                    $decoded = json_decode((string)$row['raw_data'], true);
                    $rawData = is_array($decoded) ? $decoded : $row['raw_data'];
                }

                return [
                    'id' => (int)($row['id'] ?? 0),
                    'vehicle_id' => isset($row['vehicle_id']) ? (int)$row['vehicle_id'] : null,
                    'vehicle_number' => $row['vehicle_number'] ?? '-',
                    'device_id' => $row['device_id'] ?? '-',
                    'latitude' => isset($row['latitude']) ? (float)$row['latitude'] : null,
                    'longitude' => isset($row['longitude']) ? (float)$row['longitude'] : null,
                    'speed' => isset($row['speed']) ? (float)$row['speed'] : null,
                    'ignition_status' => isset($row['ignition_status']) && $row['ignition_status'] !== '' ? (bool)$row['ignition_status'] : null,
                    'timestamp' => $row['timestamp'] ?? null,
                    'raw_data' => $rawData,
                ];
            }, $rows);

            echo json_encode([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'limit' => max(1, min(500, $limit)),
                    'offset' => max(0, $offset),
                    'total' => $total,
                ],
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Rebuild trips for a vehicle from stored GPS points in a time range.
     * GET/POST /api/tracking/rebuild-trips?vehicle_id=ID&start_time=...&end_time=...
     */
    public function rebuildTrips(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $payload = $_POST;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $raw = file_get_contents('php://input');
            if ($raw !== false && trim($raw) !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $payload = array_merge($payload, $json);
                }
            }
        }

        $vehicleId = isset($payload['vehicle_id']) ? (int)$payload['vehicle_id'] : (isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0);

        $startTime = $payload['start_time'] ?? $_GET['start_time'] ?? date('Y-m-d 00:00:00');
        $endTime = $payload['end_time'] ?? $_GET['end_time'] ?? date('Y-m-d H:i:s');
        $startTime = strlen((string)$startTime) <= 10 ? ((string)$startTime . ' 00:00:00') : (string)$startTime;
        $endTime = strlen((string)$endTime) <= 10 ? ((string)$endTime . ' 23:59:59') : (string)$endTime;

        try {
            $service = new TripDetectionService();
            $vehicles = [];
            if ($vehicleId > 0) {
                $vehicle = $this->vehicleRepository->findById($vehicleId);
                if (!$vehicle) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Vehicle not found']);
                    return;
                }
                $vehicles[] = $vehicle;
            } else {
                // Rebuild should include every configured vehicle, not only active ones.
                // Some sites keep operational vehicles as inactive in OMS master data.
                $vehicles = $this->vehicleRepository->findAll();
            }

            if (empty($vehicles)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'No vehicles available for rebuild',
                    'data' => [
                        'vehicle_count' => 0,
                        'results' => [],
                        'totals' => [
                            'tracking_points_processed' => 0,
                            'total_trips' => 0,
                            'completed_trips' => 0,
                            'in_progress_trips' => 0,
                            'cancelled_trips' => 0,
                        ],
                    ],
                ]);
                return;
            }

            $results = [];
            $errors = [];
            $totals = [
                'tracking_points_processed' => 0,
                'total_trips' => 0,
                'completed_trips' => 0,
                'in_progress_trips' => 0,
                'cancelled_trips' => 0,
                'geofence_events_generated' => 0,
                'pit_entries' => 0,
                'pit_exits' => 0,
                'destination_entries' => 0,
            ];

            foreach ($vehicles as $targetVehicle) {
                try {
                    $row = $service->rebuildTripsFromTracking($targetVehicle->id, $startTime, $endTime);
                    $row['vehicle_number'] = $targetVehicle->vehicleNumber;
                    $results[] = $row;
                    $totals['tracking_points_processed'] += (int)($row['tracking_points_processed'] ?? 0);
                    $totals['total_trips'] += (int)($row['summary']['total_trips'] ?? 0);
                    $totals['completed_trips'] += (int)($row['summary']['completed_trips'] ?? 0);
                    $totals['in_progress_trips'] += (int)($row['summary']['in_progress_trips'] ?? 0);
                    $totals['cancelled_trips'] += (int)($row['summary']['cancelled_trips'] ?? 0);
                    $totals['geofence_events_generated'] += (int)($row['diagnostics']['geofence_events_generated'] ?? 0);
                    $totals['pit_entries'] += (int)($row['diagnostics']['pit_entries'] ?? 0);
                    $totals['pit_exits'] += (int)($row['diagnostics']['pit_exits'] ?? 0);
                    $totals['destination_entries'] += (int)($row['diagnostics']['destination_entries'] ?? 0);
                } catch (\Exception $vehicleException) {
                    $errors[] = 'Vehicle ' . $targetVehicle->vehicleNumber . ': ' . $vehicleException->getMessage();
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Trips rebuilt from stored tracking points',
                'data' => [
                    'vehicle_count' => count($vehicles),
                    'range' => [
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                    ],
                    'results' => $results,
                    'totals' => $totals,
                    'errors' => $errors,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function saveSyncStatus(array $status): void
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . '/last_tracking_sync.json';
        @file_put_contents($path, json_encode($status, JSON_PRETTY_PRINT));
    }
}
