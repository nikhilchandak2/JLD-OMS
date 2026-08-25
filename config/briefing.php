<?php

/**
 * New-rep account briefing (TASK 9).
 *
 * party_handover_notes is a TRANSITIONAL BRIDGE, not a permanent feature.
 * It exists because the first hire starts before 20 years of memory can be
 * reconstructed in the structured fields. Review on handover_notes_review_date
 * and remove the capture surface once account context is thick enough.
 */
return [
    'timezone' => 'Asia/Kolkata',
    'order_pattern_months' => 6,
    'issues_resolved_limit' => 10,
    'handover_notes_review_date' => '2026-12-31',
    'headroom_bands' => [
        'not_recorded' => 'Credit limit not recorded',
        'within_limit' => 'Within limit',
        'over_band' => 'Over limit — Director queue',
        'blocked' => 'Blocked until Director decision',
    ],
];
