<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\PipelineAuthorizationException;
use App\Services\PipelineException;
use App\Services\TechnicalFlagService;

/**
 * HTTP layer for technical flags. Queue-routed only: there is no per-person assignment
 * endpoint, by design (B4).
 */
class CrmTechnicalFlagController
{
    private AuthService $authService;
    private TechnicalFlagService $flagService;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->flagService = new TechnicalFlagService();
    }

    public function index(): void
    {
        $this->run(function (array $actor) {
            $filters = [];
            foreach (['queue_id', 'status', 'deal_id', 'party_id', 'open_only'] as $key) {
                if (isset($_GET[$key]) && $_GET[$key] !== '') {
                    $filters[$key] = $_GET[$key];
                }
            }

            return ['data' => $this->flagService->queue($filters, $actor)];
        });
    }

    public function queues(): void
    {
        $this->run(fn(array $actor) => ['data' => $this->flagService->queues($actor)]);
    }

    public function stats(): void
    {
        $this->run(fn(array $actor) => ['data' => $this->flagService->stats(
            $actor,
            isset($_GET['from']) && $_GET['from'] !== '' ? (string)$_GET['from'] : null,
            isset($_GET['to']) && $_GET['to'] !== '' ? (string)$_GET['to'] : null
        )]);
    }

    public function create(): void
    {
        $this->run(function (array $actor) {
            $flag = $this->flagService->raise($this->input(), $actor);
            http_response_code(201);

            return ['data' => $flag, 'message' => 'Technical query sent to the queue.'];
        });
    }

    public function claim(string $id): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->flagService->claim((int)$id, $actor),
            'message' => 'Flag claimed.',
        ]);
    }

    public function resolve(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $input = $this->input();

            return [
                'data' => $this->flagService->resolve(
                    (int)$id,
                    $actor,
                    (string)($input['resolution_type'] ?? ''),
                    (string)($input['resolution_note'] ?? '')
                ),
                'message' => 'Flag resolved.',
            ];
        });
    }

    public function cancel(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $input = $this->input();

            return [
                'data' => $this->flagService->cancel((int)$id, $actor, (string)($input['note'] ?? '')),
                'message' => 'Flag cancelled.',
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
            echo json_encode(array_merge(['success' => true], $handler($actor)));
        } catch (PipelineAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (PipelineException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process the request', 'message' => $e->getMessage()]);
        }
    }
}
