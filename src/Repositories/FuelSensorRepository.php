<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\FuelSensor;

class FuelSensorRepository
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function findAll(): array
    {
        $sql = "SELECT * FROM fuel_sensors ORDER BY sensor_id ASC";
        $results = $this->database->fetchAll($sql);
        
        return array_map(function($row) {
            return new FuelSensor($row);
        }, $results);
    }
    
    public function findById(int $id): ?FuelSensor
    {
        $sql = "SELECT * FROM fuel_sensors WHERE id = ?";
        $result = $this->database->fetch($sql, [$id]);
        
        return $result ? new FuelSensor($result) : null;
    }
    
    public function findBySensorId(string $sensorId): ?FuelSensor
    {
        $sql = "SELECT * FROM fuel_sensors WHERE sensor_id = ?";
        $result = $this->database->fetch($sql, [$sensorId]);
        
        return $result ? new FuelSensor($result) : null;
    }
    
    public function create(FuelSensor $sensor): int
    {
        $sql = "
            INSERT INTO fuel_sensors (sensor_id, sensor_type, status, calibration_factor, tank_capacity_liters)
            VALUES (?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $sensor->sensorId,
            $sensor->sensorType,
            $sensor->status,
            $sensor->calibrationFactor,
            $sensor->tankCapacityLiters
        ]);
        
        return (int)$this->database->lastInsertId();
    }
    
    public function update(FuelSensor $sensor): bool
    {
        $sql = "
            UPDATE fuel_sensors 
            SET sensor_id = ?, sensor_type = ?, status = ?, calibration_factor = ?, tank_capacity_liters = ?
            WHERE id = ?
        ";
        
        return $this->database->execute($sql, [
            $sensor->sensorId,
            $sensor->sensorType,
            $sensor->status,
            $sensor->calibrationFactor,
            $sensor->tankCapacityLiters,
            $sensor->id
        ]);
    }
    
    public function updateLastSeen(string $sensorId): bool
    {
        $sql = "UPDATE fuel_sensors SET last_seen = NOW() WHERE sensor_id = ?";
        return $this->database->execute($sql, [$sensorId]);
    }
}
