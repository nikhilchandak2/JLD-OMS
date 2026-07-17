<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\AuthService;
use App\Repositories\VisitRequestRepository;
use App\Support\IndianDate;

/**
 * Client visit requests: marketing raises a request, technical team
 * accepts, schedules and completes the visit. Completed visits are
 * logged into crm_activities so CRM reporting picks them up.
 */
class VisitRequestController
{
    private const ALLOWED_PRIORITIES = ['normal', 'urgent'];

    private AuthService $authService;
    private VisitRequestRepository $visitRequestRepository;
    private Database $database;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->visitRequestRepository = new VisitRequestRepository();
        $this->database = new Database();
    }

    /** GET /api/visit-requests */
    public function index(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'marketing', 'technical', 'crm'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Visit requests access required']);
            return;
        }

        $status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : null;
        if ($status !== null && !in_array($status, ['pending', 'accepted', 'scheduled', 'completed', 'cancelled'], true)) {
            $status = null;
        }

        $filters = [
            'status' => $status,
            'party_id' => isset($_GET['party_id']) ? max(0, (int)$_GET['party_id']) : null,
        ];

        // "mine=1": marketing sees own requests, technical sees own assignments.
        if (!empty($_GET['mine'])) {
            if ($this->authService->hasRole('technical')) {
                $filters['assigned_to'] = (int)$user['id'];
            } else {
                $filters['requested_by'] = (int)$user['id'];
            }
        }

        try {
            $list = $this->visitRequestRepository->findAll($filters);
            echo json_encode(['success' => true, 'data' => $list]);
        } catch (\Exception $e) {
            error_log('Failed to fetch visit requests: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch visit requests']);
        }
    }

    /** POST /api/visit-requests */
    public function create(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'marketing', 'crm'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Marketing access required to raise visit requests']);
            return;
        }

        $input = $this->getJsonOrPostInput();

        $partyId = isset($input['party_id']) ? (int)$input['party_id'] : 0;
        $purpose = trim((string)($input['purpose'] ?? ''));
        $preferredDate = isset($input['preferred_date']) && $input['preferred_date'] !== '' ? (string)$input['preferred_date'] : null;
        $priority = strtolower(trim((string)($input['priority'] ?? 'normal')));

        $errors = [];
        if ($partyId <= 0) {
            $errors[] = 'Valid party ID is required';
        }
        if ($purpose === '') {
            $errors[] = 'Purpose is required';
        } elseif (strlen($purpose) > 500) {
            $errors[] = 'Purpose must be at most 500 characters';
        }
        if ($preferredDate !== null && !$this->isValidDate($preferredDate)) {
            $errors[] = 'Valid preferred date is required (DD/MM/YYYY or YYYY-MM-DD)';
        }
        if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
            $errors[] = 'Priority must be normal or urgent';
        }

        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['error' => 'Validation failed', 'details' => $errors]);
            return;
        }

        $party = $this->database->fetch("SELECT id FROM parties WHERE id = ? AND is_active = 1", [$partyId]);
        if (!$party) {
            http_response_code(404);
            echo json_encode(['error' => 'Party not found or inactive']);
            return;
        }

        try {
            $id = $this->visitRequestRepository->create(
                $partyId,
                (int)$user['id'],
                $purpose,
                $preferredDate !== null ? IndianDate::toStorage($preferredDate) : null,
                $priority
            );

            $this->logAuditEvent((int)$user['id'], 'visit_requests', $id, 'CREATE', null, [
                'party_id' => $partyId,
                'purpose' => $purpose,
                'priority' => $priority,
                'status' => 'pending'
            ]);

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Visit request raised. The technical team will pick it up.',
                'data' => $this->visitRequestRepository->findById($id)
            ]);
        } catch (\Exception $e) {
            error_log('Failed to create visit request: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create visit request']);
        }
    }

    /** PUT /api/visit-requests/{id} — body: { action: accept|schedule|complete|cancel, ... } */
    public function update(int $id): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'marketing', 'technical', 'crm'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Visit requests access required']);
            return;
        }

        $request = $this->visitRequestRepository->findById($id);
        if (!$request) {
            http_response_code(404);
            echo json_encode(['error' => 'Visit request not found']);
            return;
        }

        $input = $this->getJsonOrPostInput();
        $action = strtolower(trim((string)($input['action'] ?? '')));
        $isAdmin = $this->authService->hasRole('admin');
        $isTechnical = $this->authService->hasRole('technical');
        $currentStatus = (string)$request['status'];

        try {
            switch ($action) {
                case 'accept':
                    if (!$isTechnical && !$isAdmin) {
                        $this->forbid('Only the technical team can accept visit requests');
                        return;
                    }
                    if ($currentStatus !== 'pending') {
                        $this->conflict("Only pending requests can be accepted (current: {$currentStatus})");
                        return;
                    }
                    $this->visitRequestRepository->update($id, [
                        'status' => 'accepted',
                        'assigned_to' => (int)$user['id']
                    ]);
                    break;

                case 'schedule':
                    if (!$isTechnical && !$isAdmin) {
                        $this->forbid('Only the technical team can schedule visits');
                        return;
                    }
                    if (!in_array($currentStatus, ['pending', 'accepted'], true)) {
                        $this->conflict("Only pending/accepted requests can be scheduled (current: {$currentStatus})");
                        return;
                    }
                    $scheduledDate = (string)($input['scheduled_date'] ?? '');
                    if (!$this->isValidDate($scheduledDate)) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Valid scheduled date is required (DD/MM/YYYY or YYYY-MM-DD)']);
                        return;
                    }
                    $this->visitRequestRepository->update($id, [
                        'status' => 'scheduled',
                        'scheduled_date' => IndianDate::toStorage($scheduledDate),
                        'assigned_to' => $request['assigned_to'] ?: (int)$user['id']
                    ]);
                    break;

                case 'complete':
                    if (!$isTechnical && !$isAdmin) {
                        $this->forbid('Only the technical team can complete visits');
                        return;
                    }
                    if (!in_array($currentStatus, ['pending', 'accepted', 'scheduled'], true)) {
                        $this->conflict("Request cannot be completed (current: {$currentStatus})");
                        return;
                    }
                    $outcome = trim((string)($input['visit_outcome'] ?? ''));
                    if ($outcome === '') {
                        http_response_code(400);
                        echo json_encode(['error' => 'Visit outcome is required when completing a visit']);
                        return;
                    }
                    if (strlen($outcome) > 1000) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Visit outcome must be at most 1000 characters']);
                        return;
                    }
                    $this->completeVisit($request, $outcome, (int)$user['id']);
                    break;

                case 'cancel':
                    $isRequester = (int)$request['requested_by'] === (int)$user['id'];
                    if (!$isAdmin && !$isRequester) {
                        $this->forbid('Only the requester or admin can cancel a visit request');
                        return;
                    }
                    if (in_array($currentStatus, ['completed', 'cancelled'], true)) {
                        $this->conflict("Request cannot be cancelled (current: {$currentStatus})");
                        return;
                    }
                    $this->visitRequestRepository->update($id, ['status' => 'cancelled']);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'action must be accept, schedule, complete or cancel']);
                    return;
            }

            $updated = $this->visitRequestRepository->findById($id);

            $this->logAuditEvent((int)$user['id'], 'visit_requests', $id, 'UPDATE', $request, $updated);

            echo json_encode([
                'success' => true,
                'message' => 'Visit request updated',
                'data' => $updated
            ]);
        } catch (\Exception $e) {
            error_log('Failed to update visit request: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update visit request']);
        }
    }

    /**
     * Complete the visit and reflect it in CRM: crm_activities entry of type
     * 'visit' + parties.last_visit_date, all in one transaction.
     */
    private function completeVisit(array $request, string $outcome, int $userId): void
    {
        $this->database->beginTransaction();
        try {
            $this->visitRequestRepository->update((int)$request['id'], [
                'status' => 'completed',
                'visit_outcome' => $outcome,
                'assigned_to' => $request['assigned_to'] ?: $userId
            ]);

            $today = date('Y-m-d');

            $this->database->execute(
                "INSERT INTO crm_activities (party_id, type, subject, description, activity_date, created_by)
                 VALUES (?, 'visit', ?, ?, ?, ?)",
                [
                    (int)$request['party_id'],
                    'Technical visit: ' . mb_substr((string)$request['purpose'], 0, 200),
                    $outcome,
                    $today,
                    $userId
                ]
            );

            $this->database->execute(
                "UPDATE parties SET last_visit_date = ? WHERE id = ?",
                [$today, (int)$request['party_id']]
            );

            $this->database->commit();
        } catch (\Exception $e) {
            $this->database->rollback();
            throw $e;
        }
    }

    private function forbid(string $message): void
    {
        http_response_code(403);
        echo json_encode(['error' => $message]);
    }

    private function conflict(string $message): void
    {
        http_response_code(409);
        echo json_encode(['error' => $message]);
    }

    private function isValidDate(string $date): bool
    {
        return \App\Support\IndianDate::isValid($date);
    }

    private function getJsonOrPostInput(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return is_array($_POST) ? $_POST : [];
    }

    private function logAuditEvent(?int $userId, string $tableName, int $recordId, string $action, ?array $oldValues, ?array $newValues): void
    {
        if (!$userId) {
            return;
        }

        $this->database->execute(
            "INSERT INTO audit_logs (user_id, table_name, record_id, action, old_values, new_values)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $userId,
                $tableName,
                $recordId,
                $action,
                $oldValues ? json_encode($oldValues) : null,
                $newValues ? json_encode($newValues) : null
            ]
        );
    }
}
