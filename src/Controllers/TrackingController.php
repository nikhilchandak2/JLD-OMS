<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\WheelsEyeApiService;
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
                $vehicleData['latest_tracking'] = $trackingMap[$vehicle->id] ?? null;
                $vehicleData['path_points'] = [];
                $pathPoints = $this->gpsTrackingRepository->getRecentPathForVehicle($vehicle->id, $pathHours, $pathLimit);
                foreach ($pathPoints as $p) {
                    $vehicleData['path_points'][] = [
                        'lat' => (float)$p->latitude,
                        'lng' => (float)$p->longitude,
                        'timestamp' => $p->timestamp,
                    ];
                }
                $result[] = $vehicleData;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result,
                'timestamp' => date('Y-m-d H:i:s')
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
            echo json_encode(array_merge(['success' => $result['success']], $result));
        } catch (\Exception $e) {
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
}
