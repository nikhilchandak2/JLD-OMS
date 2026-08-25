<?php

namespace App\Services;

/**
 * Sales visit logging. Distinct from technical visit_requests.
 */
class VisitPolicy
{
    public const VIEW = 'view_visits';
    public const LOG = 'log_visit';

    /** @var array<string,string[]> */
    private const CAPABILITIES = [
        self::VIEW => ['admin', 'crm', 'sales', 'marketing', 'entry'],
        self::LOG => ['admin', 'crm', 'sales', 'marketing', 'entry'],
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
            throw new VisitAuthorizationException(
                "Role '" . ($role ?? 'none') . "' is not permitted to {$capability}."
            );
        }
    }
}
