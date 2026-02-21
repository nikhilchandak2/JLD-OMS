<?php
/**
 * Commercial Tax Invoice - Excel Template Mapping Configuration
 * 
 * This file defines how data from Order/Dispatch is mapped to Excel cells
 * Template: Formats/Commercial Tax Invoice.xlsx
 */

return [
    'template_file' => 'Formats/Commercial Tax Invoice.xlsx',
    'type' => 'excel',
    'output_filename_pattern' => 'Commercial_Tax_Invoice_{ORDER_NO}_{DATE}.xlsx',
    'output_mode' => 'consolidated', // 'consolidated' or 'per_truck'
    
    // Single-value mappings (order/party/company data)
    // Format: 'CELL_REFERENCE' => 'data.path.to.value'
    'single_value_mappings' => [
        // Order information
        // 'B5' => 'order.order_no',        // Example - adjust based on actual template
        // 'B6' => 'order.order_date',
        // 'B7' => 'order.product_name',
        
        // Party information
        // 'C10' => 'party.name',
        // 'C11' => 'party.address',
        // 'C12' => 'party.contact_person',
        // 'C13' => 'party.phone',
        // 'C14' => 'party.email',
        
        // Company information
        // 'D10' => 'company.name',
        // 'D11' => 'company.address',
        // 'D12' => 'company.gst_number',
        // 'D13' => 'company.pan_number',
        
        // NOTE: These are placeholder mappings. 
        // Actual cell references need to be identified by analyzing the template
    ],
    
    // Repeating row mappings (for truck-wise data)
    // This section defines how dispatch data is mapped to repeating rows
    'repeating_rows' => [
        'start_row' => 15,                    // Row where truck data starts
        'template_row' => 15,                  // Template row to duplicate
        'end_row_marker' => null,              // Optional: row that marks end of data area
        'mappings' => [
            // Format: 'COLUMN_ROW' => 'dispatch.field'
            // 'A15' => 'dispatch.vehicle_no',
            // 'B15' => 'dispatch.dispatch_date',
            // 'C15' => 'dispatch.dispatch_qty_trucks',
            // 'D15' => 'order.product_name',
            // 'E15' => 'party.name',
            // NOTE: Actual mappings need to be identified from template
        ],
        'insert_mode' => 'duplicate',          // 'duplicate' or 'insert'
    ],
    
    // Formatting preservation
    'preserve_formatting' => true,
    'preserve_merged_cells' => true,
    'preserve_formulas' => true,
];
