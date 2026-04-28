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

        usort($geofenceEvents, function (array $a, array $b): int {
            return $this->eventPriority($a) <=> $this->eventPriority($b);
        });
        
        // Process geofence events to detect trips
        foreach ($geofenceEvents as $event) {
            $this->processGeofenceEvent($vehicleId, $event, $trackingData);
        }

        // Fallback for sparse vendor points:
        // if boundary crossing was missed, infer state from current containing geofences.
        $this->inferTripStateFromCurrentPosition($vehicleId, $trackingData);
    }

    /**
     * Infer trip transitions using current containing geofences when entry/exit events are not emitted.
     */
    private function inferTripStateFromCurrentPosition(int $vehicleId, $trackingData): void
    {
        $containingGeofences = $this->geofenceService->getContainingGeofences(
            (float)$trackingData->latitude,
            (float)$trackingData->longitude
        );
        $activeTrip = $this->getActiveTrip($vehicleId);
        $pitGeofenceId = $this->findPitGeofenceId($containingGeofences);
        $destinationGeofenceId = $this->findDestinationGeofenceId($containingGeofences);
        $isInPit = $pitGeofenceId !== null;

        // If vehicle is currently in a pit and no active trip exists, start one.
        if (!$activeTrip && $isInPit) {
            $this->startTrip($vehicleId, $pitGeofenceId, $trackingData);
            return;
        }

        // Complete only when vehicle is inside any non-pit geofence (stockpile/other area).
        if ($activeTrip && $destinationGeofenceId !== null) {
            $this->completeTrip($activeTrip, $destinationGeofenceId, $trackingData);
        }
    }

    private function findPitGeofenceId(array $geofences): ?int
    {
        foreach ($geofences as $geofence) {
            if ($this->isPitGeofence($geofence)) {
                return (int)$geofence['id'];
            }
        }
        return null;
    }

    private function findDestinationGeofenceId(array $geofences): ?int
    {
        foreach ($geofences as $geofence) {
            if ($this->isStockpileGeofence($geofence)) {
                return (int)$geofence['id'];
            }
        }
        return null;
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
        $replayStats = $this->replayTripsFromHistoricalPoints($vehicleId, $trackingPoints);

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
            'tracking_points_processed' => (int)($replayStats['tracking_points_processed'] ?? 0),
            'deleted' => $deleted,
            'diagnostics' => $replayStats,
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
    private function replayTripsFromHistoricalPoints(int $vehicleId, array $trackingPoints): array
    {
        if (empty($trackingPoints)) {
            return [
                'tracking_points_processed' => 0,
                'geofence_events_generated' => 0,
                'pit_entries' => 0,
                'pit_exits' => 0,
                'destination_entries' => 0,
            ];
        }

        $activeGeofences = $this->geofenceService->getActiveGeofences();
        $destinationGeofenceCount = 0;
        foreach ($activeGeofences as $geofence) {
            if ($this->isStockpileGeofence($geofence)) {
                $destinationGeofenceCount++;
            }
        }

        $previousInside = $this->getInsideGeofencesForPointBeforeRange($vehicleId, (string)$trackingPoints[0]->timestamp, $activeGeofences);
        $processed = 0;
        $generatedEvents = 0;
        $pitEntries = 0;
        $pitExits = 0;
        $destinationEntries = 0;

        foreach ($trackingPoints as $point) {
            $currentInside = [];
            foreach ($activeGeofences as $geofence) {
                if ($this->geofenceService->containsPoint((float)$point->latitude, (float)$point->longitude, $geofence)) {
                    $currentInside[(int)$geofence['id']] = $geofence;
                }
            }

            $entryIds = array_values(array_diff(array_keys($currentInside), array_keys($previousInside)));
            $exitIds = array_values(array_diff(array_keys($previousInside), array_keys($currentInside)));

            usort($entryIds, function (int $a, int $b) use ($currentInside): int {
                return $this->eventPriority(
                    ['geofence_id' => $a, 'event_type' => 'entry'],
                    $currentInside[$a] ?? null
                ) <=> $this->eventPriority(
                    ['geofence_id' => $b, 'event_type' => 'entry'],
                    $currentInside[$b] ?? null
                );
            });

            foreach ($entryIds as $geofenceId) {
                $geofence = $currentInside[(int)$geofenceId] ?? $this->geofenceService->getGeofenceById((int)$geofenceId);
                $this->recordGeofenceEvent($vehicleId, (int)$geofenceId, 'entry', (float)$point->latitude, (float)$point->longitude, $point->timestamp);
                $this->processGeofenceEvent($vehicleId, ['geofence_id' => (int)$geofenceId, 'event_type' => 'entry'], $point);
                $generatedEvents++;
                if (is_array($geofence) && $this->isPitGeofence($geofence)) {
                    $pitEntries++;
                } elseif (is_array($geofence) && $this->isStockpileGeofence($geofence)) {
                    $destinationEntries++;
                }
            }
            foreach ($exitIds as $geofenceId) {
                $geofence = $previousInside[(int)$geofenceId] ?? $this->geofenceService->getGeofenceById((int)$geofenceId);
                $this->recordGeofenceEvent($vehicleId, (int)$geofenceId, 'exit', (float)$point->latitude, (float)$point->longitude, $point->timestamp);
                $this->processGeofenceEvent($vehicleId, ['geofence_id' => (int)$geofenceId, 'event_type' => 'exit'], $point);
                $generatedEvents++;
                if (is_array($geofence) && $this->isPitGeofence($geofence)) {
                    $pitExits++;
                }
            }

            $previousInside = $currentInside;
            $processed++;
        }

        return [
            'tracking_points_processed' => $processed,
            'geofence_events_generated' => $generatedEvents,
            'pit_entries' => $pitEntries,
            'pit_exits' => $pitExits,
            'destination_entries' => $destinationEntries,
            'active_geofences' => count($activeGeofences),
            'destination_geofences' => $destinationGeofenceCount,
        ];
    }

    private function eventPriority(array $event, ?array $knownGeofence = null): int
    {
        $eventType = strtolower((string)($event['event_type'] ?? ''));
        $geofence = $knownGeofence;
        if (!$geofence && isset($event['geofence_id'])) {
            $geofence = $this->geofenceService->getGeofenceById((int)$event['geofence_id']);
        }
        $isPit = is_array($geofence) && $this->isPitGeofence($geofence);

        // Ensure pit entry starts trip before any destination entry at same point.
        if ($eventType === 'entry' && $isPit) {
            return 0;
        }
        if ($eventType === 'entry') {
            return 1;
        }
        if ($eventType === 'exit' && $isPit) {
            return 2;
        }
        return 3;
    }

    /**
     * Build initial inside-state from the GPS point immediately before replay range.
     */
    private function getInsideGeofencesForPointBeforeRange(int $vehicleId, string $rangeStartTime, array $activeGeofences): array
    {
        $sql = "
            SELECT latitude, longitude
            FROM gps_tracking_data
            WHERE vehicle_id = ?
              AND timestamp < ?
            ORDER BY timestamp DESC, id DESC
            LIMIT 1
        ";
        $row = $this->database->fetch($sql, [$vehicleId, $rangeStartTime]);
        if (!$row) {
            return [];
        }

        $latitude = isset($row['latitude']) ? (float)$row['latitude'] : null;
        $longitude = isset($row['longitude']) ? (float)$row['longitude'] : null;
        if ($latitude === null || $longitude === null) {
            return [];
        }

        $inside = [];
        foreach ($activeGeofences as $geofence) {
            if ($this->geofenceService->containsPoint($latitude, $longitude, $geofence)) {
                $inside[(int)$geofence['id']] = $geofence;
            }
        }
        return $inside;
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
            // Daily rule: trip starts on pit entry only when no trip is currently active.
            if (!$this->hasActiveTripForDate($vehicleId, (string)$trackingData->timestamp)) {
                $this->startTrip($vehicleId, $geofenceId, $trackingData);
            }
            return;
        }

        // Trip ends only when vehicle enters any non-pit geofence and a trip is active.
        if ($eventType !== 'entry' || !$this->isStockpileGeofence($geofence)) {
            return;
        }

        $activeTrip = $this->getActiveTrip($vehicleId);
        if (!$activeTrip) {
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
     * Destination is any non-pit geofence.
     * Some deployments classify stockyards as parking/other/custom labels.
     */
    private function isStockpileGeofence(array $geofence): bool
    {
        $type = $this->normalizeGeofenceType($geofence);
        return in_array($type, ['stockpile', 'other'], true);
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

    private function hasActiveTripForDate(int $vehicleId, string $timestamp): bool
    {
        try {
            $date = (new \DateTime($timestamp))->format('Y-m-d');
        } catch (\Exception $e) {
            $date = date('Y-m-d');
        }
        $sql = "
            SELECT id
            FROM vehicle_trips
            WHERE vehicle_id = ?
              AND status = 'in_progress'
              AND DATE(start_time) = ?
            LIMIT 1
        ";
        return $this->database->fetch($sql, [$vehicleId, $date]) !== null;
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
