<!-- Import Busy bills (receivables) from CSV -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/admin/parties">Administration</a></li>
                    <li class="breadcrumb-item active">Import bills (Busy)</li>
                </ol>
            </nav>
            <h1 class="page-title mt-2"><i class="bi bi-upload me-2"></i>Import bills (Busy)</h1>
            <p class="page-subtitle mb-0">Upload Busy export CSV. System will add new invoices and update existing invoices by (Party + Bill/Invoice No).</p>
        </div>
        <a href="/admin/parties" class="btn btn-outline-secondary">Back to Administration</a>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="alert alert-success" style="display:none;"></div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Upload CSV</div>
    <div class="card-body">
        <p class="text-muted">Your CSV should have a <strong>header row</strong>. We auto-detect columns by name.</p>
        <ul class="small text-muted mb-3">
            <li><strong>Party name</strong> – column named "Party Name", "Customer", "Name", "Party", etc.</li>
            <li><strong>Amount due</strong> – column named "Amount", "Due", "Balance", "Outstanding", "Amount Due", etc.</li>
            <li>Optional: <strong>Bill/Invoice No</strong> ("Invoice No", "Bill No", "Reference"), <strong>Date</strong> ("Date", "Invoice Date", "Bill Date")</li>
        </ul>
        <form id="importForm">
            <div class="mb-3">
                <label class="form-label">Select Busy CSV file</label>
                <input type="file" class="form-control" id="csvFile" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary" id="btnImport" disabled>
                <i class="bi bi-upload me-1"></i> Import / Update
            </button>
        </form>
    </div>
</div>

<div class="card" id="resultCard" style="display:none;">
    <div class="card-header"><i class="bi bi-check2-circle me-2"></i>Import result</div>
    <div class="card-body">
        <div id="resultSummary"></div>
        <div id="resultErrors" class="mt-3 text-danger small"></div>
        <div id="resultPreview" class="mt-3"></div>
        <div class="mt-3">
            <a href="/crm" class="btn btn-outline-primary btn-sm">View CRM dashboard</a>
            <a href="/admin/credit-approvals" class="btn btn-outline-primary btn-sm">Credit approvals</a>
        </div>
    </div>
</div>

<script>
document.getElementById('csvFile').addEventListener('change', function() {
    document.getElementById('btnImport').disabled = !this.files.length;
});

document.getElementById('importForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fileInput = document.getElementById('csvFile');
    if (!fileInput.files || !fileInput.files[0]) {
        showError('Please select a CSV file');
        return;
    }
    showError('');
    document.getElementById('success-container').style.display = 'none';
    document.getElementById('resultCard').style.display = 'none';
    document.getElementById('btnImport').disabled = true;

    const formData = new FormData();
    formData.append('file', fileInput.files[0]);

    try {
        const response = await fetch('/api/crm/receivables/import', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData
        });
        const data = await response.json();

        if (!response.ok) {
            showError(data.error || 'Import failed');
            document.getElementById('btnImport').disabled = false;
            return;
        }

        document.getElementById('resultCard').style.display = 'block';
        document.getElementById('resultSummary').innerHTML = `
            <p class="mb-1"><strong>Parties created:</strong> ${data.parties_created ?? 0}</p>
            <p class="mb-1"><strong>Parties matched:</strong> ${data.parties_matched ?? 0}</p>
            <p class="mb-1"><strong>Invoices added:</strong> ${data.invoices_added ?? 0}</p>
            <p class="mb-1"><strong>Invoices updated:</strong> ${data.invoices_updated ?? 0}</p>
        `;
        const errEl = document.getElementById('resultErrors');
        if (data.errors && data.errors.length) {
            errEl.innerHTML = '<strong>Warnings:</strong><ul class="mb-0">' + data.errors.map(e => '<li>' + escapeHtml(e) + '</li>').join('') + '</ul>';
        } else {
            errEl.innerHTML = '';
        }
        const previewEl = document.getElementById('resultPreview');
        if (data.preview && data.preview.length) {
            previewEl.innerHTML = '<strong>Sample rows:</strong><table class="table table-sm mt-1"><thead><tr><th>Party</th><th>Amount</th><th>Reference</th><th>Date</th></tr></thead><tbody>' +
                data.preview.map(r => '<tr><td>' + escapeHtml(r.party_name) + '</td><td>₹' + Number(r.amount).toLocaleString() + '</td><td>' + escapeHtml(r.reference || '') + '</td><td>' + escapeHtml(r.date || '') + '</td></tr>').join('') + '</tbody></table>';
        } else {
            previewEl.innerHTML = '';
        }

        document.getElementById('success-container').style.display = 'block';
        document.getElementById('success-container').textContent = 'Import completed. Busy bills are updated in CRM receivables.';
        document.getElementById('btnImport').disabled = false;
        fileInput.value = '';
    } catch (err) {
        showError(err.message || 'Upload failed');
        document.getElementById('btnImport').disabled = false;
    }
});

function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>

