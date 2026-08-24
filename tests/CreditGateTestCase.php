<?php

namespace Tests;

use App\Repositories\CreditPolicyTierRepository;
use App\Services\CreditGateService;
use App\Services\CreditOverrideService;
use App\Services\DirectOrderCaptureService;
use DateTimeImmutable;
use DateTimeZone;

abstract class CreditGateTestCase extends DatabaseTestCase
{
    protected CreditGateService $gate;
    protected CreditOverrideService $overrides;
    protected DirectOrderCaptureService $capture;
    protected CreditPolicyTierRepository $tiers;
    protected array $admin;
    protected array $sales;
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new CreditGateService();
        $this->overrides = new CreditOverrideService();
        $this->capture = new DirectOrderCaptureService();
        $this->tiers = new CreditPolicyTierRepository();
        $this->admin = $this->actor('admin');
        $this->sales = $this->actor('sales');
        $this->companyId = $this->createCompany();
        $this->tiers->ensureForCompany($this->companyId);
        $this->silenceOtherLedgerFeeds($this->companyId);
    }

    protected function actor(string $role): array
    {
        $user = $this->createUser($role);

        return ['id' => $user['id'], 'role' => $role];
    }

    protected function silenceOtherLedgerFeeds(int ...$companyIds): void
    {
        (new \App\Services\DataFreshnessService())->groupAsOf(
            'ledger',
            new DateTimeImmutable('2026-08-21 10:00:00', new DateTimeZone('Asia/Kolkata'))
        );
        if ($companyIds === []) {
            $this->database->execute("UPDATE data_feeds SET is_active = 0 WHERE feed_key = 'ledger'");
            return;
        }
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        $this->database->execute(
            "UPDATE data_feeds SET is_active = 0 WHERE feed_key = 'ledger' AND company_id NOT IN ({$placeholders})",
            $companyIds
        );
        $this->database->execute(
            "UPDATE data_feeds SET is_active = 1 WHERE feed_key = 'ledger' AND company_id IN ({$placeholders})",
            $companyIds
        );
    }

    protected function putReceivable(int $partyId, float $amount): void
    {
        $this->database->execute(
            "INSERT INTO crm_receivable_entries (party_id, entry_type, amount, entry_date, description, created_by)
             VALUES (?, 'invoice', ?, '2026-01-01', 'Test outstanding', ?)",
            [$partyId, $amount, $this->admin['id']]
        );
    }

    protected function putLedger(int $companyId, int $partyId, float $amount, string $asOf, string $businessDate): int
    {
        (new \App\Repositories\DataFeedRepository())->ensureForCompany($companyId);
        $hash = hash('sha256', uniqid('ledger', true));
        $this->database->execute(
            "INSERT INTO data_feed_runs
                (feed_key, company_id, business_date, uploaded_by_user_id, uploaded_at, original_filename, file_hash,
                 status, rows_total, rows_accepted, as_of)
             VALUES ('ledger', ?, ?, ?, NOW(), 't.csv', ?, 'completed', 1, 1, ?)",
            [$companyId, $businessDate, $this->admin['id'], $hash, $asOf]
        );
        $runId = (int)$this->database->lastInsertId();
        $this->database->execute(
            "INSERT INTO ledger_outstanding (run_id, company_id, party_id, business_date, outstanding_amount, as_of)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$runId, $companyId, $partyId, $businessDate, $amount, $asOf]
        );

        return $runId;
    }
}
