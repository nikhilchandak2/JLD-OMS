<?php

namespace App\Services;

use App\Models\GPSTrackingData;
use App\Models\FuelReadingData;
use App\Repositories\VehicleRepository;
use App\Repositories\GPSDeviceRepository;
use App\Repositories\GPSTrackingRepository;
use App\Repositories\FuelSensorRepository;
use App\Repositories\FuelReadingRepository;
use App\Services\TripDetectionService;
use App\Services\FuelAlertService;

/**
 * Fetches current vehicle locations from WheelsEye API (vendor pull API)
 * and saves them into gps_tracking_data for the dashboard.
 * Also triggers geofence entry/exit and trip detection (same as webhook).
 */
class WheelsEyeApiService
{
    private const DEFAULT_BASE_URL = 'https://api.wheelseye.com';
    private const CURRENT_LOC_PATH = '/currentLoc';

    private VehicleRepository $vehicleRepository;
    private GPSDeviceRepository $gpsDeviceRepository;
    private GPSTrackingRepository $gpsTrackingRepository;
    private TripDetectionService $tripDetectionService;
    private FuelSensorRepository $fuelSensorRepository;
    private FuelReadingRepository $fuelReadingRepository;
    private FuelAlertService $fuelAlertService;

    public function __construct()
    {
        $this->vehicleRepository = new VehicleRepository();
        $this->gpsDeviceRepository = new GPSDeviceRepository();
        $this->gpsTrackingRepository = new GPSTrackingRepository();
        $this->tripDetectionService = new TripDetectionService();
        $this->fuelSensorRepository = new FuelSensorRepository();
        $this->fuelReadingRepository = new FuelReadingRepository();
        $this->fuelAlertService = new FuelAlertService();
    }

    /**
     * Fetch current locations from WheelsEye and save to database.
     * Matches vehicles by vehicle_number or by device IMEI (deviceNumber).
     *
     * @return array{success: bool, message: string, synced: int, skipped: int, fuel_saved: int, fuel_missing: int, errors: array}
     */
    public function syncCurrentLocations(): array
    {
        $token = $_ENV['WHEELSEYE_ACCESS_TOKEN'] ?? 'b6fbb5d6-fc43-44e9-884a-4323c0d56df3';
        $baseUrl = rtrim($_ENV['WHEELSEYE_API_BASE_URL'] ?? self::DEFAULT_BASE_URL, '/');
        $url = $baseUrl . self::CURRENT_LOC_PATH . '?accessToken=' . urlencode($token);

        $response = @file_get_contents($url);
        if ($response === false) {
            return [
                'success' => false,
                'message' => 'Failed to fetch from WheelsEye API (check URL and token)',
                'synced' => 0,
                'skipped' => 0,
                'errors' => ['Could not connect to ' . $baseUrl],
            ];
        }

        $json = json_decode($response, true);
        if (!is_array($json) || empty($json['data']['list'])) {
            $message = $json['message'] ?? 'Invalid or empty response from WheelsEye';
            return [
                'success' => true,
                'message' => $message,
                'synced' => 0,
                'skipped' => 0,
                'errors' => [],
            ];
        }

        $list = $json['data']['list'];
        $synced = 0;
        $skipped = 0;
        $fuelSaved = 0;
        $fuelMissing = 0;
        $errors = [];

        foreach ($list as $item) {
            $vehicleNumber = $item['vehicleNumber'] ?? null;
            $deviceNumber = (string)($item['deviceNumber'] ?? '');
            $lat = $this->extractNumericValue($item, ['latitude', 'lat']) ?? 0.0;
            $lng = $this->extractNumericValue($item, ['longitude', 'lng', 'lon', 'long']) ?? 0.0;
            if ($lat === 0.0 && $lng === 0.0) {
                $skipped++;
                continue;
            }

            $vehicle = null;
            if (!empty($vehicleNumber)) {
                $vehicle = $this->vehicleRepository->findByVehicleNumber($vehicleNumber);
            }
            if (!$vehicle && $deviceNumber !== '') {
                $vehicle = $this->vehicleRepository->findByGpsDeviceImei($deviceNumber);
            }
            if (!$vehicle) {
                $errors[] = 'No vehicle in OMS for: ' . ($vehicleNumber ?: 'device ' . $deviceNumber);
                $skipped++;
                continue;
            }

            $epoch = $item['dttimeInEpoch'] ?? $item['createdDate'] ?? time();
            $timestamp = date('Y-m-d H:i:s', $this->normalizeEpochToSeconds($epoch));
            $deviceId = $deviceNumber !== '' ? $deviceNumber : ($vehicle->gpsDeviceImei ?? 'wheelseye-api');
            $speed = $this->extractNumericValue($item, ['speed', 'gps_speed', 'vehicle_speed']);
            $heading = $this->extractNumericValue($item, ['angle', 'heading', 'course', 'direction']);
            $altitude = $this->extractNumericValue($item, ['altitude', 'alt']);
            $accuracy = $this->extractNumericValue($item, ['accuracy', 'hdop']);
            $satelliteCount = $this->extractIntegerValue($item, ['satellites', 'satellite_count', 'satellite']);
            $odometer = $this->extractNumericValue($item, ['odometer', 'odometer_reading', 'mileage', 'distance']);
            $ignitionStatus = $this->extractBooleanValue($item, [
                'ignition', 'ignition_status', 'ignition_status_flag', 'acc', 'acc_status', 'engine_status', 'engine'
            ]);
            $movementStatus = $this->extractMovementStatus($item, $speed);

            $tracking = new GPSTrackingData([
                'vehicle_id' => $vehicle->id,
                'device_id' => $deviceId,
                'latitude' => $lat,
                'longitude' => $lng,
                'altitude' => $altitude,
                'speed' => $speed,
                'heading' => $heading,
                'accuracy' => $accuracy,
                'satellite_count' => $satelliteCount,
                'timestamp' => $timestamp,
                'ignition_status' => $ignitionStatus,
                'movement_status' => $movementStatus,
                'odometer' => $odometer,
                'raw_data' => $item,
            ]);

            $this->gpsTrackingRepository->create($tracking);
            $fuelSavedForVehicle = $this->ingestFuelFromPayload($vehicle, $deviceId, $item, $timestamp);
            if ($fuelSavedForVehicle) {
                $fuelSaved++;
            } else {
                $fuelMissing++;
            }
            $this->tripDetectionService->processTrackingData($vehicle->id, $tracking);
            $synced++;
        }

        return [
            'success' => true,
            'message' => 'Synced ' . $synced . ' location(s) from WheelsEye',
            'synced' => $synced,
            'skipped' => $skipped,
            'fuel_saved' => $fuelSaved,
            'fuel_missing' => $fuelMissing,
            'errors' => $errors,
        ];
    }

