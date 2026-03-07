<?php
/**
 * CRM pipeline stages and options per BRD (B2B ceramic raw materials).
 * Lead stages, Deal/Opportunity stages, Activity types, Contact roles, Sample statuses.
 */

return [
    // 4.1 Lead Management – BRD stages
    'lead_stages' => [
        'new_lead'              => 'New Lead',
        'contacted'             => 'Contacted',
        'interested'             => 'Interested',
        'trial_stage'           => 'Trial Stage',
        'commercial_negotiation' => 'Commercial Negotiation',
        'converted_customer'    => 'Converted Customer',
        'lost'                  => 'Lost',
    ],
    // 4.2 Opportunity & Pipeline Management – BRD stages
    'deal_stages' => [
        'prospect_identified'   => 'Prospect Identified',
        'initial_meeting'       => 'Initial Meeting',
        'sample_sent'           => 'Sample Sent',
        'trial_under_testing'   => 'Trial Under Testing',
        'trial_success'        => 'Trial Success',
        'commercial_discussion' => 'Commercial Discussion',
        'trial_rejection'      => 'Trial Rejection',
        'converted_customer'    => 'Converted Customer',
    ],
    // 4.5 Sales Activity + WhatsApp, visits
    'activity_types' => [
        'call'     => 'Call',
        'meeting'  => 'Customer Meeting',
        'visit'    => 'Sales Visit',
        'whatsapp' => 'WhatsApp',
        'email'    => 'Email',
        'note'     => 'Note',
    ],
    // 4.3 Customer Database – Contact roles
    'contact_roles' => [
        'purchase_manager' => 'Purchase Manager',
        'technical_head'   => 'Technical Head',
        'plant_head'       => 'Plant Head',
        'owner'            => 'Owner',
        'other'            => 'Other',
    ],
    // 4.3 Product category (tiles / sanitary / tableware etc.)
    'product_categories' => [
        'tiles'      => 'Tiles',
        'sanitary'   => 'Sanitary',
        'tableware'  => 'Tableware',
        'other'      => 'Other',
    ],
    // 4.4 Sample & Trial – statuses
    'sample_statuses' => [
        'sample_sent'      => 'Sample Sent',
        'trial_scheduled'  => 'Trial Scheduled',
        'trial_successful' => 'Trial Successful',
        'trial_failed'     => 'Trial Failed',
        'trial_retesting'  => 'Trial Retesting',
    ],
];
