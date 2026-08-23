<?php
/**
 * CRM pipeline configuration.
 *
 * Stage names and the enquiry-source list live here. What is *mandatory* to leave a stage
 * does NOT live here - it is Director-editable data in the crm_stage_exit_criteria table
 * so it can change without a deploy.
 */

return [
    'stages' => [
        1 => 'Inquiry Captured',
        2 => 'Qualification',
        3 => 'Sample / Trial Dispatched',
        4 => 'Trial Feedback & Fit',
        5 => 'Quotation Issued',
        6 => 'Negotiation / Order Confirm',
        7 => 'Order Processing & Dispatch',
    ],

    'statuses' => [
        'active' => 'Active',
        'won' => 'Won',
        'lost' => 'Lost',
        'dropped' => 'Dropped',
    ],

    'sources' => [
        'call' => 'Phone call',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'exhibition' => 'Exhibition',
        'referral' => 'Referral',
        'walk_in' => 'Walk-in',
        'other' => 'Other',
    ],

    // Default expected turnaround for a technical flag, used for the ageing sort when the
    // rep does not set one explicitly.
    'technical_flag_turnaround_hours' => 48,
];
