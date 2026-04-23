<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\WheelsEyeApiService;
use App\Services\TripDetectionService;
use App\Repositories\VehicleRepository;
use App\Repositories\GPSTrackingRepository;

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
            if (isset($_GET['sync_now']) && (int)$_GET['sync_now'] === 1) {
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
                $vehicles = $this->vehicleRepository->findAll(['status' => 'active']);
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
