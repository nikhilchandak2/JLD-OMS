<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Core\Database;

class DumperAssignmentController
{
    private AuthService $authService;
    private Database $database;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->database = new Database();
    }

    /**
     * List excavating machines (for dropdown / display)
     * GET /api/excavating-machines
     */
    public function listMachines(): void
    {
        header('Content-Type: application/json');
        if (!$this->authService->getCurrentUser()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $sql = "SELECT * FROM excavating_machines WHERE is_active = 1 ORDER BY sort_order ASC, id ASC";
        $machines = $this->database->fetchAll($sql);
        echo json_encode(['success' => true, 'data' => $machines]);
    }

    /**
     * Update excavating machine (name, mine_name)
     * PUT /api/excavating-machines/{id}
     */
    public function updateMachine(int $id): void
    {
        header('Content-Type: application/json');
        if (!$this->authService->getCurrentUser()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim($input['name'] ?? '');
        $mineName = trim($input['mine_name'] ?? '');
        if ($name === '' || $mineName === '') {
            http_response_code(400);
            echo json_encode(['error' => 'name and mine_name required']);
            return;
        }
        $this->database->execute(
            "UPDATE excavating_machines SET name = ?, mine_name = ?, updated_at = NOW() WHERE id = ?",
            [$name, $mineName, $id]
        );
        echo json_encode(['success' => true]);
    }

    /**
     * Get dumper assignments for a date (grouped by machine)
     * GET /api/dumper-assignments?date=YYYY-MM-DD
     */
    public function getAssignments(): void
    {
        header('Content-Type: application/json');
        if (!$this->authService->getCurrentUser()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date']);
            return;
        }
        $sql = "
            SELECT a.id, a.assignment_date, a.excavating_machine_id, a.vehicle_id,
                   m.name as machine_name, m.mine_name,
                   v.vehicle_number
            FROM dumper_assignments a
            JOIN excavating_machines m ON m.id = a.excavating_machine_id
            JOIN vehicles v ON v.id = a.vehicle_id
            WHERE a.assignment_date = ?
            ORDER BY m.sort_order, m.id, v.vehicle_number
        ";
        $rows = $this->database->fetchAll($sql, [$date]);
        $byMachine = [];
        foreach ($rows as $r) {
            $mid = (int)$r['excavating_machine_id'];
            if (!isset($byMachine[$mid])) {
                $byMachine[$mid] = [
                    'excavating_machine_id' => $mid,
                    'machine_name' => $r['machine_name'],
                    'mine_name' => $r['mine_name'],
                    'assignments' => [],
                ];
            }
            $byMachine[$mid]['assignments'][] = [
                'id' => (int)$r['id'],
                'vehicle_id' => (int)$r['vehicle_id'],
                'vehicle_number' => $r['vehicle_number'],
            ];
        }
        $machinesOrdered = $this->database->fetchAll("SELECT id, name, mine_name, sort_order FROM excavating_machines WHERE is_active = 1 ORDER BY sort_order, id");
        $result = [];
        foreach ($machinesOrdered as $m) {
            $mid = (int)$m['id'];
            $result[] = [
                'excavating_machine_id' => $mid,
                'machine_name' => $m['name'],
                'mine_name' => $m['mine_name'],
                'assignments' => $byMachine[$mid]['assignments'] ?? [],
            ];
        }
        echo json_encode(['success' => true, 'date' => $date, 'data' => $result]);
    }

    /**
     * Add a dumper to a machine for a date
     * POST /api/dumper-assignments
     */
    public function addAssignment(): void
    {
        header('Content-Type: application/json');
        if (!$this->authService->getCurrentUser()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $date = trim($input['date'] ?? date('Y-m-d'));
        $machineId = (int)($input['excavating_machine_id'] ?? 0);
        $vehicleId = (int)($input['vehicle_id'] ?? 0);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $machineId < 1 || $vehicleId < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'date, excavating_machine_id, vehicle_id required']);
            return;
        }
        try {
            $this->database->execute(
                "INSERT INTO dumper_assignments (assignment_date, excavating_machine_id, vehicle_id) VALUES (?, ?, ?)",
                [$date, $machineId, $vehicleId]
            );
            $id = (int)$this->database->lastInsertId();
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (\PDOException $e) {
            if ((int)$e->getCode() === 23000 || $e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'This dumper is already assigned to a machine on this date']);
                return;
            }
            throw $e;
        }
    }

    /**
     * Remove a dumper assignment
     * DELETE /api/dumper-assignments/{id}
     */
    public function removeAssignment(int $id): void
    {
        header('Content-Type: application/json');
        if (!$this->authService->getCurrentUser()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $this->database->execute("DELETE FROM dumper_assignments WHERE id = ?", [$id]);
        echo json_encode(['success' => true]);
    }
}
