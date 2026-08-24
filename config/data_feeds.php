<?php

/**
 * Daily batch ingest configuration.
 *
 * Deadlines are IST. Changing a data_feeds row (owner, deadline, active) changes
 * behaviour with no deploy; these defaults are used only when seeding a new company.
 *
 * Ledger ingestion is batch-only by design (B1). There is no live Busy path.
 */
return [
    'timezone' => 'Asia/Kolkata',
    'feeds' => [
        'ledger' => [
            'display_name' => 'Ledger (Busy outstanding)',
            'deadline_local_time' => '09:00:00',
            'source_system' => 'busy',
        ],
        'dispatch_day_file' => [
            'display_name' => 'Dispatch day file',
            'deadline_local_time' => '18:00:00',
            'source_system' => 'dispatch',
        ],
    ],
    'required_columns' => [
        'ledger' => ['outstanding_amount'],
        'dispatch_day_file' => ['grade_code', 'quantity_tonnes'],
    ],
    'party_columns' => ['party_name', 'party_code'],
    'header_aliases' => [
        'party_name' => ['party name', 'customer', 'party', 'customer name', 'name', 'buyer', 'consignee', 'account'],
        'party_code' => ['party code', 'customer code', 'account code', 'busy code', 'code'],
        'outstanding_amount' => ['outstanding', 'outstanding amount', 'amount', 'due', 'balance', 'amount due', 'balance due', 'due amount', 'pending'],
        'invoice_no' => ['invoice no', 'invoice #', 'bill no', 'invoice', 'reference', 'inv no', 'invoice number'],
        'invoice_date' => ['invoice date', 'bill date', 'date'],
        'grade_code' => ['grade', 'grade code', 'product', 'product code', 'item', 'item code'],
        'quantity_tonnes' => ['quantity', 'qty', 'quantity tonnes', 'qty tonnes', 'tonnes', 'mt', 'weight'],
        'vehicle_no' => ['vehicle', 'vehicle no', 'vehicle number', 'truck', 'truck no'],
        'destination' => ['destination', 'site', 'delivery location'],
        'dispatch_date' => ['dispatch date', 'despatch date', 'delivery date'],
    ],
    'max_upload_bytes' => 10 * 1024 * 1024,
    'max_rows' => 20000,
    'five_thousand_row_budget_seconds' => 15,
];
