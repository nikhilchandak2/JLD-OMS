<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DataFeedAuthorizationException;
use App\Services\DataFeedException;
use App\Services\DataFeedIngestService;
use App\Services\DataFeedPolicy;
use App\Services\DataFreshnessService;
use App\Services\FeedPromotionBlockedException;
use App\Services\FeedSupersedeRequiredException;
use App\Services\PartyAliasService;

class DataFeedController
{
    private AuthService $authService;
    private DataFeedIngestService $ingest;
    private DataFreshnessService $freshness;
    private PartyAliasService $aliases;
    private DataFeedPolicy $policy;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->ingest = new DataFeedIngestService();
        $this->freshness = new DataFreshnessService();
        $this->aliases = new PartyAliasService();
        $this->policy = new DataFeedPolicy();
    }

    public function dashboard(): void
    {
        $this->run(function (array $actor) {
            $this->policy->assertCan($actor['role'] ?? null, DataFeedPolicy::VIEW);

            return ['data' => $this->ingest->dashboard()];
        });
    }

    public function upload(): void
    {
        $this->run(function (array $actor) {
            $file = $_FILES['file'] ?? null;
            if (!$file || ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
                throw new DataFeedException('No file uploaded or upload error.');
            }
            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new DataFeedException('Invalid uploaded file.');
            }
            $content = file_get_contents($tmp);
            if ($content === false) {
                throw new DataFeedException('Could not read file.');
            }

            $feedKey = (string)($_POST['feed_key'] ?? '');
            $companyId = (int)($_POST['company_id'] ?? 0);
            $businessDate = (string)($_POST['business_date'] ?? '');
            $confirm = !empty($_POST['confirm_supersede']);

            $result = $this->ingest->upload(
                $feedKey,
                $companyId,
                $businessDate,
                (string)($file['name'] ?? 'upload.csv'),
                $content,
                $actor,
                ['confirm_supersede' => $confirm]
            );
            if (empty($result['already_processed'])) {
                http_response_code(201);
            }

            return ['data' => $result];
        });
    }

    public function show(string $id): void
    {
        $this->run(function (array $actor) use ($id) {
            $this->policy->assertCan($actor['role'] ?? null, DataFeedPolicy::VIEW);

            return ['data' => $this->ingest->show((int)$id)];
        });
    }

    public function validate(string $id): void
    {
        $this->run(fn(array $actor) => ['data' => $this->ingest->validate((int)$id, $actor)]);
    }

    public function promote(string $id): void
    {
        $this->run(fn(array $actor) => ['data' => $this->ingest->promote((int)$id, $actor)]);
    }

    public function rejections(string $id): void
    {
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        $this->policy->assertCan($user['role'] ?? null, DataFeedPolicy::VIEW);

        $report = $this->ingest->rejectionReport((int)$id);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $report['filename'] . '"');
        $out = fopen('php://output', 'w');
        foreach ($report['rows'] as $line) {
            fputcsv($out, $line);
        }
        fclose($out);
    }

    public function template(string $feedKey): void
    {
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        $this->policy->assertCan($user['role'] ?? null, DataFeedPolicy::VIEW);

        $template = $this->ingest->template($feedKey);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $template['filename'] . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $template['headers']);
        fclose($out);
    }

    public function asOf(): void
    {
        $this->run(function (array $actor) {
            $this->policy->assertCan($actor['role'] ?? null, DataFeedPolicy::VIEW);
            $feedKey = (string)($_GET['feed_key'] ?? 'ledger');
            $group = !isset($_GET['group']) || $_GET['group'] === '1' || $_GET['group'] === 'true';
            $companyId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : null;

            return ['data' => $this->freshness->bannerPayload($feedKey, $companyId, $group)];
        });
    }

    public function unmatched(): void
    {
        $this->run(function (array $actor) {
            $this->policy->assertCan($actor['role'] ?? null, DataFeedPolicy::VIEW);

            return ['data' => $this->aliases->unmatchedQueue()];
        });
    }

    public function createAlias(): void
    {
        $this->run(function (array $actor) {
            $input = $this->input();
            $alias = $this->aliases->resolveManually(
                (string)($input['source_system'] ?? ''),
                (string)($input['source_identifier'] ?? ''),
                (int)($input['party_id'] ?? 0),
                $actor
            );
            $this->ingest->afterAliasResolved($actor);

            return ['data' => $alias, 'message' => 'Alias saved. Matching rows will be re-validated.'];
        });
    }

    public function updateFeed(string $id): void
    {
        $this->run(fn(array $actor) => [
            'data' => $this->ingest->updateFeed((int)$id, $this->input(), $actor),
            'message' => 'Feed updated.',
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
        } catch (DataFeedAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (FeedSupersedeRequiredException $e) {
            http_response_code(409);
            echo json_encode(array_merge(['error' => $e->getMessage()], $e->getDetails()));
        } catch (FeedPromotionBlockedException $e) {
            http_response_code(422);
            echo json_encode(array_merge(['error' => $e->getMessage()], $e->getDetails()));
        } catch (DataFeedException $e) {
            http_response_code(400);
            echo json_encode(array_merge(['error' => $e->getMessage()], $e->getDetails()));
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to process the request', 'message' => $e->getMessage()]);
        }
    }
}
