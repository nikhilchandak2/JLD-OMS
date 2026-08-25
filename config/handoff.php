<?php

/**
 * Versioned handoff packet schemas (TASK 8).
 *
 * Change required keys here and existing packets keep their schema_version.
 * New packets always persist at current_schema_version.
 */
return [
    'current_schema_version' => 1,
    'timezone' => 'Asia/Kolkata',
    'delivery_terms' => [
        'ex_works' => 'Ex-works',
        'for' => 'FOR',
        'freight' => 'Freight',
    ],
    'packet_types' => [
        'sales_to_dispatch' => 'Sales → Dispatch',
        'dispatch_to_accounts' => 'Dispatch → Accounts',
    ],
    'schemas' => [
        'sales_to_dispatch' => [
            1 => [
                'required' => [
                    'grades',
                    'quantity_tonnes',
                    'packing',
                    'delivery_timeline',
                    'delivery_terms',
                    'special_handling_notes',
                ],
            ],
        ],
        'dispatch_to_accounts' => [
            1 => [
                'required' => [
                    'delivery_date',
                    'quote_reference',
                    'agreed_terms',
                    'invoice_reference',
                ],
            ],
        ],
    ],
];
