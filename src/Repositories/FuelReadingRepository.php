<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\FuelReadingData;

class FuelReadingRepository
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function create(FuelReadingData $reading): int
    {
        $sql = "
            INSERT INTO fuel_reading_data 
            (vehicle_id, sensor_id, fuel_level, fuel_percentage, temperature, voltage, timestamp, raw_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $reading->vehicleId,
            $reading->sensorId,
            $reading->fuelLevel,
            $reading->fuelPercentage,
            $reading->temperature,
            $reading->voltage,
            $reading->timestamp,
            $reading->rawData ? json_encode($reading->rawData) : null
        ]);
        
        return (int)$this->database->lastInsertId();
    }
    
    public function getLatestForVehicle(int $vehicleId): ?FuelReadingData
    {
        $sql = "
            SELECT * FROM fuel_reading_data 
            WHERE vehicle_id = ? 
            ORDER BY timestamp DESC 
            LIMIT 1
        ";
        
        $result = $this->database->fetch($sql, [$vehicleId]);
        
        return $result ? new FuelReadingData($result) : null;
    }
    
    public function getHistoryForVehicle(int $vehicleId, ?string $startDate = null, ?string $endDate = null, int $limit = 1000): array
    {
        $sql = "
            SELECT * FROM fuel_reading_data 
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
            return new FuelReadingData($row);
        }, $results);
    }
    
    public function getFuelConsumptionForTrip(int $vehicleId, string $startTime, string $endTime): ?array
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
        
        return $result ? [
            'start_fuel' => (float)$result['start_fuel'],
            'end_fuel' => (float)$result['end_fuel'],
            'consumed' => (float)$result['consumed']
        ] : null;
    }
}
