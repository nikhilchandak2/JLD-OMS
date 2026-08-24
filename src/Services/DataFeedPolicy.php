<?php

namespace App\Services;

/**
 * Who may upload, promote, resolve aliases, or change feed config.
 */
class DataFeedPolicy
{
    public const VIEW = 'view_feeds';
    public const UPLOAD = 'upload_feeds';
    public const PROMOTE = 'promote_feeds';
    public const RESOLVE_ALIAS = 'resolve_alias';
    public const CONFIGURE = 'configure_feeds';

    /** @var array<string,string[]> */
    private const CAPABILITIES = [
        self::VIEW => ['admin', 'entry', 'crm', 'accounts', 'sales'],
        self::UPLOAD => ['admin', 'entry', 'crm', 'accounts'],
        self::PROMOTE => ['admin', 'entry', 'crm', 'accounts'],
        self::RESOLVE_ALIAS => ['admin', 'entry', 'crm', 'accounts'],
        self::CONFIGURE => ['admin'],
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
            throw new DataFeedAuthorizationException(
                "Role '" . ($role ?? 'none') . "' is not permitted to {$capability}."
            );
        }
    }
}
