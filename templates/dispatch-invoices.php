<?php require __DIR__ . '/partials/dispatch-nav.php'; ?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <i class="bi bi-list-ul me-2"></i>All Busy Invoices
            </h1>
            <p class="page-subtitle">Individual invoice list showing all invoices from all uploads</p>
        </div>
        <div class="d-flex gap-2">
            <a href="/dispatch" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Upload new file
            </a>
            <a href="/dispatch/daily" class="btn btn-outline-secondary">
                <i class="bi bi-calendar3 me-1"></i> Daily ledger
            </a>
            <a href="/dispatch/uploads" class="btn btn-outline-info">
                <i class="bi bi-cloud-upload me-1"></i> Upload history
            </a>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-funnel me-2"></i>Filters
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="filterStartDate" class="form-label">Invoice date from</label>
                <input type="date" class="form-control" id="filterStartDate">
            </div>
            <div class="col-md-3">
                <label for="filterEndDate" class="form-label">Invoice date to</label>
                <input type="date" class="form-control" id="filterEndDate">
            </div>
            <div class="col-md-3">
                <label for="filterMapping" class="form-label">Mapping status</label>
                <select class="form-select" id="filterMapping">
                    <option value="">All</option>
                    <option value="mapped">Mapped to order</option>
                    <option value="unmapped">Not mapped to any order</option>
                    <option value="error">Import errors</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="filterSearch" class="form-label">Search</label>
                <input type="text" class="form-control" id="filterSearch" placeholder="Invoice, party, product, order…">
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-primary w-100" onclick="loadInvoices(0)">
                    <i class="bi bi-search"></i> Filter
                </button>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <div class="text-muted small" id="invoicesSummary">—</div>
    <div class="d-flex align-items-center gap-2">
        <label for="pageSize" class="form-label mb-0 small text-muted">Show</label>
        <select class="form-select form-select-sm" id="pageSize" style="width: auto;">
            <option value="50">50</option>
            <option value="100" selected>100</option>
            <option value="200">200</option>
            <option value="500">500</option>
        </select>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped align-middle" id="invoicesTable">
                <thead>
                    <tr>
                        <th>Invoice date</th>
                        <th>Invoice no</th>
                        <th>Party</th>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Vehicle</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Upload</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="9" class="text-center text-muted">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <nav>
            <ul class="pagination justify-content-center" id="pagination"></ul>
        </nav>
    </div>
</div>

<script>
let invoicePage = 0;

function pageSize() {
    const el = document.getElementById('pageSize');
    const n = el ? parseInt(el.value, 10) : 100;
    return Number.isFinite(n) && n > 0 ? n : 100;
}

function statusBadge(status) {
    const map = {
        mapped: 'success',
        unmapped: 'warning',
        error: 'danger',
    };
    const cls = map[status] || 'secondary';
    return `<span class="badge bg-${cls}">${escapeHtml(status || '—')}</span>`;
}

function formatMappingCell(row) {
    if (row.mapping_status === 'mapped' && row.order_id) {
        return `<a href="/orders/${row.order_id}"><strong>${escapeHtml(row.order_no || ('#' + row.order_id))}</strong></a>`;
    }
    if (row.mapping_status === 'error') {
        return `<span class="badge bg-danger">Import error</span>`;
    }
    return `<span class="badge bg-warning text-dark">Unmapped</span>`;
}

function formatUploadCell(row) {
    if (row.upload_id) {
        return `<a href="/dispatch/uploads" class="small text-muted">Upload #${row.upload_id}</a>`;
    }
    return `<span class="small text-muted">Legacy</span>`;
}

async function loadInvoices(page = 0) {
    if (page === 0) invoicePage = 0;
    const startDate = document.getElementById('filterStartDate').value;
    const endDate = document.getElementById('filterEndDate').value;
    const mapping = document.getElementById('filterMapping').value;
    const search = document.getElementById('filterSearch').value.trim();
    const tbody = document.querySelector('#invoicesTable tbody');
    tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted p-4">Loading…</td></tr>';
    showError('');

    try {
        const params = new URLSearchParams({
            limit: String(pageSize()),
            offset: String(page * pageSize()),
        });
        if (startDate) params.set('start_date', startDate);
        if (endDate) params.set('end_date', endDate);
        if (mapping) params.set('mapping_status', mapping);
        if (search) params.set('search', search);

        const response = await apiCall(`/api/busy/daily-invoices?${params.toString()}`);
        const rows = response.data || [];
        const pagination = response.pagination || { total: 0, limit: pageSize(), offset: 0 };
        invoicePage = page;

        const total = Number(pagination.total) || 0;
        const from = total === 0 ? 0 : (pagination.offset || 0) + 1;
        const to = Math.min((pagination.offset || 0) + rows.length, total);
        document.getElementById('invoicesSummary').textContent =
            total === 0 ? 'No invoices' : `Showing ${from}–${to} of ${total} invoices`;

        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted p-4">No invoices found for this filter</td></tr>';
            document.getElementById('pagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = rows.map((row) => `
            <tr>
                <td class="text-nowrap">${escapeHtml(row.invoice_date || '—')}</td>
                <td class="text-nowrap fw-semibold">${escapeHtml(row.invoice_no || '—')}</td>
                <td>${escapeHtml(row.party_name || '—')}</td>
                <td>${escapeHtml(row.product_name || '—')}</td>
                <td class="text-end">${row.quantity_trucks || 0}</td>
                <td class="text-nowrap">${escapeHtml(row.vehicle_no || '—')}</td>
                <td>${formatMappingCell(row)}</td>
                <td>${statusBadge(row.mapping_status)}</td>
                <td>${formatUploadCell(row)}</td>
            </tr>
        `).join('');

        renderPagination(pagination);
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger p-4">${escapeHtml(error.message || 'Failed to load')}</td></tr>`;
        document.getElementById('invoicesSummary').textContent = '—';
        showError(error.message || 'Failed to load invoices');
    }
}

function renderPagination(pagination) {
    const el = document.getElementById('pagination');
    if (!pagination || pagination.total <= pagination.limit) {
        el.innerHTML = '';
        return;
    }
    const totalPages = Math.ceil(pagination.total / pagination.limit);
    const current = Math.floor(pagination.offset / pagination.limit);
    
    let html = '';
    // Previous button
    html += `<li class="page-item ${current === 0 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="loadInvoices(${current - 1}); return false;">Previous</a>
    </li>`;
    
    // Page numbers (show max 5)
    const startPage = Math.max(0, current - 2);
    const endPage = Math.min(totalPages - 1, current + 2);
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<li class="page-item ${i === current ? 'active' : ''}">
            <a class="page-link" href="#" onclick="loadInvoices(${i}); return false;">${i + 1}</a>
        </li>`;
    }
    
    // Next button
    html += `<li class="page-item ${current >= totalPages - 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="loadInvoices(${current + 1}); return false;">Next</a>
    </li>`;
    
    el.innerHTML = html;
}

// Load on page load
document.addEventListener('DOMContentLoaded', () => {
    loadInvoices(0);
});
</script>
