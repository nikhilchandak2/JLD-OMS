<?php

/**
 * Dormancy and escalation (TASK 6). Thresholds themselves live in dormancy_rules
 * and escalation_rules so a config-row change is enough — this file is labels
 * and job behaviour only.
 */
return [
    'timezone' => 'Asia/Kolkata',
    'job_name' => 'crm_nightly',
    'lock_timeout_seconds' => 0,
    'stale_lock_minutes' => 30,
    'severities' => [
        'watch' => 'Watch',
        'urgent' => 'Urgent',
    ],
    'trigger_types' => [
        'dormant_account' => 'Dormant account',
        'unresolved_issue' => 'Unresolved issue',
        'dispatch_delay' => 'Dispatch delay',
        'technical_overdue' => 'Technical overdue',
        'manual_flag' => 'Needs senior attention',
    ],
    'statuses' => [
        'open' => 'Open',
        'acknowledged' => 'Acknowledged',
        'resolved' => 'Resolved',
        'dismissed' => 'Dismissed',
    ],
];
