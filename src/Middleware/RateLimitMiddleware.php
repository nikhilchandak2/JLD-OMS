<?php

namespace App\Middleware;

class RateLimitMiddleware
{
    private const STORAGE_FILE = __DIR__ . '/../../storage/rate_limits.json';

    public function handle(): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $rule = $this->resolveRule();
        if ($rule === null) {
            return true;
        }

        $identifier = $this->resolveClientIdentifier();
        $now = time();
        $windowSeconds = $rule['window_seconds'];
        $maxRequests = $rule['max_requests'];
        $windowStart = (int)(floor($now / $windowSeconds) * $windowSeconds);
        $resetAt = $windowStart + $windowSeconds;

        $store = $this->readStore();
        $key = $rule['key'] . '|' . $identifier;
        $entry = $store[$key] ?? ['window_start' => $windowStart, 'count' => 0];

        if ((int)($entry['window_start'] ?? 0) !== $windowStart) {
            $entry = ['window_start' => $windowStart, 'count' => 0];
        }

        $currentCount = (int)($entry['count'] ?? 0);
        if ($currentCount >= $maxRequests) {
            $retryAfter = max(1, $resetAt - $now);
            $this->writeStore($store);
            $this->sendRateLimitHeaders($maxRequests, 0, $resetAt);
            header('Retry-After: ' . $retryAfter);
            http_response_code(429);
            echo json_encode(['error' => 'Too many requests. Please retry later.']);
            return false;
        }

        $entry['count'] = $currentCount + 1;
        $store[$key] = $entry;
        $this->pruneStore($store, $now);
        $this->writeStore($store);

        $remaining = max(0, $maxRequests - (int)$entry['count']);
        $this->sendRateLimitHeaders($maxRequests, $remaining, $resetAt);
        return true;
    }

    private function isEnabled(): bool
    {
        $raw = strtolower(trim((string)($_ENV['RATE_LIMIT_ENABLED'] ?? '1')));
        return !in_array($raw, ['0', 'false', 'off', 'no'], true);
    }

    private function resolveRule(): ?array
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $routeKey = $method . ' ' . $path;

        $rules = [
            'POST /api/login' => [
                'key' => 'login',
                'max_requests' => $this->readIntEnv('RATE_LIMIT_LOGIN_MAX', 8, 1, 1000),
                'window_seconds' => $this->readIntEnv('RATE_LIMIT_LOGIN_WINDOW_SECONDS', 300, 10, 86400),
            ],
            'POST /api/gps/webhook' => [
                'key' => 'gps_webhook',
                'max_requests' => $this->readIntEnv('RATE_LIMIT_GPS_WEBHOOK_MAX', 300, 1, 100000),
                'window_seconds' => $this->readIntEnv('RATE_LIMIT_GPS_WEBHOOK_WINDOW_SECONDS', 60, 1, 86400),
            ],
            'POST /api/gps/batch' => [
                'key' => 'gps_batch',
                'max_requests' => $this->readIntEnv('RATE_LIMIT_GPS_BATCH_MAX', 120, 1, 100000),
                'window_seconds' => $this->readIntEnv('RATE_LIMIT_GPS_BATCH_WINDOW_SECONDS', 60, 1, 86400),
            ],
            'POST /api/fuel/reports/upload' => [
                'key' => 'fuel_report_upload',
                'max_requests' => $this->readIntEnv('RATE_LIMIT_FUEL_UPLOAD_MAX', 20, 1, 1000),
                'window_seconds' => $this->readIntEnv('RATE_LIMIT_FUEL_UPLOAD_WINDOW_SECONDS', 300, 10, 86400),
            ],
            'POST /api/crm/receivables/import' => [
                'key' => 'receivables_import',
                'max_requests' => $this->readIntEnv('RATE_LIMIT_RECEIVABLE_IMPORT_MAX', 20, 1, 1000),
                'window_seconds' => $this->readIntEnv('RATE_LIMIT_RECEIVABLE_IMPORT_WINDOW_SECONDS', 300, 10, 86400),
            ],
            'POST /api/parties/import' => [
                'key' => 'parties_import',
                'max_requests' => $this->readIntEnv('RATE_LIMIT_PARTIES_IMPORT_MAX', 20, 1, 1000),
                'window_seconds' => $this->readIntEnv('RATE_LIMIT_PARTIES_IMPORT_WINDOW_SECONDS', 300, 10, 86400),
            ],
        ];

        return $rules[$routeKey] ?? null;
    }

    private function resolveClientIdentifier(): string
    {
        $forwardedFor = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwardedFor !== '') {
            $parts = explode(',', $forwardedFor);
            $clientIp = trim($parts[0]);
            if ($clientIp !== '') {
                return $clientIp;
            }
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    private function readStore(): array
    {
        $this->ensureStorageDirectory();
        if (!file_exists(self::STORAGE_FILE)) {
            return [];
        }

        $contents = file_get_contents(self::STORAGE_FILE);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeStore(array $store): void
    {
        $this->ensureStorageDirectory();
        file_put_contents(self::STORAGE_FILE, json_encode($store), LOCK_EX);
    }

    private function ensureStorageDirectory(): void
    {
        $dir = dirname(self::STORAGE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function pruneStore(array &$store, int $now): void
    {
        if (count($store) < 5000) {
            return;
        }

        $threshold = $now - 86400;
        foreach ($store as $key => $entry) {
            $start = (int)($entry['window_start'] ?? 0);
            if ($start < $threshold) {
                unset($store[$key]);
            }
        }
    }

    private function sendRateLimitHeaders(int $limit, int $remaining, int $resetAt): void
    {
        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . $resetAt);
    }

    private function readIntEnv(string $name, int $default, int $min, int $max): int
    {
        $value = isset($_ENV[$name]) ? (int)$_ENV[$name] : $default;
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }
}

