<?php

namespace App\Models;

class Vehicle
{
    public int $id = 0;
    public string $vehicleNumber = '';
    public string $vehicleType = 'dumper';
    public ?string $make = null;
    public ?string $model = null;
    public ?int $year = null;
    public ?string $registrationNumber = null;
    public ?int $gpsDeviceId = null;
    public ?string $gpsDeviceImei = null;
    public ?int $fuelSensorId = null;
    public ?string $fuelSensorIdString = null;
    public string $status = 'active';
    public ?string $notes = null;
    public string $createdAt = '';
    public string $updatedAt = '';
    
    // Computed fields
    public ?array $lastLocation = null;
    public ?string $lastSeen = null;
    
    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->fill($data);
        }
    }
    
    public function fill(array $data): void
    {
        $this->id = $data['id'] ?? 0;
        $this->vehicleNumber = $data['vehicle_number'] ?? '';
        $this->vehicleType = $data['vehicle_type'] ?? 'dumper';
        $this->make = $data['make'] ?? null;
        $this->model = $data['model'] ?? null;
        $this->year = isset($data['year']) ? (int)$data['year'] : null;
        $this->registrationNumber = $data['registration_number'] ?? null;
        $this->gpsDeviceId = isset($data['gps_device_id']) ? (int)$data['gps_device_id'] : null;
        $this->gpsDeviceImei = $data['gps_device_imei'] ?? $data['imei'] ?? null;
        $this->fuelSensorId = isset($data['fuel_sensor_id']) ? (int)$data['fuel_sensor_id'] : null;
        $this->fuelSensorIdString = $data['fuel_sensor_id_string'] ?? $data['sensor_id'] ?? null;
        $this->status = $data['status'] ?? 'active';
        $this->notes = $data['notes'] ?? null;
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
        
        // Computed fields
        if (isset($data['last_latitude']) && isset($data['last_longitude'])) {
            $this->lastLocation = [
                'latitude' => (float)$data['last_latitude'],
                'longitude' => (float)$data['last_longitude']
            ];
        }
        $this->lastSeen = $data['last_seen'] ?? null;
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_number' => $this->vehicleNumber,
            'vehicle_type' => $this->vehicleType,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'registration_number' => $this->registrationNumber,
            'gps_device_id' => $this->gpsDeviceId,
            'gps_device_imei' => $this->gpsDeviceImei,
            'fuel_sensor_id' => $this->fuelSensorId,
            'fuel_sensor_id_string' => $this->fuelSensorIdString,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'last_location' => $this->lastLocation,
            'last_seen' => $this->lastSeen
        ];
    }
    
    public function validate(): array
    {
        $errors = [];
        
        if (empty($this->vehicleNumber)) {
            $errors[] = 'Vehicle number is required';
        }
        
        if (!in_array($this->vehicleType, ['dumper', 'excavator', 'loader', 'truck', 'other'])) {
            $errors[] = 'Invalid vehicle type';
        }
        
        return $errors;
    }
}
