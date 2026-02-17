<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\ProductRepository;
use App\Models\Product;

class ProductController
{
    private AuthService $authService;
    private ProductRepository $productRepository;
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->productRepository = new ProductRepository();
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
            $products = $this->productRepository->findAll();
            
            echo json_encode([
                'success' => true,
                'data' => array_map(fn($product) => $product->toArray(), $products)
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
            $product = $this->productRepository->findById($id);
            
            if (!$product) {
                http_response_code(404);
                echo json_encode(['error' => 'Product not found']);
                return;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $product->toArray()
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
            // Create product object
            $product = new Product();
            $product->code = trim($input['code'] ?? '');
            $product->name = trim($input['name'] ?? '');
            $product->isActive = isset($input['is_active']) ? (bool)$input['is_active'] : true;
            
            // Validate
            $errors = $product->validate();
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['error' => 'Validation failed', 'details' => $errors]);
                return;
            }
            
            // Check for duplicate code
            $existing = $this->productRepository->findByCode($product->code);
            if ($existing) {
                http_response_code(400);
                echo json_encode(['error' => 'Product with this code already exists']);
                return;
            }
            
            $newProduct = $this->productRepository->create($product);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $newProduct->toArray()
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
            // Check if product exists
            $existingProduct = $this->productRepository->findById($id);
            if (!$existingProduct) {
                http_response_code(404);
                echo json_encode(['error' => 'Product not found']);
                return;
            }
            
            $updateData = [];
            
            // Only update provided fields
            if (isset($input['code']) && !empty($input['code'])) {
                $code = trim($input['code']);
                // Check for duplicate code (excluding current product)
                $existing = $this->productRepository->findByCode($code);
                if ($existing && $existing->id !== $id) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Product with this code already exists']);
                    return;
                }
                $updateData['code'] = $code;
            }
            
            if (isset($input['name']) && !empty($input['name'])) {
                $updateData['name'] = trim($input['name']);
            }
            
            if (isset($input['is_active'])) {
                $updateData['is_active'] = (bool)$input['is_active'];
            }
            
            if (empty($updateData)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                return;
            }
            
            $updatedProduct = $this->productRepository->update($id, $updateData);
            
            echo json_encode([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $updatedProduct->toArray()
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
            $success = $this->productRepository->delete($id);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Product deleted successfully'
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Product not found']);
            }
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
