<?php
/**
 * Placeholder keys for Nepal export Excel templates.
 * In your Excel files, put these exactly in the cells where you want the values (e.g. {{INVOICE_NO}}).
 * Formatting (borders, fonts, alignment) is preserved – only the placeholder text is replaced.
 *
 * Single-value placeholders (used in Commercial Invoice and Packing List):
 *   Order/exporter: REFERENCE_NO, BUYER_PO_NO, BUYER_PO_DATE, CONSIGNEE, CONSIGNEE_ADDRESS, NOTIFY_APPLICANT, NOTIFY_ADDRESS,
 *   PAN_NO, EXIM_CODE, LC_NUMBER, LC_ISSUE_DATE, HARMONIC_CODE, COUNTRY_ORIGIN, COUNTRY_DESTINATION, CUSTOMS_ENTRY,
 *   PAYMENT_TERMS, DELIVERY_TERMS, PRODUCT_DESCRIPTION, PRODUCT_ITEM, PACKAGING, TOTAL_BAGS, FINAL_DESTINATION, OUR_PI_NO,
 *   IEC, GSTIN, EXPORTER_NAME, EXPORTER_ADDRESS, EXPORTER_EMAIL, EXPORTER_PHONE, BENEFICIARY_NAME, BANK_ACCOUNT, BANK_NAME,
 *   PRE_CARRIAGE, PLACE_OF_RECEIPT.
 *   Dispatch: INVOICE_NO, INVOICE_DATE, TRUCK_NUMBERS, LR_NUMBERS_AND_DATES, SHIPPING_BILL, TOTAL_WEIGHT_MT, RATE_PER_MT,
 *   AMOUNT, AMOUNT_IN_WORDS, ASSESSABLE_VALUE.
 *
 * Truck-row placeholders (only in the repeating row of Packing List): TRUCK_NO, LR_NO, DATE, QTY_MT, BAGS.
 */

return [
    // Order / exporter (same in all sheets)
    'REFERENCE_NO' => 'order.reference_no',
    'BUYER_PO_NO' => 'order.buyer_po_no',
    'BUYER_PO_DATE' => 'order.buyer_po_date',
    'CONSIGNEE' => 'order.consignee',
    'CONSIGNEE_ADDRESS' => 'order.consignee_address',
    'NOTIFY_APPLICANT' => 'order.notify_applicant',
    'NOTIFY_ADDRESS' => 'order.notify_address',
    'PAN_NO' => 'order.pan_no',
    'EXIM_CODE' => 'order.exim_code',
    'LC_NUMBER' => 'order.lc_number',
    'LC_ISSUE_DATE' => 'order.lc_issue_date',
    'HARMONIC_CODE' => 'order.harmonic_code',
    'COUNTRY_ORIGIN' => 'order.country_origin',
    'COUNTRY_DESTINATION' => 'order.country_destination',
    'CUSTOMS_ENTRY' => 'order.customs_entry',
    'PAYMENT_TERMS' => 'order.payment_terms',
    'DELIVERY_TERMS' => 'order.delivery_terms',
    'PRODUCT_DESCRIPTION' => 'order.product_description',
    'PRODUCT_ITEM' => 'order.product_item',
    'PACKAGING' => 'order.packaging',
    'TOTAL_BAGS' => 'order.total_bags',
    'FINAL_DESTINATION' => 'order.final_destination',
    'OUR_PI_NO' => 'order.our_pi_no',
    'IEC' => 'order.iec',
    'GSTIN' => 'order.gstin',
    'EXPORTER_NAME' => 'order.exporter_name',
    'EXPORTER_ADDRESS' => 'order.exporter_address',
    'EXPORTER_EMAIL' => 'order.exporter_email',
    'EXPORTER_PHONE' => 'order.exporter_phone',
    'BENEFICIARY_NAME' => 'order.beneficiary_name',
    'BANK_ACCOUNT' => 'order.bank_account',
    'BANK_NAME' => 'order.bank_name',
    'PRE_CARRIAGE' => 'order.pre_carriage',
    'PLACE_OF_RECEIPT' => 'order.place_of_receipt',

    // Dispatch (header/summary)
    'INVOICE_NO' => 'dispatch.invoice_no',
    'INVOICE_DATE' => 'dispatch.invoice_date',
    'TRUCK_NUMBERS' => 'dispatch.truck_numbers',
    'LR_NUMBERS_AND_DATES' => 'dispatch.lr_numbers_and_dates',
    'SHIPPING_BILL' => 'dispatch.shipping_bill',
    'TOTAL_WEIGHT_MT' => 'dispatch.total_weight_mt',
    'RATE_PER_MT' => 'dispatch.rate_per_mt',
    'AMOUNT' => 'dispatch.amount',
    'AMOUNT_IN_WORDS' => 'dispatch.amount_in_words',
    'ASSESSABLE_VALUE' => 'dispatch.assessable_value',

    // Truck row (per-row in Packing List) – dispatch in context is that row’s truck
    'TRUCK_NO' => 'dispatch.truck_no',
    'LR_NO' => 'dispatch.lr_no',
    'DATE' => 'dispatch.date',
    'QTY_MT' => 'dispatch.qty_mt',
    'BAGS' => 'dispatch.bags',
];
