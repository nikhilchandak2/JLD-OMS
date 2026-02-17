<?php

namespace App\Models;

class FuelSensor
{
    public int $id = 0;
    public string $sensorId = '';
    public string $sensorType = 'ultrasonic';
    public string $status = 'active';
    public float $calibrationFactor = 1.0;
    public ?float $tankCapacityLiters = null;
    public ?string $lastSeen = null;
    public string $createdAt = '';
    public string $updatedAt = '';
    
    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->fill($data);
        }
    }
    
    public function fill(array $data): void
    {
        $this->id = $data['id'] ?? 0;
        $this->sensorId = $data['sensor_id'] ?? '';
        $this->sensorType = $data['sensor_type'] ?? 'ultrasonic';
        $this->status = $data['status'] ?? 'active';
        $this->calibrationFactor = isset($data['calibration_factor']) ? (float)$data['calibration_factor'] : 1.0;
        $this->tankCapacityLiters = isset($data['tank_capacity_liters']) ? (float)$data['tank_capacity_liters'] : null;
        $this->lastSeen = $data['last_seen'] ?? null;
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sensor_id' => $this->sensorId,
            'sensor_type' => $this->sensorType,
            'status' => $this->status,
            'calibration_factor' => $this->calibrationFactor,
            'tank_capacity_liters' => $this->tankCapacityLiters,
            'last_seen' => $this->lastSeen,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }
}
