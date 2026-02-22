<?php

namespace App\Models;

class GPSTrackingData
{
    public int $id = 0;
    public int $vehicleId = 0;
    public string $deviceId = '';
    public float $latitude = 0.0;
    public float $longitude = 0.0;
    public ?float $altitude = null;
    public ?float $speed = null;
    public ?float $heading = null;
    public ?float $accuracy = null;
    public ?int $satelliteCount = null;
    public string $timestamp = '';
    public ?bool $ignitionStatus = null;
    public string $movementStatus = 'stationary';
    public ?float $odometer = null;
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
        $this->deviceId = $data['device_id'] ?? '';
        $this->latitude = isset($data['latitude']) ? (float)$data['latitude'] : 0.0;
        $this->longitude = isset($data['longitude']) ? (float)$data['longitude'] : 0.0;
        $this->altitude = isset($data['altitude']) ? (float)$data['altitude'] : null;
        $this->speed = isset($data['speed']) ? (float)$data['speed'] : null;
        $this->heading = isset($data['heading']) ? (float)$data['heading'] : null;
        $this->accuracy = isset($data['accuracy']) ? (float)$data['accuracy'] : null;
        $this->satelliteCount = isset($data['satellite_count']) && $data['satellite_count'] !== '' ? (int)$data['satellite_count'] : null;
        $this->timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');
        $this->ignitionStatus = isset($data['ignition_status']) && $data['ignition_status'] !== '' ? (bool)$data['ignition_status'] : null;
        $this->movementStatus = $data['movement_status'] ?? 'stationary';
        $this->odometer = isset($data['odometer']) ? (float)$data['odometer'] : null;
        $this->rawData = isset($data['raw_data']) ? (is_string($data['raw_data']) ? json_decode($data['raw_data'], true) : $data['raw_data']) : null;
        $this->createdAt = $data['created_at'] ?? '';
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'device_id' => $this->deviceId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'altitude' => $this->altitude,
            'speed' => $this->speed,
            'heading' => $this->heading,
            'accuracy' => $this->accuracy,
            'satellite_count' => $this->satelliteCount,
            'timestamp' => $this->timestamp,
            'ignition_status' => $this->ignitionStatus,
            'movement_status' => $this->movementStatus,
            'odometer' => $this->odometer,
            'raw_data' => $this->rawData,
            'created_at' => $this->createdAt
        ];
    }
}
