<?php

/**
 * Labels for relationship mapping, competitive intelligence, and account issues.
 * Changing a label here changes display copy; the ENUM values are the contract.
 *
 * Competitor intelligence is visible to Sales and the Director (admin) only.
 * Dispatch and Accounts are gated out — assumed no until the client says otherwise.
 */
return [
    'influence_levels' => [
        'decision_maker' => 'Decision maker',
        'technical_gatekeeper' => 'Technical gatekeeper',
        'end_user' => 'End user',
        'blocker' => 'Blocker',
        'unknown' => 'Unknown',
    ],
    'relationship_strengths' => [
        'strong' => 'Strong',
        'neutral' => 'Neutral',
        'cold' => 'Cold',
        'unknown' => 'Unknown',
    ],
    'preferred_channels' => [
        'call' => 'Call',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'in_person' => 'In person',
    ],
    'intelligence_types' => [
        'factual' => 'Factual',
        'reported' => 'Reported',
        'estimated' => 'Estimated',
    ],
    'reason_codes' => [
        'price' => 'Price',
        'relationship' => 'Relationship',
        'spec_fit' => 'Spec / fit',
        'logistics' => 'Logistics',
        'payment_terms' => 'Payment terms',
        'other' => 'Other',
    ],
    'issue_types' => [
        'quality_complaint' => 'Quality complaint',
        'delivery_failure' => 'Delivery failure',
        'commercial' => 'Commercial',
        'other' => 'Other',
    ],
    'issue_statuses' => [
        'open' => 'Open',
        'resolved' => 'Resolved',
        'escalated' => 'Escalated',
    ],
    'default_resolution_window_days' => 7,
];
