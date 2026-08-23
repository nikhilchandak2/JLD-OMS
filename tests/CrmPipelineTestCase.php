<?php

namespace Tests;

use App\Services\DealService;
use App\Services\DealStageService;
use App\Services\TechnicalFlagService;

/**
 * Shared fixtures for the 7-stage deal pipeline tests: a party, an actor, a captured deal,
 * and a helper that satisfies the configured exit criteria for a stage so a test can walk a
 * deal up the pipeline without hardcoding what those criteria are.
 */
abstract class CrmPipelineTestCase extends DatabaseTestCase
{
    protected DealService $deals;
    protected DealStageService $stages;
    protected TechnicalFlagService $flags;
    protected array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deals = new DealService();
        $this->stages = new DealStageService();
        $this->flags = new TechnicalFlagService();
        $this->admin = $this->actor('admin');
    }

    protected function actor(string $role): array
    {
        $user = $this->createUser($role);

        return ['id' => $user['id'], 'role' => $role];
    }

    protected function captureDeal(array $overrides = [], ?array $actor = null): array
    {
        $partyId = $overrides['party_id'] ?? $this->createParty();

        return $this->deals->captureInquiry(array_merge([
            'party_id' => $partyId,
            'source' => 'whatsapp',
            'grades' => 'J-11, JJN-1',
            'indicative_quantity_tonnes' => 40,
            'inquiry_date' => '2026-01-05',
        ], $overrides), $actor ?? $this->admin);
    }

    /**
     * Satisfy whatever crm_stage_exit_criteria currently demands for this stage. Derived
     * criteria are satisfied by creating the record they read; captured criteria are filled
     * with placeholder text through the service.
     */
    protected function satisfyExitCriteria(int $dealId, ?array $actor = null): void
    {
        $actor = $actor ?? $this->admin;
        $evaluation = $this->stages->evaluateExitCriteria($dealId);
        $deal = $this->database->fetch("SELECT * FROM crm_deals WHERE id = ?", [$dealId]);
        $captured = [];

        foreach ($evaluation['criteria'] as $criterion) {
            if ($criterion['satisfied']) {
                continue;
            }

            switch ($criterion['field_key']) {
                case 'decision_maker_contact':
                    $this->database->execute(
                        "INSERT INTO crm_contacts (party_id, name, role, is_primary) VALUES (?, 'Purchase Head', 'purchase_manager', 1)",
                        [(int)$deal['party_id']]
                    );
                    break;
                case 'sample_sent':
                    $this->database->execute(
                        "INSERT INTO crm_samples (party_id, deal_id, sample_type, status, request_date)
                         VALUES (?, ?, 'J-11', 'sample_sent', CURDATE())",
                        [(int)$deal['party_id'], $dealId]
                    );
                    break;
                default:
                    $captured[$criterion['field_key']] = 'test value';
            }
        }

        if (!empty($captured)) {
            $this->stages->saveCriteriaValues($dealId, $captured, $actor);
        }
    }

    /** Walk a freshly captured deal up to the given stage, satisfying criteria on the way. */
    protected function advanceTo(int $dealId, int $stage, ?array $actor = null): array
    {
        $actor = $actor ?? $this->admin;
        $deal = ['stage' => 1];

        while ((int)$deal['stage'] < $stage) {
            $this->satisfyExitCriteria($dealId, $actor);
            $deal = $this->stages->advance($dealId, $actor);
        }

        return $deal;
    }

    protected function reasonCodeId(string $code): int
    {
        $row = $this->database->fetch("SELECT id FROM crm_deal_reason_codes WHERE code = ?", [$code]);
        self::assertNotNull($row, "Reason code {$code} is expected to be seeded.");

        return (int)$row['id'];
    }

    protected function queueId(): int
    {
        $row = $this->database->fetch("SELECT id FROM crm_technical_queues WHERE is_active = 1 ORDER BY id LIMIT 1");
        self::assertNotNull($row, 'A technical queue is expected to be seeded.');

        return (int)$row['id'];
    }

    protected function eventCount(int $dealId): int
    {
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM crm_deal_stage_events WHERE deal_id = ?",
            [$dealId]
        );

        return (int)$row['c'];
    }

    protected function auditCount(string $table, int $recordId): int
    {
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM audit_logs WHERE table_name = ? AND record_id = ?",
            [$table, $recordId]
        );

        return (int)$row['c'];
    }
}
