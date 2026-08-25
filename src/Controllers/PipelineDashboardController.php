<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\PipelineDashboardAuthorizationException;
use App\Services\PipelineDashboardException;
use App\Services\PipelineDashboardService;

class PipelineDashboardController
{
    private AuthService $authService;
    private PipelineDashboardService $pipeline;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->pipeline = new PipelineDashboardService();
    }

    public function show(): void
    {
        $this->run(fn(array $actor) => ['data' => $this->pipeline->dashboard($actor, $this->filters())]);
    }

    public function export(): void
    {
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        $actor = ['id' => (int)$user['id'], 'role' => $user['role'] ?? null];
        try {
            $file = $this->pipeline->excelBytes($actor, $this->filters());
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
            header('Content-Length: ' . strlen($file['bytes']));
            header('Cache-Control: private, max-age=0, must-revalidate');
            echo $file['bytes'];
        } catch (PipelineDashboardAuthorizationException $e) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        } catch (PipelineDashboardException $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Failed to export pipeline']);
        }
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        $out = [];
        foreach (['owner_user_id', 'grade_code', 'date_from', 'date_to'] as $key) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $out[$key] = $_GET[$key];
            }
        }

        return $out;
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
        } catch (PipelineDashboardAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (PipelineDashboardException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process the request', 'message' => $e->getMessage()]);
        }
    }
}
