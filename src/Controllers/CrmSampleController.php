<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\CrmSampleRepository;
use App\Repositories\PartyRepository;
use App\Models\CrmSample;

class CrmSampleController
{
    private AuthService $authService;
    private CrmSampleRepository $sampleRepo;
    private PartyRepository $partyRepo;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->sampleRepo = new CrmSampleRepository();
        $this->partyRepo = new PartyRepository();
    }

    private function requireCrmAccess(): bool
    {
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'crm', 'sales', 'marketing'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Entry or Admin access required']);
            return false;
        }
        return true;
    }

    public function index(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $filters = [];
        if (!empty($_GET['party_id'])) $filters['party_id'] = (int)$_GET['party_id'];
        if (!empty($_GET['deal_id'])) $filters['deal_id'] = (int)$_GET['deal_id'];
        if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
        try {
            $list = $this->sampleRepo->findAll($filters);
            echo json_encode(['success' => true, 'data' => array_map(fn($s) => $s->toArray(), $list)]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function show(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $sid = (int)$id;
        $sample = $sid > 0 ? $this->sampleRepo->findById($sid) : null;
        if (!$sample) {
            http_response_code(404);
            echo json_encode(['error' => 'Sample not found']);
            return;
        }
        echo json_encode(['success' => true, 'data' => $sample->toArray()]);
    }

    public function create(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $user = $this->authService->getCurrentUser();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $partyId = (int)($input['party_id'] ?? 0);
        if ($partyId <= 0 || !$this->partyRepo->findById($partyId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid party is required']);
            return;
        }
        $sample = new CrmSample();
        $sample->partyId = $partyId;
        $sample->dealId = isset($input['deal_id']) && $input['deal_id'] !== '' ? (int)$input['deal_id'] : null;
        $sample->sampleType = trim($input['sample_type'] ?? '');
        $sample->quantitySent = trim($input['quantity_sent'] ?? '');
        $sample->requestDate = !empty($input['request_date']) ? $input['request_date'] : null;
        $sample->dispatchDate = !empty($input['dispatch_date']) ? $input['dispatch_date'] : null;
        $sample->trialDate = !empty($input['trial_date']) ? $input['trial_date'] : null;
        $sample->status = trim($input['status'] ?? 'sample_sent');
        $sample->outcome = trim($input['outcome'] ?? '');
        $sample->technicalFeedback = trim($input['technical_feedback'] ?? '');
        $sample->createdBy = $user ? (int)$user['id'] : null;
        try {
            $created = $this->sampleRepo->create($sample);
            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Sample recorded', 'data' => $created->toArray()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function update(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $sid = (int)$id;
        $existing = $sid > 0 ? $this->sampleRepo->findById($sid) : null;
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Sample not found']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $data = [];
        $allowed = ['deal_id', 'sample_type', 'quantity_sent', 'request_date', 'dispatch_date', 'trial_date', 'status', 'outcome', 'technical_feedback'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) {
                $data[$f] = $f === 'deal_id' && ($input[$f] === null || $input[$f] === '') ? null : (is_string($input[$f]) ? trim($input[$f]) : $input[$f]);
            }
        }
        if (empty($data)) {
            echo json_encode(['success' => true, 'data' => $existing->toArray()]);
            return;
        }
        try {
            $updated = $this->sampleRepo->update($sid, $data);
            echo json_encode(['success' => true, 'message' => 'Sample updated', 'data' => $updated->toArray()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function delete(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $sid = (int)$id;
        try {
            if ($this->sampleRepo->delete($sid)) {
                echo json_encode(['success' => true, 'message' => 'Sample deleted']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Sample not found']);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
