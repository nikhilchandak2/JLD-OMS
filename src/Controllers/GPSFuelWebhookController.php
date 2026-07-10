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
    private const DEFAULT_MAX_PAYLOAD_BYTES = 1048576; // 1MB
    private const DEFAULT_TIMESTAMP_TOLERANCE_SECONDS = 300;

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
        
        if (!$this->validatePayloadSize()) {
            http_response_code(413);
            echo json_encode(['error' => 'Payload too large']);
            return;
        }

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true) ?? $_POST;

        $this->logWebhookDebug('received', $rawBody);

        if (!$this->validateWebhookRequest($rawBody)) {
            $this->logWebhookDebug('rejected: 401 unauthorized (api key/hmac mismatch)', $rawBody);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            // Extract device identifier (IMEI or device_id)
            $deviceId = $input['device_id'] ?? $input['imei'] ?? null;
            
            if (!$deviceId) {
                $this->logWebhookDebug('rejected: 400 missing device_id/imei', $rawBody);
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
                $this->logWebhookDebug("rejected: 404 no vehicle linked to device {$deviceId} (data NOT saved)", $rawBody);
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

            // WheelsEye may send fuel values in the same GPS payload.
            $this->ingestFuelFromPayload($vehicle, $deviceId, $input);
            
            // Trigger trip detection
            $this->tripDetectionService->processTrackingData($vehicle->id, $trackingData);
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'GPS data received',
                'vehicle_id' => $vehicle->id
            ]);
            
        } catch (\Exception $e) {
            error_log('GPS webhook error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error'
            ]);
        }
    }
    
    /**
     * Health/validation response for GET and HEAD probes on webhook URLs.
     * Providers like Ashok Leyland iAlert verify the endpoint before forwarding data.
     */
    public function webhookHealth(): void
    {
        $this->logWebhookDebug('probe: GET/HEAD endpoint validation', '');
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode([
            'status' => 'ok',
            'message' => 'Webhook endpoint is active. Send GPS data via POST.'
        ]);
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
        
        if (!$this->validatePayloadSize()) {
            http_response_code(413);
            echo json_encode(['error' => 'Payload too large']);
            return;
        }

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true) ?? $_POST;
        
        if (!$this->validateWebhookRequest($rawBody)) {
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
            error_log('Fuel webhook error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error'
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

    /**
     * Persist fuel reading when the GPS payload also contains fuel metrics.
     */
    private function ingestFuelFromPayload(\App\Models\Vehicle $vehicle, string $deviceId, array $input): void
    {
        $fuelLevel = $this->extractNumericValue($input, [
            'fuel_level', 'fuelLevel', 'fuel', 'level', 'tank_level', 'tankLevel', 'fuel_liters', 'fuelLiters'
        ]);
        $fuelPercentage = $this->extractNumericValue($input, [
            'fuel_percentage', 'fuelPercentage', 'percentage', 'fuel_percent', 'fuelPercent'
        ]);
        $temperature = $this->extractNumericValue($input, ['temperature', 'temp', 'fuel_temp', 'fuelTemperature']);
        $voltage = $this->extractNumericValue($input, ['voltage', 'battery_voltage', 'sensor_voltage']);

        // Skip if there is no fuel signal in this payload.
        if ($fuelLevel === null && $fuelPercentage === null && $temperature === null && $voltage === null) {
            return;
        }

        $sensorId = (string)($input['sensor_id'] ?? $input['fuel_sensor_id'] ?? $input['device_id'] ?? $input['imei'] ?? $deviceId);
        if ($sensorId === '') {
            $sensorId = 'vehicle-' . $vehicle->id . '-fuel';
        }

        $fuelSensor = $this->fuelSensorRepository->findBySensorId($sensorId);
        if (!$fuelSensor) {
            $fuelSensor = new \App\Models\FuelSensor([
                'sensor_id' => $sensorId,
                'sensor_type' => 'ultrasonic',
                'status' => 'active'
            ]);
            $fuelSensor->id = $this->fuelSensorRepository->create($fuelSensor);
        }
        $this->fuelSensorRepository->updateLastSeen($sensorId);

        // Auto-link sensor to vehicle so Fuel Management lists this vehicle consistently.
        if (empty($vehicle->fuelSensorId)) {
            $db = new \App\Core\Database();
            $db->execute("UPDATE vehicles SET fuel_sensor_id = ? WHERE id = ?", [$fuelSensor->id, $vehicle->id]);
            $vehicle->fuelSensorId = $fuelSensor->id;
        }

        $fuelData = new FuelReadingData([
            'vehicle_id' => $vehicle->id,
            'sensor_id' => $sensorId,
            'fuel_level' => $fuelLevel ?? 0,
            'fuel_percentage' => $fuelPercentage,
            'temperature' => $temperature,
            'voltage' => $voltage,
            'timestamp' => $input['timestamp'] ?? $input['time'] ?? date('Y-m-d H:i:s'),
            'raw_data' => $input
        ]);

        $this->fuelReadingRepository->create($fuelData);
        $this->fuelAlertService->checkFuelAlerts($vehicle->id, $fuelData);
    }

    /**
     * Return first numeric value from provided keys, including nested payloads.
     */
    private function extractNumericValue(array $payload, array $targetKeys): ?float
    {
        $lookup = [];
        foreach ($targetKeys as $key) {
            $lookup[$this->normalizeKey($key)] = true;
        }

        $stack = [$payload];
        while ($stack) {
            $current = array_pop($stack);
            foreach ($current as $key => $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                if (isset($lookup[$this->normalizeKey((string)$key)])) {
                    $parsed = $this->parseNumeric((string)$value);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                }
            }
        }
        return null;
    }

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? strtolower($key);
    }

    private function parseNumeric(string $value): ?float
    {
        $clean = trim(str_replace('%', '', $value));
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }
        return (float)$clean;
    }
    
    /**
     * TEMPORARY debug logger to capture raw webhook payloads (e.g. from Ashok Leyland iAlert).
     * Writes to storage/gps_webhook_debug.log. Disable by setting GPS_WEBHOOK_DEBUG=0 in .env.
     */
    private function logWebhookDebug(string $stage, string $rawBody): void
    {
        $enabled = $_ENV['GPS_WEBHOOK_DEBUG'] ?? '1';
        if ($enabled === '0' || $enabled === 'false') {
            return;
        }

        try {
            $logFile = dirname(__DIR__, 2) . '/storage/gps_webhook_debug.log';

            // Cap file at ~5MB: keep the most recent half when exceeded.
            if (is_file($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
                $contents = file_get_contents($logFile) ?: '';
                file_put_contents($logFile, substr($contents, (int)(strlen($contents) / 2)), LOCK_EX);
            }

            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0 || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'REMOTE_ADDR', 'REQUEST_METHOD'], true)) {
                    $headers[$key] = is_string($value) ? $value : json_encode($value);
                }
            }

            $entry = json_encode([
                'time' => date('Y-m-d H:i:s'),
                'stage' => $stage,
                'headers' => $headers,
                'query' => $_GET,
                'body' => $rawBody !== '' ? $rawBody : $_POST,
            ], JSON_UNESCAPED_SLASHES);

            file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Debug logging must never break the webhook.
        }
    }

    private function validateWebhookRequest(string $rawBody): bool
    {
        $apiKeyConfigured = isset($_ENV['GPS_FUEL_API_KEY']) && (string)$_ENV['GPS_FUEL_API_KEY'] !== '';
        $hmacConfigured = isset($_ENV['GPS_FUEL_WEBHOOK_SECRET']) && (string)$_ENV['GPS_FUEL_WEBHOOK_SECRET'] !== '';

        // Backwards compatibility for local/dev environments without webhook auth configured.
        if (!$apiKeyConfigured && !$hmacConfigured) {
            return true;
        }

        if ($apiKeyConfigured && $this->validateApiKey()) {
            return true;
        }

        if ($hmacConfigured && $this->validateHmacSignature($rawBody)) {
            return true;
        }

        return false;
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
        return false;
    }

    private function validatePayloadSize(): bool
    {
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        $maxBytes = (int)($_ENV['GPS_FUEL_WEBHOOK_MAX_BYTES'] ?? self::DEFAULT_MAX_PAYLOAD_BYTES);
        return $contentLength <= 0 || $contentLength <= max($maxBytes, 1);
    }

    private function validateHmacSignature(string $rawBody): bool
    {
        $secret = (string)($_ENV['GPS_FUEL_WEBHOOK_SECRET'] ?? '');
        if ($secret === '') {
            // HMAC mode not configured.
            return false;
        }

        $timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
        $signatureHeader = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
        if ($timestamp === '' || $signatureHeader === '') {
            return false;
        }

        if (!ctype_digit((string)$timestamp)) {
            return false;
        }

        $tolerance = (int)($_ENV['GPS_FUEL_WEBHOOK_TOLERANCE_SECONDS'] ?? self::DEFAULT_TIMESTAMP_TOLERANCE_SECONDS);
        $timestampInt = (int)$timestamp;
        if (abs(time() - $timestampInt) > max($tolerance, 1)) {
            return false;
        }

        $provided = $this->normalizeSignature($signatureHeader);
        if ($provided === '') {
            return false;
        }

        $signedPayload = $timestamp . '.' . $rawBody;
        $expected = hash_hmac('sha256', $signedPayload, $secret);
        return hash_equals($expected, $provided);
    }

    private function normalizeSignature(string $signature): string
    {
        $trimmed = trim($signature);
        if (stripos($trimmed, 'sha256=') === 0) {
            return substr($trimmed, 7);
        }
        return $trimmed;
    }
}
