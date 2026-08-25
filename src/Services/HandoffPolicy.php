<?php

namespace App\Services;

/**
 * Role capabilities for handoff packets. Receiving teams acknowledge; they do not edit payload fields.
 */
class HandoffPolicy
{
    public const VIEW = 'view_handoff';
    public const CREATE_SALES = 'create_sales_to_dispatch';
    public const ACK_SALES = 'acknowledge_sales_to_dispatch';
    public const CREATE_ACCOUNTS = 'create_dispatch_to_accounts';
    public const ACK_ACCOUNTS = 'acknowledge_dispatch_to_accounts';

    /** @var array<string,string[]> */
    private const CAPABILITIES = [
        self::VIEW => ['admin', 'crm', 'sales', 'entry', 'dispatch', 'order_processing', 'accounts', 'marketing'],
        self::CREATE_SALES => ['admin', 'crm', 'sales', 'entry'],
        self::ACK_SALES => ['admin', 'dispatch', 'order_processing'],
        self::CREATE_ACCOUNTS => ['admin', 'dispatch', 'order_processing', 'accounts'],
        self::ACK_ACCOUNTS => ['admin', 'accounts'],
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
            throw new HandoffAuthorizationException(
                "Role '" . ($role ?? 'none') . "' is not permitted to {$capability}."
            );
        }
    }

    public function createCapability(string $packetType): string
    {
        return $packetType === HandoffService::TYPE_DISPATCH_TO_ACCOUNTS
            ? self::CREATE_ACCOUNTS
            : self::CREATE_SALES;
    }

    public function acknowledgeCapability(string $packetType): string
    {
        return $packetType === HandoffService::TYPE_DISPATCH_TO_ACCOUNTS
            ? self::ACK_ACCOUNTS
            : self::ACK_SALES;
    }
}
