<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\PartyRepository;
use App\Models\Party;

class PartyController
{
    private AuthService $authService;
    private PartyRepository $partyRepository;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->partyRepository = new PartyRepository();
    }
    
    public function index(): void
    {
        header('Content-Type: application/json');
        
        // Check permissions - allow both entry and admin users
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Entry or Admin access required']);
            return;
        }
        
        try {
            $parties = $this->partyRepository->findAll();
            
            echo json_encode([
                'success' => true,
                'data' => array_map(fn($party) => $party->toArray(), $parties)
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function show(int $id): void
    {
        header('Content-Type: application/json');
        
        // Check permissions - allow both entry and admin users
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Entry or Admin access required']);
            return;
        }
        
        try {
            $party = $this->partyRepository->findById($id);
            
            if (!$party) {
                http_response_code(404);
                echo json_encode(['error' => 'Party not found']);
                return;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $party->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function create(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Check permissions - allow both entry and admin users
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Entry or Admin access required']);
            return;
        }
        
        // Get input data
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        try {
            // Create party object
            $party = new Party();
            $party->name = trim($input['name'] ?? '');
            $party->contactPerson = trim($input['contact_person'] ?? '');
            $party->phone = trim($input['phone'] ?? '');
            $party->email = trim($input['email'] ?? '');
            $party->address = trim($input['address'] ?? '');
            $party->isActive = isset($input['is_active']) ? (bool)$input['is_active'] : true;
            
            // Validate
            $errors = $party->validate();
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['error' => 'Validation failed', 'details' => $errors]);
                return;
            }
            
            // Check for duplicate name
            $existing = $this->partyRepository->findByName($party->name);
            if ($existing) {
                http_response_code(400);
                echo json_encode(['error' => 'Party with this name already exists']);
                return;
            }
            
            $newParty = $this->partyRepository->create($party);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Party created successfully',
                'data' => $newParty->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function update(int $id): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Check permissions - allow both entry and admin users
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Entry or Admin access required']);
            return;
        }
        
        // Get input data
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }
        
        try {
            // Check if party exists
            $existingParty = $this->partyRepository->findById($id);
            if (!$existingParty) {
                http_response_code(404);
                echo json_encode(['error' => 'Party not found']);
                return;
            }
            
            $updateData = [];
            
            // Only update provided fields
            if (isset($input['name']) && !empty($input['name'])) {
                $name = trim($input['name']);
                // Check for duplicate name (excluding current party)
                $existing = $this->partyRepository->findByName($name);
                if ($existing && $existing->id !== $id) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Party with this name already exists']);
                    return;
                }
                $updateData['name'] = $name;
            }
            
            if (isset($input['contact_person'])) {
                $updateData['contact_person'] = trim($input['contact_person']);
            }
            
            if (isset($input['phone'])) {
                $updateData['phone'] = trim($input['phone']);
            }
            
            if (isset($input['email'])) {
                $email = trim($input['email']);
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid email format']);
                    return;
                }
                $updateData['email'] = $email;
            }
            
            if (isset($input['address'])) {
                $updateData['address'] = trim($input['address']);
            }
            
            if (isset($input['is_active'])) {
                $updateData['is_active'] = (bool)$input['is_active'];
            }
            
            if (empty($updateData)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                return;
            }
            
            $updatedParty = $this->partyRepository->update($id, $updateData);
            
            echo json_encode([
                'success' => true,
                'message' => 'Party updated successfully',
                'data' => $updatedParty->toArray()
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function delete(int $id): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Check permissions - allow both entry and admin users
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Entry or Admin access required']);
            return;
        }
        
        try {
            $success = $this->partyRepository->delete($id);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Party deleted successfully'
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Party not found']);
            }
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
