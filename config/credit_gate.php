<?php

/**
 * Credit gate defaults. Per-company routing and the 10% Tier 2 ceiling live in
 * credit_policy_tiers and can change without a deploy. This file is only the
 * fallback used when seeding a new company.
 */
return [
    'timezone' => 'Asia/Kolkata',
    'expire_after_days' => 7,
    'default_tier2_percentage' => 10.00,
    'tiers' => [
        1 => [
            'threshold_type' => 'percentage',
            'threshold_percentage' => null,
            'threshold_amount' => null,
            'routing' => 'auto',
            'allows_provisional_proceed' => 0,
        ],
        2 => [
            'threshold_type' => 'percentage',
            'threshold_percentage' => 10.00,
            'threshold_amount' => null,
            'routing' => 'passive_queue',
            'allows_provisional_proceed' => 1,
        ],
        3 => [
            'threshold_type' => 'percentage',
            'threshold_percentage' => null,
            'threshold_amount' => null,
            'routing' => 'realtime_push',
            'allows_provisional_proceed' => 0,
        ],
    ],
];
