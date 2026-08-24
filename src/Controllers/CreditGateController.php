<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\CreditGateAuthorizationException;
use App\Services\CreditGateException;
use App\Services\CreditGatePolicy;
use App\Services\CreditGateService;
use App\Services\CreditOverrideService;
use App\Services\DirectOrderCaptureService;
use App\Services\IllegalOverrideTransitionException;
use App\Support\CompanyContext;

class CreditGateController
{
    private AuthService $authService;
    private CreditGateService $gate;
    private CreditOverrideService $overrides;
    private DirectOrderCaptureService $capture;
    private CreditGatePolicy $policy;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->gate = new CreditGateService();
        $this->overrides = new CreditOverrideService();
        $this->capture = new DirectOrderCaptureService();
        $this->policy = new CreditGatePolicy();
    }

    public function evaluate(): void
    {
        $this->run(function (array $actor) {
            $this->policy->assertCan($actor['role'] ?? null, CreditGatePolicy::EVALUATE);
            $partyId = (int)($_GET['party_id'] ?? 0);
            $companyId = (int)($_GET['company_id'] ?? CompanyContext::getActiveCompanyId() ?? 0);
            $proposed = isset($_GET['proposed_order_value']) ? (float)$_GET['proposed_order_value'] : 0.0;
            if ($partyId <= 0 || $companyId <= 0) {
                throw new CreditGateException('party_id and company_id are required.');
            }
            $evaluation = $this->gate->evaluate($partyId, $companyId, $proposed);

            return ['data' => $this->gate->serializeForRole($evaluation, $actor['role'] ?? null)];
        });
    }

    public function prefill(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            return ['data' => $this->capture->prefill((int)$id, $actor)];
        });
    }

    public function capture(): void
    {
        $this->run(function (array $actor) {
            $input = $this->input();
            if (empty($input['company_id'])) {
                $input['company_id'] = CompanyContext::getActiveCompanyId();
            }
            $result = $this->capture->capture($input, $actor);
            http_response_code(201);

            $status = $result['credit_gate']['credit_gate_status'] ?? 'cleared';
            $message = match ($status) {
                'pending_director' => 'Order captured — pending Director confirmation. Dispatch may proceed.',
                'blocked' => 'Order captured but blocked until the Director decides.',
                default => 'Order captured. Credit gate cleared.',
            };

            return ['data' => $result, 'message' => $message];
        });
    }

    public function queue(): void
    {
        $this->run(function (array $actor) {
            $filters = ['open_only' => 1];
            if (!empty($_GET['status'])) {
                $filters['status'] = (string)$_GET['status'];
                unset($filters['open_only']);
            }
            if (!empty($_GET['tier'])) {
                $filters['tier'] = (int)$_GET['tier'];
            }

            return ['data' => $this->overrides->queue($filters, $actor)];
        });
    }

    public function show(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $this->policy->assertCan($actor['role'] ?? null, CreditGatePolicy::VIEW_QUEUE);

            return ['data' => $this->overrides->present((int)$id, $actor)];
        });
    }

    public function decide(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            return [
                'data' => $this->overrides->decide((int)$id, $this->input(), $actor),
                'message' => 'Override updated.',
            ];
        });
    }

    public function batchApprove(): void
    {
        $this->run(function (array $actor) {
            $ids = $this->input()['ids'] ?? [];
            if (!is_array($ids) || $ids === []) {
                throw new CreditGateException('ids must be a non-empty array of Tier 2 override ids.');
            }

            return [
                'data' => $this->overrides->batchApprove($ids, $actor),
                'message' => count($ids) . ' Tier 2 override(s) approved.',
            ];
        });
    }

    public function withdraw(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            return [
                'data' => $this->overrides->decide((int)$id, ['action' => 'withdraw'], $actor),
                'message' => 'Override withdrawn.',
            ];
        });
    }

    public function volume(): void
    {
        $this->run(function (array $actor) {
            $this->policy->assertCan($actor['role'] ?? null, CreditGatePolicy::VIEW_QUEUE);

            return ['data' => $this->overrides->volumeByTier()];
        });
    }

    public function expire(): void
    {
        $this->run(function (array $actor) {
            $this->policy->assertCan($actor['role'] ?? null, CreditGatePolicy::DECIDE);

            return ['data' => ['expired' => $this->overrides->expireOverdue(['id' => null, 'role' => 'system'])]];
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
        } catch (CreditGateAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (IllegalOverrideTransitionException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (CreditGateException $e) {
            http_response_code(400);
            echo json_encode(array_merge(['error' => $e->getMessage()], $e->getDetails()));
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process the request', 'message' => $e->getMessage()]);
        }
    }
}
