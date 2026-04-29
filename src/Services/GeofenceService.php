<?php

namespace App\Services;

use App\Core\Database;

class GeofenceService
{
    private Database $database;

    // Tolerance (meters) to prevent boundary flapping when geofences are very close
    // or when GPS coordinates jitter slightly.
    private const DEFAULT_CIRCLE_EDGE_TOLERANCE_METERS = 3.0;
    private const DEFAULT_POLYGON_EDGE_TOLERANCE_METERS = 3.0;
    
    public function __construct()
    {
        $this->database = new Database();
    }

    private function getCircleEdgeToleranceMeters(): float
    {
        $raw = $_ENV['GEOFENCE_CIRCLE_EDGE_TOLERANCE_METERS'] ?? null;
        if ($raw === null || $raw === '') {
            return self::DEFAULT_CIRCLE_EDGE_TOLERANCE_METERS;
        }
        $val = (float)$raw;
        return $val > 0 ? $val : self::DEFAULT_CIRCLE_EDGE_TOLERANCE_METERS;
    }

    private function getPolygonEdgeToleranceMeters(): float
    {
        $raw = $_ENV['GEOFENCE_POLYGON_EDGE_TOLERANCE_METERS'] ?? null;
        if ($raw === null || $raw === '') {
            return self::DEFAULT_POLYGON_EDGE_TOLERANCE_METERS;
        }
        $val = (float)$raw;
        return $val > 0 ? $val : self::DEFAULT_POLYGON_EDGE_TOLERANCE_METERS;
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
            $isInside = $this->containsPointInGeofence(
                (float)$trackingData->latitude,
                (float)$trackingData->longitude,
                $geofence
            );
            
            // Check previous position
            $previousTracking = $this->getPreviousTracking($vehicleId);
            
            if ($previousTracking) {
                $wasInside = $this->containsPointInGeofence(
                    (float)$previousTracking->latitude,
                    (float)$previousTracking->longitude,
                    $geofence
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
     * Check whether a point lies inside a geofence shape.
     */
    private function containsPointInGeofence(float $lat, float $lon, array $geofence): bool
    {
        $shapeType = $geofence['shape_type'] ?? 'circle';
        if ($shapeType === 'polygon') {
            $polygonPoints = $this->normalizePolygonPoints($geofence['polygon_points'] ?? null);
            if (count($polygonPoints) >= 3) {
                return $this->isPointInPolygon($lat, $lon, $polygonPoints);
            }
        }

        return $this->isPointInCircle(
            $lat,
            $lon,
            (float)($geofence['latitude'] ?? 0),
            (float)($geofence['longitude'] ?? 0),
            (float)($geofence['radius_meters'] ?? 0)
        );
    }
    
    /**
     * Check if point is inside a circular geofence.
     */
    private function isPointInCircle(float $lat, float $lon, float $centerLat, float $centerLon, float $radiusMeters): bool
    {
        if ($radiusMeters <= 0) {
            return false;
        }
        $distance = $this->calculateDistance($lat, $lon, $centerLat, $centerLon);
        $toleranceMeters = $this->getCircleEdgeToleranceMeters();
        // Treat points slightly outside as inside to absorb GPS noise.
        return $distance <= (($radiusMeters + $toleranceMeters) / 1000); // Convert meters to km
    }

    /**
     * Ray-casting point-in-polygon check.
     */
    private function isPointInPolygon(float $lat, float $lon, array $polygonPoints): bool
    {
        $toleranceMeters = $this->getPolygonEdgeToleranceMeters();
        $inside = false;
        $count = count($polygonPoints);
        if ($count < 3) {
            return false;
        }

        // If the point is on/very near any polygon edge, consider it inside.
        // This is critical when geofences are extremely close to each other.
        if ($this->isPointNearPolygonEdge($lat, $lon, $polygonPoints, $toleranceMeters)) {
            return true;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latI = $polygonPoints[$i]['lat'];
            $lonI = $polygonPoints[$i]['lng'];
            $latJ = $polygonPoints[$j]['lat'];
            $lonJ = $polygonPoints[$j]['lng'];

            $intersects = (($latI > $lat) !== ($latJ > $lat))
                && ($lon < (($lonJ - $lonI) * ($lat - $latI) / (($latJ - $latI) ?: 1e-12) + $lonI));
            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    private function isPointNearPolygonEdge(float $lat, float $lon, array $polygonPoints, float $toleranceMeters): bool
    {
        $count = count($polygonPoints);
        if ($count < 2) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latA = $polygonPoints[$j]['lat'];
            $lonA = $polygonPoints[$j]['lng'];
            $latB = $polygonPoints[$i]['lat'];
            $lonB = $polygonPoints[$i]['lng'];

            $distMeters = $this->distancePointToSegmentMeters($lat, $lon, $latA, $lonA, $latB, $lonB);
            if ($distMeters <= $toleranceMeters) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distance from point P to segment AB using a local meters-projection.
     * This is accurate enough for small geofences (tens to hundreds of meters).
     */
    private function distancePointToSegmentMeters(
        float $latP,
        float $lonP,
        float $latA,
        float $lonA,
        float $latB,
        float $lonB
    ): float {
        // Project lat/lon into a local tangent plane in meters around point P.
        // Using P as reference keeps values small and stable.
        $metersPerDegLat = 110540.0; // approx meters per 1 degree latitude
        $cosLat = cos(deg2rad($latP));
        $metersPerDegLon = 111320.0 * $cosLat; // approx meters per 1 degree longitude at this latitude

        // P is (0,0) in this coordinate system.
        $ax = ($lonA - $lonP) * $metersPerDegLon;
        $ay = ($latA - $latP) * $metersPerDegLat;
        $bx = ($lonB - $lonP) * $metersPerDegLon;
        $by = ($latB - $latP) * $metersPerDegLat;

        $abx = $bx - $ax;
        $aby = $by - $ay;
        $abLenSq = $abx * $abx + $aby * $aby;

        // Segment is effectively a point.
        if ($abLenSq <= 1e-12) {
            return sqrt($ax * $ax + $ay * $ay);
        }

        // Closest point on AB to P (P is at origin).
        $apx = -$ax;
        $apy = -$ay;
        $t = ($apx * $abx + $apy * $aby) / $abLenSq;
        $t = max(0.0, min(1.0, $t));

        $nx = $ax + $t * $abx;
        $ny = $ay + $t * $aby;

        return sqrt($nx * $nx + $ny * $ny);
    }

    /**
     * Calculate distance between two points in km.
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
     * Normalize polygon points from JSON or array input.
     */
    private function normalizePolygonPoints($polygonPoints): array
    {
        if (is_string($polygonPoints)) {
            $decoded = json_decode($polygonPoints, true);
            $polygonPoints = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($polygonPoints)) {
            return [];
        }

        $normalized = [];
        foreach ($polygonPoints as $point) {
            if (!is_array($point) || !isset($point['lat']) || !isset($point['lng'])) {
                continue;
            }
            $lat = (float)$point['lat'];
            $lng = (float)$point['lng'];
            if (!is_finite($lat) || !is_finite($lng)) {
                continue;
            }
            $normalized[] = ['lat' => $lat, 'lng' => $lng];
        }

        return $normalized;
    }

    /**
     * Calculate centroid and max radius from polygon points.
     */
    private function deriveCircleFromPolygon(array $polygonPoints): array
    {
        $count = count($polygonPoints);
        if ($count < 3) {
            return ['latitude' => 0.0, 'longitude' => 0.0, 'radius_meters' => 0.0];
        }

        $sumLat = 0.0;
        $sumLng = 0.0;
        foreach ($polygonPoints as $point) {
            $sumLat += $point['lat'];
            $sumLng += $point['lng'];
        }
        $centerLat = $sumLat / $count;
        $centerLng = $sumLng / $count;

        $maxKm = 0.0;
        foreach ($polygonPoints as $point) {
            $distanceKm = $this->calculateDistance($centerLat, $centerLng, $point['lat'], $point['lng']);
            if ($distanceKm > $maxKm) {
                $maxKm = $distanceKm;
            }
        }

        return [
            'latitude' => $centerLat,
            'longitude' => $centerLng,
            'radius_meters' => $maxKm * 1000
        ];
    }

    /**
     * Hydrate DB row values for API consumers.
     */
    private function hydrateGeofenceRow(array $row): array
    {
        $row['shape_type'] = $row['shape_type'] ?? 'circle';
        $row['geofence_type'] = strtolower(trim((string)($row['geofence_type'] ?? '')));
        $row['polygon_points'] = $this->normalizePolygonPoints($row['polygon_points'] ?? null);
        return $row;
    }
    
    /**
     * Get previous tracking data for vehicle
     */
    private function getPreviousTracking(int $vehicleId): ?object
    {
        $sql = "
            SELECT * FROM gps_tracking_data
            WHERE vehicle_id = ?
            ORDER BY id DESC
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
        
        $rows = $this->database->fetchAll($sql);
        return array_map(fn(array $row) => $this->hydrateGeofenceRow($row), $rows);
    }

    /**
     * Return active geofences that currently contain the given point.
     */
    public function getContainingGeofences(float $latitude, float $longitude): array
    {
        $matches = [];
        foreach ($this->getActiveGeofences() as $geofence) {
            if ($this->containsPointInGeofence($latitude, $longitude, $geofence)) {
                $matches[] = $geofence;
            }
        }
        return $matches;
    }

    /**
     * Check if a coordinate is inside a specific geofence row.
     */
    public function containsPoint(float $latitude, float $longitude, array $geofence): bool
    {
        return $this->containsPointInGeofence($latitude, $longitude, $geofence);
    }
    
    /**
     * Get geofence by ID
     */
    public function getGeofenceById(int $id): ?array
    {
        $sql = "SELECT * FROM geofences WHERE id = ?";
        $row = $this->database->fetch($sql, [$id]);
        return $row ? $this->hydrateGeofenceRow($row) : null;
    }
    
    /**
     * Create geofence
     */
    public function createGeofence(array $data): int
    {
        $shapeType = ($data['shape_type'] ?? 'circle') === 'polygon' ? 'polygon' : 'circle';
        $polygonPoints = $this->normalizePolygonPoints($data['polygon_points'] ?? null);
        $latitude = (float)($data['latitude'] ?? 0);
        $longitude = (float)($data['longitude'] ?? 0);
        $radiusMeters = (float)($data['radius_meters'] ?? 0);

        if ($shapeType === 'polygon' && count($polygonPoints) >= 3) {
            $derived = $this->deriveCircleFromPolygon($polygonPoints);
            $latitude = $derived['latitude'];
            $longitude = $derived['longitude'];
            $radiusMeters = max(1, $derived['radius_meters']);
        }

        $sql = "
            INSERT INTO geofences 
            (name, geofence_type, material_type, shape_type, latitude, longitude, radius_meters, polygon_points, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $data['name'],
            $data['geofence_type'],
            $data['material_type'] ?? null,
            $shapeType,
            $latitude,
            $longitude,
            $radiusMeters,
            count($polygonPoints) >= 3 ? json_encode($polygonPoints) : null,
            $data['is_active'] ?? 1
        ]);
        
        return (int)$this->database->lastInsertId();
    }
    
    /**
     * Update geofence
     */
    public function updateGeofence(int $id, array $data): bool
    {
        $shapeType = ($data['shape_type'] ?? 'circle') === 'polygon' ? 'polygon' : 'circle';
        $polygonPoints = $this->normalizePolygonPoints($data['polygon_points'] ?? null);
        $latitude = (float)($data['latitude'] ?? 0);
        $longitude = (float)($data['longitude'] ?? 0);
        $radiusMeters = (float)($data['radius_meters'] ?? 0);

        if ($shapeType === 'polygon' && count($polygonPoints) >= 3) {
            $derived = $this->deriveCircleFromPolygon($polygonPoints);
            $latitude = $derived['latitude'];
            $longitude = $derived['longitude'];
            $radiusMeters = max(1, $derived['radius_meters']);
        }

        $sql = "
            UPDATE geofences 
            SET name = ?, geofence_type = ?, material_type = ?, shape_type = ?,
                latitude = ?, longitude = ?, radius_meters = ?, polygon_points = ?, is_active = ?
            WHERE id = ?
        ";
        
        return $this->database->execute($sql, [
            $data['name'],
            $data['geofence_type'],
            $data['material_type'] ?? null,
            $shapeType,
            $latitude,
            $longitude,
            $radiusMeters,
            count($polygonPoints) >= 3 ? json_encode($polygonPoints) : null,
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
