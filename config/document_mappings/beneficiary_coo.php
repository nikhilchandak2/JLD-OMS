<?php
/**
 * Beneficiary COO (Certificate of Origin) - Word Template Mapping Configuration
 * 
 * Template: Formats/Benifcery COO.docx
 */

return [
    'template_file' => 'Formats/Benifcery COO.docx',
    'type' => 'word',
    'output_filename_pattern' => 'Beneficiary_COO_{ORDER_NO}_{DATE}.docx',
    'output_mode' => 'consolidated',
    
    'placeholders' => [
        // Placeholder mappings to be identified from template
    ],
    
    'tables' => [
        // Table mappings to be identified from template
    ],
    
    'preserve_formatting' => true,
    'preserve_styles' => true,
];
