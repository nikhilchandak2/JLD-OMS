<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Vehicle;

class VehicleRepository
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function findAll(array $filters = []): array
    {
        $sql = "
            SELECT v.*,
                   gd.imei as gps_device_imei,
                   fs.sensor_id as fuel_sensor_id_string,
                   (SELECT latitude FROM gps_tracking_data WHERE vehicle_id = v.id ORDER BY timestamp DESC LIMIT 1) as last_latitude,
                   (SELECT longitude FROM gps_tracking_data WHERE vehicle_id = v.id ORDER BY timestamp DESC LIMIT 1) as last_longitude,
                   (SELECT timestamp FROM gps_tracking_data WHERE vehicle_id = v.id ORDER BY timestamp DESC LIMIT 1) as last_seen
            FROM vehicles v
            LEFT JOIN gps_devices gd ON v.gps_device_id = gd.id
            LEFT JOIN fuel_sensors fs ON v.fuel_sensor_id = fs.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if (!empty($filters['status'])) {
            $sql .= " AND v.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['vehicle_type'])) {
            $sql .= " AND v.vehicle_type = ?";
            $params[] = $filters['vehicle_type'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (v.vehicle_number LIKE ? OR v.registration_number LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " ORDER BY v.vehicle_number ASC";
        
        $results = $this->database->fetchAll($sql, $params);
        
        return array_map(function($row) {
            return new Vehicle($row);
        }, $results);
    }
    
    public function findById(int $id): ?Vehicle
    {
        $sql = "
            SELECT v.*,
                   gd.imei as gps_device_imei,
                   fs.sensor_id as fuel_sensor_id_string
            FROM vehicles v
            LEFT JOIN gps_devices gd ON v.gps_device_id = gd.id
            LEFT JOIN fuel_sensors fs ON v.fuel_sensor_id = fs.id
            WHERE v.id = ?
        ";
        
        $result = $this->database->fetch($sql, [$id]);
        
        return $result ? new Vehicle($result) : null;
    }
    
    public function findByVehicleNumber(string $vehicleNumber): ?Vehicle
    {
        $sql = "SELECT * FROM vehicles WHERE vehicle_number = ?";
        $result = $this->database->fetch($sql, [$vehicleNumber]);
        
        return $result ? new Vehicle($result) : null;
    }
    
    public function findByGpsDeviceId(int $gpsDeviceId): ?Vehicle
    {
        $sql = "SELECT * FROM vehicles WHERE gps_device_id = ?";
        $result = $this->database->fetch($sql, [$gpsDeviceId]);
        
        return $result ? new Vehicle($result) : null;
    }
    
    public function findByGpsDeviceImei(string $imei): ?Vehicle
    {
        $sql = "
            SELECT v.*
            FROM vehicles v
            JOIN gps_devices gd ON v.gps_device_id = gd.id
            WHERE gd.imei = ? OR gd.device_id = ?
        ";
        $result = $this->database->fetch($sql, [$imei, $imei]);
        
        return $result ? new Vehicle($result) : null;
    }
    
    public function create(Vehicle $vehicle): int
    {
        $sql = "
            INSERT INTO vehicles (vehicle_number, vehicle_type, make, model, year, registration_number, gps_device_id, fuel_sensor_id, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $vehicle->vehicleNumber,
            $vehicle->vehicleType,
            $vehicle->make,
            $vehicle->model,
            $vehicle->year,
            $vehicle->registrationNumber,
            $vehicle->gpsDeviceId,
            $vehicle->fuelSensorId,
            $vehicle->status,
            $vehicle->notes
        ]);
        
        return (int)$this->database->lastInsertId();
    }
    
    public function update(Vehicle $vehicle): bool
    {
        $sql = "
            UPDATE vehicles 
            SET vehicle_number = ?, vehicle_type = ?, make = ?, model = ?, year = ?, 
                registration_number = ?, gps_device_id = ?, fuel_sensor_id = ?, status = ?, notes = ?
            WHERE id = ?
        ";
        
        return $this->database->execute($sql, [
            $vehicle->vehicleNumber,
            $vehicle->vehicleType,
            $vehicle->make,
            $vehicle->model,
            $vehicle->year,
            $vehicle->registrationNumber,
            $vehicle->gpsDeviceId,
            $vehicle->fuelSensorId,
            $vehicle->status,
            $vehicle->notes,
            $vehicle->id
        ]);
    }
    
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM vehicles WHERE id = ?";
        return $this->database->execute($sql, [$id]);
    }
}
