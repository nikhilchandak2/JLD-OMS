<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DormancyAuthorizationException;
use App\Services\DormancyException;
use App\Services\EscalationService;

class EscalationController
{
    private AuthService $authService;
    private EscalationService $escalations;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->escalations = new EscalationService();
    }

    public function index(): void
    {
        $status = isset($_GET['status']) ? (string)$_GET['status'] : null;
        $this->run(fn(array $actor) => [
            'data' => $this->escalations->inbox($actor, $status),
        ]);
    }

    public function show(string $id): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->escalations->show((int)$id, $actor),
        ]);
    }

    public function create(): void
    {
        $this->run(function (array $actor) {
            $row = $this->escalations->raiseManual($this->input(), $actor);
            http_response_code(201);

            return ['data' => $row, 'message' => 'Flagged for senior attention.'];
        });
    }

    public function acknowledge(string $id): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->escalations->acknowledge((int)$id, $actor),
            'message' => 'Acknowledged.',
        ]);
    }

    public function resolve(string $id): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->escalations->resolve((int)$id, $this->input(), $actor),
            'message' => 'Resolved.',
        ]);
    }

    public function dismiss(string $id): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->escalations->dismiss((int)$id, $this->input(), $actor),
            'message' => 'Dismissed.',
        ]);
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
        } catch (DormancyAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (DormancyException $e) {
            http_response_code(400);
            echo json_encode(array_merge(['error' => $e->getMessage()], $e->getDetails()));
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process the request', 'message' => $e->getMessage()]);
        }
    }
}
