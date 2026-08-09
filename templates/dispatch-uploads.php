<?php require __DIR__ . '/partials/dispatch-nav.php'; ?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <i class="bi bi-cloud-upload me-2"></i>Busy Invoice Uploads
            </h1>
            <p class="page-subtitle">History of CSV / PDF invoice files uploaded from Busy</p>
        </div>
        <div class="d-flex gap-2">
            <a href="/dispatch" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Upload new file
            </a>
            <a href="/dispatch/daily" class="btn btn-outline-secondary">
                <i class="bi bi-calendar3 me-1"></i> Daily ledger
            </a>
            <a href="/dispatch/invoices" class="btn btn-outline-info">
                <i class="bi bi-list-ul me-1"></i> All invoices
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
            <div class="col-md-4">
                <label for="filterQ" class="form-label">Filename</label>
                <input type="search" class="form-control" id="filterQ" placeholder="Search filename…">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-primary w-100" onclick="loadUploads(0)">
                    <i class="bi bi-search"></i> Filter
                </button>
            </div>
        </div>
        <p class="text-muted small mt-3 mb-0">
            Filter by <strong>invoice date</strong> (e.g. 1 Jul 2026), not the day the file was uploaded.
            New uploads keep the CSV/PDF for download. Older batches show as
            <span class="badge bg-secondary">legacy</span> (file not retained).
        </p>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <div class="text-muted small" id="uploadsSummary">—</div>
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
            <table class="table table-striped align-middle" id="uploadsTable">
                <thead>
                    <tr>
                        <th>Uploaded</th>
                        <th>Invoice dates</th>
                        <th>File</th>
                        <th>Type</th>
                        <th>By</th>
                        <th class="text-end">Invoices</th>
                        <th class="text-end">Mapped</th>
                        <th class="text-end">Unmapped</th>
                        <th class="text-end">Failed</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="11" class="text-center text-muted">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <nav>
            <ul class="pagination justify-content-center" id="pagination"></ul>
        </nav>
    </div>
</div>

<script>
let uploadPage = 0;

function pageSize() {
    const el = document.getElementById('pageSize');
    const n = el ? parseInt(el.value, 10) : 100;
    return Number.isFinite(n) && n > 0 ? n : 100;
}

function statusBadge(status) {
    const map = {
        processed: 'success',
        partial: 'warning',
        failed: 'danger',
        legacy: 'secondary',
    };
    const cls = map[status] || 'secondary';
    return `<span class="badge bg-${cls}">${escapeHtml(status || '—')}</span>`;
}

function formatBytes(n) {
    if (n == null || n === '') return '';
    const v = Number(n);
    if (!Number.isFinite(v) || v <= 0) return '';
    if (v < 1024) return v + ' B';
    if (v < 1024 * 1024) return (v / 1024).toFixed(1) + ' KB';
    return (v / (1024 * 1024)).toFixed(1) + ' MB';
}

