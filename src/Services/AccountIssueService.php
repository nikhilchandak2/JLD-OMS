<?php

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\CrmAccountIssueRepository;
use App\Repositories\CrmDealRepository;
use App\Repositories\PartyRepository;

class AccountIssueService
{
    private CrmAccountIssueRepository $issues;
    private PartyRepository $parties;
    private CrmDealRepository $deals;
    private AuditLogRepository $audit;
    private AccountContextPolicy $policy;
    private array $config;

    public function __construct()
    {
        $this->issues = new CrmAccountIssueRepository();
        $this->parties = new PartyRepository();
        $this->deals = new CrmDealRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new AccountContextPolicy();
        $this->config = require dirname(__DIR__, 2) . '/config/account_context.php';
    }

    /** @return array<int,array<string,mixed>> */
    public function listForParty(int $partyId, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, AccountContextPolicy::VIEW_ISSUES);
        $this->assertParty($partyId);

        return array_map([$this, 'present'], $this->issues->findByParty($partyId));
    }

    /**
     * @param array<string,mixed> $input
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function create(int $partyId, array $input, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, AccountContextPolicy::EDIT_ISSUES);
        $this->assertParty($partyId);

        $description = trim((string)($input['description'] ?? ''));
        if ($description === '') {
            throw new AccountContextException('Issue description is required.');
        }

        $type = (string)($input['issue_type'] ?? 'other');
        if (!isset($this->config['issue_types'][$type])) {
            throw new AccountContextException('A valid issue type is required.');
        }

        $raisedOn = trim((string)($input['raised_on'] ?? ''));
        if ($raisedOn === '') {
            $raisedOn = date('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raisedOn)) {
            throw new AccountContextException('raised_on must be a date (YYYY-MM-DD).');
        }

        $window = $input['resolution_window_days'] ?? $this->config['default_resolution_window_days'];
        $window = (int)$window;
        if ($window < 1) {
            throw new AccountContextException('Resolution window must be at least 1 day.');
        }

        $dealId = isset($input['deal_id']) && $input['deal_id'] !== '' && $input['deal_id'] !== null
            ? (int)$input['deal_id']
            : null;
        if ($dealId !== null && $dealId > 0) {
            $deal = $this->deals->findById($dealId);
            if ($deal === null || (int)$deal['party_id'] !== $partyId) {
                throw new AccountContextException('Deal not found for this customer.');
            }
        } else {
            $dealId = null;
        }

        $id = $this->issues->create([
            'party_id' => $partyId,
            'deal_id' => $dealId,
            'issue_type' => $type,
            'raised_on' => $raisedOn,
            'description' => $description,
            'resolution_window_days' => $window,
            'raised_by_user_id' => $actor['id'] ?? null,
        ]);
        $this->audit->log($actor['id'] ?? null, 'crm_account_issues', $id, 'CREATE', null, [
            'party_id' => $partyId,
            'issue_type' => $type,
            'raised_on' => $raisedOn,
        ]);

        $row = $this->issues->findById($id);
        if ($row === null) {
            throw new AccountContextException('Issue could not be reloaded.');
        }

        return $this->present($row);
    }

    /**
     * @param array<string,mixed> $input
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function resolve(int $id, array $input, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, AccountContextPolicy::EDIT_ISSUES);
        $existing = $this->issues->findById($id);
        if ($existing === null) {
            throw new AccountContextException('Issue not found.');
        }
        if ($existing['status'] === 'resolved') {
            throw new AccountContextException('This issue is already resolved.');
        }

        $note = trim((string)($input['resolution_note'] ?? ''));
        if ($note === '') {
            throw new AccountContextException('A resolution note is required.');
        }
        $resolvedOn = trim((string)($input['resolved_on'] ?? ''));
        if ($resolvedOn === '') {
            $resolvedOn = date('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolvedOn)) {
            throw new AccountContextException('resolved_on must be a date (YYYY-MM-DD).');
        }

        $this->issues->resolve($id, $resolvedOn, $note);
        (new EscalationService())->closeForSource(
            'crm_account_issues',
            $id,
            'The underlying issue was resolved.'
        );
        $this->audit->log($actor['id'] ?? null, 'crm_account_issues', $id, 'UPDATE', [
            'status' => $existing['status'],
        ], [
            'status' => 'resolved',
            'resolved_on' => $resolvedOn,
        ]);

        $row = $this->issues->findById($id);

        return $this->present($row);
    }

    /** @param array<string,mixed> $row */
    public function present(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['party_id'] = (int)$row['party_id'];
        $row['deal_id'] = $row['deal_id'] === null ? null : (int)$row['deal_id'];
        $row['resolution_window_days'] = (int)$row['resolution_window_days'];
        $row['issue_type_label'] = $this->config['issue_types'][$row['issue_type']] ?? $row['issue_type'];
        $row['status_label'] = $this->config['issue_statuses'][$row['status']] ?? $row['status'];

        return $row;
    }

    private function assertParty(int $partyId): void
    {
        if ($partyId <= 0 || $this->parties->findById($partyId) === null) {
            throw new AccountContextException('Party not found.');
        }
    }
}