    /**
     * Persist fuel reading when WheelsEye current location payload contains fuel metrics.
     */
    private function ingestFuelFromPayload(\App\Models\Vehicle $vehicle, string $deviceId, array $input, string $timestamp): bool
    {
        $fuelLevel = $this->extractNumericValue($input, [
            'fuel_level', 'fuellevel', 'fuel', 'level', 'tank_level', 'tanklevel', 'fuel_liters', 'fuelliters',
            'diesel', 'diesel_level', 'current_fuel', 'currentfuel', 'fuel_qty', 'fuelquantity'
        ]);
        $fuelPercentage = $this->extractNumericValue($input, [
            'fuel_percentage', 'fuelpercentage', 'percentage', 'fuel_percent', 'fuelpercent', 'fuel_pct', 'fuellevelpercent'
        ]);
        $temperature = $this->extractNumericValue($input, ['temperature', 'temp', 'fuel_temp', 'fueltemperature']);
        $voltage = $this->extractNumericValue($input, ['voltage', 'battery_voltage', 'sensor_voltage']);

        if ($fuelLevel === null) {
            $fuelLevel = $this->extractNumericByKeyword($input, ['fuel', 'diesel', 'tank']);
            if ($fuelLevel === null) {
                $fuelLevel = $this->extractNumericByNestedKeywordPath($input, ['fuel', 'diesel', 'tank']);
            }
        }
        if ($fuelPercentage === null) {
            $fuelPercentage = $this->extractNumericByKeyword($input, ['percent']);
            if ($fuelPercentage === null) {
                $fuelPercentage = $this->extractNumericByNestedKeywordPath($input, ['percent']);
            }
        }

        if ($fuelLevel === null && $fuelPercentage === null && $temperature === null && $voltage === null) {
            return false;
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

        if (empty($vehicle->fuelSensorId)) {
            $db = new \App\Core\Database();
            $db->execute("UPDATE vehicles SET fuel_sensor_id = ? WHERE id = ?", [$fuelSensor->id, $vehicle->id]);
            $vehicle->fuelSensorId = $fuelSensor->id;
        }

        $fuelData = new FuelReadingData([
            'vehicle_id' => $vehicle->id,
            'sensor_id' => $sensorId,
            'fuel_level' => $fuelLevel ?? ($fuelPercentage ?? 0),
            'fuel_percentage' => $fuelPercentage,
            'temperature' => $temperature,
            'voltage' => $voltage,
            'timestamp' => $timestamp,
            'raw_data' => $input
        ]);

        $this->fuelReadingRepository->create($fuelData);
        $this->fuelAlertService->checkFuelAlerts($vehicle->id, $fuelData);
        return true;
    }

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
        $clean = trim(str_replace([',', '%'], ['', ''], $value));
        if ($clean === '') {
            return null;
        }
        if (is_numeric($clean)) {
            return (float)$clean;
        }
        if (preg_match('/-?\d+(?:\.\d+)?/', $clean, $matches) === 1) {
            return (float)$matches[0];
        }
        return null;
    }

