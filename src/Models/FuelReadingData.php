<?php

namespace App\Models;

class FuelReadingData
{
    public int $id = 0;
    public int $vehicleId = 0;
    public string $sensorId = '';
    public float $fuelLevel = 0.0;
    public ?float $fuelPercentage = null;
    public ?float $temperature = null;
    public ?float $voltage = null;
    public string $timestamp = '';
    public ?array $rawData = null;
    public string $createdAt = '';
    
    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->fill($data);
        }
    }
    
    public function fill(array $data): void
    {
        $this->id = $data['id'] ?? 0;
        $this->vehicleId = $data['vehicle_id'] ?? 0;
        $this->sensorId = $data['sensor_id'] ?? '';
        $this->fuelLevel = isset($data['fuel_level']) ? (float)$data['fuel_level'] : 0.0;
        $this->fuelPercentage = isset($data['fuel_percentage']) ? (float)$data['fuel_percentage'] : null;
        $this->temperature = isset($data['temperature']) ? (float)$data['temperature'] : null;
        $this->voltage = isset($data['voltage']) ? (float)$data['voltage'] : null;
        $this->timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');
        $this->rawData = isset($data['raw_data']) ? (is_string($data['raw_data']) ? json_decode($data['raw_data'], true) : $data['raw_data']) : null;
        $this->createdAt = $data['created_at'] ?? '';
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'sensor_id' => $this->sensorId,
            'fuel_level' => $this->fuelLevel,
            'fuel_percentage' => $this->fuelPercentage,
            'temperature' => $this->temperature,
            'voltage' => $this->voltage,
            'timestamp' => $this->timestamp,
            'raw_data' => $this->rawData,
            'created_at' => $this->createdAt
        ];
    }
}
