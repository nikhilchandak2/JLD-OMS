<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\GeofenceService;

class GeofenceController
{
    private AuthService $authService;
    private GeofenceService $geofenceService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->geofenceService = new GeofenceService();
    }
    
    public function index(): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $geofences = $this->geofenceService->getActiveGeofences();
            
            echo json_encode([
                'success' => true,
                'data' => $geofences
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function show(int $id): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $geofence = $this->geofenceService->getGeofenceById($id);
            
            if (!$geofence) {
                http_response_code(404);
                echo json_encode(['error' => 'Geofence not found']);
                return;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $geofence
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function create(): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            
            // Validate required fields
            if (empty($input['name']) || empty($input['geofence_type']) || 
                !isset($input['latitude']) || !isset($input['longitude']) || 
                !isset($input['radius_meters'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields']);
                return;
            }
            
            $geofenceId = $this->geofenceService->createGeofence($input);
            
            echo json_encode([
                'success' => true,
                'message' => 'Geofence created successfully',
                'data' => ['id' => $geofenceId]
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function update(int $id): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            
            $this->geofenceService->updateGeofence($id, $input);
            
            echo json_encode([
                'success' => true,
                'message' => 'Geofence updated successfully'
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    public function delete(int $id): void
    {
        header('Content-Type: application/json');
        
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        
        try {
            $this->geofenceService->deleteGeofence($id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Geofence deleted successfully'
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
