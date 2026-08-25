<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DealService;
use App\Services\DealStageService;
use App\Services\ExitCriteriaNotMetException;
use App\Services\IllegalTransitionException;
use App\Services\PipelineAuthorizationException;
use App\Services\PipelineException;
use App\Services\StageSkipException;
use App\Services\TransitionReasonRequiredException;

/**
 * HTTP layer for the deal pipeline. No SQL, no business rules: every decision is taken by
 * DealService / DealStageService, including role gating.
 */
class CrmDealController
{
    private AuthService $authService;
    private DealService $dealService;
    private DealStageService $stageService;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->dealService = new DealService();
        $this->stageService = new DealStageService();
    }

    public function index(): void
    {
        $this->run(function (array $actor) {
            $filters = [];
            foreach (['status', 'stage', 'party_id', 'owner_user_id', 'company_id', 'on_technical_hold'] as $key) {
                if (isset($_GET[$key]) && $_GET[$key] !== '') {
                    $filters[$key] = $_GET[$key];
                }
            }

            return ['data' => $this->dealService->list($filters, $actor)];
        });
    }

    public function summary(): void
    {
        $this->run(fn(array $actor) => [
            'data' => [
                'stages' => $this->dealService->pipelineSummary($actor),
                'sources' => $this->dealService->sources(),
            ],
        ]);
    }

    public function show(string $id): void
    {
        $this->run(fn(array $actor) => ['data' => $this->dealService->show((int)$id, $actor)]);
    }

    public function create(): void
    {
        $this->run(function (array $actor) {
            $deal = $this->dealService->captureInquiry($this->input(), $actor);
            http_response_code(201);

            return ['data' => $deal, 'message' => 'Enquiry captured at Stage 1.'];
        });
    }

    public function update(string $id): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->dealService->updateDetails((int)$id, $this->input(), $actor),
            'message' => 'Deal updated.',
        ]);
    }

    public function criteria(string $id): void
    {
        $this->run(fn() => ['data' => $this->stageService->evaluateExitCriteria((int)$id)]);
    }

    public function saveCriteria(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $input = $this->input();
            $values = $input['values'] ?? $input;

            return [
                'data' => $this->stageService->saveCriteriaValues((int)$id, (array)$values, $actor),
                'message' => 'Stage details saved.',
            ];
        });
    }

    public function advance(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $deal = $this->stageService->advance((int)$id, $actor);

            return [
                'data' => $this->dealService->show((int)$id, $actor),
                'message' => 'Moved to ' . $this->stageService->stageLabel((int)$deal['stage']) . '.',
            ];
        });
    }

    public function moveBack(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $input = $this->input();
            $deal = $this->stageService->moveBack((int)$id, $actor, (string)($input['reason_note'] ?? ''));

            return [
                'data' => $this->dealService->show((int)$id, $actor),
                'message' => 'Moved back to ' . $this->stageService->stageLabel((int)$deal['stage']) . '.',
            ];
        });
    }

    public function win(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $this->stageService->markWon((int)$id, $actor);

            return ['data' => $this->dealService->show((int)$id, $actor), 'message' => 'Deal marked won.'];
        });
    }

    public function close(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $input = $this->input();
            $this->stageService->terminate(
                (int)$id,
                $actor,
                (string)($input['status'] ?? ''),
                (int)($input['reason_code_id'] ?? 0),
                $input['reason_note'] ?? null
            );

            return ['data' => $this->dealService->show((int)$id, $actor), 'message' => 'Deal closed.'];
        });
    }

    public function reopen(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $input = $this->input();
            $this->stageService->reopen((int)$id, $actor, (string)($input['reason_note'] ?? ''));

            return ['data' => $this->dealService->show((int)$id, $actor), 'message' => 'Deal reopened.'];
        });
    }

    public function addGrade(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $input = $this->input();
            $qty = isset($input['indicative_qty_tonnes']) && $input['indicative_qty_tonnes'] !== ''
                ? (float)$input['indicative_qty_tonnes']
                : null;

            return [
                'data' => $this->dealService->addGrade((int)$id, (string)($input['grade_code'] ?? ''), $qty, $actor),
                'message' => 'Grade added.',
            ];
        });
    }

    public function removeGrade(string $id, string $gradeCode): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->dealService->removeGrade((int)$id, urldecode($gradeCode), $actor),
            'message' => 'Grade removed.',
        ]);
    }

    public function delete(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $this->dealService->softDelete((int)$id, $actor);

            return ['message' => 'Deal deleted.'];
        });
    }

    public function reasonCodes(): void
    {
        $this->run(function (array $actor) {
            $appliesTo = isset($_GET['applies_to']) && $_GET['applies_to'] !== '' ? (string)$_GET['applies_to'] : null;

            return ['data' => $this->dealService->reasonCodes($appliesTo, $actor)];
        });
    }

    // -----------------------------------------------------------------------

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
        } catch (PipelineAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (ExitCriteriaNotMetException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage(), 'unmet' => $e->getDetails()['unmet'] ?? []]);
        } catch (StageSkipException | IllegalTransitionException | TransitionReasonRequiredException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (PipelineException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process the request', 'message' => $e->getMessage()]);
        }
    }
}
