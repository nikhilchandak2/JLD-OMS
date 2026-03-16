<?php
/**
 * CRM pipeline stages and options per BRD (B2B ceramic raw materials).
 * Activity types, Contact roles, Sample statuses, Funnel stages.
 */

return [
    // Sales Activity + WhatsApp, visits
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
    // log.md: 5-stage CRM funnel (company-level)
    'funnel_stages' => [
        'sampling'          => '1. Sampling',
        'technical_support' => '2. Technical Support',
        're_sampling'       => '3. Re-Sampling',
        'trial_order'       => '4. Trial Order',
        'closed'            => '5. Closed',
    ],
    // log.md: Industry type
    'industry_types' => [
        'tiles'        => 'Tiles',
        'sanitaryware' => 'Sanitaryware',
        'tableware'    => 'Tableware',
        'refractory'   => 'Refractory',
        'glaze'        => 'Glaze',
    ],
    // log.md: Tiles subtype (when industry = Tiles)
    'tiles_subtypes' => [
        'slab'           => 'Slab',
        'double_charge'  => 'Double Charge',
        'gvt'            => 'GVT',
        'nano'           => 'Nano',
        'full_body'      => 'Full Body',
        'other'          => 'Other',
    ],
    // Visit details: sample products dropdown (samples provided to client)
    'sample_products' => [
        'ball_clay'   => 'Ball Clay',
        'kaolin'      => 'Kaolin',
        'feldspar'    => 'Feldspar',
        'quartz'      => 'Quartz',
        'soda_feldspar' => 'Soda Feldspar',
        'potash_feldspar' => 'Potash Feldspar',
        'other'      => 'Other',
    ],
];
