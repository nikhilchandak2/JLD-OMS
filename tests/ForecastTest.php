<?php

namespace Tests;

use App\Services\CrmNightlyJobService;
use App\Services\DormancyService;
use App\Services\ForecastAuthorizationException;
use App\Services\ForecastException;
use App\Services\ForecastPrefillService;
use App\Services\ForecastService;
use PDOException;

class ForecastTest extends DatabaseTestCase
{
    private const AS_OF = '2026-08-25';

    private ForecastPrefillService $prefill;
    private ForecastService $forecasts;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefill = new ForecastPrefillService();
        $this->forecasts = new ForecastService();
        $this->admin = $this->actor('admin');
    }

    public function testPrefillUsesThreeMonthsOfConsistentHistory(): void
    {
        $partyId = $this->createParty();
        $j11 = $this->createGrade('J-11');
        $this->dispatch($partyId, $j11, '2026-05-10', 20);
        $this->dispatch($partyId, $j11, '2026-06-12', 20);
        $this->dispatch($partyId, $j11, '2026-07-08', 20);

        $rows = $this->prefill->forParty($partyId, self::AS_OF);
        self::assertCount(1, $rows);
        self::assertSame('J-11', $rows[0]['grade_code']);
        self::assertSame(20.0, $rows[0]['qty_low_tonnes']);
        self::assertSame(20.0, $rows[0]['qty_high_tonnes']);
        self::assertSame(3, $rows[0]['months_observed']);
    }

    public function testPrefillErraticHistoryUsesObservedMinAndMax(): void
    {
        $partyId = $this->createParty();
        $j11 = $this->createGrade('J-11');
        $this->dispatch($partyId, $j11, '2026-05-10', 10);
        $this->dispatch($partyId, $j11, '2026-06-12', 50);
        $this->dispatch($partyId, $j11, '2026-07-08', 12);

        $rows = $this->prefill->forParty($partyId, self::AS_OF);
        self::assertSame(10.0, $rows[0]['qty_low_tonnes']);
        self::assertSame(50.0, $rows[0]['qty_high_tonnes']);
    }

    public function testPrefillWithOnlyOneMonthStillShowsARange(): void
    {
        $partyId = $this->createParty();
        $j11 = $this->createGrade('J-11');
        $this->dispatch($partyId, $j11, '2026-07-08', 18.4);

        $rows = $this->prefill->forParty($partyId, self::AS_OF);
        self::assertCount(1, $rows);
        self::assertSame(18.4, $rows[0]['qty_low_tonnes']);
        self::assertSame(18.4, $rows[0]['qty_high_tonnes']);
        self::assertSame(1, $rows[0]['months_observed']);
    }

    public function testPrefillWithNoHistoryIsEmpty(): void
    {
        self::assertSame([], $this->prefill->forParty($this->createParty(), self::AS_OF));
    }

    public function testAGradeBoughtFourMonthsAgoIsExcluded(): void
    {
        $partyId = $this->createParty();
        $j11 = $this->createGrade('J-11');
        $this->dispatch($partyId, $j11, '2026-04-15', 40);

        self::assertSame([], $this->prefill->forParty($partyId, self::AS_OF));
    }

    public function testApplicationRejectsLowAboveHigh(): void
    {
        $period = $this->forecasts->openPeriod('2026-08', $this->admin);
        $this->expectException(ForecastException::class);
        $this->forecasts->savePartyLines((int)$period['id'], $this->createParty(), [
            ['grade_code' => 'J-11', 'qty_low_tonnes' => 12, 'qty_high_tonnes' => 4],
        ], $this->admin);
    }

    public function testDatabaseCheckRejectsLowAboveHighEvenIfAppIsBypassed(): void
    {
        $period = $this->forecasts->openPeriod('2026-08', $this->admin);
        $partyId = $this->createParty();
        try {
            $this->database->execute(
                "INSERT INTO forecast_lines (period_id, party_id, grade_code, qty_low_tonnes, qty_high_tonnes, source)
                 VALUES (?, ?, 'J-11', 12, 4, 'added')",
                [(int)$period['id'], $partyId]
            );
            self::fail('CHECK must reject low > high.');
        } catch (PDOException $e) {
            self::assertTrue(
                str_contains($e->getMessage(), 'chk_forecast_qty_range')
                || str_contains($e->getMessage(), '3819')
                || str_contains(strtolower($e->getMessage()), 'check'),
                $e->getMessage()
            );
        }
    }

    public function testLockingPreventsEditsAndIsAudited(): void
    {
        $period = $this->forecasts->openPeriod('2026-08', $this->admin);
        $partyId = $this->createParty();
        $this->forecasts->savePartyLines((int)$period['id'], $partyId, [
            ['grade_code' => 'J-11', 'qty_low_tonnes' => 10, 'qty_high_tonnes' => 12],
        ], $this->admin);

        $locked = $this->forecasts->lockPeriod((int)$period['id'], $this->admin);
        self::assertSame('locked', $locked['status']);
        $audit = $this->database->fetch(
            "SELECT action FROM audit_logs WHERE table_name = 'forecast_periods' AND record_id = ? AND action = 'UPDATE'",
            [(int)$period['id']]
        );
        self::assertNotNull($audit);

        try {
            $this->forecasts->savePartyLines((int)$period['id'], $partyId, [
                ['grade_code' => 'J-11', 'qty_low_tonnes' => 8, 'qty_high_tonnes' => 9],
            ], $this->admin);
            self::fail('Locked periods must refuse edits.');
        } catch (ForecastException $e) {
            self::assertStringContainsString('locked', strtolower($e->getMessage()));
        }

        $this->expectException(ForecastAuthorizationException::class);
        $this->forecasts->lockPeriod((int)$period['id'], $this->actor('sales'));
    }

    public function testWorksheetPrefillsThenSaveMarksTheLineEdited(): void
    {
        $rep = $this->actor('sales');
        $partyId = $this->createParty();
        $this->database->execute("UPDATE parties SET assigned_sales_owner = ? WHERE id = ?", [$rep['id'], $partyId]);
        $j11 = $this->createGrade('J-11');
        $this->dispatch($partyId, $j11, '2026-07-08', 20);
        $this->forecasts->openPeriod('2026-08', $this->admin);

        $sheet = $this->forecasts->worksheet($rep, '2026-08', self::AS_OF);
        self::assertNotSame('', $sheet['purpose_line']);
        self::assertCount(1, $sheet['accounts']);
        self::assertSame('prefilled', $sheet['accounts'][0]['lines'][0]['source']);

        $saved = $this->forecasts->savePartyLines((int)$sheet['period']['id'], $partyId, [
            ['grade_code' => 'J-11', 'qty_low_tonnes' => 18, 'qty_high_tonnes' => 22, 'confidence' => 'high'],
        ], $rep);
        self::assertSame('edited', $saved[0]['source']);
        self::assertSame(18.0, $saved[0]['qty_low_tonnes']);
    }

    public function testNightlyActualsAndForecastGapFlag(): void
    {
        $partyId = $this->createParty();
        $j11 = $this->createGrade('J-11');
        $this->dispatch($partyId, $j11, '2026-08-12', 15);
        $period = $this->forecasts->openPeriod('2026-08', $this->admin);
        $this->forecasts->savePartyLines((int)$period['id'], $partyId, [
            ['grade_code' => 'J-11', 'qty_low_tonnes' => 10, 'qty_high_tonnes' => 20],
        ], $this->admin);

        $job = new CrmNightlyJobService();
        $job->run(self::AS_OF);

        $actual = $this->database->fetch(
            "SELECT * FROM forecast_actuals WHERE period_id = ? AND party_id = ? AND grade_code = 'J-11'",
            [(int)$period['id'], $partyId]
        );
        self::assertNotNull($actual);
        self::assertEqualsWithDelta(15.0, (float)$actual['actual_tonnes'], 0.01);
        self::assertEqualsWithDelta(0.0, (float)$actual['variance_vs_midpoint'], 0.01);

        $byGrade = $this->forecasts->actuals($this->admin, '2026-08');
        self::assertNotEmpty($byGrade['by_grade']);
        self::assertArrayNotHasKey('accuracy_by_owner', $byGrade);
        self::assertArrayHasKey('by_account', $byGrade);

        $gapParty = $this->createParty();
        $this->forecasts->savePartyLines((int)$period['id'], $gapParty, [
            ['grade_code' => 'J-11', 'qty_low_tonnes' => 5, 'qty_high_tonnes' => 8],
        ], $this->admin);
        $gaps = $this->forecasts->partyIdsWithForecastGap(self::AS_OF);
        self::assertArrayHasKey($gapParty, $gaps);
        self::assertArrayNotHasKey($partyId, $gaps, 'An August dispatch/order counterpart: this party has August actuals from an order.');

        $orderedInAugust = $this->database->fetch(
            "SELECT id FROM orders WHERE party_id = ? AND order_date >= '2026-08-01' AND order_date < '2026-09-01'",
            [$partyId]
        );
        self::assertNotNull($orderedInAugust);

        (new DormancyService())->rebuild(self::AS_OF);
        $signal = $this->database->fetch(
            "SELECT forecast_gap_flag FROM account_dormancy_signals WHERE party_id = ? AND computed_on = ?",
            [$gapParty, self::AS_OF]
        );
        self::assertNotNull($signal);
        self::assertSame(1, (int)$signal['forecast_gap_flag']);
    }

    public function testPurposeLineComesFromConfigNotAHardcodedScreen(): void
    {
        $config = require dirname(__DIR__) . '/config/forecast.php';
        self::assertNotSame('', $config['purpose_line']);
        $page = file_get_contents(dirname(__DIR__) . '/templates/crm/forecast.php');
        self::assertStringContainsString("config/forecast.php", $page);
        self::assertStringContainsString('purpose_line', $page);
        self::assertStringContainsString('taps', $page);
        self::assertStringNotContainsString('leaderboard', $page);
        $actuals = file_get_contents(dirname(__DIR__) . '/templates/crm/forecast-actuals.php');
        self::assertStringContainsString('not a rep scorecard', strtolower($actuals));
    }

    public function testSalesCannotOpenAPeriod(): void
    {
        $this->expectException(ForecastAuthorizationException::class);
        $this->forecasts->openPeriod('2026-09', $this->actor('sales'));
    }

    private function actor(string $role): array
    {
        $user = $this->createUser($role);

        return ['id' => $user['id'], 'role' => $role];
    }

    private function createGrade(string $code): int
    {
        $existing = $this->database->fetch("SELECT id FROM products WHERE code = ?", [$code]);
        if ($existing) {
            return (int)$existing['id'];
        }
        $this->database->execute(
            "INSERT INTO products (code, name, is_active) VALUES (?, ?, 1)",
            [$code, $code]
        );

        return (int)$this->database->lastInsertId();
    }

    private function dispatch(int $partyId, int $productId, string $dispatchDate, float $tonnes): void
    {
        $companyId = $this->createCompany();
        $suffix = $this->uniqueSuffix();
        $this->database->execute(
            "INSERT INTO orders (company_id, order_no, order_date, product_id, order_qty_trucks, order_weight_tons, tons_per_truck, party_id, created_by, status)
             VALUES (?, ?, ?, ?, 1, ?, 40, ?, ?, 'pending')",
            [$companyId, "FC-{$suffix}", $dispatchDate, $productId, $tonnes, $partyId, $this->admin['id']]
        );
        $orderId = (int)$this->database->lastInsertId();
        $this->database->execute(
            "INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, status, loading_weight_tons, dispatched_by)
             VALUES (?, ?, 1, 'active', ?, ?)",
            [$orderId, $dispatchDate, $tonnes, $this->admin['id']]
        );
    }
}
