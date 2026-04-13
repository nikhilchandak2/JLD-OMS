<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\VehicleRepository;
use App\Repositories\GPSTrackingRepository;
use App\Repositories\TripStoppageRepository;
use App\Services\GeofenceService;

class TripDetectionService
{
    private const STOPPAGE_SPEED_KMH = 5.0;
    private const MIN_STOPPAGE_MINUTES = 2.0;

    private Database $database;
    private VehicleRepository $vehicleRepository;
    private GPSTrackingRepository $gpsTrackingRepository;
    private TripStoppageRepository $tripStoppageRepository;
    private GeofenceService $geofenceService;

    public function __construct()
    {
        $this->database = new Database();
        $this->vehicleRepository = new VehicleRepository();
        $this->gpsTrackingRepository = new GPSTrackingRepository();
        $this->tripStoppageRepository = new TripStoppageRepository();
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
            return;
        }

        if (!$this->isDestinationGeofence($geofence)) {
            return;
        }

        $activeTrip = $this->getActiveTrip($vehicleId);
        if (!$activeTrip) {
            return;
        }

        if ($eventType === 'entry') {
            // Store the destination geofence on entry, trip completes on exit.
            $this->markDestinationEntry((int)$activeTrip['id'], $geofenceId);
        } elseif ($eventType === 'exit' && (int)($activeTrip['destination_geofence_id'] ?? 0) === $geofenceId) {
            // Complete only when exiting the same destination geofence.
            $this->completeTrip($activeTrip, $geofenceId, $trackingData);
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
     * Complete a trip when vehicle exits the selected destination geofence.
     */
    private function completeTrip(array $activeTrip, int $destinationGeofenceId, $trackingData): void
    {
        $vehicleId = (int)$activeTrip['vehicle_id'];

        // Get geofence details for material type
        $geofence = $this->geofenceService->getGeofenceById($destinationGeofenceId);
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
            $destinationGeofenceId,
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

        // Analyze stoppages (when stopped, for how long, count) and save
        $stoppageSummary = $this->analyzeAndSaveStoppages(
            (int)$activeTrip['id'],
            $vehicleId,
            $activeTrip['start_time'],
            $trackingData->timestamp
        );
        if ($stoppageSummary['count'] > 0) {
            $updateSql = "
                UPDATE vehicle_trips
                SET stoppage_count = ?, total_stoppage_minutes = ?
                WHERE id = ?
            ";
            $this->database->execute($updateSql, [
                $stoppageSummary['count'],
                $stoppageSummary['total_minutes'],
                $activeTrip['id']
            ]);
        }
    }

    /**
     * Persist destination geofence when vehicle enters a valid destination.
     */
    private function markDestinationEntry(int $tripId, int $destinationGeofenceId): void
    {
        $sql = "
            UPDATE vehicle_trips
            SET destination_geofence_id = ?
            WHERE id = ? AND status = 'in_progress'
        ";
        $this->database->execute($sql, [$destinationGeofenceId, $tripId]);
    }

    /**
     * Destination geofences are every geofence except pits.
     */
    private function isDestinationGeofence(array $geofence): bool
    {
        $type = strtolower((string)($geofence['geofence_type'] ?? ''));
        return in_array($type, ['stockpile', 'other', 'others', 'parking'], true);
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

    /**
     * Analyze GPS points between trip start and end to detect stoppages (vehicle stopped for min duration).
     * Saves stoppages to trip_stoppages and returns count and total minutes.
     */
    private function analyzeAndSaveStoppages(int $tripId, int $vehicleId, string $startTime, string $endTime): array
    {
        $points = $this->gpsTrackingRepository->getTrackingBetween($vehicleId, $startTime, $endTime);
        if (empty($points)) {
            return ['count' => 0, 'total_minutes' => 0.0];
        }

        $stoppages = [];
        $i = 0;
        while ($i < count($points)) {
            if (!$this->isStopped($points[$i])) {
                $i++;
                continue;
            }
            $segStart = $points[$i]->timestamp;
            $segLat = $points[$i]->latitude;
            $segLon = $points[$i]->longitude;
            while ($i < count($points) && $this->isStopped($points[$i])) {
                $i++;
            }
            $segEnd = $i > 0 ? $points[$i - 1]->timestamp : $segStart;
            $durationMinutes = $this->durationMinutes($segStart, $segEnd);
            if ($durationMinutes >= self::MIN_STOPPAGE_MINUTES) {
                $stoppages[] = [
                    'start_time' => $segStart,
                    'end_time' => $segEnd,
                    'duration_minutes' => round($durationMinutes, 2),
                    'latitude' => $segLat,
                    'longitude' => $segLon
                ];
            }
        }

        $totalMinutes = 0.0;
        foreach ($stoppages as $s) {
            $this->tripStoppageRepository->insert(
                $tripId,
                $s['start_time'],
                $s['end_time'],
                $s['duration_minutes'],
                $s['latitude'],
                $s['longitude']
            );
            $totalMinutes += $s['duration_minutes'];
        }

        return [
            'count' => count($stoppages),
            'total_minutes' => round($totalMinutes, 2)
        ];
    }

    private function isStopped($point): bool
    {
        if ($point->speed !== null) {
            return $point->speed < self::STOPPAGE_SPEED_KMH;
        }
        return strtolower((string)$point->movementStatus) === 'stationary';
    }

    private function durationMinutes(string $startTime, string $endTime): float
    {
        $start = new \DateTime($startTime);
        $end = new \DateTime($endTime);
        return ($end->getTimestamp() - $start->getTimestamp()) / 60.0;
    }
}
