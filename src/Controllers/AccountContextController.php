<?php

namespace App\Controllers;

use App\Services\AccountContextAuthorizationException;
use App\Services\AccountContextException;
use App\Services\AccountContextService;
use App\Services\AccountIssueService;
use App\Services\AuthService;
use App\Services\CompetitorPositionService;

class AccountContextController
{
    private AuthService $authService;
    private AccountContextService $context;
    private CompetitorPositionService $competitors;
    private AccountIssueService $issues;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->context = new AccountContextService();
        $this->competitors = new CompetitorPositionService();
        $this->issues = new AccountIssueService();
    }

    public function meta(): void
    {
        $this->run(fn(array $actor) => ['data' => $this->context->meta($actor)]);
    }

    public function snapshot(string $partyId): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->context->snapshotForParty((int)$partyId, $actor),
        ]);
    }

    public function saveContext(string $partyId): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->context->upsertContext((int)$partyId, $this->input(), $actor),
            'message' => 'Account context saved.',
        ]);
    }

    public function recordCompetitor(string $partyId): void
    {
        $this->run(function (array $actor) use ($partyId) {
            $row = $this->competitors->record((int)$partyId, $this->input(), $actor);
            http_response_code(201);

            return ['data' => $row, 'message' => 'Competitor position recorded.'];
        });
    }

    public function createIssue(string $partyId): void
    {
        $this->run(function (array $actor) use ($partyId) {
            $row = $this->issues->create((int)$partyId, $this->input(), $actor);
            http_response_code(201);

            return ['data' => $row, 'message' => 'Issue logged.'];
        });
    }

    public function resolveIssue(string $id): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->issues->resolve((int)$id, $this->input(), $actor),
            'message' => 'Issue resolved.',
        ]);
    }

    public function search(): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->context->search((string)($_GET['q'] ?? ''), $actor),
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
        } catch (AccountContextAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (AccountContextException $e) {
            http_response_code(400);
            echo json_encode(array_merge(['error' => $e->getMessage()], $e->getDetails()));
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process the request', 'message' => $e->getMessage()]);
        }
    }
}
