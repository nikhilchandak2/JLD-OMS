<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\CrmActivityRepository;
use App\Core\Database;

class CrmController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    private function requireCrmAccess(): bool
    {
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'crm'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Entry or Admin access required']);
            return false;
        }
        return true;
    }

    public function summary(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        try {
            $db = new Database();
            $pdo = $db->getConnection();
            $stmt = $pdo->query("SELECT COUNT(*) FROM crm_activities WHERE DATE(activity_date) = CURDATE()");
            $activitiesToday = (int)$stmt->fetchColumn();
            echo json_encode([
                'success' => true,
                'data' => [
                    'activities_today' => $activitiesToday,
                ],
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function stages(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $config = require __DIR__ . '/../../config/crm_stages.php';
        echo json_encode(['success' => true, 'data' => $config]);
    }

    /** For CRM dropdowns: list users as id + name (sales owner assignment) */
    public function userOptions(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        try {
            $pdo = (new Database())->getConnection();
            $stmt = $pdo->query("SELECT id, name FROM users ORDER BY name");
            $list = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $list[] = ['id' => (int)$row['id'], 'name' => $row['name'] ?? ''];
            }
            echo json_encode(['success' => true, 'data' => $list]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * log.md: 5-stage funnel – counts and total value per stage, optional parties list per stage.
     * GET /api/crm/funnel – summary; GET /api/crm/funnel?stage=sampling – parties in that stage.
     */
    public function funnel(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $config = require __DIR__ . '/../../config/crm_stages.php';
        $stages = $config['funnel_stages'] ?? [];
        try {
            $pdo = (new Database())->getConnection();
            $stageFilter = isset($_GET['stage']) ? trim($_GET['stage']) : null;
            if ($stageFilter !== null && $stageFilter !== '') {
                if (!isset($stages[$stageFilter])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid stage']);
                    return;
                }
                $stmt = $pdo->prepare("
                    SELECT id, name, contact_person, email, funnel_stage,
                           monthly_consumption_ton, avg_price_per_ton,
                           (COALESCE(monthly_consumption_ton, 0) * COALESCE(avg_price_per_ton, 0)) AS funnel_value
                    FROM parties
                    WHERE funnel_stage = ?
                    ORDER BY name
                ");
                $stmt->execute([$stageFilter]);
                $list = [];
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $list[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'contact_person' => $row['contact_person'],
                        'email' => $row['email'],
                        'funnel_value' => $row['funnel_value'] ? (float)$row['funnel_value'] : null,
                    ];
                }
                echo json_encode(['success' => true, 'data' => $list, 'stage' => $stageFilter, 'stage_label' => $stages[$stageFilter]]);
                return;
            }
            $stmt = $pdo->query("
                SELECT funnel_stage,
                       COUNT(*) AS cnt,
                       SUM(COALESCE(monthly_consumption_ton, 0) * COALESCE(avg_price_per_ton, 0)) AS total_value
                FROM parties
                WHERE funnel_stage IS NOT NULL AND funnel_stage != ''
                GROUP BY funnel_stage
            ");
            $byStage = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $byStage[$row['funnel_stage']] = [
                    'count' => (int)$row['cnt'],
                    'total_value' => (float)$row['total_value'],
                ];
            }
            $summary = [];
            foreach ($stages as $key => $label) {
                $summary[] = [
                    'stage' => $key,
                    'label' => $label,
                    'count' => $byStage[$key]['count'] ?? 0,
                    'total_value' => $byStage[$key]['total_value'] ?? 0,
                ];
            }
            echo json_encode(['success' => true, 'data' => $summary]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
