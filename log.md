Context
This PHP-based OMS already exists.
I have uploaded final, approved document formats in Excel (.xlsx) and Word (.docx).
These formats MUST be reused exactly. No redesign, no layout changes.
Objective
Build a document generation system where:
Data is entered once (master export dispatch form)
The same data populates multiple existing Excel/Word templates
Output files retain original formatting, headers, merged cells, and structure
Uploaded Files
SCOMET format (Excel/Word)
COO format
BILTI format
Commercial Tax Invoice format
SAFTA Invoice format
Rules
Do NOT recreate templates in HTML
Do NOT modify layout
Only replace placeholders or mapped cells
Output must be downloadable as Excel/Word
Technical Requirements
PHP only
Use PHPSpreadsheet for Excel
Use PHPWord for Word documents
MySQL as data source
Implementation Tasks
Analyze uploaded templates
Identify fixed cells / placeholders
Identify repeating rows (truck-wise data)
Define a mapping layer
Database field → Excel cell OR Word placeholder
Create reusable PHP services:
loadTemplate()
mapData()
generateOutput()
Support:
Multiple trucks per day
One file per truck OR consolidated file (configurable)
Log generated documents (order ID, truck ID, document type)
Deliverables
Mapping configuration array for each document
PHP code for Excel population
PHP code for Word population
Example generation for one order with two trucks
Constraints
No hardcoded business values
Easy to add new formats later
No framework change
Start by explaining how the uploaded templates will be parsed and mapped.
The required formats and files are present in the Formats folder.

---
## 2025-02-22 – WheelsEye vendor API (to continue later)
- Vendor provided **WheelsEye current-location API** details.
- Documented in: `docs/WHEELSEYE_VENDOR_API.md`
- **Action:** Share **vehicle numbers** (and IMEIs if needed) with vendor (ref: 8387079292).
- Optional later: implement pull from `api.wheelseye.com/currentLoc` using token in `.env`.
