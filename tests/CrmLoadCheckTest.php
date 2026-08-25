<?php

namespace Tests;

use App\Repositories\AccountDormancySignalRepository;
use App\Services\PipelineDashboardService;

/**
 * TASK 11 load check. B7: 300–600 parties, ~100 deals/month, 3 years under 150k rows.
 * This test uses 3-year deal volume on the dashboard snapshot (3,600 rows) and a
 * 2,500-order slice for the nightly activity query. Full 3-year dispatch volume
 * is timed by scripts/crm_load_check.php.
 */
class CrmLoadCheckTest extends DatabaseTestCase
{
    public function testDashboardQueriesStayFastAtThreeYearDealVolume(): void
    {
        $asOf = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        $this->fillSnapshot($asOf, 3600);

        $svc = new PipelineDashboardService();
        $admin = ['id' => 1, 'role' => 'admin'];

        $start = microtime(true);
        $data = $svc->dashboard($admin);
        $dashMs = (microtime(true) - $start) * 1000;
        self::assertTrue($data['refreshed']);
        self::assertGreaterThan(0, array_sum(array_column($data['by_stage'], 'deal_count')));
        self::assertLessThan(1500, $dashMs, "Dashboard took {$dashMs}ms at 3,600 snapshot rows.");

        $start = microtime(true);
        $svc->explainByStage();
        $svc->explainTimeInStage();
        $explainMs = (microtime(true) - $start) * 1000;
        self::assertLessThan(500, $explainMs, "EXPLAIN pair took {$explainMs}ms.");
    }

    public function testNightlyActivitySnapshotStaysFastOnIndexedOrders(): void
    {
        $asOf = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        $companyId = $this->createCompany();
        $productId = $this->createProduct();
        $user = $this->createUser('admin');
        $partyIds = [];
        for ($i = 0; $i < 80; $i++) {
            $partyIds[] = $this->createParty();
        }

        $rows = [];
        $params = [];
        $n = 0;
        foreach ($partyIds as $partyId) {
            for ($o = 0; $o < 30; $o++) {
                $rows[] = '(?, ?, ?, ?, 1, ?, ?, \'pending\')';
                $date = (new \DateTimeImmutable('2024-01-01'))->modify("+{$n} days")->format('Y-m-d');
                array_push($params, $companyId, 'LD' . $this->uniqueSuffix() . $n, $date, $productId, $partyId, $user['id']);
                $n++;
                if (count($rows) >= 100) {
                    $this->flushOrders($rows, $params);
                    $rows = [];
                    $params = [];
                }
            }
        }
        if ($rows !== []) {
            $this->flushOrders($rows, $params);
        }

        $repo = new AccountDormancySignalRepository();
        $start = microtime(true);
        $activity = $repo->activitySnapshot($asOf);
        $ms = (microtime(true) - $start) * 1000;
        self::assertNotEmpty($activity);
        self::assertLessThan(2000, $ms, "Nightly activity snapshot took {$ms}ms on 2,400 orders.");
    }

    private function fillSnapshot(string $asOf, int $deals): void
    {
        $this->database->execute('DELETE FROM pipeline_time_in_stage_facts');
        $this->database->execute('DELETE FROM pipeline_deal_snapshot_grades');
        $this->database->execute('DELETE FROM pipeline_deal_snapshot');

        $batch = [];
        $params = [];
        $facts = [];
        $factParams = [];
        for ($i = 1; $i <= $deals; $i++) {
            $stage = (($i - 1) % 7) + 1;
            $batch[] = '(?, ?, ?, \'active\', NULL, NULL, 1, \'Load Party\', ?, 1000, ?, NULL)';
            array_push($params, $asOf, $i, $stage, "Deal {$i}", '2026-01-01');
            $facts[] = '(?, ?, ?, \'active\', NULL, NULL, 1, \'Load Party\', ?, ?, 1, 86400, 0, 86400, 0)';
            array_push($factParams, $asOf, $i, $stage, "Deal {$i}", '2026-01-01');
            if (count($batch) >= 100) {
                $this->flushSnapshot($batch, $params, $facts, $factParams);
                $batch = [];
                $params = [];
                $facts = [];
                $factParams = [];
            }
        }
        if ($batch !== []) {
            $this->flushSnapshot($batch, $params, $facts, $factParams);
        }
    }

    /**
     * @param list<string> $batch
     * @param list<mixed> $params
     * @param list<string> $facts
     * @param list<mixed> $factParams
     */
    private function flushSnapshot(array $batch, array $params, array $facts, array $factParams): void
    {
        $this->database->execute(
            'INSERT INTO pipeline_deal_snapshot
                (as_of, deal_id, stage, status, owner_user_id, owner_name, party_id, party_name, title, indicative_value, inquiry_date, stage_entered_at)
             VALUES ' . implode(',', $batch),
            $params
        );
        $this->database->execute(
            'INSERT INTO pipeline_time_in_stage_facts
                (as_of, deal_id, stage, status, owner_user_id, owner_name, party_id, party_name, title, inquiry_date, is_current, lifetime_seconds, hold_seconds, current_dwell_seconds, current_hold_seconds)
             VALUES ' . implode(',', $facts),
            $factParams
        );
    }

    /**
     * @param list<string> $rows
     * @param list<mixed> $params
     */
    private function flushOrders(array $rows, array $params): void
    {
        $this->database->execute(
            'INSERT INTO orders (company_id, order_no, order_date, product_id, order_qty_trucks, party_id, created_by, status)
             VALUES ' . implode(',', $rows),
            $params
        );
    }
}
