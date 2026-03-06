# Export document mappings

Templates are loaded from **`Formats/Export/`** (relative to project root).

## Placeholder-based layout (recommended)

To keep your **exact** Excel layout and formatting (borders, fonts, alignment), put **placeholders** in the cells where you want values. The generator replaces only the placeholder text and leaves all formatting unchanged.

### In your Excel templates

1. Open **Commercial Invoice.xlsx** and **Packing List.xlsx** in Excel.
2. In each cell where a value should appear, type the placeholder in double curly braces, e.g. `{{INVOICE_NO}}`, `{{CONSIGNEE}}`.
3. Save the file. That cell’s borders, font, and alignment stay as-is; only the placeholder is replaced when you generate documents.

### Available placeholders

**Order / exporter (same in all sheets)**  
`{{REFERENCE_NO}}` `{{BUYER_PO_NO}}` `{{BUYER_PO_DATE}}` `{{CONSIGNEE}}` `{{CONSIGNEE_ADDRESS}}`  
`{{NOTIFY_APPLICANT}}` `{{NOTIFY_ADDRESS}}` `{{PAN_NO}}` `{{EXIM_CODE}}`  
`{{LC_NUMBER}}` `{{LC_ISSUE_DATE}}` `{{HARMONIC_CODE}}` `{{COUNTRY_ORIGIN}}` `{{COUNTRY_DESTINATION}}`  
`{{CUSTOMS_ENTRY}}` `{{PAYMENT_TERMS}}` `{{DELIVERY_TERMS}}` `{{PRODUCT_DESCRIPTION}}` `{{PRODUCT_ITEM}}`  
`{{PACKAGING}}` `{{TOTAL_BAGS}}` `{{FINAL_DESTINATION}}` `{{OUR_PI_NO}}`  
`{{IEC}}` `{{GSTIN}}` `{{EXPORTER_NAME}}` `{{EXPORTER_ADDRESS}}` `{{EXPORTER_EMAIL}}` `{{EXPORTER_PHONE}}`  
`{{BENEFICIARY_NAME}}` `{{BANK_ACCOUNT}}` `{{BANK_NAME}}` `{{PRE_CARRIAGE}}` `{{PLACE_OF_RECEIPT}}`

**Dispatch (header/summary)**  
`{{INVOICE_NO}}` `{{INVOICE_DATE}}` `{{TRUCK_NUMBERS}}` `{{LR_NUMBERS_AND_DATES}}` `{{SHIPPING_BILL}}`  
`{{TOTAL_WEIGHT_MT}}` `{{RATE_PER_MT}}` `{{AMOUNT}}` `{{AMOUNT_IN_WORDS}}` `{{ASSESSABLE_VALUE}}`

**Packing List – truck rows (one row per truck)**  
Put these in the **first** truck row only; that row is duplicated for each truck.  
`{{TRUCK_NO}}` `{{LR_NO}}` `{{DATE}}` `{{QTY_MT}}` `{{BAGS}}`

The row used as the truck template is set in `packing_list.php` as `repeating_rows.template_row` (e.g. 25).

### Template file names

- **Commercial Invoice:** set in `commercial_invoice.php` → `'template_file' => 'Formats/Export/Commercial Invoice.xlsx'`
- **Packing List:** set in `packing_list.php` → `'template_file' => 'Formats/Export/Packing List.xlsx'`

If your Excel filenames differ, edit `template_file` in each PHP file. For Packing List, ensure `repeating_rows.template_row` is the 1-based row index of the row that contains the truck placeholders (`{{TRUCK_NO}}`, etc.).