async function loadUploads(page = 0) {
    const tbody = document.querySelector('#uploadsTable tbody');
    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">Loading…</td></tr>';
    showError('');

    try {
        const size = pageSize();
        const params = new URLSearchParams({
            limit: String(size),
            offset: String(page * size),
        });
        const start = document.getElementById('filterStartDate').value;
        const end = document.getElementById('filterEndDate').value;
        const q = document.getElementById('filterQ').value.trim();
        if (start) params.set('start_date', start);
        if (end) params.set('end_date', end);
        if (q) params.set('q', q);

        const response = await apiCall('/api/busy/invoice-uploads?' + params.toString());
        const rows = response.data || [];
        const pagination = response.pagination || { total: 0, limit: size, offset: 0 };
        uploadPage = page;

        const total = Number(pagination.total) || 0;
        const from = total === 0 ? 0 : (pagination.offset || 0) + 1;
        const to = Math.min((pagination.offset || 0) + rows.length, total);
        document.getElementById('uploadsSummary').textContent =
            total === 0 ? 'No uploads' : `Showing ${from}–${to} of ${total} uploads`;

        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">No uploads found for this filter</td></tr>';
            document.getElementById('pagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = rows.map((row) => {
            const sizeHint = formatBytes(row.file_size);
            const fileCell = `
                <div class="fw-semibold">${escapeHtml(row.original_filename || '—')}</div>
                ${sizeHint ? `<div class="small text-muted">${escapeHtml(sizeHint)}</div>` : ''}
                ${row.parse_notes ? `<div class="small text-muted">${escapeHtml(String(row.parse_notes).slice(0, 120))}</div>` : ''}
            `;
            const downloadBtn = row.has_file
                ? `<a class="btn btn-sm btn-outline-primary" href="/api/busy/invoice-uploads/${row.id}/download">
                        <i class="bi bi-download"></i> Download
                   </a>`
                : `<span class="text-muted small">No file</span>`;
            const invDates = row.invoice_date_from
                ? (row.invoice_date_label || `${row.invoice_date_from} – ${row.invoice_date_to || row.invoice_date_from}`)
                : '—';
            
            // Add invoice count and date count for better context
            let dateInfo = '';
            if (row.invoice_date_from) {
                const invoiceCount = row.invoice_count || 0;
                // Use date_count if available, otherwise estimate from date range
                let dateCount = 1;
                if (typeof row.date_count === 'number' && row.date_count > 0) {
                    dateCount = row.date_count;
                } else if (row.invoice_date_to && row.invoice_date_from !== row.invoice_date_to) {
                    dateCount = 2; // At least 2 different dates if from != to
                }
                
                if (dateCount > 1) {
                    dateInfo = `<span class="small text-muted">(${invoiceCount} invoices, ${dateCount} different dates)</span>`;
                } else {
                    dateInfo = `<span class="small text-muted">(${invoiceCount} invoice${invoiceCount !== 1 ? 's' : ''})</span>`;
                }
            }
            const dailyLink = row.invoice_date_from
                ? `/dispatch/daily?start_date=${encodeURIComponent(row.invoice_date_from)}&end_date=${encodeURIComponent(row.invoice_date_to || row.invoice_date_from)}`
                : `/dispatch/daily?all_dates=1`;
            return `
                <tr>
                    <td class="text-nowrap">${escapeHtml(row.created_at || '—')}</td>
                    <td class="text-nowrap fw-semibold">${escapeHtml(invDates)}${dateInfo}</td>
                    <td>${fileCell}</td>
                    <td><span class="badge bg-light text-dark text-uppercase">${escapeHtml(row.file_type || '—')}</span></td>
                    <td>${escapeHtml(row.uploaded_by_name || '—')}</td>
                    <td class="text-end">${row.invoice_count ?? 0}</td>
                    <td class="text-end text-success">${row.mapped_count ?? 0}</td>
                    <td class="text-end text-warning">${row.unmapped_count ?? 0}</td>
                    <td class="text-end text-danger">${row.failed_count ?? 0}</td>
                    <td>${statusBadge(row.status)}</td>
                    <td class="text-nowrap">
                        ${downloadBtn}
                        <a class="btn btn-sm btn-outline-secondary ms-1" href="${dailyLink}" title="Open daily ledger">
                            <i class="bi bi-table"></i>
                        </a>
                    </td>
                </tr>
            `;
        }).join('');

        renderPagination(pagination);
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="11" class="text-center text-danger">${escapeHtml(error.message || 'Failed to load')}</td></tr>`;
        document.getElementById('uploadsSummary').textContent = '—';
        showError(error.message || 'Failed to load uploads');
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
    if (current > 0) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="loadUploads(${current - 1}); return false;">Previous</a></li>`;
    }
    const start = Math.max(0, current - 2);
    const end = Math.min(totalPages - 1, current + 2);
    for (let i = start; i <= end; i++) {
        html += `<li class="page-item ${i === current ? 'active' : ''}">
            <a class="page-link" href="#" onclick="loadUploads(${i}); return false;">${i + 1}</a>
        </li>`;
    }
    if (current < totalPages - 1) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="loadUploads(${current + 1}); return false;">Next</a></li>`;
    }
    el.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('filterQ').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            loadUploads(0);
        }
    });
    document.getElementById('pageSize').addEventListener('change', () => loadUploads(0));
    loadUploads(0);
});
</script>
