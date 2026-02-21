<?php
/**
 * SCOMET FORMAT - Word Template Mapping Configuration
 * 
 * Template: Formats/SCOMET FORMAT (1).docx
 */

return [
    'template_file' => 'Formats/SCOMET FORMAT (1).docx',
    'type' => 'word',
    'output_filename_pattern' => 'SCOMET_{ORDER_NO}_{DATE}.docx',
    'output_mode' => 'consolidated',
    
    // Placeholder replacements
    // Format: '{PLACEHOLDER}' => 'data.path.to.value'
    'placeholders' => [
        // Order information
        // '{ORDER_NO}' => 'order.order_no',
        // '{ORDER_DATE}' => 'order.order_date',
        // '{PRODUCT_NAME}' => 'order.product_name',
        
        // Party information
        // '{PARTY_NAME}' => 'party.name',
        // '{PARTY_ADDRESS}' => 'party.address',
        // '{PARTY_CONTACT}' => 'party.contact_person',
        // '{PARTY_PHONE}' => 'party.phone',
        
        // Company information
        // '{COMPANY_NAME}' => 'company.name',
        // '{COMPANY_ADDRESS}' => 'company.address',
        // '{COMPANY_GST}' => 'company.gst_number',
        
        // NOTE: Actual placeholders need to be identified from template
    ],
    
    // Table row duplication
    // For tables that need to repeat rows for each dispatch/truck
    'tables' => [
        [
            'table_index' => 0,                 // First table (0-indexed)
            'template_row' => 1,                // Row to duplicate (0-indexed)
            'mappings' => [
                // Format: 'column_index' => 'dispatch.field'
                // 0 => 'dispatch.vehicle_no',   // First column
                // 1 => 'dispatch.dispatch_date', // Second column
                // 2 => 'dispatch.dispatch_qty_trucks',
                // NOTE: Actual column mappings need to be identified
            ]
        ]
    ],
    
    // Formatting preservation
    'preserve_formatting' => true,
    'preserve_styles' => true,
];
