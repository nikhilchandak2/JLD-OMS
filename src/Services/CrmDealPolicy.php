<?php

namespace App\Services;

/**
 * Role capabilities for the deal pipeline, in one place.
 *
 * Enforced in the services and applied at serialization (I10), not in templates: a role that
 * must not see a field does not get it in any response body, including exports.
 */
class CrmDealPolicy
{
    public const VIEW_DEAL = 'view_deal';
    public const VIEW_DEAL_VALUE = 'view_deal_value';
    public const CREATE_DEAL = 'create_deal';
    public const MOVE_DEAL = 'move_deal';
    public const TERMINATE_DEAL = 'terminate_deal';
    public const REOPEN_DEAL = 'reopen_deal';
    public const DELETE_DEAL = 'delete_deal';
    public const RAISE_TECHNICAL_FLAG = 'raise_technical_flag';
    public const WORK_TECHNICAL_QUEUE = 'work_technical_queue';

    /** @var array<string,string[]> capability => roles */
    private const CAPABILITIES = [
        self::VIEW_DEAL => ['admin', 'crm', 'sales', 'marketing', 'entry'],
        self::VIEW_DEAL_VALUE => ['admin', 'crm', 'sales'],
        self::CREATE_DEAL => ['admin', 'crm', 'sales', 'entry'],
        self::MOVE_DEAL => ['admin', 'crm', 'sales', 'entry'],
        self::TERMINATE_DEAL => ['admin', 'crm', 'sales'],
        self::REOPEN_DEAL => ['admin', 'crm'],
        self::DELETE_DEAL => ['admin'],
        self::RAISE_TECHNICAL_FLAG => ['admin', 'crm', 'sales', 'entry', 'marketing'],
        self::WORK_TECHNICAL_QUEUE => ['admin', 'technical', 'crm'],
    ];

    /** Fields removed from a serialized deal for roles without VIEW_DEAL_VALUE. */
    private const VALUE_FIELDS = ['value', 'expected_close_date'];

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
            throw new PipelineAuthorizationException(
                "Role '" . ($role ?? 'none') . "' is not permitted to {$capability}."
            );
        }
    }

    /**
     * Strip fields the role must not receive. Applied to every deal payload leaving a
     * service, so list endpoints and exports are gated by the same rule as detail views.
     */
    public function serializeDeal(array $deal, ?string $role): array
    {
        if (!$this->can($role, self::VIEW_DEAL_VALUE)) {
            foreach (self::VALUE_FIELDS as $field) {
                unset($deal[$field]);
            }
        }

        return $deal;
    }

    public function serializeDeals(array $deals, ?string $role): array
    {
        return array_map(fn(array $deal) => $this->serializeDeal($deal, $role), $deals);
    }
}
