# Document Generation System - Next Steps

## ✅ What's Been Done

1. **Foundation Created**
   - PHPWord dependency added to `composer.json`
   - Mapping configuration system created
   - Excel and Word document services implemented
   - API endpoints created
   - Database migration for logging

2. **Document Types Configured**
   - Commercial Tax Invoice (Excel)
   - SCOMET Format (Word)
   - Beneficiary COO (Word)
   - Bilty (Word)
   - SAFTA Invoice (Excel)

3. **Services Created**
   - `DocumentGeneratorService` - Main service
   - `ExcelDocumentService` - Excel template handling
   - `WordDocumentService` - Word template handling

---

## 🔧 What Needs to Be Done

### Step 1: Install PHPWord

On your server, run:
```bash
cd /var/www/tracking
composer install
```

Or locally:
```bash
composer install
```

---

### Step 2: Run Database Migration

```bash
mysql -u tracking_user -p order_processing_prod < database/migrations/007_create_document_generation_logs.sql
```

---

### Step 3: Analyze Templates and Complete Mappings

For each template, you need to:

#### For Excel Templates:
1. Open template in Excel
2. Identify which cells contain dynamic data
3. Note the cell references (e.g., B5, C10)
4. Update the mapping file in `config/document_mappings/`

#### For Word Templates:
1. Open template in Word
2. Find placeholders (e.g., `{ORDER_NO}`, `{PARTY_NAME}`)
3. Update the mapping file with actual placeholders

**See `docs/TEMPLATE_MAPPING_GUIDE.md` for detailed instructions.**

---

### Step 4: Test Document Generation

#### Option A: Using Test Script
```bash
php scripts/test_document_generation.php 1 commercial_tax_invoice
```

#### Option B: Using API
```bash
curl -X POST https://oms.jldminerals.com/api/documents/generate \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=your_session_id" \
  -d '{
    "order_id": 1,
    "document_type": "commercial_tax_invoice"
  }'
```

---

### Step 5: Add UI (Optional)

Add a "Generate Documents" button in the order detail page that:
- Shows available document types
- Allows selecting which documents to generate
- Downloads generated files

---

## 📋 Template Analysis Checklist

For each template, identify:

### Excel Templates:
- [ ] Order number cell
- [ ] Order date cell
- [ ] Party name cell
- [ ] Party address cell
- [ ] Company name cell
- [ ] Company GST number cell
- [ ] Product name cell
- [ ] Repeating row start (for truck data)
- [ ] Column mappings for truck data

### Word Templates:
- [ ] All placeholders (search for `{` or `[`)
- [ ] Table structure (if any)
- [ ] Table row to duplicate
- [ ] Column mappings for table

---

## 🎯 Quick Start

1. **Install dependencies**: `composer install`
2. **Run migration**: See Step 2 above
3. **Pick one template** (start with Commercial Tax Invoice)
4. **Open it** and identify cell references/placeholders
5. **Update mapping file** in `config/document_mappings/commercial_tax_invoice.php`
6. **Test generation**: `php scripts/test_document_generation.php 1 commercial_tax_invoice`
7. **Verify output** in `storage/documents/`
8. **Repeat** for other templates

---

## 📚 Documentation

- **Architecture**: `docs/DOCUMENT_GENERATION_SYSTEM.md`
- **Mapping Guide**: `docs/TEMPLATE_MAPPING_GUIDE.md`
- **API Endpoints**: See `src/Controllers/DocumentController.php`

---

## ⚠️ Important Notes

1. **Template files must remain unchanged** - Only data cells are modified
2. **Formatting is preserved** - Styles, fonts, merged cells stay intact
3. **Mapping files are placeholders** - You must complete them by analyzing templates
4. **Test with sample data** before using in production
