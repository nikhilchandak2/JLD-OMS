<?php

namespace App\Services;

/**
 * Role capabilities for the credit gate.
 *
 * The Director is the admin role. Reps may evaluate and capture, but a role
 * that must not see ledger detail does not receive those fields in any body.
 */
class CreditGatePolicy
{
    public const EVALUATE = 'evaluate_credit';
    public const CAPTURE = 'capture_order';
    public const VIEW_LEDGER_DETAIL = 'view_credit_ledger';
    public const DECIDE = 'decide_credit_override';
    public const VIEW_QUEUE = 'view_credit_queue';
    public const WITHDRAW = 'withdraw_credit_override';

    /** @var array<string,string[]> */
    private const CAPABILITIES = [
        self::EVALUATE => ['admin', 'crm', 'sales', 'entry', 'order_processing', 'accounts'],
        self::CAPTURE => ['admin', 'crm', 'sales', 'entry', 'order_processing'],
        self::VIEW_LEDGER_DETAIL => ['admin'],
        self::DECIDE => ['admin'],
        self::VIEW_QUEUE => ['admin'],
        self::WITHDRAW => ['admin', 'crm', 'sales', 'entry', 'order_processing'],
    ];

    /** Fields a rep must never receive. Headroom and as-of stay. */
    public const LEDGER_FIELDS = [
        'credit_limit',
        'outstanding',
        'outstanding_breakdown',
        'exposure',
        'computed_overage',
        'overage_percentage',
        'credit_limit_snapshot',
        'outstanding_snapshot',
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
            throw new CreditGateAuthorizationException(
                "Role '" . ($role ?? 'none') . "' is not permitted to {$capability}."
            );
        }
    }

    /**
     * Strip ledger detail from an evaluation or override payload.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function serializeForRole(array $payload, ?string $role): array
    {
        if ($this->can($role, self::VIEW_LEDGER_DETAIL)) {
            return $payload;
        }

        foreach (self::LEDGER_FIELDS as $field) {
            unset($payload[$field]);
        }

        if (isset($payload['evaluation']) && is_array($payload['evaluation'])) {
            $payload['evaluation'] = $this->serializeForRole($payload['evaluation'], $role);
        }

        if (isset($payload['prior_overrides']) && is_array($payload['prior_overrides'])) {
            $payload['prior_overrides'] = array_map(
                fn(array $row) => $this->serializeForRole($row, $role),
                $payload['prior_overrides']
            );
        }

        return $payload;
    }
}
