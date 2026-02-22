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
        $sql = "
            SELECT t1.*
            FROM gps_tracking_data t1
            INNER JOIN (
                SELECT vehicle_id, MAX(timestamp) as max_timestamp
                FROM gps_tracking_data
                GROUP BY vehicle_id
            ) t2 ON t1.vehicle_id = t2.vehicle_id AND t1.timestamp = t2.max_timestamp
            ORDER BY t1.timestamp DESC
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
}
