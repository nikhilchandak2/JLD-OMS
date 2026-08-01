<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\PartyImportService;
use App\Repositories\PartyRepository;
use App\Models\Party;

class PartyController
{
    private const MAX_UPLOAD_BYTES = 5242880; // 5MB

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
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'accounts', 'crm', 'sales', 'marketing'])) {
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
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'accounts', 'crm', 'sales', 'marketing'])) {
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
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'accounts', 'crm', 'sales', 'marketing', 'order_processing'])) {
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
            $party->gstNumber = Party::normalizeGstNumber($input['gst_number'] ?? $input['gst_no'] ?? '');
            $party->phone = trim($input['phone'] ?? '');
            $party->email = trim($input['email'] ?? '');
            $party->address = trim($input['address'] ?? '');
            $party->isActive = isset($input['is_active']) ? (bool)$input['is_active'] : true;
            
            // Validate
            $errors = $party->validate();
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['error' => $errors[0], 'details' => $errors]);
                return;
            }
            
            // Check for duplicate name
            $existing = $this->partyRepository->findByName($party->name);
            if ($existing) {
                http_response_code(400);
                echo json_encode(['error' => 'Party with this name already exists']);
                return;
            }

            $existingGst = $this->partyRepository->findByGstNumber($party->gstNumber);
            if ($existingGst) {
                http_response_code(400);
                echo json_encode(['error' => 'GST already exists']);
                return;
            }
            
            $newParty = $this->partyRepository->create($party);
            $id = $newParty->id;

            // Apply CRM profile fields if provided (same as update)
            $profileKeys = [
                'region', 'product_category', 'production_capacity', 'factory_locations',
                'credit_limit', 'payment_terms_days', 'technical_notes',
                'products_introduced', 'monthly_consumption', 'year_of_association',
                'order_frequency', 'last_order_date', 'last_visit_date', 'payment_track',
                'target_volume', 'next_followup_date', 'assigned_sales_owner',
                'number_of_plants', 'general_notes',
                'funnel_stage', 'industry_type', 'tiles_subtype',
                'monthly_consumption_ton', 'avg_price_per_ton', 'current_supplier_details',
                'relation_with_purchase', 'relation_with_internal_team', 'probability_of_conversion',
                'visit_description', 'followup_notes', 'visit_samples_provided'
            ];
            $updateData = [];
            foreach ($profileKeys as $key) {
                if (!array_key_exists($key, $input)) continue;
                $v = $input[$key];
                if ($key === 'credit_limit' || $key === 'monthly_consumption_ton' || $key === 'avg_price_per_ton') {
                    $updateData[$key] = ($v !== null && $v !== '') ? (float)$v : null;
                } elseif (in_array($key, ['year_of_association', 'payment_terms_days', 'assigned_sales_owner', 'number_of_plants', 'relation_with_purchase', 'relation_with_internal_team', 'probability_of_conversion'])) {
                    $updateData[$key] = ($v !== null && $v !== '') ? (int)$v : null;
                } elseif ($key === 'visit_samples_provided') {
                    $updateData[$key] = is_array($v) ? $v : null;
                } else {
                    $updateData[$key] = is_string($v) ? trim($v) : $v;
                    if ($updateData[$key] === '') $updateData[$key] = null;
                }
            }
            if (!empty($updateData)) {
                $this->partyRepository->update($id, $updateData);
                $newParty = $this->partyRepository->findById($id);
            }

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Party created successfully',
                'data' => $newParty->toArray()
            ]);
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                http_response_code(400);
                echo json_encode(['error' => 'GST already exists']);
                return;
            }
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
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
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'accounts', 'crm', 'sales', 'marketing'])) {
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

            if (isset($input['gst_number']) || isset($input['gst_no'])) {
                $updateData['gst_number'] = Party::normalizeGstNumber($input['gst_number'] ?? $input['gst_no'] ?? '');
            }
            
            if (isset($input['phone'])) {
                $updateData['phone'] = trim($input['phone']);
            }
            
            if (isset($input['email'])) {
                $updateData['email'] = trim($input['email']);
            }
            
            if (isset($input['address'])) {
                $updateData['address'] = trim($input['address']);
            }
            
            if (isset($input['is_active'])) {
                $updateData['is_active'] = (bool)$input['is_active'];
            }
            if (array_key_exists('region', $input)) {
                $updateData['region'] = $input['region'] !== null && $input['region'] !== '' ? trim($input['region']) : null;
            }
            if (array_key_exists('product_category', $input)) {
                $updateData['product_category'] = $input['product_category'] !== null && $input['product_category'] !== '' ? trim($input['product_category']) : null;
            }
            if (array_key_exists('production_capacity', $input)) {
                $updateData['production_capacity'] = $input['production_capacity'] !== null && $input['production_capacity'] !== '' ? trim($input['production_capacity']) : null;
            }
            if (array_key_exists('factory_locations', $input)) {
                $updateData['factory_locations'] = $input['factory_locations'] !== null && $input['factory_locations'] !== '' ? trim($input['factory_locations']) : null;
            }
            if (array_key_exists('credit_limit', $input)) {
                $updateData['credit_limit'] = $input['credit_limit'] !== null && $input['credit_limit'] !== '' ? (float)$input['credit_limit'] : null;
            }
            if (array_key_exists('payment_terms_days', $input)) {
                $updateData['payment_terms_days'] = $input['payment_terms_days'] !== null && $input['payment_terms_days'] !== '' ? (int)$input['payment_terms_days'] : null;
            }
            if (array_key_exists('technical_notes', $input)) {
                $updateData['technical_notes'] = $input['technical_notes'] !== null ? trim($input['technical_notes']) : null;
            }
            if (array_key_exists('products_introduced', $input)) {
                $updateData['products_introduced'] = $input['products_introduced'] !== null && $input['products_introduced'] !== '' ? trim($input['products_introduced']) : null;
            }
            if (array_key_exists('monthly_consumption', $input)) {
                $updateData['monthly_consumption'] = $input['monthly_consumption'] !== null && $input['monthly_consumption'] !== '' ? trim($input['monthly_consumption']) : null;
            }
            if (array_key_exists('year_of_association', $input)) {
                $updateData['year_of_association'] = $input['year_of_association'] !== null && $input['year_of_association'] !== '' ? (int)$input['year_of_association'] : null;
            }
            if (array_key_exists('order_frequency', $input)) {
                $updateData['order_frequency'] = $input['order_frequency'] !== null && $input['order_frequency'] !== '' ? trim($input['order_frequency']) : null;
            }
            if (array_key_exists('last_order_date', $input)) {
                $updateData['last_order_date'] = $input['last_order_date'] !== null && $input['last_order_date'] !== '' ? trim($input['last_order_date']) : null;
            }
            if (array_key_exists('last_visit_date', $input)) {
                $updateData['last_visit_date'] = $input['last_visit_date'] !== null && $input['last_visit_date'] !== '' ? trim($input['last_visit_date']) : null;
            }
            if (array_key_exists('payment_track', $input)) {
                $updateData['payment_track'] = $input['payment_track'] !== null && $input['payment_track'] !== '' ? trim($input['payment_track']) : null;
            }
            if (array_key_exists('target_volume', $input)) {
                $updateData['target_volume'] = $input['target_volume'] !== null && $input['target_volume'] !== '' ? trim($input['target_volume']) : null;
            }
            if (array_key_exists('next_followup_date', $input)) {
                $updateData['next_followup_date'] = $input['next_followup_date'] !== null && $input['next_followup_date'] !== '' ? trim($input['next_followup_date']) : null;
            }
            if (array_key_exists('assigned_sales_owner', $input)) {
                $updateData['assigned_sales_owner'] = $input['assigned_sales_owner'] !== null && $input['assigned_sales_owner'] !== '' ? (int)$input['assigned_sales_owner'] : null;
            }
            if (array_key_exists('number_of_plants', $input)) {
                $updateData['number_of_plants'] = $input['number_of_plants'] !== null && $input['number_of_plants'] !== '' ? (int)$input['number_of_plants'] : null;
            }
            if (array_key_exists('general_notes', $input)) {
                $updateData['general_notes'] = $input['general_notes'] !== null ? trim($input['general_notes']) : null;
            }
            if (array_key_exists('funnel_stage', $input)) {
                $updateData['funnel_stage'] = $input['funnel_stage'] !== null && $input['funnel_stage'] !== '' ? trim($input['funnel_stage']) : null;
            }
            if (array_key_exists('industry_type', $input)) {
                $updateData['industry_type'] = $input['industry_type'] !== null && $input['industry_type'] !== '' ? trim($input['industry_type']) : null;
            }
            if (array_key_exists('tiles_subtype', $input)) {
                $updateData['tiles_subtype'] = $input['tiles_subtype'] !== null && $input['tiles_subtype'] !== '' ? trim($input['tiles_subtype']) : null;
            }
            if (array_key_exists('monthly_consumption_ton', $input)) {
                $updateData['monthly_consumption_ton'] = $input['monthly_consumption_ton'] !== null && $input['monthly_consumption_ton'] !== '' ? (float)$input['monthly_consumption_ton'] : null;
            }
            if (array_key_exists('avg_price_per_ton', $input)) {
                $updateData['avg_price_per_ton'] = $input['avg_price_per_ton'] !== null && $input['avg_price_per_ton'] !== '' ? (float)$input['avg_price_per_ton'] : null;
            }
            if (array_key_exists('current_supplier_details', $input)) {
                $updateData['current_supplier_details'] = $input['current_supplier_details'] !== null ? trim($input['current_supplier_details']) : null;
            }
            if (array_key_exists('relation_with_purchase', $input)) {
                $v = $input['relation_with_purchase'];
                $updateData['relation_with_purchase'] = ($v !== null && $v !== '') ? max(1, min(5, (int)$v)) : null;
            }
            if (array_key_exists('relation_with_internal_team', $input)) {
                $v = $input['relation_with_internal_team'];
                $updateData['relation_with_internal_team'] = ($v !== null && $v !== '') ? max(1, min(5, (int)$v)) : null;
            }
            if (array_key_exists('probability_of_conversion', $input)) {
                $v = $input['probability_of_conversion'];
                $updateData['probability_of_conversion'] = ($v !== null && $v !== '') ? max(1, min(5, (int)$v)) : null;
            }
            if (array_key_exists('visit_description', $input)) {
                $updateData['visit_description'] = $input['visit_description'] !== null ? trim($input['visit_description']) : null;
            }
            if (array_key_exists('followup_notes', $input)) {
                $updateData['followup_notes'] = $input['followup_notes'] !== null ? trim($input['followup_notes']) : null;
            }
            if (array_key_exists('visit_samples_provided', $input)) {
                $v = $input['visit_samples_provided'];
                $updateData['visit_samples_provided'] = is_array($v) ? $v : null;
            }

            if (empty($updateData)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                return;
            }

            $coreFields = ['name', 'contact_person', 'gst_number', 'phone', 'email'];
            $touchesCore = !empty(array_intersect(array_keys($updateData), $coreFields));
            if ($touchesCore) {
                $candidate = new Party(array_merge($existingParty->toArray(), $updateData));
                $errors = $candidate->validate();
                if (!empty($errors)) {
                    http_response_code(400);
                    echo json_encode(['error' => $errors[0], 'details' => $errors]);
                    return;
                }
                if (!empty($candidate->gstNumber)) {
                    $existingGst = $this->partyRepository->findByGstNumber($candidate->gstNumber, $id);
                    if ($existingGst) {
                        http_response_code(400);
                        echo json_encode(['error' => 'GST already exists']);
                        return;
                    }
                }
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
        
        // Destructive: admin only.
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Only an admin can delete parties. Contact admin if deletion is required.']);
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
            $this->respondServerError('Failed to delete party', $e);
        }
    }

    /**
     * Import parties from CSV. Expects columns: Parties (or Party), email.
     * POST multipart/form-data with file = .csv
     */
    public function importFromCsv(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'accounts', 'crm', 'sales', 'marketing'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Entry or Admin access required']);
            return;
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded or upload error.']);
            return;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            http_response_code(400);
            echo json_encode(['error' => 'Only CSV files are allowed.']);
            return;
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            http_response_code(400);
            echo json_encode(['error' => 'CSV file must be between 1 byte and 5MB']);
            return;
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid uploaded file']);
            return;
        }

        $content = file_get_contents($tmpName);
        if ($content === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Could not read file.']);
            return;
        }

        try {
            $service = new PartyImportService();
            $result = $service->importFromCsv($content);
            if (!$result['success']) {
                http_response_code(422);
            }
            echo json_encode([
                'success' => $result['success'],
                'created' => $result['created'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'errors' => $result['errors'],
                'preview' => $result['preview'],
                'columns' => $result['columns'] ?? null,
            ]);
        } catch (\Exception $e) {
            $this->respondServerError('Failed to import parties CSV', $e);
        }
    }

    private function respondServerError(string $message, \Throwable $e): void
    {
        error_log($message . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $message]);
    }
}
