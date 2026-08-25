<?php

/**
 * Monthly grade-level forecasting (TASK 7).
 *
 * The purpose_line is shown persistently on the entry screen. It is not a
 * performance measure. Change this string without a deploy of application logic
 * — Director wording goes here when supplied.
 */
return [
    'timezone' => 'Asia/Kolkata',
    'prefill_completed_months' => 3,
    'purpose_line' => 'This forecast exists to help production serve customers. It is not a measure of your performance.',
    'confidences' => [
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
    ],
    'sources' => [
        'prefilled' => 'From recent dispatches',
        'edited' => 'Adjusted',
        'added' => 'Added',
    ],
];
