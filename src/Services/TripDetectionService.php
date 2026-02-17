<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\VehicleRepository;
use App\Repositories\GPSTrackingRepository;
use App\Services\GeofenceService;

class TripDetectionService
{
    private Database $database;
    private VehicleRepository $vehicleRepository;
    private GPSTrackingRepository $gpsTrackingRepository;
    private GeofenceService $geofenceService;
    
    public function __construct()
    {
        $this->database = new Database();
        $this->vehicleRepository = new VehicleRepository();
        $this->gpsTrackingRepository = new GPSTrackingRepository();
        $this->geofenceService = new GeofenceService();
    }
    
    /**
     * Process new GPS tracking data and detect trips
     */
    public function processTrackingData(int $vehicleId, $trackingData): void
    {
        // Check if vehicle entered/exited any geofences
        $geofenceEvents = $this->geofenceService->checkGeofenceEvents($vehicleId, $trackingData);
        
        // Process geofence events to detect trips
        foreach ($geofenceEvents as $event) {
            $this->processGeofenceEvent($vehicleId, $event, $trackingData);
        }
    }
    
    /**
     * Process geofence entry/exit events to detect trips
     */
    private function processGeofenceEvent(int $vehicleId, array $event, $trackingData): void
    {
        $geofenceId = $event['geofence_id'];
        $eventType = $event['event_type'];
        
        // Get geofence details
        $geofence = $this->geofenceService->getGeofenceById($geofenceId);
        
        if (!$geofence) {
            return;
        }
        
        if ($eventType === 'entry' && $geofence['geofence_type'] === 'pit') {
            // Vehicle entered pit - start new trip
            $this->startTrip($vehicleId, $geofenceId, $trackingData);
        } elseif ($eventType === 'entry' && $geofence['geofence_type'] === 'stockpile') {
            // Vehicle entered stockpile - complete trip
            $this->completeTrip($vehicleId, $geofenceId, $trackingData);
        }
    }
    
    /**
     * Start a new trip (vehicle entered pit)
     */
    private function startTrip(int $vehicleId, int $pitGeofenceId, $trackingData): void
    {
        // Check if there's an in-progress trip
        $activeTrip = $this->getActiveTrip($vehicleId);
        
        if ($activeTrip) {
            // Cancel previous trip if exists
            $this->cancelTrip($activeTrip['id']);
        }
        
        // Create new trip
        $sql = "
            INSERT INTO vehicle_trips 
            (vehicle_id, trip_type, source_geofence_id, start_time, start_latitude, start_longitude, status)
            VALUES (?, 'pit_to_stockpile', ?, ?, ?, ?, 'in_progress')
        ";
        
        $this->database->execute($sql, [
            $vehicleId,
            $pitGeofenceId,
            $trackingData->timestamp,
            $trackingData->latitude,
            $trackingData->longitude
        ]);
    }
    
    /**
     * Complete a trip (vehicle entered stockpile)
     */
    private function completeTrip(int $vehicleId, int $stockpileGeofenceId, $trackingData): void
    {
        $activeTrip = $this->getActiveTrip($vehicleId);
        
        if (!$activeTrip) {
            return; // No active trip to complete
        }
        
        // Get geofence details for material type
        $geofence = $this->geofenceService->getGeofenceById($stockpileGeofenceId);
        $materialType = $geofence['material_type'] ?? null;
        
        // Calculate distance and duration
        $distance = $this->calculateDistance(
            $activeTrip['start_latitude'],
            $activeTrip['start_longitude'],
            $trackingData->latitude,
            $trackingData->longitude
        );
        
        $duration = $this->calculateDuration($activeTrip['start_time'], $trackingData->timestamp);
        
        // Get fuel consumption for this trip
        $fuelData = $this->getFuelConsumptionForTrip($vehicleId, $activeTrip['start_time'], $trackingData->timestamp);
        
        // Update trip
        $sql = "
            UPDATE vehicle_trips 
            SET destination_geofence_id = ?,
                material_type = ?,
                end_time = ?,
                end_latitude = ?,
                end_longitude = ?,
                distance_km = ?,
                duration_minutes = ?,
                fuel_consumed_liters = ?,
                fuel_start_liters = ?,
                fuel_end_liters = ?,
                status = 'completed'
            WHERE id = ?
        ";
        
        $this->database->execute($sql, [
            $stockpileGeofenceId,
            $materialType,
            $trackingData->timestamp,
            $trackingData->latitude,
            $trackingData->longitude,
            $distance,
            $duration,
            $fuelData['consumed'] ?? null,
            $fuelData['start_fuel'] ?? null,
            $fuelData['end_fuel'] ?? null,
            $activeTrip['id']
        ]);
    }
    
    /**
     * Get active trip for vehicle
     */
    private function getActiveTrip(int $vehicleId): ?array
    {
        $sql = "
            SELECT * FROM vehicle_trips 
            WHERE vehicle_id = ? AND status = 'in_progress'
            ORDER BY start_time DESC
            LIMIT 1
        ";
        
        return $this->database->fetch($sql, [$vehicleId]);
    }
    
    /**
     * Cancel a trip
     */
    private function cancelTrip(int $tripId): void
    {
        $sql = "UPDATE vehicle_trips SET status = 'cancelled' WHERE id = ?";
        $this->database->execute($sql, [$tripId]);
    }
    
    /**
     * Calculate distance between two points (Haversine formula)
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon/2) * sin($dLon/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return round($earthRadius * $c, 2);
    }
    
    /**
     * Calculate duration in minutes
     */
    private function calculateDuration(string $startTime, string $endTime): int
    {
        $start = new \DateTime($startTime);
        $end = new \DateTime($endTime);
        $diff = $start->diff($end);
        
        return ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    }
    
    /**
     * Get fuel consumption for trip
     */
    private function getFuelConsumptionForTrip(int $vehicleId, string $startTime, string $endTime): ?array
    {
        $sql = "
            SELECT 
                MIN(fuel_level) as start_fuel,
                MAX(fuel_level) as end_fuel,
                (MIN(fuel_level) - MAX(fuel_level)) as consumed
            FROM fuel_reading_data
            WHERE vehicle_id = ? 
            AND timestamp BETWEEN ? AND ?
        ";
        
        $result = $this->database->fetch($sql, [$vehicleId, $startTime, $endTime]);
        
        if (!$result || $result['consumed'] === null) {
            return null;
        }
        
        return [
            'start_fuel' => (float)$result['start_fuel'],
            'end_fuel' => (float)$result['end_fuel'],
            'consumed' => (float)$result['consumed']
        ];
    }
}
