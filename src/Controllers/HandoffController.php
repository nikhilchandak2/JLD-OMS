<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\HandoffAuthorizationException;
use App\Services\HandoffException;
use App\Services\HandoffImmutableException;
use App\Services\HandoffService;

class HandoffController
{
    private AuthService $authService;
    private HandoffService $handoffs;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->handoffs = new HandoffService();
    }

    public function meta(): void
    {
        $this->run(fn(array $actor) => ['data' => $this->handoffs->meta($actor)]);
    }

    public function index(): void
    {
        $this->run(function (array $actor) {
            $filters = [];
            foreach (['packet_type', 'deal_id', 'order_id'] as $key) {
                if (isset($_GET[$key]) && $_GET[$key] !== '') {
                    $filters[$key] = $_GET[$key];
                }
            }
            if (isset($_GET['pending_ack']) && $_GET['pending_ack'] === '1') {
                $filters['pending_ack'] = 1;
            }
            if (isset($_GET['current_only']) && $_GET['current_only'] === '1') {
                $filters['current_only'] = 1;
            }

            return ['data' => $this->handoffs->list($filters, $actor)];
        });
    }

    public function create(): void
    {
        $this->run(function (array $actor) {
            $row = $this->handoffs->create($this->input(), $actor);
            http_response_code(201);

            return ['data' => $row, 'message' => 'Handoff packet created.'];
        });
    }

    public function show(string $id): void
    {
        $this->run(fn(array $actor) => ['data' => $this->handoffs->show((int)$id, $actor)]);
    }

    public function acknowledge(string $id): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->handoffs->acknowledge((int)$id, $actor),
            'message' => 'Packet acknowledged. Fields stay as transferred — they are not re-entered.',
        ]);
    }

    public function supersede(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $row = $this->handoffs->supersede((int)$id, $this->input(), $actor);
            http_response_code(201);

            return ['data' => $row, 'message' => 'Replacement packet created. The previous packet is superseded.'];
        });
    }

    public function pdf(string $id): void
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
            $file = $this->handoffs->pdfBytes((int)$id, $actor);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
            header('Content-Length: ' . strlen($file['bytes']));
            header('Cache-Control: private, max-age=0, must-revalidate');
            echo $file['bytes'];
        } catch (HandoffAuthorizationException $e) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        } catch (HandoffException $e) {
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
        } catch (HandoffAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (HandoffImmutableException $e) {
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (HandoffException $e) {
            http_response_code(400);
            echo json_encode(array_merge(['error' => $e->getMessage()], $e->getDetails()));
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process the request', 'message' => $e->getMessage()]);
        }
    }
}
