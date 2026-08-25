<?php

namespace App\Services;

/**
 * New-rep briefing. Composition only, except handover notes (transitional).
 */
class BriefingPolicy
{
    public const VIEW = 'view_briefing';
    public const WRITE_HANDOVER = 'write_handover_note';

    /** @var array<string,string[]> */
    private const CAPABILITIES = [
        self::VIEW => ['admin', 'crm', 'sales', 'marketing', 'entry'],
        self::WRITE_HANDOVER => ['admin', 'crm', 'sales'],
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
            throw new BriefingAuthorizationException(
                "Role '" . ($role ?? 'none') . "' is not permitted to {$capability}."
            );
        }
    }
}
