<!-- Import products from CSV -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/admin/products">Products</a></li>
                    <li class="breadcrumb-item active">Import</li>
                </ol>
            </nav>
            <h1 class="page-title mt-2"><i class="bi bi-upload me-2"></i>Import products</h1>
            <p class="page-subtitle mb-0">Upload a CSV with <strong>Product Name</strong> and <strong>HSN Code</strong></p>
        </div>
        <a href="/admin/products" class="btn btn-outline-secondary">Back to products</a>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="alert alert-success" style="display:none;"></div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Upload CSV</div>
    <div class="card-body">
        <ul class="small text-muted mb-3">
            <li><strong>Product Name</strong> (or Product, Name, Item) – product name</li>
            <li><strong>HSN Code</strong> (or HSN, HSN No) – tax classification code</li>
        </ul>
        <form id="importForm">
            <div class="mb-3">
                <label class="form-label">Select CSV file</label>
                <input type="file" class="form-control" id="csvFile" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary" id="btnImport" disabled>
                <i class="bi bi-upload me-1"></i> Import
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
            <a href="/admin/products" class="btn btn-outline-primary btn-sm">View all products</a>
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
        const response = await fetch('/api/products/import', {
            method: 'POST',
            headers: { 'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : '' },
            body: formData
        });
        const data = await response.json();

        if (!response.ok || data.success === false) {
            document.getElementById('resultCard').style.display = 'block';
            document.getElementById('resultSummary').innerHTML = `
                <p class="mb-1"><strong>Created:</strong> ${data.created ?? 0}</p>
                <p class="mb-1"><strong>Updated:</strong> ${data.updated ?? 0}</p>
                <p class="mb-1"><strong>Skipped:</strong> ${data.skipped ?? 0}</p>
            `;
            const errEl = document.getElementById('resultErrors');
            const errs = data.errors && data.errors.length ? data.errors : [data.error || 'Import failed'];
            errEl.innerHTML = '<strong>Errors:</strong><ul class="mb-0">' + errs.map(function(x) {
                const d = document.createElement('div'); d.textContent = x; return '<li>' + d.innerHTML + '</li>';
            }).join('') + '</ul>';
            if (data.columns && data.columns.headers) {
                errEl.innerHTML += '<p class="mt-2 mb-0"><strong>Detected columns:</strong> ' +
                    data.columns.headers.map(function(h) { return h || '(blank)'; }).join(', ') + '</p>';
            }
            document.getElementById('btnImport').disabled = false;
            return;
        }

        document.getElementById('resultCard').style.display = 'block';
        document.getElementById('resultSummary').innerHTML = `
            <p class="mb-1"><strong>Created:</strong> ${data.created ?? 0}</p>
            <p class="mb-1"><strong>Updated:</strong> ${data.updated ?? 0}</p>
            <p class="mb-1"><strong>Skipped:</strong> ${data.skipped ?? 0}</p>
        `;
        const previewEl = document.getElementById('resultPreview');
        if (data.preview && data.preview.length) {
            previewEl.innerHTML = '<strong>Sample:</strong><table class="table table-sm mt-1"><thead><tr><th>Product</th><th>HSN</th><th>Action</th></tr></thead><tbody>' +
                data.preview.map(function(r) {
                    const d = document.createElement('div');
                    d.textContent = r.name; const name = d.innerHTML;
                    d.textContent = r.hsn_code || ''; const hsn = d.innerHTML;
                    return '<tr><td>' + name + '</td><td>' + hsn + '</td><td>' + (r.action || '') + '</td></tr>';
                }).join('') + '</tbody></table>';
        } else {
            previewEl.innerHTML = '';
        }
        document.getElementById('success-container').style.display = 'block';
        const total = (data.created ?? 0) + (data.updated ?? 0);
        document.getElementById('success-container').textContent =
            total > 0 ? ('Import completed. ' + total + ' product(s) saved.') : 'Import completed (no changes).';
        document.getElementById('btnImport').disabled = false;
        fileInput.value = '';
    } catch (err) {
        showError(err.message || 'Upload failed');
        document.getElementById('btnImport').disabled = false;
    }
});
</script>
