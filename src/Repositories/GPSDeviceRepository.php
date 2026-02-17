<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\GPSDevice;

class GPSDeviceRepository
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function findAll(): array
    {
        $sql = "SELECT * FROM gps_devices ORDER BY device_id ASC";
        $results = $this->database->fetchAll($sql);
        
        return array_map(function($row) {
            return new GPSDevice($row);
        }, $results);
    }
    
    public function findById(int $id): ?GPSDevice
    {
        $sql = "SELECT * FROM gps_devices WHERE id = ?";
        $result = $this->database->fetch($sql, [$id]);
        
        return $result ? new GPSDevice($result) : null;
    }
    
    public function findByDeviceId(string $deviceId): ?GPSDevice
    {
        $sql = "SELECT * FROM gps_devices WHERE device_id = ? OR imei = ?";
        $result = $this->database->fetch($sql, [$deviceId, $deviceId]);
        
        return $result ? new GPSDevice($result) : null;
    }
    
    public function findByImei(string $imei): ?GPSDevice
    {
        $sql = "SELECT * FROM gps_devices WHERE imei = ?";
        $result = $this->database->fetch($sql, [$imei]);
        
        return $result ? new GPSDevice($result) : null;
    }
    
    public function create(GPSDevice $device): int
    {
        $sql = "
            INSERT INTO gps_devices (device_id, imei, device_type, status, firmware_version)
            VALUES (?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $device->deviceId,
            $device->imei,
            $device->deviceType,
            $device->status,
            $device->firmwareVersion
        ]);
        
        return (int)$this->database->lastInsertId();
    }
    
    public function update(GPSDevice $device): bool
    {
        $sql = "
            UPDATE gps_devices 
            SET device_id = ?, imei = ?, device_type = ?, status = ?, firmware_version = ?
            WHERE id = ?
        ";
        
        return $this->database->execute($sql, [
            $device->deviceId,
            $device->imei,
            $device->deviceType,
            $device->status,
            $device->firmwareVersion,
            $device->id
        ]);
    }
    
    public function updateLastSeen(string $deviceId, ?int $batteryLevel = null, ?int $signalStrength = null): bool
    {
        $sql = "
            UPDATE gps_devices 
            SET last_seen = NOW(),
                battery_level = COALESCE(?, battery_level),
                signal_strength = COALESCE(?, signal_strength)
            WHERE device_id = ? OR imei = ?
        ";
        
        return $this->database->execute($sql, [
            $batteryLevel,
            $signalStrength,
            $deviceId,
            $deviceId
        ]);
    }
}
