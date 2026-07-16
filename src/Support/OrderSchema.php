<?php

namespace App\Support;

use App\Core\Database;

/** Detects orders billing columns so queries work before/after migration 038. */
class OrderSchema
{
    private static ?bool $hasBillingPartyColumns = null;

    public static function hasBillingPartyColumns(): bool
    {
        if (self::$hasBillingPartyColumns !== null) {
            return self::$hasBillingPartyColumns;
        }

        $db = new Database();
        $row = $db->fetch(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'billing_party_id'"
        );
        self::$hasBillingPartyColumns = ((int)($row['c'] ?? 0)) > 0;
        return self::$hasBillingPartyColumns;
    }

    public static function billingPartyJoin(string $orderAlias = 'o', string $billingAlias = 'bp'): string
    {
        if (!self::hasBillingPartyColumns()) {
            return '';
        }

        return "LEFT JOIN parties {$billingAlias} ON {$orderAlias}.billing_party_id = {$billingAlias}.id";
    }

    public static function billingPartyNameSelect(string $billingAlias = 'bp'): string
    {
        if (!self::hasBillingPartyColumns()) {
            return 'NULL AS billing_party_name';
        }

        return "{$billingAlias}.name AS billing_party_name";
    }

    /** SQL predicate: invoice billed-to party vs order delivery or billing party. */
    public static function invoicePartyMatchWhere(string $orderAlias = 'o'): string
    {
        if (!self::hasBillingPartyColumns()) {
            return "{$orderAlias}.party_id = ?";
        }

        return "(
            (COALESCE({$orderAlias}.bill_to_other_party, 0) = 0 AND {$orderAlias}.party_id = ?)
            OR (COALESCE({$orderAlias}.bill_to_other_party, 0) = 1 AND {$orderAlias}.billing_party_id = ?)
        )";
    }

    /** @return array<int, int> */
    public static function invoicePartyMatchParams(int $partyId): array
    {
        if (!self::hasBillingPartyColumns()) {
            return [$partyId];
        }

        return [$partyId, $partyId];
    }
}
