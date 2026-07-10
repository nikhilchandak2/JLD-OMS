<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\OrderService;

/**
 * Party-level credit requests.
 * Sales raises a request when order creation is blocked by the credit gate;
 * admin decides via the existing /api/orders/credit-approvals endpoints.
 */
class CreditRequestController
{
    private AuthService $authService;
    private OrderService $orderService;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->orderService = new OrderService();
    }

    /** GET /api/parties/{id}/credit-status */
    public function creditStatus(int $partyId): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'order_processing', 'sales', 'accounts', 'crm', 'dispatch'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }

        try {
            $status = $this->orderService->getPartyCreditStatus($partyId);
            echo json_encode(['success' => true, 'data' => $status]);
        } catch (\Exception $e) {
            if (stripos($e->getMessage(), 'not found') !== false) {
                http_response_code(404);
                echo json_encode(['error' => 'Party not found']);
                return;
            }
            error_log('Failed to fetch party credit status: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch party credit status']);
        }
    }

    /** POST /api/parties/{id}/credit-requests */
    public function create(int $partyId): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'order_processing', 'sales'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }

        $raw = file_get_contents('php://input');
        $input = json_decode($raw ?: '', true);
        if (!is_array($input)) {
            $input = is_array($_POST) ? $_POST : [];
        }

        $requestedIncrease = null;
        if (isset($input['requested_limit_increase']) && $input['requested_limit_increase'] !== '') {
            if (!is_numeric($input['requested_limit_increase']) || (float)$input['requested_limit_increase'] <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'requested_limit_increase must be a positive number']);
                return;
            }
            $requestedIncrease = (float)$input['requested_limit_increase'];
        }

        $reason = isset($input['reason']) ? trim((string)$input['reason']) : null;
        if ($reason !== null && strlen($reason) > 500) {
            http_response_code(400);
            echo json_encode(['error' => 'reason must be at most 500 characters']);
            return;
        }

        try {
            $result = $this->orderService->createPartyCreditRequest(
                $partyId,
                (int)$user['id'],
                $requestedIncrease,
                $reason ?: null
            );

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Credit request submitted for admin approval.',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'not found') !== false) {
                http_response_code(404);
                echo json_encode(['error' => 'Party not found']);
                return;
            }
            if (stripos($msg, 'already pending') !== false || stripos($msg, 'limit reached') !== false) {
                http_response_code(409);
                echo json_encode(['error' => $msg]);
                return;
            }
            error_log('Failed to create credit request: ' . $msg);
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create credit request']);
        }
    }
}
