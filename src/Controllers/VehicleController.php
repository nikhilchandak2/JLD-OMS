<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\VehicleRepository;
use App\Repositories\GPSDeviceRepository;
use App\Repositories\FuelSensorRepository;
use App\Models\Vehicle;
use App\Models\GPSDevice;
use App\Models\FuelSensor;

class VehicleController
{
    private AuthService $authService;
    private VehicleRepository $vehicleRepository;
    private GPSDeviceRepository $gpsDeviceRepository;
    private FuelSensorRepository $fuelSensorRepository;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->vehicleRepository = new VehicleRepository();
        $this->gpsDeviceRepository = new GPSDeviceRepository();
        $this->fuelSensorRepository = new FuelSensorRepository();
    }
    
    public function index(): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $filters = [
                'status' => $_GET['status'] ?? null,
                'vehicle_type' => $_GET['vehicle_type'] ?? null,
                'search' => $_GET['search'] ?? null
            ];
            
            $vehicles = $this->vehicleRepository->findAll($filters);
            
            echo json_encode([
                'success' => true,
                'data' => array_map(fn($v) => $v->toArray(), $vehicles)
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function show(int $id): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $vehicle = $this->vehicleRepository->findById($id);
            
            if (!$vehicle) {
                http_response_code(404);
                echo json_encode(['error' => 'Vehicle not found']);
                return;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $vehicle->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function create(): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            
            $vehicle = new Vehicle([
                'vehicle_number' => $input['vehicle_number'] ?? '',
                'vehicle_type' => $input['vehicle_type'] ?? 'dumper',
                'make' => $input['make'] ?? null,
                'model' => $input['model'] ?? null,
                'year' => $input['year'] ?? null,
                'registration_number' => $input['registration_number'] ?? null,
                'status' => $input['status'] ?? 'active',
                'notes' => $input['notes'] ?? null
            ]);
            
            $errors = $vehicle->validate();
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['error' => implode(', ', $errors)]);
                return;
            }
            
            // Handle GPS device assignment
            if (!empty($input['gps_device_imei'])) {
                $gpsDevice = $this->gpsDeviceRepository->findByImei($input['gps_device_imei']);
                if (!$gpsDevice) {
                    // Auto-create GPS device
                    $gpsDevice = new GPSDevice([
                        'device_id' => $input['gps_device_imei'],
                        'imei' => $input['gps_device_imei'],
                        'device_type' => 'wheelseye',
                        'status' => 'active'
                    ]);
                    $gpsDeviceId = $this->gpsDeviceRepository->create($gpsDevice);
                    $gpsDevice->id = $gpsDeviceId;
                }
                $vehicle->gpsDeviceId = $gpsDevice->id;
            }
            
            // Handle fuel sensor assignment
            if (!empty($input['fuel_sensor_id'])) {
                $fuelSensor = $this->fuelSensorRepository->findBySensorId($input['fuel_sensor_id']);
                if (!$fuelSensor) {
                    // Auto-create fuel sensor
                    $fuelSensor = new FuelSensor([
                        'sensor_id' => $input['fuel_sensor_id'],
                        'sensor_type' => 'ultrasonic',
                        'status' => 'active'
                    ]);
                    $sensorId = $this->fuelSensorRepository->create($fuelSensor);
                    $fuelSensor->id = $sensorId;
                }
                $vehicle->fuelSensorId = $fuelSensor->id;
            }
            
            $vehicleId = $this->vehicleRepository->create($vehicle);
            
            echo json_encode([
                'success' => true,
                'message' => 'Vehicle created successfully',
                'data' => ['id' => $vehicleId]
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function update(int $id): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $vehicle = $this->vehicleRepository->findById($id);
            if (!$vehicle) {
                http_response_code(404);
                echo json_encode(['error' => 'Vehicle not found']);
                return;
            }
            
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            
            $vehicle->vehicleNumber = $input['vehicle_number'] ?? $vehicle->vehicleNumber;
            $vehicle->vehicleType = $input['vehicle_type'] ?? $vehicle->vehicleType;
            $vehicle->make = $input['make'] ?? $vehicle->make;
            $vehicle->model = $input['model'] ?? $vehicle->model;
            $vehicle->year = $input['year'] ?? $vehicle->year;
            $vehicle->registrationNumber = $input['registration_number'] ?? $vehicle->registrationNumber;
            $vehicle->status = $input['status'] ?? $vehicle->status;
            $vehicle->notes = $input['notes'] ?? $vehicle->notes;
            
            // Handle GPS device assignment
            if (isset($input['gps_device_imei'])) {
                if (!empty($input['gps_device_imei'])) {
                    $gpsDevice = $this->gpsDeviceRepository->findByImei($input['gps_device_imei']);
                    if (!$gpsDevice) {
                        $gpsDevice = new GPSDevice([
                            'device_id' => $input['gps_device_imei'],
                            'imei' => $input['gps_device_imei'],
                            'device_type' => 'wheelseye',
                            'status' => 'active'
                        ]);
                        $gpsDeviceId = $this->gpsDeviceRepository->create($gpsDevice);
                        $gpsDevice->id = $gpsDeviceId;
                    }
                    $vehicle->gpsDeviceId = $gpsDevice->id;
                } else {
                    $vehicle->gpsDeviceId = null;
                }
            }
            
            // Handle fuel sensor assignment
            if (isset($input['fuel_sensor_id'])) {
                if (!empty($input['fuel_sensor_id'])) {
                    $fuelSensor = $this->fuelSensorRepository->findBySensorId($input['fuel_sensor_id']);
                    if (!$fuelSensor) {
                        $fuelSensor = new FuelSensor([
                            'sensor_id' => $input['fuel_sensor_id'],
                            'sensor_type' => 'ultrasonic',
                            'status' => 'active'
                        ]);
                        $sensorId = $this->fuelSensorRepository->create($fuelSensor);
                        $fuelSensor->id = $sensorId;
                    }
                    $vehicle->fuelSensorId = $fuelSensor->id;
                } else {
                    $vehicle->fuelSensorId = null;
                }
            }
            
            $this->vehicleRepository->update($vehicle);
            
            echo json_encode([
                'success' => true,
                'message' => 'Vehicle updated successfully'
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function delete(int $id): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        
        try {
            $this->vehicleRepository->delete($id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Vehicle deleted successfully'
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function gpsDevices(): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $devices = $this->gpsDeviceRepository->findAll();
            
            echo json_encode([
                'success' => true,
                'data' => array_map(fn($d) => $d->toArray(), $devices)
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function fuelSensors(): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $sensors = $this->fuelSensorRepository->findAll();
            
            echo json_encode([
                'success' => true,
                'data' => array_map(fn($s) => $s->toArray(), $sensors)
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
