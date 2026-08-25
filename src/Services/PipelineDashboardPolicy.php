<?php

namespace App\Services;

/**
 * Reps see their own deals. Director (admin) and CRM see all, with an owner filter.
 */
class PipelineDashboardPolicy
{
    public const VIEW = 'view_pipeline_dashboard';
    public const VIEW_ALL = 'view_all_pipeline_dashboard';

    /** @var array<string,string[]> */
    private const CAPABILITIES = [
        self::VIEW => ['admin', 'crm', 'sales', 'marketing', 'entry'],
        self::VIEW_ALL => ['admin', 'crm'],
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
            throw new PipelineDashboardAuthorizationException(
                "Role '" . ($role ?? 'none') . "' is not permitted to {$capability}."
            );
        }
    }
}
