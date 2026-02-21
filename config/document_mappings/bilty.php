<?php
/**
 * Bilty (Bill of Lading) - Word Template Mapping Configuration
 * 
 * Template: Formats/Bulty Updated.docx
 */

return [
    'template_file' => 'Formats/Bulty Updated.docx',
    'type' => 'word',
    'output_filename_pattern' => 'Bilty_{ORDER_NO}_{VEHICLE_NO}_{DATE}.docx',
    'output_mode' => 'per_truck', // One file per truck
    
    'placeholders' => [
        // Placeholder mappings to be identified from template
    ],
    
    'tables' => [
        // Table mappings to be identified from template
    ],
    
    'preserve_formatting' => true,
    'preserve_styles' => true,
];
