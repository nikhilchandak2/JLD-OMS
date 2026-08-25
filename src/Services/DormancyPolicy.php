<?php

namespace App\Services;

/**
 * Dormancy list is for the account owner. The escalation inbox is Director
 * (admin) and CRM. Sales may raise a manual "needs senior attention" flag.
 */
class DormancyPolicy
{
    public const VIEW_DORMANCY = 'view_dormancy';
    public const VIEW_ALL_DORMANCY = 'view_all_dormancy';
    public const VIEW_ESCALATIONS = 'view_escalations';
    public const ACT_ESCALATIONS = 'act_escalations';
    public const RAISE_MANUAL = 'raise_manual_escalation';

    /** @var array<string,string[]> */
    private const CAPABILITIES = [
        self::VIEW_DORMANCY => ['admin', 'crm', 'sales', 'marketing', 'entry'],
        self::VIEW_ALL_DORMANCY => ['admin', 'crm'],
        self::VIEW_ESCALATIONS => ['admin', 'crm'],
        self::ACT_ESCALATIONS => ['admin', 'crm'],
        self::RAISE_MANUAL => ['admin', 'crm', 'sales'],
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
            throw new DormancyAuthorizationException(
                "Role '" . ($role ?? 'none') . "' is not permitted to {$capability}."
            );
        }
    }
}
