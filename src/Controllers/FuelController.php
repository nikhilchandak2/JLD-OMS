<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Core\Database;
use App\Repositories\VehicleRepository;
use App\Repositories\FuelReadingRepository;
use App\Services\FuelAlertService;

class FuelController
{
    private AuthService $authService;
    private Database $database;
    private VehicleRepository $vehicleRepository;
    private FuelReadingRepository $fuelReadingRepository;
    private FuelAlertService $fuelAlertService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->database = new Database();
        $this->vehicleRepository = new VehicleRepository();
        $this->fuelReadingRepository = new FuelReadingRepository();
        $this->fuelAlertService = new FuelAlertService();
    }
    
    /**
     * Get all vehicles with fuel data
     * GET /api/fuel/vehicles
     */
    public function vehicles(): void
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
            
            $result = [];
            foreach ($vehicles as $vehicle) {
                if ($vehicle->fuelSensorId) {
                    $latestReading = $this->fuelReadingRepository->getLatestForVehicle($vehicle->id);
                    $alerts = $this->fuelAlertService->getUnresolvedAlerts($vehicle->id);
                    
                    $vehicleData = $vehicle->toArray();
                    $vehicleData['latest_fuel_reading'] = $latestReading ? $latestReading->toArray() : null;
                    $vehicleData['fuel_alerts'] = $alerts;
                    $result[] = $vehicleData;
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get fuel data for a specific vehicle
     * GET /api/fuel/vehicle/{id}
     */
    public function vehicleFuel(int $id): void
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
            
            $startDate = $_GET['start_date'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d 23:59:59');
            
            $history = $this->fuelReadingRepository->getHistoryForVehicle($id, $startDate, $endDate);
            $latestReading = $this->fuelReadingRepository->getLatestForVehicle($id);
            $alerts = $this->fuelAlertService->getUnresolvedAlerts($id);
            
            // Calculate statistics
            $stats = $this->calculateFuelStatistics($id, $startDate, $endDate);
            
            echo json_encode([
                'success' => true,
                'vehicle' => $vehicle->toArray(),
                'latest_reading' => $latestReading ? $latestReading->toArray() : null,
                'history' => array_map(fn($r) => $r->toArray(), $history),
                'alerts' => $alerts,
                'statistics' => $stats
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get all fuel alerts
     * GET /api/fuel/alerts
     */
    public function alerts(): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $sql = "
                SELECT fa.*, v.vehicle_number
                FROM fuel_alerts fa
                JOIN vehicles v ON fa.vehicle_id = v.id
                WHERE fa.is_resolved = 0
                ORDER BY fa.created_at DESC
            ";
            
            $alerts = $this->database->fetchAll($sql);
            
            echo json_encode([
                'success' => true,
                'data' => $alerts
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function calculateFuelStatistics(int $vehicleId, string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                MIN(fuel_level) as min_fuel,
                MAX(fuel_level) as max_fuel,
                AVG(fuel_level) as avg_fuel,
                COUNT(*) as reading_count,
                (MAX(fuel_level) - MIN(fuel_level)) as total_consumed
            FROM fuel_reading_data
            WHERE vehicle_id = ?
            AND timestamp BETWEEN ? AND ?
        ";
        
        $result = $this->database->fetch($sql, [$vehicleId, $startDate, $endDate]);
        
        return $result ?? [
            'min_fuel' => 0,
            'max_fuel' => 0,
            'avg_fuel' => 0,
            'reading_count' => 0,
            'total_consumed' => 0
        ];
    }
}
