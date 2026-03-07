<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\CrmContactRepository;
use App\Repositories\PartyRepository;
use App\Models\CrmContact;

class CrmContactController
{
    private AuthService $authService;
    private CrmContactRepository $contactRepo;
    private PartyRepository $partyRepo;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->contactRepo = new CrmContactRepository();
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

    public function listByParty(string $partyId): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $id = (int)$partyId;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid party ID']);
            return;
        }
        if (!$this->partyRepo->findById($id)) {
            http_response_code(404);
            echo json_encode(['error' => 'Party not found']);
            return;
        }
        try {
            $list = $this->contactRepo->findByParty($id);
            echo json_encode(['success' => true, 'data' => array_map(fn($c) => $c->toArray(), $list)]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function show(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $cid = (int)$id;
        $contact = $cid > 0 ? $this->contactRepo->findById($cid) : null;
        if (!$contact) {
            http_response_code(404);
            echo json_encode(['error' => 'Contact not found']);
            return;
        }
        echo json_encode(['success' => true, 'data' => $contact->toArray()]);
    }

    public function create(string $partyId): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $pid = (int)$partyId;
        if ($pid <= 0 || !$this->partyRepo->findById($pid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or unknown party']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $contact = new CrmContact();
        $contact->partyId = $pid;
        $contact->name = trim($input['name'] ?? '');
        $contact->role = trim($input['role'] ?? '');
        $contact->phone = trim($input['phone'] ?? '');
        $contact->email = trim($input['email'] ?? '');
        $contact->isPrimary = !empty($input['is_primary']);
        if ($contact->name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Name is required']);
            return;
        }
        try {
            $created = $this->contactRepo->create($contact);
            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Contact created', 'data' => $created->toArray()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function update(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $cid = (int)$id;
        $existing = $cid > 0 ? $this->contactRepo->findById($cid) : null;
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Contact not found']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $data = [];
        if (isset($input['name'])) $data['name'] = trim($input['name']);
        if (isset($input['role'])) $data['role'] = trim($input['role']);
        if (isset($input['phone'])) $data['phone'] = trim($input['phone']);
        if (isset($input['email'])) $data['email'] = trim($input['email']);
        if (array_key_exists('is_primary', $input)) $data['is_primary'] = !empty($input['is_primary']);
        if (isset($data['name']) && $data['name'] === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Name cannot be empty']);
            return;
        }
        if (empty($data)) {
            echo json_encode(['success' => true, 'data' => $existing->toArray()]);
            return;
        }
        try {
            $updated = $this->contactRepo->update($cid, $data);
            echo json_encode(['success' => true, 'message' => 'Contact updated', 'data' => $updated->toArray()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function delete(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $cid = (int)$id;
        if ($cid <= 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Contact not found']);
            return;
        }
        try {
            if ($this->contactRepo->delete($cid)) {
                echo json_encode(['success' => true, 'message' => 'Contact deleted']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Contact not found']);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
