<?php
/**
 * SAFTA Invoice Format - Excel Template Mapping Configuration
 * 
 * Template: Formats/Safta Invoice Format.xls
 */

return [
    'template_file' => 'Formats/Safta Invoice Format.xls',
    'type' => 'excel',
    'output_filename_pattern' => 'SAFTA_Invoice_{ORDER_NO}_{DATE}.xlsx',
    'output_mode' => 'consolidated',
    
    'single_value_mappings' => [
        // Cell mappings to be identified from template
    ],
    
    'repeating_rows' => [
        'start_row' => null, // To be identified
        'template_row' => null,
        'mappings' => [
            // Column mappings to be identified
        ],
    ],
    
    'preserve_formatting' => true,
    'preserve_merged_cells' => true,
    'preserve_formulas' => true,
];
