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
            
            // Create a map of vehicle_id => latest tracking
            $trackingMap = [];
            foreach ($latestTracking as $tracking) {
                $trackingMap[$tracking->vehicleId] = $tracking->toArray();
            }
            
            // Combine vehicles with their latest tracking data
            $result = [];
            foreach ($vehicles as $vehicle) {
                $vehicleData = $vehicle->toArray();
                $vehicleData['latest_tracking'] = $trackingMap[$vehicle->id] ?? null;
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
     */
    public function syncFromWheelsEye(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user) {
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
