<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\VehicleRepository;

class WheelsEyeHistoricalTripsService
{
    private const DEFAULT_BASE_URL = 'https://api.wheelseye.com';

    private Database $database;
    private VehicleRepository $vehicleRepository;
    private GeofenceService $geofenceService;

    public function __construct()
    {
        $this->database = new Database();
        $this->vehicleRepository = new VehicleRepository();
        $this->geofenceService = new GeofenceService();
    }

    /**
     * Sync yesterday's trip segments into `vehicle_trips` for a single OMS vehicle id.
     *
     * NOTE: This depends on the WheelsEye vendor having itinerary + path-detail endpoints.
     *
     * @return array{success: bool, inserted: int, skipped: int, errors: array, last_run: string}
     */
    public function syncYesterdayTrips(int $vehicleId): array
    {
        if ($vehicleId <= 0) {
            return [
                'success' => false,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => ['Invalid vehicle_id'],
                'last_run' => date('Y-m-d H:i:s'),
            ];
        }

        $vehicle = $this->vehicleRepository->findById($vehicleId);
        if (!$vehicle) {
            return [
                'success' => false,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => ['Vehicle not found in OMS'],
                'last_run' => date('Y-m-d H:i:s'),
            ];
        }

        // `public/index.php` and the cron script set timezone from APP_TIMEZONE.
        $yesterday = new \DateTime('yesterday');
        $startDate = $yesterday->format('Y-m-d') . ' 00:00:00';
        $endDate = $yesterday->format('Y-m-d') . ' 23:59:59';

        try {
            $itineraryJson = $this->fetchItinerary($vehicleId, $vehicle->vehicleNumber, $startDate, $endDate);
            $segments = $this->extractSegmentsFromItinerary($itineraryJson);

            if (empty($segments)) {
                return [
                    'success' => true,
                    'inserted' => 0,
                    'skipped' => 0,
                    'errors' => [],
                    'last_run' => date('Y-m-d H:i:s'),
                ];
            }

            $inserted = 0;
            $skipped = 0;
            $errors = [];

            foreach ($segments as $seg) {
                $fromTimeRaw = $seg['fromTime'] ?? null;
                $toTimeRaw = $seg['toTime'] ?? null;
                if (!$fromTimeRaw || !$toTimeRaw) {
                    $skipped++;
                    continue;
                }

                $fromTime = $this->normalizeToDateTimeString($fromTimeRaw);
                $toTime = $this->normalizeToDateTimeString($toTimeRaw);
                if (!$fromTime || !$toTime) {
                    $skipped++;
                    continue;
                }

                try {
                    $pathDetailJson = $this->fetchPathDetail($vehicleId, $vehicle->vehicleNumber, $fromTimeRaw, $toTimeRaw);
                    $polyline = $this->extractFollowedPolyLine($pathDetailJson);
                    if (!$polyline) {
                        $skipped++;
                        continue;
                    }

                    $points = $this->decodePolyline($polyline);
                    if (empty($points)) {
                        $skipped++;
                        continue;
                    }

                    $start = $points[0];
                    $end = $points[count($points) - 1];
                    if (!isset($start['lat'], $start['lng'], $end['lat'], $end['lng'])) {
                        $skipped++;
                        continue;
                    }

                    [$sourceGeofenceId, $destGeofenceId, $tripType, $materialType] = $this->detectTripGeofences(
                        (float)$start['lat'],
                        (float)$start['lng'],
                        (float)$end['lat'],
                        (float)$end['lng']
                    );

                    $status = 'completed';

                    if ($this->vehicleTripExists($vehicleId, $fromTime, $toTime)) {
                        $skipped++;
                        continue;
                    }

                    $sql = "
                        INSERT INTO vehicle_trips
                        (vehicle_id, trip_type, source_geofence_id, destination_geofence_id, material_type,
                         start_time, end_time, start_latitude, start_longitude, end_latitude, end_longitude, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ";

                    $this->database->execute($sql, [
                        $vehicleId,
                        $tripType,
                        $sourceGeofenceId,
                        $destGeofenceId,
                        $materialType,
                        $fromTime,
                        $toTime,
                        (float)$start['lat'],
                        (float)$start['lng'],
                        (float)$end['lat'],
                        (float)$end['lng'],
                        $status,
                    ]);

                    $inserted++;
                } catch (\Throwable $e) {
                    $errors[] = 'segment [' . (string)$fromTimeRaw . ' -> ' . (string)$toTimeRaw . ']: ' . $e->getMessage();
                }
            }

            return [
                'success' => true,
                'inserted' => $inserted,
                'skipped' => $skipped,
                'errors' => $errors,
                'last_run' => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => [$e->getMessage()],
                'last_run' => date('Y-m-d H:i:s'),
            ];
        }
    }

    private function fetchItinerary(int $vehicleId, string $vehicleNumber, string $startDate, string $endDate): array
    {
        $token = $_ENV['WHEELSEYE_ACCESS_TOKEN'] ?? 'b6fbb5d6-fc43-44e9-884a-4323c0d56df3';
        $baseUrl = rtrim($_ENV['WHEELSEYE_API_BASE_URL'] ?? self::DEFAULT_BASE_URL, '/');

        $path = $_ENV['WHEELSEYE_ITINERARY_PATH'] ?? '/getItinerary';
        $url = $baseUrl . $path;

        $query = [
            'accessToken' => $token,
            'vehicleId' => $vehicleId,
            'vehicleNumber' => $vehicleNumber,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];

        $response = $this->httpGetJson($url, $query);
        return $response;
    }

    private function fetchPathDetail(int $vehicleId, string $vehicleNumber, mixed $fromTimeRaw, mixed $toTimeRaw): array
    {
        $token = $_ENV['WHEELSEYE_ACCESS_TOKEN'] ?? 'b6fbb5d6-fc43-44e9-884a-4323c0d56df3';
        $baseUrl = rtrim($_ENV['WHEELSEYE_API_BASE_URL'] ?? self::DEFAULT_BASE_URL, '/');

        $path = $_ENV['WHEELSEYE_PATH_DETAIL_PATH'] ?? '/getPathDetail';
        $url = $baseUrl . $path;

        $query = [
            'accessToken' => $token,
            'vehicleId' => $vehicleId,
            'vehicleNumber' => $vehicleNumber,
            'fromTime' => $fromTimeRaw,
            'toTime' => $toTimeRaw,
        ];

        $response = $this->httpGetJson($url, $query);
        return $response;
    }

    /**
     * Extract itinerary segments (fromTime + toTime) recursively.
     *
     * @return array<int, array{fromTime: mixed, toTime: mixed}>
     */
    private function extractSegmentsFromItinerary(array $payload): array
    {
        $segments = [];
        $this->collectSegments($payload, $segments, 0);

        // De-dupe by raw pair.
        $seen = [];
        $out = [];
        foreach ($segments as $s) {
            $k = (string)($s['fromTime'] ?? '') . '|' . (string)($s['toTime'] ?? '');
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $s;
        }

        return $out;
    }

    private function collectSegments($node, array &$out, int $depth): void
    {
        if ($depth > 10 || !is_array($node)) {
            return;
        }

        $from = $this->firstMatchingValue($node, ['fromTime', 'from_time', 'startTime', 'start_time', 'from', 'beginTime']);
        $to = $this->firstMatchingValue($node, ['toTime', 'to_time', 'endTime', 'end_time', 'to', 'end']);
        if ($from !== null && $to !== null) {
            $out[] = ['fromTime' => $from, 'toTime' => $to];
        }

        foreach ($node as $v) {
            if (is_array($v)) {
                $this->collectSegments($v, $out, $depth + 1);
            }
        }
    }

    private function firstMatchingValue(array $node, array $keys): mixed
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $node)) {
                $val = $node[$k];
                if ($val !== null && $val !== '') {
                    return $val;
                }
            }
        }
        return null;
    }

    /**
     * Extract the followed polyline string from the JSON payload.
     */
    private function extractFollowedPolyLine(array $payload): ?string
    {
        $keys = [
            'followedPolyLine',
            'followedPolyline',
            'followed_polyline',
            'followedPolyLineEncoded',
            'followed_polyline_encoded',
            'polyline',
            'routePolyline',
        ];

        return $this->extractFirstStringByKeys($payload, $keys, 0);
    }

    private function extractFirstStringByKeys($node, array $keys, int $depth): ?string
    {
        if ($depth > 12 || !is_array($node)) {
            return null;
        }

        foreach ($keys as $k) {
            if (array_key_exists($k, $node)) {
                $v = $node[$k];
                if (is_string($v) && trim($v) !== '') {
                    return $v;
                }
            }
        }

        foreach ($node as $v) {
            if (is_array($v)) {
                $res = $this->extractFirstStringByKeys($v, $keys, $depth + 1);
                if ($res !== null) {
                    return $res;
                }
            }
        }

        return null;
    }

    /**
     * Decode a standard Google-encoded polyline string into an array of points.
     *
     * @return array<int, array{lat: float, lng: float}>
     */
    private function decodePolyline(mixed $polyline): array
    {
        // If API already returns explicit points, accept them.
        if (is_array($polyline)) {
            $points = [];
            foreach ($polyline as $p) {
                if (!is_array($p)) {
                    continue;
                }
                if (isset($p['lat'], $p['lng'])) {
                    $points[] = ['lat' => (float)$p['lat'], 'lng' => (float)$p['lng']];
                } elseif (count($p) >= 2 && isset($p[0], $p[1])) {
                    // Assume [lat, lng]
                    $points[] = ['lat' => (float)$p[0], 'lng' => (float)$p[1]];
                }
            }
            return $points;
        }

        if (!is_string($polyline) || trim($polyline) === '') {
            return [];
        }

        $encoded = trim($polyline);
        $precision = (int)($_ENV['WHEELSEYE_POLYLINE_PRECISION'] ?? 5);
        if ($precision <= 0) {
            $precision = 5;
        }

        $index = 0;
        $lat = 0;
        $lng = 0;
        $len = strlen($encoded);
        $points = [];
        $factor = pow(10, $precision);

        while ($index < $len) {
            $result = 0;
            $shift = 0;
            do {
                if ($index >= $len) {
                    break 2;
                }
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20);

            $dlat = (($result & 1) !== 0) ? ~($result >> 1) : ($result >> 1);
            $lat += $dlat;

            $result = 0;
            $shift = 0;
            do {
                if ($index >= $len) {
                    break 2;
                }
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20);

            $dlng = (($result & 1) !== 0) ? ~($result >> 1) : ($result >> 1);
            $lng += $dlng;

            $points[] = [
                'lat' => $lat / $factor,
                'lng' => $lng / $factor,
            ];
        }

        return $points;
    }

    /**
     * Determine pit->stockpile classification by checking which geofences contain start/end.
     *
     * @return array{0: ?int, 1: ?int, 2: string, 3: ?string}
     */
    private function detectTripGeofences(float $startLat, float $startLng, float $endLat, float $endLng): array
    {
        $active = $this->geofenceService->getActiveGeofences();

        $startPitId = null;
        $endDestId = null;
        $endMaterial = null;

        foreach ($active as $g) {
            $geofenceType = strtolower(trim((string)($g['geofence_type'] ?? '')));

            if ($this->geofenceService->containsPoint($startLat, $startLng, $g) && $geofenceType === 'pit') {
                $startPitId = (int)$g['id'];
            }

            if ($this->geofenceService->containsPoint($endLat, $endLng, $g) && $geofenceType !== 'pit') {
                $endDestId = (int)$g['id'];
                $endMaterial = $g['material_type'] ?? null;
            }
        }

        if ($startPitId !== null && $endDestId !== null) {
            return [$startPitId, $endDestId, 'pit_to_stockpile', $endMaterial];
        }

        return [$startPitId, $endDestId, 'other', $endMaterial];
    }

    private function vehicleTripExists(int $vehicleId, string $startTime, string $endTime): bool
    {
        $sql = "
            SELECT id
            FROM vehicle_trips
            WHERE vehicle_id = ?
              AND start_time = ?
              AND end_time = ?
            LIMIT 1
        ";
        $row = $this->database->fetch($sql, [$vehicleId, $startTime, $endTime]);
        return $row !== null;
    }

    private function normalizeToDateTimeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $epoch = (int)$value;
            if ($epoch > 9999999999) {
                $epoch = (int)floor($epoch / 1000);
            }
            if ($epoch <= 0) {
                return null;
            }
            return date('Y-m-d H:i:s', $epoch);
        }

        $parsed = strtotime((string)$value);
        if ($parsed === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $parsed);
    }

    private function httpGetJson(string $url, array $query): array
    {
        $qs = http_build_query($query);
        $fullUrl = strpos($url, '?') === false ? ($url . '?' . $qs) : ($url . '&' . $qs);

        $ch = curl_init($fullUrl);
        if ($ch === false) {
            throw new \RuntimeException('cURL init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('WheelsEye request failed: ' . ($err ?: 'unknown error'));
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('WheelsEye request returned HTTP ' . $status);
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            throw new \RuntimeException('WheelsEye response is not valid JSON');
        }
        return $json;
    }
}

