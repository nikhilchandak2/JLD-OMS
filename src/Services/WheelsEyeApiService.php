<?php

namespace App\Services;

use App\Core\Database;
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
    private Database $database;

    public function __construct()
    {
        $this->vehicleRepository = new VehicleRepository();
        $this->gpsDeviceRepository = new GPSDeviceRepository();
        $this->gpsTrackingRepository = new GPSTrackingRepository();
        $this->tripDetectionService = new TripDetectionService();
        $this->fuelSensorRepository = new FuelSensorRepository();
        $this->fuelReadingRepository = new FuelReadingRepository();
        $this->fuelAlertService = new FuelAlertService();
        $this->database = new Database();
    }

    /**
     * Fetch current locations from WheelsEye and save to database.
     * Matches vehicles by vehicle_number or by device IMEI (deviceNumber).
     *
     * @return array{success: bool, message: string, synced: int, skipped: int, fuel_saved: int, fuel_missing: int, errors: array}
     */
    public function syncCurrentLocations(): array
    {
        $tripCountsBefore = $this->getTripStatusCounts();
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
        $vehiclesSynced = 0;
        $skipped = 0;
        $fuelSaved = 0;
        $fuelMissing = 0;
        $errors = [];

        foreach ($list as $item) {
            $vehicleNumber = $item['vehicleNumber'] ?? null;
            $deviceNumber = (string)($item['deviceNumber'] ?? '');
            $vehicle = $this->resolveVehicleFromPayloadIdentifiers($item, $vehicleNumber, $deviceNumber);
            if (!$vehicle) {
                $errors[] = 'No vehicle in OMS for: ' . ($vehicleNumber ?: 'device ' . $deviceNumber);
                $skipped++;
                continue;
            }

            $deviceId = $deviceNumber !== '' ? $deviceNumber : ($vehicle->gpsDeviceImei ?? 'wheelseye-api');
            $points = $this->extractTrackingPointsFromPayload($item);
            if (empty($points)) {
                $skipped++;
                continue;
            }

            $savedForVehicle = 0;
            foreach ($points as $point) {
                $tracking = new GPSTrackingData([
                    'vehicle_id' => $vehicle->id,
                    'device_id' => $deviceId,
                    'latitude' => $point['latitude'],
                    'longitude' => $point['longitude'],
                    'altitude' => $point['altitude'],
                    'speed' => $point['speed'],
                    'heading' => $point['heading'],
                    'accuracy' => $point['accuracy'],
                    'satellite_count' => $point['satellite_count'],
                    'timestamp' => $point['timestamp'],
                    'ignition_status' => $point['ignition_status'],
                    'movement_status' => $point['movement_status'],
                    'odometer' => $point['odometer'],
                    'raw_data' => $point['raw_data'],
                ]);

                if (!$this->isDuplicateTrackingPoint($vehicle->id, $tracking)) {
                    $this->gpsTrackingRepository->create($tracking);
                    $this->tripDetectionService->processTrackingData($vehicle->id, $tracking);
                    $synced++;
                    $savedForVehicle++;
                }
            }

            if ($savedForVehicle > 0) {
                $vehiclesSynced++;
            } else {
                $skipped++;
            }

            $latestPayload = $points[count($points) - 1]['raw_data'] ?? $item;
            $latestTimestamp = $points[count($points) - 1]['timestamp'] ?? date('Y-m-d H:i:s');
            $fuelSavedForVehicle = $this->ingestFuelFromPayload($vehicle, $deviceId, is_array($latestPayload) ? $latestPayload : $item, $latestTimestamp);
            if ($fuelSavedForVehicle) {
                $fuelSaved++;
            } else {
                $fuelMissing++;
            }
        }

        $tripCountsAfter = $this->getTripStatusCounts();
        $tripCountDelta = [
            'total' => max(0, $tripCountsAfter['total'] - $tripCountsBefore['total']),
            'in_progress' => max(0, $tripCountsAfter['in_progress'] - $tripCountsBefore['in_progress']),
            'completed' => max(0, $tripCountsAfter['completed'] - $tripCountsBefore['completed']),
            'cancelled' => max(0, $tripCountsAfter['cancelled'] - $tripCountsBefore['cancelled']),
        ];

        return [
            'success' => true,
            'message' => 'Synced ' . $synced . ' GPS point(s) across ' . $vehiclesSynced . ' vehicle(s) from WheelsEye',
            'synced' => $synced,
            'vehicles_synced' => $vehiclesSynced,
            'skipped' => $skipped,
            'fuel_saved' => $fuelSaved,
            'fuel_missing' => $fuelMissing,
            'trip_count_delta' => $tripCountDelta,
            'errors' => $errors,
        ];
    }

    /**
     * Snapshot trip counts by status so each sync run can report trip increments.
     *
     * @return array{total:int,in_progress:int,completed:int,cancelled:int}
     */
    private function getTripStatusCounts(): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
            FROM vehicle_trips
        ";
        $row = $this->database->fetch($sql) ?? [];
        return [
            'total' => (int)($row['total'] ?? 0),
            'in_progress' => (int)($row['in_progress'] ?? 0),
            'completed' => (int)($row['completed'] ?? 0),
            'cancelled' => (int)($row['cancelled'] ?? 0),
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

    /**
     * Extract all track points available in payload (single-point or history/path arrays).
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractTrackingPointsFromPayload(array $payload): array
    {
        $candidates = [];
        $this->collectPointCandidates($payload, $candidates, 0);
        if (empty($candidates)) {
            return [];
        }

        $points = [];
        foreach ($candidates as $candidate) {
            $context = array_merge($payload, $candidate);
            $lat = $this->extractNumericValue($context, ['latitude', 'lat']);
            $lng = $this->extractNumericValue($context, ['longitude', 'lng', 'lon', 'long']);
            if ($lat === null || $lng === null) {
                continue;
            }
            if (!$this->isValidCoordinate($lat, $lng)) {
                continue;
            }

            $epoch = $context['dttimeInEpoch'] ?? $context['createdDate'] ?? $context['timestamp'] ?? $context['time'] ?? null;
            $timestamp = date('Y-m-d H:i:s', $this->normalizeEpochToSeconds($epoch ?? time()));
            $speed = $this->extractNumericValue($context, ['speed', 'gps_speed', 'vehicle_speed']);

            $points[] = [
                'latitude' => $lat,
                'longitude' => $lng,
                'altitude' => $this->extractNumericValue($context, ['altitude', 'alt']),
                'speed' => $speed,
                'heading' => $this->extractNumericValue($context, ['angle', 'heading', 'course', 'direction']),
                'accuracy' => $this->extractNumericValue($context, ['accuracy', 'hdop']),
                'satellite_count' => $this->extractIntegerValue($context, ['satellites', 'satellite_count', 'satellite']),
                'timestamp' => $timestamp,
                'ignition_status' => $this->extractBooleanValue($context, [
                    'ignition', 'ignition_status', 'ignition_status_flag', 'acc', 'acc_status', 'engine_status', 'engine'
                ]),
                'movement_status' => $this->extractMovementStatus($context, $speed),
                'odometer' => $this->extractNumericValue($context, ['odometer', 'odometer_reading', 'mileage', 'distance']),
                'raw_data' => $context,
            ];
        }

        if (empty($points)) {
            return [];
        }

        usort($points, fn(array $a, array $b) => strcmp($a['timestamp'], $b['timestamp']));

        $deduped = [];
        $seen = [];
        foreach ($points as $point) {
            $key = $point['timestamp'] . '|' . round((float)$point['latitude'], 6) . '|' . round((float)$point['longitude'], 6);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $point;
        }

        return $deduped;
    }

    /**
     * Recursively collect arrays that look like track points (have lat/lng).
     *
     * @param array<int, array<string, mixed>> $out
     */
    private function collectPointCandidates($node, array &$out, int $depth): void
    {
        if ($depth > 8 || !is_array($node)) {
            return;
        }

        if ($this->arrayLooksLikePoint($node)) {
            $out[] = $node;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectPointCandidates($value, $out, $depth + 1);
            }
        }
    }

    private function arrayLooksLikePoint(array $data): bool
    {
        $lat = $this->extractNumericValue($data, ['latitude', 'lat']);
        $lng = $this->extractNumericValue($data, ['longitude', 'lng', 'lon', 'long']);
        return $lat !== null && $lng !== null;
    }

    private function isValidCoordinate(float $lat, float $lng): bool
    {
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    private function isDuplicateTrackingPoint(int $vehicleId, GPSTrackingData $tracking): bool
    {
        $sql = "
            SELECT id
            FROM gps_tracking_data
            WHERE vehicle_id = ?
              AND timestamp = ?
              AND ABS(latitude - ?) < 0.000001
              AND ABS(longitude - ?) < 0.000001
            LIMIT 1
        ";
        $row = $this->database->fetch($sql, [
            $vehicleId,
            $tracking->timestamp,
            $tracking->latitude,
            $tracking->longitude
        ]);
        return $row !== null;
    }

    private function resolveVehicleFromPayloadIdentifiers(array $item, ?string $vehicleNumber, string $deviceNumber): ?\App\Models\Vehicle
    {
        $identifiers = $this->extractVehicleIdentifiers($item, $vehicleNumber, $deviceNumber);

        foreach ($identifiers['device'] as $deviceId) {
            $vehicle = $this->vehicleRepository->findByGpsDeviceImei($deviceId);
            if ($vehicle) {
                return $vehicle;
            }
        }

        foreach ($identifiers['vehicle'] as $number) {
            $vehicle = $this->vehicleRepository->findByVehicleNumber($number);
            if ($vehicle) {
                return $vehicle;
            }
        }

        foreach ($identifiers['vehicle'] as $number) {
            $vehicle = $this->vehicleRepository->findByNumberOrRegistrationFuzzy($number);
            if ($vehicle) {
                return $vehicle;
            }
        }

        return null;
    }

    /**
     * Extract plausible vehicle/device identifiers from payload keys used by WheelsEye variants.
     *
     * @return array{vehicle: string[], device: string[]}
     */
    private function extractVehicleIdentifiers(array $item, ?string $vehicleNumber, string $deviceNumber): array
    {
        $vehicleIds = [];
        $deviceIds = [];

        foreach ([$vehicleNumber, $item['registrationNo'] ?? null, $item['registration_number'] ?? null, $item['vehicleNo'] ?? null, $item['vehicleno'] ?? null, $item['truckNo'] ?? null] as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }
            $val = trim((string)$candidate);
            if ($val !== '') {
                $vehicleIds[] = $val;
            }
        }

        foreach ([$deviceNumber, $item['imei'] ?? null, $item['device_id'] ?? null, $item['trackerId'] ?? null, $item['tracker_id'] ?? null] as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }
            $val = trim((string)$candidate);
            if ($val !== '') {
                $deviceIds[] = $val;
            }
        }

        // Deep-scan for additional string IDs in nested objects.
        $stack = [$item];
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
                $k = strtolower((string)$key);
                $v = trim((string)$value);
                if ($v === '') {
                    continue;
                }
                if (str_contains($k, 'imei') || str_contains($k, 'device')) {
                    $deviceIds[] = $v;
                } elseif (str_contains($k, 'vehicle') || str_contains($k, 'truck') || str_contains($k, 'reg')) {
                    $vehicleIds[] = $v;
                }
            }
        }

        return [
            'vehicle' => array_values(array_unique($vehicleIds)),
            'device' => array_values(array_unique($deviceIds)),
        ];
    }
}
