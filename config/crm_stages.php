<?php
/**
 * CRM pipeline stages for leads and deals.
 * Used by CRM UI and validation.
 */

return [
    'lead_stages' => [
        'new'       => 'New',
        'contacted' => 'Contacted',
        'qualified' => 'Qualified',
        'converted' => 'Converted',
        'lost'      => 'Lost',
    ],
    'deal_stages' => [
        'qualified'   => 'Qualified',
        'proposal'    => 'Proposal',
        'negotiation' => 'Negotiation',
        'won'         => 'Won',
        'lost'        => 'Lost',
    ],
    'activity_types' => [
        'call'    => 'Call',
        'meeting' => 'Meeting',
        'note'    => 'Note',
        'email'   => 'Email',
    ],
];
