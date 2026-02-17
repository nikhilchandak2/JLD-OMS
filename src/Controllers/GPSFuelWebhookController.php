<?php

namespace App\Controllers;

use App\Repositories\VehicleRepository;
use App\Repositories\GPSDeviceRepository;
use App\Repositories\FuelSensorRepository;
use App\Repositories\GPSTrackingRepository;
use App\Repositories\FuelReadingRepository;
use App\Models\GPSTrackingData;
use App\Models\FuelReadingData;
use App\Services\TripDetectionService;
use App\Services\FuelAlertService;

class GPSFuelWebhookController
{
    private VehicleRepository $vehicleRepository;
    private GPSDeviceRepository $gpsDeviceRepository;
    private FuelSensorRepository $fuelSensorRepository;
    private GPSTrackingRepository $gpsTrackingRepository;
    private FuelReadingRepository $fuelReadingRepository;
    private TripDetectionService $tripDetectionService;
    private FuelAlertService $fuelAlertService;
    
    public function __construct()
    {
        $this->vehicleRepository = new VehicleRepository();
        $this->gpsDeviceRepository = new GPSDeviceRepository();
        $this->fuelSensorRepository = new FuelSensorRepository();
        $this->gpsTrackingRepository = new GPSTrackingRepository();
        $this->fuelReadingRepository = new FuelReadingRepository();
        $this->tripDetectionService = new TripDetectionService();
        $this->fuelAlertService = new FuelAlertService();
    }
    
