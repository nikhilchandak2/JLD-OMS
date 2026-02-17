<?php

namespace App\Services;

use App\Core\Database;

class GeofenceService
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    /**
     * Check if vehicle entered/exited any geofences
     */
    public function checkGeofenceEvents(int $vehicleId, $trackingData): array
    {
        $events = [];
        
        // Get all active geofences
        $geofences = $this->getActiveGeofences();
        
        foreach ($geofences as $geofence) {
            $isInside = $this->isPointInGeofence(
                $trackingData->latitude,
                $trackingData->longitude,
                $geofence['latitude'],
                $geofence['longitude'],
                $geofence['radius_meters']
            );
            
            // Check previous position
            $previousTracking = $this->getPreviousTracking($vehicleId);
            
            if ($previousTracking) {
                $wasInside = $this->isPointInGeofence(
                    $previousTracking->latitude,
                    $previousTracking->longitude,
                    $geofence['latitude'],
                    $geofence['longitude'],
                    $geofence['radius_meters']
                );
                
                // Entry event
                if (!$wasInside && $isInside) {
                    $this->recordGeofenceEvent($vehicleId, $geofence['id'], 'entry', 
                        $trackingData->latitude, $trackingData->longitude, $trackingData->timestamp);
                    $events[] = [
                        'geofence_id' => $geofence['id'],
                        'event_type' => 'entry',
                        'geofence_name' => $geofence['name']
                    ];
                }
                
                // Exit event
                if ($wasInside && !$isInside) {
                    $this->recordGeofenceEvent($vehicleId, $geofence['id'], 'exit',
                        $trackingData->latitude, $trackingData->longitude, $trackingData->timestamp);
                    $events[] = [
                        'geofence_id' => $geofence['id'],
                        'event_type' => 'exit',
                        'geofence_name' => $geofence['name']
                    ];
                }
            } else {
                // First tracking data - check if inside
                if ($isInside) {
                    $this->recordGeofenceEvent($vehicleId, $geofence['id'], 'entry',
                        $trackingData->latitude, $trackingData->longitude, $trackingData->timestamp);
                    $events[] = [
                        'geofence_id' => $geofence['id'],
                        'event_type' => 'entry',
                        'geofence_name' => $geofence['name']
                    ];
                }
            }
        }
        
        return $events;
    }
    
    /**
     * Check if point is inside geofence (circular)
     */
    private function isPointInGeofence(float $lat, float $lon, float $centerLat, float $centerLon, float $radiusMeters): bool
    {
        $distance = $this->calculateDistance($lat, $lon, $centerLat, $centerLon);
        return $distance <= ($radiusMeters / 1000); // Convert meters to km
    }
    
    /**
     * Calculate distance between two points in km
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
        
        return $earthRadius * $c;
    }
    
    /**
     * Get previous tracking data for vehicle
     */
    private function getPreviousTracking(int $vehicleId): ?object
    {
        $sql = "
            SELECT * FROM gps_tracking_data
            WHERE vehicle_id = ?
            ORDER BY timestamp DESC
            LIMIT 1 OFFSET 1
        ";
        
        $result = $this->database->fetch($sql, [$vehicleId]);
        
        if (!$result) {
            return null;
        }
        
        return new \App\Models\GPSTrackingData($result);
    }
    
    /**
     * Record geofence event
     */
    private function recordGeofenceEvent(int $vehicleId, int $geofenceId, string $eventType,
                                         float $lat, float $lon, string $timestamp): void
    {
        $sql = "
            INSERT INTO geofence_events 
            (vehicle_id, geofence_id, event_type, latitude, longitude, timestamp)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $vehicleId,
            $geofenceId,
            $eventType,
            $lat,
            $lon,
            $timestamp
        ]);
    }
    
    /**
     * Get all active geofences
     */
    public function getActiveGeofences(): array
    {
        $sql = "
            SELECT * FROM geofences
            WHERE is_active = 1
            ORDER BY name ASC
        ";
        
        return $this->database->fetchAll($sql);
    }
    
    /**
     * Get geofence by ID
     */
    public function getGeofenceById(int $id): ?array
    {
        $sql = "SELECT * FROM geofences WHERE id = ?";
        return $this->database->fetch($sql, [$id]);
    }
    
    /**
     * Create geofence
     */
    public function createGeofence(array $data): int
    {
        $sql = "
            INSERT INTO geofences 
            (name, geofence_type, material_type, latitude, longitude, radius_meters, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $data['name'],
            $data['geofence_type'],
            $data['material_type'] ?? null,
            $data['latitude'],
            $data['longitude'],
            $data['radius_meters'],
            $data['is_active'] ?? 1
        ]);
        
        return (int)$this->database->lastInsertId();
    }
    
    /**
     * Update geofence
     */
    public function updateGeofence(int $id, array $data): bool
    {
        $sql = "
            UPDATE geofences 
            SET name = ?, geofence_type = ?, material_type = ?, 
                latitude = ?, longitude = ?, radius_meters = ?, is_active = ?
            WHERE id = ?
        ";
        
        return $this->database->execute($sql, [
            $data['name'],
            $data['geofence_type'],
            $data['material_type'] ?? null,
            $data['latitude'],
            $data['longitude'],
            $data['radius_meters'],
            $data['is_active'] ?? 1,
            $id
        ]);
    }
    
    /**
     * Delete geofence
     */
    public function deleteGeofence(int $id): bool
    {
        $sql = "DELETE FROM geofences WHERE id = ?";
        return $this->database->execute($sql, [$id]);
    }
}
