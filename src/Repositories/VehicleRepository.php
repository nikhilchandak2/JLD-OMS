<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Vehicle;
use App\Support\TableSchema;

class VehicleRepository
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }

    private function selectListSql(): string
    {
        $imei = TableSchema::hasTable('gps_devices')
            ? TableSchema::columnExpr('gps_devices', ['imei', 'device_id'], 'gd', 'gps_device_imei')
            : 'NULL AS gps_device_imei';
        $sensor = TableSchema::hasTable('fuel_sensors')
            ? 'fs.sensor_id as fuel_sensor_id_string'
            : 'NULL AS fuel_sensor_id_string';
        $gpsJoin = TableSchema::hasTable('gps_devices')
            ? 'LEFT JOIN gps_devices gd ON v.gps_device_id = gd.id'
            : '';
        $fuelJoin = TableSchema::hasTable('fuel_sensors')
            ? 'LEFT JOIN fuel_sensors fs ON v.fuel_sensor_id = fs.id'
            : '';
        $lastLat = 'NULL AS last_latitude';
        $lastLng = 'NULL AS last_longitude';
        $lastSeen = 'NULL AS last_seen';
        if (TableSchema::hasTable('gps_tracking_data')) {
            $lastLat = '(SELECT latitude FROM gps_tracking_data WHERE vehicle_id = v.id ORDER BY timestamp DESC LIMIT 1) as last_latitude';
            $lastLng = '(SELECT longitude FROM gps_tracking_data WHERE vehicle_id = v.id ORDER BY timestamp DESC LIMIT 1) as last_longitude';
            $lastSeen = '(SELECT timestamp FROM gps_tracking_data WHERE vehicle_id = v.id ORDER BY timestamp DESC LIMIT 1) as last_seen';
        }

        return "
            SELECT v.*,
                   {$imei},
                   {$sensor},
                   {$lastLat},
                   {$lastLng},
                   {$lastSeen}
            FROM vehicles v
            {$gpsJoin}
            {$fuelJoin}
        ";
    }

    private function numberColumn(): string
    {
        return TableSchema::hasColumn('vehicles', 'vehicle_number') ? 'vehicle_number' : 'vehicle_no';
    }

    private function registrationColumn(): string
    {
        return TableSchema::hasColumn('vehicles', 'registration_number') ? 'registration_number' : 'registration_no';
    }
    
    public function findAll(array $filters = []): array
    {
        if (!TableSchema::hasTable('vehicles')) {
            return [];
        }
        $sql = $this->selectListSql() . ' WHERE 1=1';
        
        $params = [];
        
        if (!empty($filters['status'])) {
            if (TableSchema::hasColumn('vehicles', 'status')) {
                $sql .= ' AND v.status = ?';
                $params[] = $filters['status'];
            } elseif (TableSchema::hasColumn('vehicles', 'is_active')) {
                $sql .= ' AND v.is_active = ?';
                $params[] = $filters['status'] === 'inactive' ? 0 : 1;
            }
        }
        
        if (!empty($filters['vehicle_type'])) {
            $sql .= " AND v.vehicle_type = ?";
            $params[] = $filters['vehicle_type'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (v.{$this->numberColumn()} LIKE ? OR v.{$this->registrationColumn()} LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " ORDER BY v.{$this->numberColumn()} ASC";
        
        $results = $this->database->fetchAll($sql, $params);
        
        return array_map(function($row) {
            return new Vehicle($row);
        }, $results);
    }
    
    public function findById(int $id): ?Vehicle
    {
        if (!TableSchema::hasTable('vehicles')) {
            return null;
        }
        $sql = $this->selectListSql() . ' WHERE v.id = ?';
        
        $result = $this->database->fetch($sql, [$id]);
        
        return $result ? new Vehicle($result) : null;
    }
    
    public function findByVehicleNumber(string $vehicleNumber): ?Vehicle
    {
        if (!TableSchema::hasTable('vehicles')) {
            return null;
        }
        $sql = "SELECT * FROM vehicles WHERE {$this->numberColumn()} = ?";
        $result = $this->database->fetch($sql, [$vehicleNumber]);
        
        return $result ? new Vehicle($result) : null;
    }

    /**
     * Fuzzy matcher for vendor numbers like RJ07GD5241 vs OMS numbers like 5241.
     */
    public function findByNumberOrRegistrationFuzzy(string $value): ?Vehicle
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($value)) ?? '');
        if ($clean === '') {
            return null;
        }

        $num = $this->numberColumn();
        $reg = $this->registrationColumn();
        $sql = "
            SELECT *
            FROM vehicles
            WHERE UPPER(REPLACE(REPLACE(IFNULL({$num}, ''), '-', ''), ' ', '')) = ?
               OR UPPER(REPLACE(REPLACE(IFNULL({$reg}, ''), '-', ''), ' ', '')) = ?
            LIMIT 1
        ";
        $exact = $this->database->fetch($sql, [$clean, $clean]);
        if ($exact) {
            return new Vehicle($exact);
        }

        // Common fleet pattern: WheelsEye vehicleNumber contains registration while OMS uses short fleet code.
        // Try matching by trailing digit groups (prefer longest match).
        if (preg_match_all('/\d+/', $clean, $matches) !== 1 || empty($matches[0])) {
            return null;
        }
        $digitGroups = array_values(array_unique($matches[0]));
        usort($digitGroups, fn(string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($digitGroups as $digits) {
            if (strlen($digits) < 3) {
                continue;
            }
            $like = '%' . $digits;
            $candidateSql = "
                SELECT *
                FROM vehicles
                WHERE UPPER(REPLACE(REPLACE(IFNULL({$num}, ''), '-', ''), ' ', '')) LIKE ?
                   OR UPPER(REPLACE(REPLACE(IFNULL({$reg}, ''), '-', ''), ' ', '')) LIKE ?
                ORDER BY id ASC
                LIMIT 1
            ";
            $candidate = $this->database->fetch($candidateSql, [$like, $like]);
            if ($candidate) {
                return new Vehicle($candidate);
            }
        }

        return null;
    }
    
    public function findByGpsDeviceId(int $gpsDeviceId): ?Vehicle
    {
        $sql = "SELECT * FROM vehicles WHERE gps_device_id = ?";
        $result = $this->database->fetch($sql, [$gpsDeviceId]);
        
        return $result ? new Vehicle($result) : null;
    }
    
    public function findByGpsDeviceImei(string $imei): ?Vehicle
    {
        if (!TableSchema::hasTable('vehicles') || !TableSchema::hasTable('gps_devices')) {
            return null;
        }
        $imeiCol = TableSchema::hasColumn('gps_devices', 'imei') ? 'gd.imei = ? OR ' : '';
        $sql = "
            SELECT v.*
            FROM vehicles v
            JOIN gps_devices gd ON v.gps_device_id = gd.id
            WHERE {$imeiCol}gd.device_id = ?
        ";
        $params = $imeiCol !== '' ? [$imei, $imei] : [$imei];
        $result = $this->database->fetch($sql, $params);
        
        return $result ? new Vehicle($result) : null;
    }
    
    public function create(Vehicle $vehicle): int
    {
        $values = [
            $this->numberColumn() => $vehicle->vehicleNumber,
            'vehicle_type' => $vehicle->vehicleType,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            $this->registrationColumn() => $vehicle->registrationNumber,
        ];
        if (TableSchema::hasColumn('vehicles', 'gps_device_id')) {
            $values['gps_device_id'] = $vehicle->gpsDeviceId;
        }
        if (TableSchema::hasColumn('vehicles', 'fuel_sensor_id')) {
            $values['fuel_sensor_id'] = $vehicle->fuelSensorId;
        }
        if (TableSchema::hasColumn('vehicles', 'status')) {
            $values['status'] = $vehicle->status;
        } elseif (TableSchema::hasColumn('vehicles', 'is_active')) {
            $values['is_active'] = $vehicle->status === 'inactive' ? 0 : 1;
        }
        if (TableSchema::hasColumn('vehicles', 'notes')) {
            $values['notes'] = $vehicle->notes;
        }
        $columns = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $this->database->execute(
            'INSERT INTO vehicles (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')',
            array_values($values)
        );
        
        return (int)$this->database->lastInsertId();
    }
    
    public function update(Vehicle $vehicle): bool
    {
        $values = [
            $this->numberColumn() => $vehicle->vehicleNumber,
            'vehicle_type' => $vehicle->vehicleType,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            $this->registrationColumn() => $vehicle->registrationNumber,
        ];
        if (TableSchema::hasColumn('vehicles', 'gps_device_id')) {
            $values['gps_device_id'] = $vehicle->gpsDeviceId;
        }
        if (TableSchema::hasColumn('vehicles', 'fuel_sensor_id')) {
            $values['fuel_sensor_id'] = $vehicle->fuelSensorId;
        }
        if (TableSchema::hasColumn('vehicles', 'status')) {
            $values['status'] = $vehicle->status;
        } elseif (TableSchema::hasColumn('vehicles', 'is_active')) {
            $values['is_active'] = $vehicle->status === 'inactive' ? 0 : 1;
        }
        if (TableSchema::hasColumn('vehicles', 'notes')) {
            $values['notes'] = $vehicle->notes;
        }
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            $sets[] = "{$column} = ?";
            $params[] = $value;
        }
        $params[] = $vehicle->id;

        return $this->database->execute(
            'UPDATE vehicles SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }
    
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM vehicles WHERE id = ?";
        return $this->database->execute($sql, [$id]);
    }
}
