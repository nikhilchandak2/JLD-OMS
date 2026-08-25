<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ForecastAuthorizationException;
use App\Services\ForecastException;
use App\Services\ForecastService;

class ForecastController
{
    private AuthService $authService;
    private ForecastService $forecasts;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->forecasts = new ForecastService();
    }

    public function meta(): void
    {
        $this->run(fn(array $actor) => ['data' => $this->forecasts->meta($actor)]);
    }

    public function worksheet(): void
    {
        $this->run(function (array $actor) {
            $asOf = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

            return ['data' => $this->forecasts->worksheet($actor, isset($_GET['year_month']) ? (string)$_GET['year_month'] : null, $asOf)];
        });
    }

    public function actuals(): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->forecasts->actuals($actor, isset($_GET['year_month']) ? (string)$_GET['year_month'] : null),
        ]);
    }

    public function openPeriod(): void
    {
        $this->run(function (array $actor) {
            $ym = (string)($this->input()['year_month'] ?? '');
            $row = $this->forecasts->openPeriod($ym, $actor);
            http_response_code(201);

            return ['data' => $row, 'message' => 'Period opened.'];
        });
    }

    public function lockPeriod(string $id): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->forecasts->lockPeriod((int)$id, $actor),
            'message' => 'Period locked. Edits are closed.',
        ]);
    }

    public function saveParty(string $periodId, string $partyId): void
    {
        $this->run(function (array $actor) use ($periodId, $partyId) {
            $lines = $this->input()['lines'] ?? [];
            if (!is_array($lines)) {
                throw new ForecastException('lines must be a list.');
            }

            return [
                'data' => $this->forecasts->savePartyLines((int)$periodId, (int)$partyId, $lines, $actor),
                'message' => 'Saved.',
            ];
        });
    }

    private function input(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = $raw === false || $raw === '' ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : $_POST;
    }

    private function run(callable $handler): void
    {
        header('Content-Type: application/json');
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        $actor = ['id' => (int)$user['id'], 'role' => $user['role'] ?? null];
        try {
            $payload = $handler($actor);
            echo json_encode(array_merge(['success' => true], $payload));
        } catch (ForecastAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (ForecastException $e) {
            http_response_code(400);
            echo json_encode(array_merge(['error' => $e->getMessage()], $e->getDetails()));
        } catch (\Throwable $e) {
            error_log($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process the request', 'message' => $e->getMessage()]);
        }
    }
}
