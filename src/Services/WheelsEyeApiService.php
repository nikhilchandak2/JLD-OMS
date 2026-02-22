<?php

namespace App\Services;

use App\Models\GPSTrackingData;
use App\Repositories\VehicleRepository;
use App\Repositories\GPSDeviceRepository;
use App\Repositories\GPSTrackingRepository;
use App\Services\TripDetectionService;

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

    public function __construct()
    {
        $this->vehicleRepository = new VehicleRepository();
        $this->gpsDeviceRepository = new GPSDeviceRepository();
        $this->gpsTrackingRepository = new GPSTrackingRepository();
        $this->tripDetectionService = new TripDetectionService();
    }

    /**
     * Fetch current locations from WheelsEye and save to database.
     * Matches vehicles by vehicle_number or by device IMEI (deviceNumber).
     *
     * @return array{success: bool, message: string, synced: int, skipped: int, errors: array}
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
        $errors = [];

        foreach ($list as $item) {
            $vehicleNumber = $item['vehicleNumber'] ?? null;
            $deviceNumber = (string)($item['deviceNumber'] ?? '');
            $lat = isset($item['latitude']) ? (float)$item['latitude'] : 0.0;
            $lng = isset($item['longitude']) ? (float)$item['longitude'] : 0.0;
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
            $timestamp = date('Y-m-d H:i:s', (int)$epoch);
            $deviceId = $deviceNumber !== '' ? $deviceNumber : ($vehicle->gpsDeviceImei ?? 'wheelseye-api');

            $tracking = new GPSTrackingData([
                'vehicle_id' => $vehicle->id,
                'device_id' => $deviceId,
                'latitude' => $lat,
                'longitude' => $lng,
                'speed' => isset($item['speed']) ? (float)$item['speed'] : null,
                'heading' => isset($item['angle']) ? (float)$item['angle'] : null,
                'timestamp' => $timestamp,
                'ignition_status' => isset($item['ignition']) ? (bool)$item['ignition'] : null,
                'movement_status' => (!empty($item['speed']) && (float)$item['speed'] > 0) ? 'moving' : 'stationary',
                'raw_data' => $item,
            ]);

            $this->gpsTrackingRepository->create($tracking);
            $this->tripDetectionService->processTrackingData($vehicle->id, $tracking);
            $synced++;
        }

        return [
            'success' => true,
            'message' => 'Synced ' . $synced . ' location(s) from WheelsEye',
            'synced' => $synced,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
