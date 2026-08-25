<?php

/**
 * Pipeline dashboards (TASK 10).
 *
 * Stall thresholds are Director-editable here without a deploy. A deal on
 * technical hold is not a stalled rep: hold time is stored separately and
 * subtracted from dwell before the threshold is applied.
 */
return [
    'timezone' => 'Asia/Kolkata',
    'default_stall_days' => 14,
    'stall_days_by_stage' => [
        // 1 => 7,
    ],
];
