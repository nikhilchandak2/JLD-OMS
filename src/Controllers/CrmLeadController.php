<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\CrmLeadRepository;
use App\Repositories\CrmDealRepository;
use App\Repositories\PartyRepository;
use App\Models\CrmLead;
use App\Models\CrmDeal;
use App\Models\Party;

class CrmLeadController
{
    private AuthService $authService;
    private CrmLeadRepository $leadRepo;
    private CrmDealRepository $dealRepo;
    private PartyRepository $partyRepo;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->leadRepo = new CrmLeadRepository();
        $this->dealRepo = new CrmDealRepository();
        $this->partyRepo = new PartyRepository();
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
        if (!empty($_GET['stage'])) $filters['stage'] = $_GET['stage'];
        if (!empty($_GET['assigned_to'])) $filters['assigned_to'] = (int)$_GET['assigned_to'];
        try {
            $list = $this->leadRepo->findAll($filters);
            echo json_encode(['success' => true, 'data' => array_map(fn($l) => $l->toArray(), $list)]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function show(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $lid = (int)$id;
        $lead = $lid > 0 ? $this->leadRepo->findById($lid) : null;
        if (!$lead) {
            http_response_code(404);
            echo json_encode(['error' => 'Lead not found']);
            return;
        }
        echo json_encode(['success' => true, 'data' => $lead->toArray()]);
    }

    public function create(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $lead = new CrmLead();
        $lead->title = trim($input['title'] ?? '');
        $lead->companyName = trim($input['company_name'] ?? '');
        $lead->contactName = trim($input['contact_name'] ?? '');
        $lead->phone = trim($input['phone'] ?? '');
        $lead->email = trim($input['email'] ?? '');
        $lead->source = trim($input['source'] ?? '');
        $lead->value = isset($input['value']) ? (float)$input['value'] : null;
        $lead->stage = trim($input['stage'] ?? 'new');
        $lead->partyId = isset($input['party_id']) && $input['party_id'] !== '' ? (int)$input['party_id'] : null;
        $lead->assignedTo = isset($input['assigned_to']) && $input['assigned_to'] !== '' ? (int)$input['assigned_to'] : null;
        $lead->notes = trim($input['notes'] ?? '');
        if ($lead->title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Title is required']);
            return;
        }
        try {
            $created = $this->leadRepo->create($lead);
            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Lead created', 'data' => $created->toArray()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function update(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $lid = (int)$id;
        $existing = $lid > 0 ? $this->leadRepo->findById($lid) : null;
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Lead not found']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $data = [];
        $allowed = ['title', 'company_name', 'contact_name', 'phone', 'email', 'source', 'value', 'stage', 'party_id', 'assigned_to', 'notes'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) {
                if ($f === 'value') $data[$f] = $input[$f] !== null && $input[$f] !== '' ? (float)$input[$f] : null;
                elseif ($f === 'party_id' || $f === 'assigned_to') $data[$f] = $input[$f] !== null && $input[$f] !== '' ? (int)$input[$f] : null;
                else $data[$f] = trim((string)$input[$f]);
            }
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
            $updated = $this->leadRepo->update($lid, $data);
            echo json_encode(['success' => true, 'message' => 'Lead updated', 'data' => $updated->toArray()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function delete(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $lid = (int)$id;
        try {
            if ($this->leadRepo->delete($lid)) {
                echo json_encode(['success' => true, 'message' => 'Lead deleted']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Lead not found']);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /** Convert lead to deal: create party if needed, create deal, set lead stage to converted */
    public function convertToDeal(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $lid = (int)$id;
        $lead = $lid > 0 ? $this->leadRepo->findById($lid) : null;
        if (!$lead) {
            http_response_code(404);
            echo json_encode(['error' => 'Lead not found']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $partyId = $lead->partyId;
        if (!$partyId && !empty($lead->companyName)) {
            $existing = $this->partyRepo->findByName($lead->companyName);
            if ($existing) {
                $partyId = $existing->id;
            } else {
                $party = new Party();
                $party->name = $lead->companyName;
                $party->contactPerson = $lead->contactName;
                $party->phone = $lead->phone;
                $party->email = $lead->email;
                $party->address = '';
                $party->isActive = true;
                $createdParty = $this->partyRepo->create($party);
                $partyId = $createdParty->id;
            }
        }
        if (!$partyId) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot convert: no party linked and no company name to create one']);
            return;
        }
        $deal = new CrmDeal();
        $deal->partyId = $partyId;
        $deal->leadId = $lead->id;
        $deal->title = trim($input['title'] ?? $lead->title);
        $deal->value = $lead->value;
        $deal->stage = 'qualified';
        $deal->expectedCloseDate = !empty($input['expected_close_date']) ? $input['expected_close_date'] : null;
        $deal->assignedTo = $lead->assignedTo;
        $deal->notes = $lead->notes;
        if ($deal->title === '') $deal->title = 'Deal from lead: ' . $lead->title;
        try {
            $createdDeal = $this->dealRepo->create($deal);
            $this->leadRepo->update($lid, ['stage' => 'converted', 'party_id' => $partyId]);
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Lead converted to deal',
                'data' => [
                    'deal' => $createdDeal->toArray(),
                    'party_id' => $partyId,
                ],
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
