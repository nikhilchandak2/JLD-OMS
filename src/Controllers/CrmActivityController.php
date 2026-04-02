<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\CrmActivityRepository;
use App\Repositories\PartyRepository;
use App\Models\CrmActivity;

class CrmActivityController
{
    private AuthService $authService;
    private CrmActivityRepository $activityRepo;
    private PartyRepository $partyRepo;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->activityRepo = new CrmActivityRepository();
        $this->partyRepo = new PartyRepository();
    }

    private function requireCrmAccess(): bool
    {
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'crm'])) {
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
        $user = $this->authService->getCurrentUser();
        $filters = [];
        if (!empty($_GET['party_id'])) $filters['party_id'] = (int)$_GET['party_id'];
        if (!empty($_GET['deal_id'])) $filters['deal_id'] = (int)$_GET['deal_id'];
        if (!empty($_GET['type'])) $filters['type'] = $_GET['type'];
        if (!empty($_GET['created_by'])) {
            $requestedCreatedBy = (int)$_GET['created_by'];
            if ($requestedCreatedBy > 0) {
                // Non-admin users should only be able to view their own activities
                $filters['created_by'] = $this->authService->hasRole('admin') ? $requestedCreatedBy : (int)($user['id'] ?? 0);
            }
        }
        if (!empty($_GET['from_date'])) $filters['from_date'] = $_GET['from_date'];
        if (!empty($_GET['to_date'])) $filters['to_date'] = $_GET['to_date'];
        if (isset($_GET['limit']) && $_GET['limit'] !== '') $filters['limit'] = (int)$_GET['limit'];
        try {
            $list = $this->activityRepo->findAll($filters);
            echo json_encode(['success' => true, 'data' => array_map(fn($a) => $a->toArray(), $list)]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function show(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $aid = (int)$id;
        $activity = $aid > 0 ? $this->activityRepo->findById($aid) : null;
        if (!$activity) {
            http_response_code(404);
            echo json_encode(['error' => 'Activity not found']);
            return;
        }
        echo json_encode(['success' => true, 'data' => $activity->toArray()]);
    }

    public function create(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $user = $this->authService->getCurrentUser();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $activity = new CrmActivity();
        $activity->partyId = (int)($input['party_id'] ?? 0);
        $activity->dealId = isset($input['deal_id']) && $input['deal_id'] !== '' ? (int)$input['deal_id'] : null;
        $activity->contactId = isset($input['contact_id']) && $input['contact_id'] !== '' ? (int)$input['contact_id'] : null;
        $activity->type = trim($input['type'] ?? 'note');
        $activity->subject = trim($input['subject'] ?? '');
        $activity->description = trim($input['description'] ?? '');
        $activity->activityDate = trim($input['activity_date'] ?? date('Y-m-d H:i:s'));
        $activity->createdBy = $user ? (int)$user['id'] : null;
        if ($activity->partyId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Party is required']);
            return;
        }
        $validTypes = ['call', 'meeting', 'note', 'email', 'visit', 'whatsapp'];
        if (!in_array($activity->type, $validTypes)) {
            $activity->type = 'note';
        }
        try {
            $created = $this->activityRepo->create($activity);
            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Activity created', 'data' => $created->toArray()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function update(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $aid = (int)$id;
        $existing = $aid > 0 ? $this->activityRepo->findById($aid) : null;
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Activity not found']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $data = [];
        if (array_key_exists('deal_id', $input)) $data['deal_id'] = $input['deal_id'] !== null && $input['deal_id'] !== '' ? (int)$input['deal_id'] : null;
        if (array_key_exists('contact_id', $input)) $data['contact_id'] = $input['contact_id'] !== null && $input['contact_id'] !== '' ? (int)$input['contact_id'] : null;
        if (isset($input['type'])) $data['type'] = trim($input['type']);
        if (isset($input['subject'])) $data['subject'] = trim($input['subject']);
        if (isset($input['description'])) $data['description'] = trim($input['description']);
        if (isset($input['activity_date'])) $data['activity_date'] = trim($input['activity_date']);
        if (isset($data['type']) && !in_array($data['type'], ['call', 'meeting', 'note', 'email', 'visit', 'whatsapp'])) unset($data['type']);
        if (empty($data)) {
            echo json_encode(['success' => true, 'data' => $existing->toArray()]);
            return;
        }
        try {
            $updated = $this->activityRepo->update($aid, $data);
            echo json_encode(['success' => true, 'message' => 'Activity updated', 'data' => $updated->toArray()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function delete(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $aid = (int)$id;
        try {
            if ($this->activityRepo->delete($aid)) {
                echo json_encode(['success' => true, 'message' => 'Activity deleted']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Activity not found']);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
