<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\CrmDealRepository;
use App\Models\CrmDeal;

class CrmDealController
{
    private AuthService $authService;
    private CrmDealRepository $dealRepo;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->dealRepo = new CrmDealRepository();
    }

    private function requireCrmAccess(): bool
    {
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin'])) {
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
        if (!empty($_GET['stage'])) $filters['stage'] = $_GET['stage'];
        if (!empty($_GET['assigned_to'])) $filters['assigned_to'] = (int)$_GET['assigned_to'];
        try {
            $list = $this->dealRepo->findAll($filters);
            echo json_encode(['success' => true, 'data' => array_map(fn($d) => $d->toArray(), $list)]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function show(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $did = (int)$id;
        $deal = $did > 0 ? $this->dealRepo->findById($did) : null;
        if (!$deal) {
            http_response_code(404);
            echo json_encode(['error' => 'Deal not found']);
            return;
        }
        echo json_encode(['success' => true, 'data' => $deal->toArray()]);
    }

    public function create(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $deal = new CrmDeal();
        $deal->partyId = (int)($input['party_id'] ?? 0);
        $deal->leadId = isset($input['lead_id']) && $input['lead_id'] !== '' ? (int)$input['lead_id'] : null;
        $deal->title = trim($input['title'] ?? '');
        $deal->value = isset($input['value']) && $input['value'] !== '' ? (float)$input['value'] : null;
        $deal->stage = trim($input['stage'] ?? 'qualified');
        $deal->expectedCloseDate = !empty($input['expected_close_date']) ? $input['expected_close_date'] : null;
        $deal->assignedTo = isset($input['assigned_to']) && $input['assigned_to'] !== '' ? (int)$input['assigned_to'] : null;
        $deal->notes = trim($input['notes'] ?? '');
        if ($deal->partyId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Party is required']);
            return;
        }
        if ($deal->title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Title is required']);
            return;
        }
        try {
            $created = $this->dealRepo->create($deal);
            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Deal created', 'data' => $created->toArray()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function update(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $did = (int)$id;
        $existing = $did > 0 ? $this->dealRepo->findById($did) : null;
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Deal not found']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $data = [];
        $allowed = ['party_id', 'lead_id', 'title', 'value', 'stage', 'expected_close_date', 'assigned_to', 'notes'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) {
                if ($f === 'value') $data[$f] = $input[$f] !== null && $input[$f] !== '' ? (float)$input[$f] : null;
                elseif ($f === 'party_id' || $f === 'lead_id' || $f === 'assigned_to') $data[$f] = $input[$f] !== null && $input[$f] !== '' ? (int)$input[$f] : null;
                else $data[$f] = is_string($input[$f]) ? trim($input[$f]) : $input[$f];
            }
        }
        if (isset($data['party_id']) && $data['party_id'] <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid party']);
            return;
        }
        if (isset($data['title']) && $data['title'] === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Title cannot be empty']);
            return;
        }
        if (empty($data)) {
            echo json_encode(['success' => true, 'data' => $existing->toArray()]);
            return;
        }
        try {
            $updated = $this->dealRepo->update($did, $data);
            echo json_encode(['success' => true, 'message' => 'Deal updated', 'data' => $updated->toArray()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function delete(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $did = (int)$id;
        try {
            if ($this->dealRepo->delete($did)) {
                echo json_encode(['success' => true, 'message' => 'Deal deleted']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Deal not found']);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
