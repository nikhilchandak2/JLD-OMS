# Template Mapping Guide

## How to Complete Template Mappings

The mapping configuration files in `config/document_mappings/` are currently placeholders. You need to analyze each template and fill in the actual cell references or placeholders.

---

## For Excel Templates (.xlsx, .xls)

### Step 1: Open Template in Excel

1. Open the template file (e.g., `Formats/Commercial Tax Invoice.xlsx`)
2. Identify which cells contain:
   - **Static text** (headers, labels) - these stay as-is
   - **Dynamic data** (order number, party name, etc.) - these need mapping

### Step 2: Identify Cell References

For each dynamic field, note:
- **Cell reference** (e.g., `B5`, `C10`, `D15`)
- **What data it should contain** (e.g., order number, party name)

### Step 3: Update Mapping Configuration

Edit the corresponding `.php` file in `config/document_mappings/`:

```php
'single_value_mappings' => [
    'B5' => 'order.order_no',        // Cell B5 = Order Number
    'B6' => 'order.order_date',      // Cell B6 = Order Date
    'C10' => 'party.name',           // Cell C10 = Party Name
    'C11' => 'party.address',        // Cell C11 = Party Address
    'D10' => 'company.name',         // Cell D10 = Company Name
    'D12' => 'company.gst_number',   // Cell D12 = Company GST Number
    // ... add all mappings
],
```

### Step 4: Identify Repeating Rows

If the template has a table that repeats for each truck/dispatch:

1. Find the **template row** (the row that will be duplicated)
2. Note the **row number** (e.g., row 15)
3. Map each column to dispatch data:

```php
'repeating_rows' => [
    'start_row' => 15,              // Where truck data starts
    'template_row' => 15,            // Row to duplicate
    'mappings' => [
        'A15' => 'dispatch.vehicle_no',      // Column A = Vehicle Number
        'B15' => 'dispatch.dispatch_date',    // Column B = Dispatch Date
        'C15' => 'dispatch.dispatch_qty_trucks', // Column C = Quantity
        // ... add all column mappings
    ]
],
```

---

## For Word Templates (.docx)

### Step 1: Open Template in Word

1. Open the template file (e.g., `Formats/SCOMET FORMAT (1).docx`)
2. Look for **placeholders** like:
   - `{ORDER_NO}`
   - `{PARTY_NAME}`
   - `{COMPANY_NAME}`
   - Or similar patterns

### Step 2: Identify Placeholders

Note all placeholders and what data they should contain.

### Step 3: Update Mapping Configuration

Edit the corresponding `.php` file:

```php
'placeholders' => [
    '{ORDER_NO}' => 'order.order_no',
    '{ORDER_DATE}' => 'order.order_date',
    '{PARTY_NAME}' => 'party.name',
    '{PARTY_ADDRESS}' => 'party.address',
    '{COMPANY_NAME}' => 'company.name',
    '{COMPANY_GST}' => 'company.gst_number',
    // ... add all placeholders
],
```

### Step 4: Identify Tables

If the template has tables that need row duplication:

1. Count tables in the document (0-indexed)
2. Find the **template row** (row to duplicate)
3. Map columns to dispatch data:

```php
'tables' => [
    [
        'table_index' => 0,          // First table (0 = first)
        'template_row' => 1,         // Row to duplicate (0-indexed)
        'mappings' => [
            0 => 'dispatch.vehicle_no',      // Column 0 = Vehicle Number
            1 => 'dispatch.dispatch_date',   // Column 1 = Dispatch Date
            2 => 'dispatch.dispatch_qty_trucks', // Column 2 = Quantity
            // ... add all column mappings
        ]
    ]
],
```

---

## Available Data Fields

### Order Data (`order.*`)
- `order.order_no`
- `order.order_date`
- `order.product_name`
- `order.order_qty_trucks`
- `order.status`

### Party Data (`party.*`)
- `party.name`
- `party.address`
- `party.contact_person`
- `party.phone`
- `party.email`

### Company Data (`company.*`)
- `company.name`
- `company.address`
- `company.gst_number`
- `company.pan_number`
- `company.contact_person`
- `company.phone`
- `company.email`

### Dispatch Data (`dispatch.*`)
- `dispatch.vehicle_no`
- `dispatch.dispatch_date`
- `dispatch.dispatch_qty_trucks`
- `dispatch.remarks`

---

## Testing Mappings

After updating mappings:

1. Use the API endpoint: `POST /api/documents/generate`
2. Or create a test script in `scripts/test_document_generation.php`
3. Check generated files in `storage/documents/`
4. Verify all data is correctly populated

---

## Example: Complete Mapping

### Commercial Tax Invoice (Excel)

```php
return [
    'template_file' => 'Formats/Commercial Tax Invoice.xlsx',
    'type' => 'excel',
    'output_filename_pattern' => 'Commercial_Tax_Invoice_{ORDER_NO}_{DATE}.xlsx',
    'output_mode' => 'consolidated',
    
    'single_value_mappings' => [
        'B5' => 'order.order_no',
        'B6' => 'order.order_date',
        'B7' => 'order.product_name',
        'C10' => 'party.name',
        'C11' => 'party.address',
        'C12' => 'party.contact_person',
        'C13' => 'party.phone',
        'D10' => 'company.name',
        'D11' => 'company.address',
        'D12' => 'company.gst_number',
    ],
    
    'repeating_rows' => [
        'start_row' => 15,
        'template_row' => 15,
        'mappings' => [
            'A15' => 'dispatch.vehicle_no',
            'B15' => 'dispatch.dispatch_date',
            'C15' => 'dispatch.dispatch_qty_trucks',
            'D15' => 'order.product_name',
        ],
    ],
];
```

---

## Need Help?

If you're unsure about:
- Cell references: Open Excel, select a cell, check the name box (top-left)
- Placeholders: Search for `{` or `[` in Word document
- Table structure: Count tables from top to bottom (0-indexed)