    /**
     * Receive GPS tracking data from device
     * POST /api/gps/webhook
     */
    public function receiveGPSData(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Get input data
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        // Validate API key if configured
        if (!$this->validateApiKey()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            // Extract device identifier (IMEI or device_id)
            $deviceId = $input['device_id'] ?? $input['imei'] ?? null;
            
            if (!$deviceId) {
                http_response_code(400);
                echo json_encode(['error' => 'device_id or imei is required']);
                return;
            }
            
            // Find or create GPS device
            $gpsDevice = $this->gpsDeviceRepository->findByDeviceId($deviceId);
            
            if (!$gpsDevice) {
                // Auto-register device
                $gpsDevice = new \App\Models\GPSDevice([
                    'device_id' => $deviceId,
                    'imei' => $input['imei'] ?? $deviceId,
                    'device_type' => 'wheelseye',
                    'status' => 'active'
                ]);
                $gpsDeviceId = $this->gpsDeviceRepository->create($gpsDevice);
                $gpsDevice->id = $gpsDeviceId;
            }
            
            // Update device last seen and status
            $batteryLevel = $input['battery'] ?? $input['battery_level'] ?? null;
            $signalStrength = $input['signal'] ?? $input['signal_strength'] ?? null;
            $this->gpsDeviceRepository->updateLastSeen($deviceId, $batteryLevel, $signalStrength);
            
            // Find vehicle by GPS device
            $vehicle = $this->vehicleRepository->findByGpsDeviceId($gpsDevice->id);
            
            if (!$vehicle) {
                // Try to find by IMEI
                $vehicle = $this->vehicleRepository->findByGpsDeviceImei($deviceId);
            }
            
            if (!$vehicle) {
                http_response_code(404);
                echo json_encode(['error' => 'Vehicle not found for device', 'device_id' => $deviceId]);
                return;
            }
            
            // Map incoming data to our model
            $trackingData = new GPSTrackingData([
                'vehicle_id' => $vehicle->id,
                'device_id' => $deviceId,
                'latitude' => $input['latitude'] ?? $input['lat'] ?? 0,
                'longitude' => $input['longitude'] ?? $input['lng'] ?? 0,
                'altitude' => $input['altitude'] ?? $input['alt'] ?? null,
                'speed' => $input['speed'] ?? null,
                'heading' => $input['heading'] ?? $input['course'] ?? null,
                'accuracy' => $input['accuracy'] ?? null,
                'satellite_count' => $input['satellites'] ?? $input['satellite_count'] ?? null,
                'timestamp' => $input['timestamp'] ?? $input['time'] ?? date('Y-m-d H:i:s'),
                'ignition_status' => $input['ignition'] ?? $input['ignition_status'] ?? null,
                'movement_status' => $this->determineMovementStatus($input),
                'odometer' => $input['odometer'] ?? $input['odometer_reading'] ?? null,
                'raw_data' => $input
            ]);
            
            // Save tracking data
            $this->gpsTrackingRepository->create($trackingData);
            
            // Trigger trip detection
            $this->tripDetectionService->processTrackingData($vehicle->id, $trackingData);
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'GPS data received',
                'vehicle_id' => $vehicle->id
            ]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Receive fuel sensor data
     * POST /api/fuel/webhook
     */
    public function receiveFuelData(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        if (!$this->validateApiKey()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $sensorId = $input['sensor_id'] ?? $input['device_id'] ?? null;
            
            if (!$sensorId) {
                http_response_code(400);
                echo json_encode(['error' => 'sensor_id is required']);
                return;
            }
            
            // Find or create fuel sensor
            $fuelSensor = $this->fuelSensorRepository->findBySensorId($sensorId);
            
            if (!$fuelSensor) {
                $fuelSensor = new \App\Models\FuelSensor([
                    'sensor_id' => $sensorId,
                    'sensor_type' => 'ultrasonic',
                    'status' => 'active'
                ]);
                $sensorIdDb = $this->fuelSensorRepository->create($fuelSensor);
                $fuelSensor->id = $sensorIdDb;
            }
            
            $this->fuelSensorRepository->updateLastSeen($sensorId);
            
            // Find vehicle by fuel sensor
            $vehicle = $this->vehicleRepository->findById($fuelSensor->id); // This needs to be fixed
            
            // Actually, we need to find vehicle by fuel_sensor_id
            $sql = "SELECT * FROM vehicles WHERE fuel_sensor_id = ?";
            $db = new \App\Core\Database();
            $result = $db->fetch($sql, [$fuelSensor->id]);
            
            if (!$result) {
                http_response_code(404);
                echo json_encode(['error' => 'Vehicle not found for sensor', 'sensor_id' => $sensorId]);
                return;
            }
            
            $vehicle = new \App\Models\Vehicle($result);
            
            $fuelData = new FuelReadingData([
                'vehicle_id' => $vehicle->id,
                'sensor_id' => $sensorId,
                'fuel_level' => $input['fuel_level'] ?? $input['level'] ?? 0,
                'fuel_percentage' => $input['fuel_percentage'] ?? $input['percentage'] ?? null,
                'temperature' => $input['temperature'] ?? null,
                'voltage' => $input['voltage'] ?? null,
                'timestamp' => $input['timestamp'] ?? $input['time'] ?? date('Y-m-d H:i:s'),
                'raw_data' => $input
            ]);
            
            $this->fuelReadingRepository->create($fuelData);
            
            // Check for fuel alerts
            $this->fuelAlertService->checkFuelAlerts($vehicle->id, $fuelData);
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Fuel data received',
                'vehicle_id' => $vehicle->id
            ]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ]);
        }
    }
    
    private function determineMovementStatus(array $data): string
    {
        $speed = $data['speed'] ?? 0;
        
        if ($speed > 5) {
            return 'moving';
        } elseif ($speed > 0) {
            return 'idle';
        } else {
            return 'stationary';
        }
    }
    
    private function validateApiKey(): bool
    {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        
        if ($apiKey) {
            $apiKey = str_replace('Bearer ', '', $apiKey);
            $validApiKey = $_ENV['GPS_FUEL_API_KEY'] ?? null;
            
            if ($validApiKey) {
                return hash_equals($validApiKey, $apiKey);
            }
        }
        
        // If no API key configured, allow all (for development)
        return true;
    }
}
