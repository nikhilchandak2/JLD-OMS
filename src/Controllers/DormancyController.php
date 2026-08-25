<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DormancyAuthorizationException;
use App\Services\DormancyException;
use App\Services\DormancyService;

class DormancyController
{
    private AuthService $authService;
    private DormancyService $dormancy;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->dormancy = new DormancyService();
    }

    public function index(): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->dormancy->listForActor($actor, isset($_GET['on']) ? (string)$_GET['on'] : null),
        ]);
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
