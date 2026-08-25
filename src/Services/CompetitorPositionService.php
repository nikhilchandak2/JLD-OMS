<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CrmCompetitorPositionRepository;
use App\Repositories\PartyRepository;

/**
 * Competitor positions are append-with-current-flag. Recording a new current
 * position clears is_current on the superseded row in the same transaction.
 * An UPDATE of a historical row is never offered — that would destroy the
 * "they moved from 70% to 40% over eight months" signal.
 */
class CompetitorPositionService
{
    private Database $database;
    private CrmCompetitorPositionRepository $positions;
    private PartyRepository $parties;
    private AuditLogRepository $audit;
    private AccountContextPolicy $policy;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->positions = new CrmCompetitorPositionRepository();
        $this->parties = new PartyRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new AccountContextPolicy();
        $this->config = require dirname(__DIR__, 2) . '/config/account_context.php';
    }

    /** @return array<int,array<string,mixed>> */
    public function listForParty(int $partyId, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, AccountContextPolicy::VIEW_COMPETITOR);
        $this->assertParty($partyId);

        return array_map([$this, 'present'], $this->positions->findByParty($partyId));
    }

    /**
     * @param array<string,mixed> $input
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function record(int $partyId, array $input, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, AccountContextPolicy::EDIT_COMPETITOR);
        $this->assertParty($partyId);

        $name = trim((string)($input['competitor_name'] ?? ''));
        if ($name === '') {
            throw new AccountContextException('Competitor name is required.');
        }

        $intelligence = (string)($input['intelligence_type'] ?? '');
        if (!isset($this->config['intelligence_types'][$intelligence])) {
            throw new AccountContextException('Intelligence type must be factual, reported, or estimated.');
        }

        $reason = (string)($input['reason_code'] ?? 'other');
        if (!isset($this->config['reason_codes'][$reason])) {
            throw new AccountContextException('A valid reason code is required.');
        }

        $share = $input['estimated_share_pct'] ?? null;
        if ($share === '' || $share === null) {
            $share = null;
        } else {
            $share = (int)$share;
            if ($share < 0 || $share > 100) {
                throw new AccountContextException('Estimated share must be between 0 and 100, or blank if unknown.');
            }
        }

        $grade = trim((string)($input['grade_code'] ?? ''));
        $grade = $grade === '' ? null : strtoupper($grade);
        $isCurrent = array_key_exists('is_current', $input) ? !empty($input['is_current']) : true;
        $recordedAt = date('Y-m-d H:i:s');

        $this->database->beginTransaction();
        try {
            if ($isCurrent) {
                $this->positions->clearCurrent($partyId, $name, $grade);
            }
            $id = $this->positions->create([
                'party_id' => $partyId,
                'competitor_name' => $name,
                'grade_code' => $grade,
                'application' => trim((string)($input['application'] ?? '')) ?: null,
                'estimated_share_pct' => $share,
                'reason_code' => $reason,
                'reason_note' => trim((string)($input['reason_note'] ?? '')) ?: null,
                'intelligence_type' => $intelligence,
                'recorded_by_user_id' => $actor['id'] ?? null,
                'recorded_at' => $recordedAt,
                'is_current' => $isCurrent,
            ]);
            $this->audit->log($actor['id'] ?? null, 'crm_competitor_positions', $id, 'CREATE', null, [
                'party_id' => $partyId,
                'competitor_name' => $name,
                'grade_code' => $grade,
                'intelligence_type' => $intelligence,
                'is_current' => $isCurrent,
            ]);
            $this->database->commit();
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }

        $row = $this->positions->findById($id);
        if ($row === null) {
            throw new AccountContextException('Competitor position could not be reloaded.');
        }

        return $this->present($row);
    }

    /** @param array<string,mixed> $row */
    public function present(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['party_id'] = (int)$row['party_id'];
        $row['is_current'] = (int)$row['is_current'] === 1;
        $row['estimated_share_pct'] = $row['estimated_share_pct'] === null ? null : (int)$row['estimated_share_pct'];
        $row['intelligence_type_label'] = $this->config['intelligence_types'][$row['intelligence_type']] ?? $row['intelligence_type'];
        $row['reason_code_label'] = $this->config['reason_codes'][$row['reason_code']] ?? $row['reason_code'];

        return $row;
    }

    private function assertParty(int $partyId): void
    {
        if ($partyId <= 0 || $this->parties->findById($partyId) === null) {
            throw new AccountContextException('Party not found.');
        }
    }
}
