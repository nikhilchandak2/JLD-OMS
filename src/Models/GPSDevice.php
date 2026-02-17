<?php

namespace App\Models;

class GPSDevice
{
    public int $id = 0;
    public string $deviceId = '';
    public ?string $imei = null;
    public string $deviceType = 'wheelseye';
    public string $status = 'active';
    public ?string $lastSeen = null;
    public ?int $batteryLevel = null;
    public ?int $signalStrength = null;
    public ?string $firmwareVersion = null;
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
        $this->deviceId = $data['device_id'] ?? '';
        $this->imei = $data['imei'] ?? null;
        $this->deviceType = $data['device_type'] ?? 'wheelseye';
        $this->status = $data['status'] ?? 'active';
        $this->lastSeen = $data['last_seen'] ?? null;
        $this->batteryLevel = isset($data['battery_level']) ? (int)$data['battery_level'] : null;
        $this->signalStrength = isset($data['signal_strength']) ? (int)$data['signal_strength'] : null;
        $this->firmwareVersion = $data['firmware_version'] ?? null;
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->deviceId,
            'imei' => $this->imei,
            'device_type' => $this->deviceType,
            'status' => $this->status,
            'last_seen' => $this->lastSeen,
            'battery_level' => $this->batteryLevel,
            'signal_strength' => $this->signalStrength,
            'firmware_version' => $this->firmwareVersion,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }
}
