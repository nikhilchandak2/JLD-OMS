<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\VisitAuthorizationException;
use App\Services\VisitException;
use App\Services\VisitService;

class CrmVisitController
{
    private AuthService $authService;
    private VisitService $visits;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->visits = new VisitService();
    }

    public function create(): void
    {
        $this->run(function (array $actor) {
            $row = $this->visits->log($this->input(), $actor);
            http_response_code(201);

            return ['data' => $row, 'message' => 'Visit logged.'];
        });
    }

    public function listByParty(string $partyId): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->visits->listForParty((int)$partyId, $actor),
        ]);
    }

    public function overdue(): void
    {
        $this->run(function (array $actor) {
            $all = isset($_GET['all']) && $_GET['all'] === '1';

            return ['data' => $this->visits->overdue($actor, $all)];
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
        } catch (VisitAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (VisitException $e) {
            http_response_code(400);
            echo json_encode(array_merge(['error' => $e->getMessage()], $e->getDetails()));
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process the request', 'message' => $e->getMessage()]);
        }
    }
}
