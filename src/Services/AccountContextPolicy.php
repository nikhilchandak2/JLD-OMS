<?php

namespace App\Services;

/**
 * Role capabilities for relationship mapping, competitive intelligence, and issues.
 *
 * Competitor intelligence is Sales + Director (admin) + CRM maintainers.
 * Dispatch and Accounts are excluded until the client says otherwise.
 */
class AccountContextPolicy
{
    public const VIEW_CONTACTS = 'view_contacts';
    public const EDIT_CONTACTS = 'edit_contacts';
    public const VIEW_COMPETITOR = 'view_competitor';
    public const EDIT_COMPETITOR = 'edit_competitor';
    public const VIEW_ISSUES = 'view_issues';
    public const EDIT_ISSUES = 'edit_issues';
    public const VIEW_CONTEXT = 'view_context';
    public const EDIT_CONTEXT = 'edit_context';
    public const SEARCH = 'search_account_context';

    /** @var array<string,string[]> */
    private const CAPABILITIES = [
        self::VIEW_CONTACTS => ['admin', 'crm', 'sales', 'marketing', 'entry'],
        self::EDIT_CONTACTS => ['admin', 'crm', 'sales', 'marketing', 'entry'],
        self::VIEW_COMPETITOR => ['admin', 'crm', 'sales'],
        self::EDIT_COMPETITOR => ['admin', 'crm', 'sales'],
        self::VIEW_ISSUES => ['admin', 'crm', 'sales', 'marketing', 'entry'],
        self::EDIT_ISSUES => ['admin', 'crm', 'sales'],
        self::VIEW_CONTEXT => ['admin', 'crm', 'sales', 'marketing', 'entry'],
        self::EDIT_CONTEXT => ['admin', 'crm', 'sales'],
        self::SEARCH => ['admin', 'crm', 'sales', 'marketing', 'entry'],
    ];

    public function can(?string $role, string $capability): bool
    {
        if ($role === null || $role === '') {
            return false;
        }

        return in_array($role, self::CAPABILITIES[$capability] ?? [], true);
    }

    public function assertCan(?string $role, string $capability): void
    {
        if (!$this->can($role, $capability)) {
            throw new AccountContextAuthorizationException(
                "Role '" . ($role ?? 'none') . "' is not permitted to {$capability}."
            );
        }
    }

    /**
     * Strip competitor intelligence from a snapshot for roles that must not see it.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function serializeSnapshot(array $snapshot, ?string $role): array
    {
        $snapshot['capabilities'] = [
            'view_competitors' => $this->can($role, self::VIEW_COMPETITOR),
            'edit_competitors' => $this->can($role, self::EDIT_COMPETITOR),
            'view_contacts' => $this->can($role, self::VIEW_CONTACTS),
            'edit_contacts' => $this->can($role, self::EDIT_CONTACTS),
            'view_issues' => $this->can($role, self::VIEW_ISSUES),
            'edit_issues' => $this->can($role, self::EDIT_ISSUES),
            'view_context' => $this->can($role, self::VIEW_CONTEXT),
            'edit_context' => $this->can($role, self::EDIT_CONTEXT),
        ];

        if (!$this->can($role, self::VIEW_COMPETITOR)) {
            unset($snapshot['competitors']);
        }

        return $snapshot;
    }
}
