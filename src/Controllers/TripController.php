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
                $params[] = strlen($_GET['start_date']) <= 10 ? $_GET['start_date'] . ' 00:00:00' : $_GET['start_date'];
            }
            
            if (!empty($_GET['end_date'])) {
                $sql .= " AND t.start_time <= ?";
                $endDate = $_GET['end_date'];
                $params[] = strlen($endDate) <= 10 ? $endDate . ' 23:59:59' : $endDate;
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
            
            // Get statistics with same filters (pass explicit filter values, not flat params)
            $filters = [
                'vehicle_id' => $_GET['vehicle_id'] ?? null,
                'start_date' => $_GET['start_date'] ?? null,
                'end_date' => $_GET['end_date'] ?? null,
                'material_type' => $_GET['material_type'] ?? null,
                'status' => $_GET['status'] ?? null,
            ];
            $stats = $this->getTripStatistics($filters);
            
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
    
    private function getTripStatistics(array $filters): array
    {
        $whereClause = "WHERE 1=1";
        $statParams = [];
        if (!empty($filters['vehicle_id'])) {
            $whereClause .= " AND vehicle_id = ?";
            $statParams[] = $filters['vehicle_id'];
        }
        if (!empty($filters['start_date'])) {
            $whereClause .= " AND start_time >= ?";
            $statParams[] = strlen($filters['start_date']) <= 10 ? $filters['start_date'] . ' 00:00:00' : $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $whereClause .= " AND start_time <= ?";
            $endDate = $filters['end_date'];
            $statParams[] = strlen($endDate) <= 10 ? $endDate . ' 23:59:59' : $endDate;
        }
        if (!empty($filters['material_type'])) {
            $whereClause .= " AND material_type = ?";
            $statParams[] = $filters['material_type'];
        }
        if (!empty($filters['status'])) {
            $whereClause .= " AND status = ?";
            $statParams[] = $filters['status'];
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
