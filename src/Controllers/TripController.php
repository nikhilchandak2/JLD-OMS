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
            $destinationBreakdown = $this->getDestinationBreakdown($filters);
            $destinationBreakdownByVehicle = $this->getDestinationBreakdownByVehicle($filters);
            $stoppageSummaryByVehicle = $this->getStoppageSummaryByVehicle($filters);
            $todayStoppageSummary = $this->getTodayStoppageSummary();
            
            echo json_encode([
                'success' => true,
                'data' => $trips,
                'statistics' => $stats,
                'destination_breakdown' => $destinationBreakdown,
                'destination_breakdown_by_vehicle' => $destinationBreakdownByVehicle,
                'stoppage_summary_by_vehicle' => $stoppageSummaryByVehicle,
                'today_stoppage_summary' => $todayStoppageSummary
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
            $destinationBreakdown = $this->getDestinationBreakdown([
                'vehicle_id' => $id
            ]);
            
            echo json_encode([
                'success' => true,
                'vehicle' => $vehicle->toArray(),
                'data' => $trips,
                'statistics' => $stats,
                'destination_breakdown' => $destinationBreakdown
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Date-wise stoppage timeline for a vehicle.
     * GET /api/trips/vehicle/{id}/stoppage-timeline?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     */
    public function vehicleStoppageTimeline(int $id): void
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

            $startDateInput = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $endDateInput = $_GET['end_date'] ?? date('Y-m-d');

            $startDate = strlen($startDateInput) <= 10 ? $startDateInput . ' 00:00:00' : $startDateInput;
            $endDate = strlen($endDateInput) <= 10 ? $endDateInput . ' 23:59:59' : $endDateInput;

            $sql = "
                SELECT
                    DATE(ts.start_time) AS stop_date,
                    COUNT(*) AS stop_count,
                    COALESCE(SUM(ts.duration_minutes), 0) AS total_stoppage_minutes
                FROM trip_stoppages ts
                JOIN vehicle_trips t ON ts.trip_id = t.id
                WHERE t.vehicle_id = ?
                  AND ts.start_time BETWEEN ? AND ?
                GROUP BY DATE(ts.start_time)
                ORDER BY stop_date ASC
            ";

            $rows = $this->database->fetchAll($sql, [$id, $startDate, $endDate]);

            echo json_encode([
                'success' => true,
                'vehicle' => $vehicle->toArray(),
                'data' => $rows
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

    /**
     * Get detailed stoppages for a trip (start/end/duration/location).
     * GET /api/trips/{id}/stoppages
     */
    public function tripStoppages(int $id): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        try {
            $tripSql = "
                SELECT t.id, t.vehicle_id, v.vehicle_number, t.start_time, t.end_time, t.stoppage_count, t.total_stoppage_minutes
                FROM vehicle_trips t
                JOIN vehicles v ON t.vehicle_id = v.id
                WHERE t.id = ?
                LIMIT 1
            ";
            $trip = $this->database->fetch($tripSql, [$id]);
            if (!$trip) {
                http_response_code(404);
                echo json_encode(['error' => 'Trip not found']);
                return;
            }

            $stoppageSql = "
                SELECT id, trip_id, start_time, end_time, duration_minutes, latitude, longitude
                FROM trip_stoppages
                WHERE trip_id = ?
                ORDER BY start_time ASC
            ";
            $stoppages = $this->database->fetchAll($stoppageSql, [$id]);

            echo json_encode([
                'success' => true,
                'trip' => $trip,
                'data' => $stoppages
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function getTripStatistics(array $filters): array
    {
        [$whereClause, $statParams] = $this->buildTripFilterClause($filters);
        $sql = "
            SELECT 
                COUNT(*) as total_trips,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_trips,
                SUM(distance_km) as total_distance,
                SUM(fuel_consumed_liters) as total_fuel_consumed,
                AVG(duration_minutes) as avg_duration
            FROM vehicle_trips t
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

    private function getDestinationBreakdown(array $filters): array
    {
        [$whereClause, $params] = $this->buildTripFilterClause($filters);

        $whereClause .= " AND t.status = 'completed' AND t.destination_geofence_id IS NOT NULL";
        $sql = "
            SELECT
                t.destination_geofence_id,
                COALESCE(dg.name, 'Unassigned') AS destination_name,
                COUNT(*) AS trip_count
            FROM vehicle_trips t
            LEFT JOIN geofences dg ON t.destination_geofence_id = dg.id
            {$whereClause}
            GROUP BY t.destination_geofence_id, dg.name
            ORDER BY trip_count DESC, destination_name ASC
        ";

        return $this->database->fetchAll($sql, $params);
    }

    private function getDestinationBreakdownByVehicle(array $filters): array
    {
        [$whereClause, $params] = $this->buildTripFilterClause($filters);

        $whereClause .= " AND t.status = 'completed' AND t.destination_geofence_id IS NOT NULL";
        $sql = "
            SELECT
                t.vehicle_id,
                t.destination_geofence_id,
                COALESCE(dg.name, 'Unassigned') AS destination_name,
                COUNT(*) AS trip_count
            FROM vehicle_trips t
            LEFT JOIN geofences dg ON t.destination_geofence_id = dg.id
            {$whereClause}
            GROUP BY t.vehicle_id, t.destination_geofence_id, dg.name
            ORDER BY t.vehicle_id ASC, trip_count DESC, destination_name ASC
        ";

        $rows = $this->database->fetchAll($sql, $params);
        $grouped = [];
        foreach ($rows as $row) {
            $vehicleId = (int)$row['vehicle_id'];
            if (!isset($grouped[$vehicleId])) {
                $grouped[$vehicleId] = [];
            }
            $grouped[$vehicleId][] = [
                'destination_geofence_id' => $row['destination_geofence_id'],
                'destination_name' => $row['destination_name'],
                'trip_count' => $row['trip_count'],
            ];
        }

        return $grouped;
    }

    private function buildTripFilterClause(array $filters): array
    {
        $whereClause = "WHERE 1=1";
        $params = [];

        if (!empty($filters['vehicle_id'])) {
            $whereClause .= " AND t.vehicle_id = ?";
            $params[] = $filters['vehicle_id'];
        }
        if (!empty($filters['start_date'])) {
            $whereClause .= " AND t.start_time >= ?";
            $params[] = strlen($filters['start_date']) <= 10 ? $filters['start_date'] . ' 00:00:00' : $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $whereClause .= " AND t.start_time <= ?";
            $endDate = $filters['end_date'];
            $params[] = strlen($endDate) <= 10 ? $endDate . ' 23:59:59' : $endDate;
        }
        if (!empty($filters['material_type'])) {
            $whereClause .= " AND t.material_type = ?";
            $params[] = $filters['material_type'];
        }
        if (!empty($filters['status'])) {
            $whereClause .= " AND t.status = ?";
            $params[] = $filters['status'];
        }

        return [$whereClause, $params];
    }

    /**
     * Aggregate stoppage metrics by vehicle for current filter range.
     */
    private function getStoppageSummaryByVehicle(array $filters): array
    {
        [$whereClause, $params] = $this->buildTripFilterClause($filters);
        $sql = "
            SELECT
                t.vehicle_id,
                v.vehicle_number,
                COALESCE(SUM(COALESCE(t.stoppage_count, 0)), 0) AS total_stops,
                COALESCE(SUM(COALESCE(t.total_stoppage_minutes, 0)), 0) AS total_stoppage_minutes,
                COUNT(*) AS trip_count
            FROM vehicle_trips t
            JOIN vehicles v ON t.vehicle_id = v.id
            {$whereClause}
            GROUP BY t.vehicle_id, v.vehicle_number
            ORDER BY total_stoppage_minutes DESC, total_stops DESC, v.vehicle_number ASC
        ";

        return $this->database->fetchAll($sql, $params);
    }

    /**
     * Today's global stoppage totals (all vehicles).
     */
    private function getTodayStoppageSummary(): array
    {
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 23:59:59');
        $sql = "
            SELECT
                COALESCE(SUM(COALESCE(stoppage_count, 0)), 0) AS total_stops,
                COALESCE(SUM(COALESCE(total_stoppage_minutes, 0)), 0) AS total_stoppage_minutes,
                COUNT(*) AS trip_count,
                COUNT(DISTINCT vehicle_id) AS vehicle_count
            FROM vehicle_trips
            WHERE start_time BETWEEN ? AND ?
        ";

        return $this->database->fetch($sql, [$start, $end]) ?? [
            'total_stops' => 0,
            'total_stoppage_minutes' => 0,
            'trip_count' => 0,
            'vehicle_count' => 0,
        ];
    }
}
