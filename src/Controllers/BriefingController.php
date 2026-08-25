<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\BriefingAuthorizationException;
use App\Services\BriefingException;
use App\Services\BriefingService;

class BriefingController
{
    private AuthService $authService;
    private BriefingService $briefings;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->briefings = new BriefingService();
    }

    public function show(string $partyId): void
    {
        $this->run(fn(array $actor) => ['data' => $this->briefings->compose((int)$partyId, $actor)]);
    }

    public function addNote(string $partyId): void
    {
        $this->run(function (array $actor) use ($partyId) {
            $note = (string)($this->input()['note'] ?? '');
            $row = $this->briefings->addHandoverNote((int)$partyId, $note, $actor);
            http_response_code(201);

            return ['data' => $row, 'message' => 'Handover note saved. This dump is transitional — not a permanent feature.'];
        });
    }

    public function pdf(string $partyId): void
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
            $file = $this->briefings->pdfBytes((int)$partyId, $actor);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
            header('Content-Length: ' . strlen($file['bytes']));
            header('Cache-Control: private, max-age=0, must-revalidate');
            echo $file['bytes'];
        } catch (BriefingAuthorizationException $e) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        } catch (BriefingException $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Failed to generate PDF']);
        }
    }

    /** @return array<string,mixed> */
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
        } catch (BriefingAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (BriefingException $e) {
            http_response_code(400);
            echo json_encode(array_merge(['error' => $e->getMessage()], $e->getDetails()));
        } catch (\Throwable $e) {
            error_log($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process the request', 'message' => $e->getMessage()]);
        }
    }
}
