<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\CrmTaskRepository;
use App\Models\CrmTask;

class CrmTaskController
{
    private AuthService $authService;
    private CrmTaskRepository $taskRepo;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->taskRepo = new CrmTaskRepository();
    }

    /**
     * GET /api/crm/tasks
     * - Default: tasks assigned to current user
     * - Admin can pass ?all=1 to see all tasks
     */
    public function index(): void
    {
        header('Content-Type: application/json');
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'crm', 'entry'])) {
            http_response_code(403);
            echo json_encode(['error' => 'CRM access required']);
            return;
        }

        $all = isset($_GET['all']) && (string)$_GET['all'] === '1';
        $list = [];
        if ($all && $this->authService->hasRole('admin')) {
            $list = $this->taskRepo->findAll();
        } else {
            $list = $this->taskRepo->findMine((int)$user['id']);
        }

        echo json_encode([
            'success' => true,
            'data' => array_map(static function (CrmTask $t) {
                return $t->toArray();
            }, $list)
        ]);
    }

    /**
     * POST /api/crm/tasks (admin only)
     * Body: {title, description?, due_date?, assigned_to, party_id?}
     */
    public function create(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasRole('admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        $title = isset($input['title']) ? trim((string)$input['title']) : '';
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'title is required']);
            return;
        }

        $assignedTo = isset($input['assigned_to']) && $input['assigned_to'] !== ''
            ? (int)$input['assigned_to']
            : null;
        if ($assignedTo === null) {
            http_response_code(400);
            echo json_encode(['error' => 'assigned_to is required']);
            return;
        }

        $description = array_key_exists('description', $input) ? (string)$input['description'] : null;
        if ($description !== null) {
            $description = trim($description);
            if ($description === '') $description = null;
        }

        $dueDate = null;
        if (isset($input['due_date']) && (string)$input['due_date'] !== '') {
            $dueDate = trim((string)$input['due_date']);
            // Basic YYYY-MM-DD validation
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                http_response_code(400);
                echo json_encode(['error' => 'due_date must be in YYYY-MM-DD format']);
                return;
            }
        }

        $partyId = null;
        if (isset($input['party_id']) && $input['party_id'] !== '') {
            $partyId = (int)$input['party_id'];
        }

        $task = new CrmTask();
        $task->title = $title;
        $task->description = $description;
        $task->partyId = $partyId;
        $task->dueDate = $dueDate;
        $task->status = 'pending';
        $task->assignedTo = $assignedTo;
        $task->createdBy = (int)$user['id'];

        $created = $this->taskRepo->create($task);

        echo json_encode([
            'success' => true,
            'message' => 'Task created',
            'data' => $created->toArray()
        ]);
    }

    /**
     * PUT /api/crm/tasks/{id}
     * Assignee can mark completed (status)
     * Admin can also re-assign and edit fields
     */
    public function update(int $id): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'crm', 'entry'])) {
            http_response_code(403);
            echo json_encode(['error' => 'CRM access required']);
            return;
        }

        $task = $this->taskRepo->findById($id);
        if (!$task) {
            http_response_code(404);
            echo json_encode(['error' => 'Task not found']);
            return;
        }

        $isAdmin = $this->authService->hasRole('admin');
        $isAssignee = ($task->assignedTo !== null && (int)$task->assignedTo === (int)$user['id']);
        if (!$isAdmin && !$isAssignee) {
            http_response_code(403);
            echo json_encode(['error' => 'Not allowed']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        $updateData = [];

        // Status update is allowed for both admin and assignee
        if (isset($input['status']) && is_string($input['status'])) {
            $status = trim($input['status']);
            if (!in_array($status, ['pending', 'completed'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status']);
                return;
            }
            $updateData['status'] = $status;
        }

        // Only admin can edit assignment / other fields
        if ($isAdmin) {
            if (array_key_exists('assigned_to', $input)) {
                $updateData['assigned_to'] = $input['assigned_to'] !== null && $input['assigned_to'] !== '' ? (int)$input['assigned_to'] : null;
            }
            if (array_key_exists('title', $input)) {
                $updateData['title'] = trim((string)$input['title']);
            }
            if (array_key_exists('description', $input)) {
                $d = $input['description'] !== null ? trim((string)$input['description']) : null;
                $updateData['description'] = ($d === '') ? null : $d;
            }
            if (array_key_exists('party_id', $input)) {
                $updateData['party_id'] = $input['party_id'] !== null && $input['party_id'] !== '' ? (int)$input['party_id'] : null;
            }
            if (array_key_exists('due_date', $input)) {
                $dd = $input['due_date'];
                if ($dd === null || $dd === '') {
                    $updateData['due_date'] = null;
                } else {
                    $dd = trim((string)$dd);
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd)) {
                        http_response_code(400);
                        echo json_encode(['error' => 'due_date must be in YYYY-MM-DD format']);
                        return;
                    }
                    $updateData['due_date'] = $dd;
                }
            }
        }

        $updated = $this->taskRepo->update($id, $updateData);

        echo json_encode([
            'success' => true,
            'message' => 'Task updated',
            'data' => $updated ? $updated->toArray() : null
        ]);
    }
}

