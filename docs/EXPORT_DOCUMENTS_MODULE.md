# Export Documents Module (Nepal)

This module is a **separate system** on the same OMS portal. It is used only for Nepal export documentation and is **not** linked to:

- **Orders & Dispatches** (OMS orders, order processing, analytics)
- **Vehicle Tracking** (vehicles, live tracking, trips, geofences, fuel)
- **Administration** (parties, products, users, Busy integration)

## Purpose

- One **Nepal export order** (fixed data: consignee, LC details, terms, product).
- Per **dispatch** (trucks, LR no., weight, amount): generate **one Excel file** containing:
  - Commercial Invoice
  - Tax Invoice
  - Packing List
  - (and any other sheets in the same fixed format)

Same input once per dispatch → one click → all documents in fixed format.

## Where it lives in the app

| What | Location |
|------|----------|
| **Sidebar** | Section **Export Documents** → **Nepal Export Docs** (between Vehicle Tracking and Administration) |
| **Web route** | `GET /export` |
| **API routes** | `GET/POST /api/export/orders`, `GET /api/export/orders/{id}`, `POST /api/export/dispatch-pack`, `GET /api/export/download` |
| **Controller** | `App\Controllers\ExportDocumentsController` |
| **Service** | `App\Services\ExportDocumentPackService` (generates the pack) |
| **Templates** | `templates/export/` (e.g. `index.php`) |
| **Storage** | `storage/export_documents/` (generated files) |

## Data separation

- **Export orders** are stored in `export_orders` (or equivalent). They are **not** the same as `orders` in the main OMS.
- **Dispatch data** for document generation (trucks, LR no., weight, amount) is passed when calling **Generate dispatch pack**; it is **not** tied to OMS dispatches or vehicle trips.
- Document generation uses only export-order + dispatch payload; it does not read from OMS orders, dispatches, or tracking.

## Next steps (implementation)

1. **Migration**: Add `export_orders` (and optionally `export_dispatches`) tables; keep schema focused on export fields (consignee, LC, terms, product, etc.).
2. **Templates**: Add Excel templates for Commercial Invoice, Tax Invoice, Packing List under e.g. `Formats/Export/` or `config/export_document_mappings/`.
3. **Mapping**: Define cell mappings per sheet (fixed fields from export order, variable fields from dispatch).
4. **ExportDocumentPackService**: Implement `generatePack()` to build one workbook with all sheets and save to `storage/export_documents/`.
5. **UI**: Add form to create/edit export orders and “Generate dispatch pack” (select order + enter dispatch details, then call `POST /api/export/dispatch-pack` and offer download).

This keeps Nepal export docs **separate** from vehicle tracking, order processing, and administration on the same portal.
