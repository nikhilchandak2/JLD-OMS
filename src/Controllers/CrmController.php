<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Repositories\CrmLeadRepository;
use App\Repositories\CrmDealRepository;
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
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin'])) {
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
            $leadCounts = [];
            $stmt = $pdo->query("SELECT stage, COUNT(*) AS cnt FROM crm_leads GROUP BY stage");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $leadCounts[$row['stage']] = (int)$row['cnt'];
            }
            $dealCounts = [];
            $stmt = $pdo->query("SELECT stage, COUNT(*) AS cnt FROM crm_deals GROUP BY stage");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $dealCounts[$row['stage']] = (int)$row['cnt'];
            }
            $totalLeads = array_sum($leadCounts);
            $totalDeals = array_sum($dealCounts);
            $stmt = $pdo->query("SELECT COUNT(*) FROM crm_activities WHERE DATE(activity_date) = CURDATE()");
            $activitiesToday = (int)$stmt->fetchColumn();
            echo json_encode([
                'success' => true,
                'data' => [
                    'leads_by_stage' => $leadCounts,
                    'deals_by_stage' => $dealCounts,
                    'total_leads' => $totalLeads,
                    'total_deals' => $totalDeals,
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
}
