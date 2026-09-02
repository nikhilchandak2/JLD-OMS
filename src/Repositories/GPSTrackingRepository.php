<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\GPSTrackingData;

class GPSTrackingRepository
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function create(GPSTrackingData $tracking): int
    {
        $sql = "
            INSERT INTO gps_tracking_data 
            (vehicle_id, device_id, latitude, longitude, altitude, speed, heading, accuracy, 
             satellite_count, timestamp, ignition_status, movement_status, odometer, raw_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $tracking->vehicleId,
            $tracking->deviceId,
            $tracking->latitude,
            $tracking->longitude,
            $tracking->altitude,
            $tracking->speed,
            $tracking->heading,
            $tracking->accuracy,
            $tracking->satelliteCount !== null && $tracking->satelliteCount !== '' ? (int)$tracking->satelliteCount : null,
            $tracking->timestamp,
            $tracking->ignitionStatus === null || $tracking->ignitionStatus === '' ? null : (int)(bool)$tracking->ignitionStatus,
            $tracking->movementStatus,
            $tracking->odometer,
            $tracking->rawData ? json_encode($tracking->rawData) : null
        ]);
        
        return (int)$this->database->lastInsertId();
    }
    
    public function getLatestForVehicle(int $vehicleId): ?GPSTrackingData
    {
        if (!\App\Support\TableSchema::hasTable('gps_tracking_data')) {
            return null;
        }
        $sql = "
            SELECT * FROM gps_tracking_data 
            WHERE vehicle_id = ? 
            ORDER BY timestamp DESC 
            LIMIT 1
        ";
        
        $result = $this->database->fetch($sql, [$vehicleId]);
        
        return $result ? new GPSTrackingData($result) : null;
    }
    
    public function getLatestForAllVehicles(): array
    {
        if (!\App\Support\TableSchema::hasTable('gps_tracking_data')) {
            return [];
        }
        $sql = "
            SELECT t.*
            FROM gps_tracking_data t
            WHERE t.id = (
                SELECT t2.id
                FROM gps_tracking_data t2
                WHERE t2.vehicle_id = t.vehicle_id
                ORDER BY t2.timestamp DESC, t2.id DESC
                LIMIT 1
            )
            ORDER BY t.timestamp DESC
        ";
        
        $results = $this->database->fetchAll($sql);
        
        return array_map(function($row) {
            return new GPSTrackingData($row);
        }, $results);
    }
    
    public function getHistoryForVehicle(int $vehicleId, ?string $startDate = null, ?string $endDate = null, int $limit = 1000): array
    {
        $sql = "
            SELECT * FROM gps_tracking_data 
            WHERE vehicle_id = ?
        ";
        
        $params = [$vehicleId];
        
        if ($startDate) {
            $sql .= " AND timestamp >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND timestamp <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " ORDER BY timestamp DESC LIMIT ?";
        $params[] = $limit;
        
        $results = $this->database->fetchAll($sql, $params);
        
        return array_map(function($row) {
            return new GPSTrackingData($row);
        }, $results);
    }

    /**
     * Get recent path points for a vehicle (oldest first) for drawing route polyline.
     * @param int $vehicleId
     * @param int $hours Last N hours of data
     * @param int $limit Max points to return
     * @return GPSTrackingData[]
     */
    public function getRecentPathForVehicle(int $vehicleId, int $hours = 24, int $limit = 1000): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $sql = "
            SELECT * FROM gps_tracking_data
            WHERE vehicle_id = ? AND timestamp >= ?
            ORDER BY timestamp ASC
            LIMIT ?
        ";
        $results = $this->database->fetchAll($sql, [$vehicleId, $since, $limit]);
        return array_map(function($row) {
            return new GPSTrackingData($row);
        }, $results);
    }

    /**
     * Get tracking points for a vehicle between two timestamps (chronological, for stoppage analysis).
     * @return GPSTrackingData[]
     */
    public function getTrackingBetween(int $vehicleId, string $startTime, string $endTime, int $limit = 5000): array
    {
        $sql = "
            SELECT * FROM gps_tracking_data
            WHERE vehicle_id = ? AND timestamp >= ? AND timestamp <= ?
            ORDER BY timestamp ASC
            LIMIT ?
        ";
        $results = $this->database->fetchAll($sql, [$vehicleId, $startTime, $endTime, $limit]);
        return array_map(function($row) {
            return new GPSTrackingData($row);
        }, $results);
    }

    /**
     * Get historical pulled tracking data (newest first), with optional vehicle filter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPulledDataHistory(?string $vehicleFilter = null, int $limit = 50, int $offset = 0): array
    {
        if (!\App\Support\TableSchema::hasTable('gps_tracking_data')) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $vehicleNumber = \App\Support\TableSchema::columnExpr('vehicles', ['vehicle_number', 'vehicle_no'], 'v', 'vehicle_number');
        $sql = "
            SELECT
                t.id,
                t.vehicle_id,
                {$vehicleNumber},
                t.device_id,
                t.latitude,
                t.longitude,
                t.speed,
                t.ignition_status,
                t.timestamp,
                t.raw_data
            FROM gps_tracking_data t
            LEFT JOIN vehicles v ON v.id = t.vehicle_id
            WHERE t.raw_data IS NOT NULL
        ";
        $params = [];
        if ($vehicleFilter !== null && trim($vehicleFilter) !== '') {
            $numberCol = \App\Support\TableSchema::hasColumn('vehicles', 'vehicle_number') ? 'v.vehicle_number' : 'v.vehicle_no';
            $sql .= " AND ({$numberCol} LIKE ? OR t.device_id LIKE ?)";
            $like = '%' . trim($vehicleFilter) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " ORDER BY t.timestamp DESC, t.id DESC LIMIT {$limit} OFFSET {$offset}";

        return $this->database->fetchAll($sql, $params);
    }

    public function countPulledDataHistory(?string $vehicleFilter = null): int
    {
        if (!\App\Support\TableSchema::hasTable('gps_tracking_data')) {
            return 0;
        }
        $sql = "
            SELECT COUNT(*) AS total
            FROM gps_tracking_data t
            LEFT JOIN vehicles v ON v.id = t.vehicle_id
            WHERE t.raw_data IS NOT NULL
        ";
        $params = [];
        if ($vehicleFilter !== null && trim($vehicleFilter) !== '') {
            $numberCol = \App\Support\TableSchema::hasColumn('vehicles', 'vehicle_number') ? 'v.vehicle_number' : 'v.vehicle_no';
            $sql .= " AND ({$numberCol} LIKE ? OR t.device_id LIKE ?)";
            $like = '%' . trim($vehicleFilter) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $row = $this->database->fetch($sql, $params);
        return (int)($row['total'] ?? 0);
    }
}