    private function extractIntegerValue(array $payload, array $targetKeys): ?int
    {
        $numeric = $this->extractNumericValue($payload, $targetKeys);
        return $numeric === null ? null : (int)round($numeric);
    }

    private function extractBooleanValue(array $payload, array $targetKeys): ?bool
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
                    continue;
                }
                if (!isset($lookup[$this->normalizeKey((string)$key)])) {
                    continue;
                }
                $normalized = strtolower(trim((string)$value));
                if ($normalized === '') {
                    continue;
                }
                if (in_array($normalized, ['1', 'true', 'on', 'yes', 'y', 'open'], true)) {
                    return true;
                }
                if (in_array($normalized, ['0', 'false', 'off', 'no', 'n', 'close'], true)) {
                    return false;
                }
                if (is_numeric($normalized)) {
                    return ((float)$normalized) > 0;
                }
            }
        }
        return null;
    }

    private function extractNumericByKeyword(array $payload, array $keywords): ?float
    {
        $normalizedKeywords = array_map(fn(string $k) => $this->normalizeKey($k), $keywords);
        $stack = [$payload];
        while ($stack) {
            $current = array_pop($stack);
            foreach ($current as $key => $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $normalizedKey = $this->normalizeKey((string)$key);
                $matched = false;
                foreach ($normalizedKeywords as $keyword) {
                    if ($keyword !== '' && str_contains($normalizedKey, $keyword)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
                $parsed = $this->parseNumeric((string)$value);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }
        return null;
    }

    private function extractNumericByNestedKeywordPath(array $payload, array $keywords): ?float
    {
        $normalizedKeywords = array_map(fn(string $k) => $this->normalizeKey($k), $keywords);
        return $this->extractNumericByNestedKeywordPathRecursive($payload, $normalizedKeywords, []);
    }

    private function extractNumericByNestedKeywordPathRecursive(array $payload, array $normalizedKeywords, array $parents): ?float
    {
        foreach ($payload as $key => $value) {
            $nextParents = $parents;
            $nextParents[] = $this->normalizeKey((string)$key);
            if (is_array($value)) {
                $nested = $this->extractNumericByNestedKeywordPathRecursive($value, $normalizedKeywords, $nextParents);
                if ($nested !== null) {
                    return $nested;
                }
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $hasKeywordInPath = false;
            foreach ($normalizedKeywords as $keyword) {
                if ($keyword === '') {
                    continue;
                }
                foreach ($nextParents as $pathKey) {
                    if (str_contains($pathKey, $keyword)) {
                        $hasKeywordInPath = true;
                        break 2;
                    }
                }
            }
            if (!$hasKeywordInPath) {
                continue;
            }
            $parsed = $this->parseNumeric((string)$value);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        return null;
    }

    private function extractMovementStatus(array $payload, ?float $speed): string
    {
        $movement = $this->extractStringValue($payload, ['movement_status', 'movement', 'status', 'motion_status']);
        if ($movement !== null) {
            $normalized = strtolower(trim($movement));
            if (in_array($normalized, ['moving', 'movement', 'running', 'drive'], true)) {
                return 'moving';
            }
            if (in_array($normalized, ['idle', 'idling'], true)) {
                return 'idle';
            }
            if (in_array($normalized, ['stop', 'stopped', 'stationary', 'parked', 'parking'], true)) {
                return 'stationary';
            }
        }
        if ($speed !== null) {
            return $speed > 3 ? 'moving' : ($speed > 0 ? 'idle' : 'stationary');
        }
        return 'stationary';
    }

    private function extractStringValue(array $payload, array $targetKeys): ?string
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
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                if (!isset($lookup[$this->normalizeKey((string)$key)])) {
                    continue;
                }
                $stringValue = trim((string)$value);
                if ($stringValue !== '') {
                    return $stringValue;
                }
            }
        }
        return null;
    }

    /**
     * WheelsEye can send epoch in seconds, milliseconds, or date string.
     */
    private function normalizeEpochToSeconds($value): int
    {
        if (is_numeric($value)) {
            $epoch = (int)$value;
            if ($epoch > 9999999999) {
                $epoch = (int)floor($epoch / 1000);
            }
            return $epoch > 0 ? $epoch : time();
        }
        if (is_string($value) && trim($value) !== '') {
            $parsed = strtotime($value);
            if ($parsed !== false) {
                return $parsed;
            }
        }
        return time();
    }
}
