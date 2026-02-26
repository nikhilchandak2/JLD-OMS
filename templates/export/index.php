<!-- Export Documents (Nepal) – standalone module. Not linked to OMS orders, tracking, or admin. -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i>Nepal Export Documents
            </h1>
            <p class="page-subtitle">Generate Commercial Invoice, Tax Invoice, Packing List and related docs for Nepal export (LUT). Separate from Orders &amp; Vehicle Tracking.</p>
        </div>
    </div>
</div>

<div class="alert alert-info border-0 mb-4">
    <strong><i class="bi bi-info-circle me-2"></i>Separate system</strong><br>
    This section is only for <strong>Nepal export documentation</strong>. It does not use OMS Orders, Dispatches, or Vehicle Tracking. You maintain export orders and dispatch details here; one click generates all required documents in a single Excel file.
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Export orders</div>
            <div class="card-body">
                <p class="text-muted mb-3">Export orders hold fixed data per Nepal order: consignee, LC details, terms, product description. Each dispatch (trucks, LR no., weight, amount) uses that order and produces one document pack.</p>
                <div id="export-orders-list" class="mb-3">
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-inbox fs-1"></i>
                        <p class="mt-2 mb-0">Loading export orders…</p>
                    </div>
                </div>
                <p class="small text-muted mb-0">Export order list and “Generate dispatch pack” will be wired here. API: <code>GET /api/export/orders</code>, <code>POST /api/export/dispatch-pack</code>.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Generate dispatch pack</div>
            <div class="card-body">
                <p class="text-muted small">Select an export order and enter this dispatch’s details (trucks, LR no., weight, amount). One click generates Commercial Invoice, Tax Invoice, Packing List in one Excel file.</p>
                <button type="button" class="btn btn-outline-primary w-100" disabled title="Will open form when export orders exist">
                    <i class="bi bi-file-earmark-zip me-1"></i> Generate documents (coming next)
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const listEl = document.getElementById('export-orders-list');
    if (!listEl) return;

    fetch('/api/export/orders', {
        headers: { 'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : '' }
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                listEl.innerHTML = '<ul class="list-group list-group-flush">' +
                    data.data.map(function(o) {
                        return '<li class="list-group-item d-flex justify-content-between align-items-center">' +
                            '<span>' + (o.reference_no || o.buyer_po_no || 'Export #' + o.id) + '</span>' +
                            '<span class="badge bg-secondary">' + (o.consignee || '—') + '</span></li>';
                    }).join('') + '</ul>';
            } else {
                listEl.innerHTML = '<p class="text-muted mb-0">No export orders yet. Create one via API or add a form here.</p>';
            }
        })
        .catch(function() {
            listEl.innerHTML = '<p class="text-muted mb-0">Could not load export orders. Ensure export module and migrations are set up.</p>';
        });
})();
</script>
