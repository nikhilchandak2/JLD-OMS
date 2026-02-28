<?php
/**
 * Packing List – Excel template mapping for Nepal export.
 * File in Formats/Export/: Packing List.xlsx
 * Repeating rows: one row per truck (template_row used for truck-wise data).
 */

return [
    'template_file' => 'Formats/Export/Packing List.xlsx',
    'sheet_name' => null,
    'single_value_mappings' => [
        'A6' => 'order.reference_no',
        'D6' => 'dispatch.invoice_no',
        'G6' => 'dispatch.invoice_date',
        'A7' => 'order.exporter_name',
        'A8' => 'order.exporter_address',
        'A10' => 'order.exporter_email',
        'A11' => 'order.exporter_phone',
        'A13' => 'order.consignee',
        'D13' => 'order.notify_applicant',
        'D14' => 'order.notify_address',
        'D15' => 'order.pan_no',
        'D16' => 'order.exim_code',
        'D8' => 'order.buyer_po_no',
        'D10' => 'order.our_pi_no',
        'A18' => 'order.pre_carriage',
        'B18' => 'order.place_of_receipt',
        'A20' => 'order.country_origin',
        'B20' => 'order.country_destination',
        'D20' => 'order.final_destination',
        'D18' => 'order.payment_terms',
        'D19' => 'order.delivery_terms',
        'A24' => 'order.product_description',
        'A25' => 'order.product_item',
        'A26' => 'order.packaging',
        'A27' => 'order.total_bags',
        'A30' => 'order.lc_number',
        'A31' => 'order.lc_issue_date',
        'A32' => 'order.harmonic_code',
        'A33' => 'order.country_origin',
        'A34' => 'order.customs_entry',
        'H39' => 'dispatch.total_weight_mt',
        'H40' => 'order.exporter_name',
    ],
    'repeating_rows' => [
        'start_row' => 25,
        'template_row' => 25,
        'mappings' => [
            'D25' => 'dispatch.truck_no',
            'E25' => 'dispatch.lr_no',
            'F25' => 'dispatch.date',
            'G25' => 'dispatch.qty_mt',
            'G26' => 'dispatch.bags',
        ],
    ],
];
