<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Core\Database;
use App\Repositories\VehicleRepository;

class TripController
{
    private AuthService $authService;
    private Database $database;
    private VehicleRepository $vehicleRepository;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->database = new Database();
        $this->vehicleRepository = new VehicleRepository();
    }
    
    /**
     * Get all trips with filters
     * GET /api/trips
     */
    public function index(): void
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
                SELECT t.*,
                       v.vehicle_number,
                       sg.name as source_geofence_name,
                       dg.name as destination_geofence_name,
                       dg.material_type
                FROM vehicle_trips t
                JOIN vehicles v ON t.vehicle_id = v.id
                LEFT JOIN geofences sg ON t.source_geofence_id = sg.id
                LEFT JOIN geofences dg ON t.destination_geofence_id = dg.id
                WHERE 1=1
            ";
            
            $params = [];
            
            if (!empty($_GET['vehicle_id'])) {
                $sql .= " AND t.vehicle_id = ?";
                $params[] = $_GET['vehicle_id'];
            }
            
            if (!empty($_GET['start_date'])) {
                $sql .= " AND t.start_time >= ?";
                $params[] = $_GET['start_date'];
            }
            
            if (!empty($_GET['end_date'])) {
                $sql .= " AND t.start_time <= ?";
                $params[] = $_GET['end_date'];
            }
            
            if (!empty($_GET['material_type'])) {
                $sql .= " AND t.material_type = ?";
                $params[] = $_GET['material_type'];
            }
            
            if (!empty($_GET['status'])) {
                $sql .= " AND t.status = ?";
                $params[] = $_GET['status'];
            }
            
            $sql .= " ORDER BY t.start_time DESC LIMIT 1000";
            
            $trips = $this->database->fetchAll($sql, $params);
            
            // Get statistics
            $stats = $this->getTripStatistics($params);
            
            echo json_encode([
                'success' => true,
                'data' => $trips,
                'statistics' => $stats
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get trips for a specific vehicle
     * GET /api/trips/vehicle/{id}
     */
    public function vehicleTrips(int $id): void
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
            
            $sql = "
                SELECT t.*,
                       sg.name as source_geofence_name,
                       dg.name as destination_geofence_name,
                       dg.material_type
                FROM vehicle_trips t
                LEFT JOIN geofences sg ON t.source_geofence_id = sg.id
                LEFT JOIN geofences dg ON t.destination_geofence_id = dg.id
                WHERE t.vehicle_id = ?
                ORDER BY t.start_time DESC
                LIMIT 500
            ";
            
            $trips = $this->database->fetchAll($sql, [$id]);
            
            // Get vehicle-specific statistics
            $stats = $this->getVehicleTripStatistics($id);
            
            echo json_encode([
                'success' => true,
                'vehicle' => $vehicle->toArray(),
                'data' => $trips,
                'statistics' => $stats
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get trips for a specific stockpile
     * GET /api/trips/stockpile/{id}
     */
    public function stockpileTrips(int $id): void
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
                SELECT t.*,
                       v.vehicle_number,
                       sg.name as source_geofence_name,
                       dg.name as destination_geofence_name,
                       dg.material_type
                FROM vehicle_trips t
                JOIN vehicles v ON t.vehicle_id = v.id
                LEFT JOIN geofences sg ON t.source_geofence_id = sg.id
                LEFT JOIN geofences dg ON t.destination_geofence_id = dg.id
                WHERE t.destination_geofence_id = ?
                AND t.status = 'completed'
                ORDER BY t.start_time DESC
                LIMIT 1000
            ";
            
            $trips = $this->database->fetchAll($sql, [$id]);
            
            // Get stockpile statistics
            $stats = $this->getStockpileStatistics($id);
            
            echo json_encode([
                'success' => true,
                'data' => $trips,
                'statistics' => $stats
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function getTripStatistics(array $params): array
    {
        $whereClause = "WHERE 1=1";
        $statParams = [];
        
        if (!empty($params[0])) {
            $whereClause .= " AND vehicle_id = ?";
            $statParams[] = $params[0];
        }
        
        $sql = "
            SELECT 
                COUNT(*) as total_trips,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_trips,
                SUM(distance_km) as total_distance,
                SUM(fuel_consumed_liters) as total_fuel_consumed,
                AVG(duration_minutes) as avg_duration
            FROM vehicle_trips
            {$whereClause}
        ";
        
        return $this->database->fetch($sql, $statParams) ?? [];
    }
    
    private function getVehicleTripStatistics(int $vehicleId): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_trips,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_trips,
                SUM(distance_km) as total_distance,
                SUM(fuel_consumed_liters) as total_fuel_consumed,
                AVG(duration_minutes) as avg_duration,
                AVG(fuel_consumed_liters) as avg_fuel_per_trip
            FROM vehicle_trips
            WHERE vehicle_id = ?
        ";
        
        return $this->database->fetch($sql, [$vehicleId]) ?? [];
    }
    
    private function getStockpileStatistics(int $stockpileId): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_trips,
                SUM(fuel_consumed_liters) as total_fuel_consumed,
                AVG(duration_minutes) as avg_duration,
                COUNT(DISTINCT vehicle_id) as unique_vehicles
            FROM vehicle_trips
            WHERE destination_geofence_id = ?
            AND status = 'completed'
        ";
        
        return $this->database->fetch($sql, [$stockpileId]) ?? [];
    }
}
