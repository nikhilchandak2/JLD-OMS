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

        // Fallback reconciliation: close stale in-progress trip if vehicle is already inside stockpile
        // and we can confirm pit exit happened (covers missed/late entry events).
        $this->reconcileTripCompletionFromCurrentPosition($vehicleId, $trackingData);
    }

    /**
     * Rebuild geofence events and trips for a vehicle using stored GPS points in a time range.
     */
    public function rebuildTripsFromTracking(int $vehicleId, string $startTime, string $endTime): array
    {
        $start = new \DateTime($startTime);
        $end = new \DateTime($endTime);
        if ($end < $start) {
            throw new \InvalidArgumentException('End time must be greater than or equal to start time');
        }

        $startTime = $start->format('Y-m-d H:i:s');
        $endTime = $end->format('Y-m-d H:i:s');

        $deleted = $this->clearTripAndGeofenceDataForRange($vehicleId, $startTime, $endTime);

        $trackingPoints = $this->gpsTrackingRepository->getTrackingBetween($vehicleId, $startTime, $endTime, 20000);
        $processed = $this->replayTripsFromHistoricalPoints($vehicleId, $trackingPoints);

        $summarySql = "
            SELECT
                COUNT(*) AS total_trips,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_trips,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_trips,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_trips
            FROM vehicle_trips
            WHERE vehicle_id = ?
              AND start_time BETWEEN ? AND ?
        ";
        $summary = $this->database->fetch($summarySql, [$vehicleId, $startTime, $endTime]) ?? [];

        return [
            'vehicle_id' => $vehicleId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'tracking_points_processed' => $processed,
            'deleted' => $deleted,
            'summary' => [
                'total_trips' => (int)($summary['total_trips'] ?? 0),
                'completed_trips' => (int)($summary['completed_trips'] ?? 0),
                'in_progress_trips' => (int)($summary['in_progress_trips'] ?? 0),
                'cancelled_trips' => (int)($summary['cancelled_trips'] ?? 0),
            ]
        ];
    }

    /**
     * Recompute geofence entry/exit transitions from chronological points (for rebuild use only).
     */
    private function replayTripsFromHistoricalPoints(int $vehicleId, array $trackingPoints): int
    {
        if (empty($trackingPoints)) {
            return 0;
        }

        $activeGeofences = $this->geofenceService->getActiveGeofences();
        $previousInside = [];
        $processed = 0;

        foreach ($trackingPoints as $point) {
            $currentInside = [];
            foreach ($activeGeofences as $geofence) {
                if ($this->geofenceService->containsPoint((float)$point->latitude, (float)$point->longitude, $geofence)) {
                    $currentInside[(int)$geofence['id']] = $geofence;
                }
            }

            // For first point in range, mimic live behavior: treat current inside geofences as entries.
            $entryIds = empty($previousInside)
                ? array_keys($currentInside)
                : array_values(array_diff(array_keys($currentInside), array_keys($previousInside)));
            $exitIds = empty($previousInside)
                ? []
                : array_values(array_diff(array_keys($previousInside), array_keys($currentInside)));

            foreach ($entryIds as $geofenceId) {
                $this->recordGeofenceEvent($vehicleId, (int)$geofenceId, 'entry', (float)$point->latitude, (float)$point->longitude, $point->timestamp);
                $this->processGeofenceEvent($vehicleId, ['geofence_id' => (int)$geofenceId, 'event_type' => 'entry'], $point);
            }
            foreach ($exitIds as $geofenceId) {
                $this->recordGeofenceEvent($vehicleId, (int)$geofenceId, 'exit', (float)$point->latitude, (float)$point->longitude, $point->timestamp);
                $this->processGeofenceEvent($vehicleId, ['geofence_id' => (int)$geofenceId, 'event_type' => 'exit'], $point);
            }

            $this->reconcileTripCompletionFromCurrentPosition($vehicleId, $point);
            $previousInside = $currentInside;
            $processed++;
        }

        return $processed;
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
        
        if ($eventType === 'entry' && $this->isPitGeofence($geofence)) {
            // Vehicle entered pit - start new trip
            $this->startTrip($vehicleId, $geofenceId, $trackingData);
            return;
        }

        $activeTrip = $this->getActiveTrip($vehicleId);
        if (!$activeTrip) {
            return;
        }

        // A trip can complete only when vehicle enters a stockpile geofence.
        if (!$this->isStockpileGeofence($geofence) || $eventType !== 'entry') {
            return;
        }

        // Enforce rule: complete only after the vehicle has exited the source pit.
        if (!$this->hasExitedSourcePitSinceTripStart($activeTrip, $trackingData->timestamp, (float)$trackingData->latitude, (float)$trackingData->longitude)) {
            $this->markDestinationEntry((int)$activeTrip['id'], $geofenceId);
            return;
        }

        $this->completeTrip($activeTrip, $geofenceId, $trackingData);
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
     * Persist destination geofence when vehicle enters stockpile before pit-exit condition is satisfied.
     */
    private function markDestinationEntry(int $tripId, int $destinationGeofenceId): void
    {
        $sql = "
            UPDATE vehicle_trips
            SET destination_geofence_id = ?
            WHERE id = ? AND status = 'in_progress' AND destination_geofence_id IS NULL
        ";
        $this->database->execute($sql, [$destinationGeofenceId, $tripId]);
    }

    /**
     * Completed trips are based on stockpile entry only.
     */
    private function isStockpileGeofence(array $geofence): bool
    {
        $type = $this->normalizeGeofenceType($geofence);
        // Some sites mark stockpile destinations as "other"/"others".
        return in_array($type, ['stockpile', 'stock_pile', 'stock pile', 'other', 'others'], true);
    }

    private function isPitGeofence(array $geofence): bool
    {
        $type = $this->normalizeGeofenceType($geofence);
        return $type === 'pit';
    }

    private function normalizeGeofenceType(array $geofence): string
    {
        return strtolower(trim((string)($geofence['geofence_type'] ?? '')));
    }

    /**
     * Use current GPS point to complete an in-progress trip when stockpile entry event was missed.
     */
    private function reconcileTripCompletionFromCurrentPosition(int $vehicleId, $trackingData): void
    {
        $activeTrip = $this->getActiveTrip($vehicleId);
        if (!$activeTrip) {
            return;
        }

        if (!$this->hasExitedSourcePitSinceTripStart($activeTrip, $trackingData->timestamp, (float)$trackingData->latitude, (float)$trackingData->longitude)) {
            return;
        }

        $stockpile = $this->resolveCurrentStockpileGeofence(
            (float)$trackingData->latitude,
            (float)$trackingData->longitude,
            (int)$activeTrip['destination_geofence_id']
        );
        if (!$stockpile) {
            return;
        }

        $this->completeTrip($activeTrip, (int)$stockpile['id'], $trackingData);
    }

    private function resolveCurrentStockpileGeofence(float $latitude, float $longitude, int $preferredGeofenceId = 0): ?array
    {
        $containingGeofences = $this->geofenceService->getContainingGeofences($latitude, $longitude);
        if (empty($containingGeofences)) {
            return null;
        }

        $stockpiles = array_values(array_filter($containingGeofences, fn(array $geofence) => $this->isStockpileGeofence($geofence)));
        if (empty($stockpiles)) {
            return null;
        }

        if ($preferredGeofenceId > 0) {
            foreach ($stockpiles as $geofence) {
                if ((int)$geofence['id'] === $preferredGeofenceId) {
                    return $geofence;
                }
            }
        }

        return $stockpiles[0];
    }

    /**
     * Confirm source pit exit happened between trip start and current event time.
     */
    private function hasExitedSourcePitSinceTripStart(array $activeTrip, string $eventTimestamp, ?float $currentLatitude = null, ?float $currentLongitude = null): bool
    {
        $sourceGeofenceId = (int)($activeTrip['source_geofence_id'] ?? 0);
        if ($sourceGeofenceId <= 0) {
            return true;
        }

        $sql = "
            SELECT id
            FROM geofence_events
            WHERE vehicle_id = ?
              AND geofence_id = ?
              AND event_type = 'exit'
              AND timestamp >= ?
              AND timestamp <= ?
            ORDER BY id DESC
            LIMIT 1
        ";

        $row = $this->database->fetch($sql, [
            (int)$activeTrip['vehicle_id'],
            $sourceGeofenceId,
            $activeTrip['start_time'],
            $eventTimestamp
        ]);

        if ($row !== null) {
            return true;
        }

        // Fallback for sparse vendor points: if current point is outside pit, infer pit exit happened.
        if ($currentLatitude !== null && $currentLongitude !== null) {
            $sourcePit = $this->geofenceService->getGeofenceById($sourceGeofenceId);
            if (!$sourcePit) {
                return true;
            }
            return !$this->geofenceService->containsPoint($currentLatitude, $currentLongitude, $sourcePit);
        }

        return false;
    }
    
    /**
     * Get active trip for vehicle
     */
    private function getActiveTrip(int $vehicleId): ?array
    {
        $sql = "
            SELECT * FROM vehicle_trips 
            WHERE vehicle_id = ? AND status = 'in_progress'
            ORDER BY id DESC
            LIMIT 1
        ";
        
        return $this->database->fetch($sql, [$vehicleId]);
    }

    private function clearTripAndGeofenceDataForRange(int $vehicleId, string $startTime, string $endTime): array
    {
        $tripIds = $this->database->fetchAll(
            "SELECT id FROM vehicle_trips WHERE vehicle_id = ? AND start_time BETWEEN ? AND ?",
            [$vehicleId, $startTime, $endTime]
        );

        $deletedStoppages = 0;
        foreach ($tripIds as $row) {
            $this->database->execute("DELETE FROM trip_stoppages WHERE trip_id = ?", [(int)$row['id']]);
            $deletedStoppages++;
        }

        $tripCount = count($tripIds);
        $this->database->execute(
            "DELETE FROM vehicle_trips WHERE vehicle_id = ? AND start_time BETWEEN ? AND ?",
            [$vehicleId, $startTime, $endTime]
        );
        $this->database->execute(
            "DELETE FROM geofence_events WHERE vehicle_id = ? AND timestamp BETWEEN ? AND ?",
            [$vehicleId, $startTime, $endTime]
        );

        return [
            'vehicle_trips' => $tripCount,
            'trip_stoppage_groups' => $deletedStoppages
        ];
    }

    private function recordGeofenceEvent(int $vehicleId, int $geofenceId, string $eventType, float $lat, float $lon, string $timestamp): void
    {
        $sql = "
            INSERT INTO geofence_events
            (vehicle_id, geofence_id, event_type, latitude, longitude, timestamp)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        $this->database->execute($sql, [$vehicleId, $geofenceId, $eventType, $lat, $lon, $timestamp]);
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
