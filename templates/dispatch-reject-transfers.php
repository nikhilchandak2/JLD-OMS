<?php include __DIR__ . '/partials/dispatch-nav.php'; ?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <i class="bi bi-arrow-left-right me-2"></i>Rejected &amp; Transferred Trucks
            </h1>
            <p class="page-subtitle" id="rejectTransfersSubtitle">
                Trucks rejected by parties, transferred to other orders, or sent for replacement
            </p>
        </div>
        <button class="btn btn-primary" onclick="loadRejectTransfers()">
            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
        </button>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="filterStartDate" class="form-label">From</label>
                <input type="date" class="form-control" id="filterStartDate" value="<?= date('Y-m-d', strtotime('-90 days')) ?>">
            </div>
            <div class="col-md-3">
                <label for="filterEndDate" class="form-label">To</label>
                <input type="date" class="form-control" id="filterEndDate" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
                <label for="filterActionType" class="form-label">Action</label>
                <select class="form-select" id="filterActionType">
                    <option value="">All actions</option>
                    <option value="transfer">Transferred to another party</option>
                    <option value="replacement">Rejected — replacement truck</option>
                    <option value="credit_note">Rejected — credit note only</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-primary w-100" onclick="loadRejectTransfers(true)">
                    <i class="bi bi-funnel me-1"></i> Apply Filters
                </button>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2"></i>Rejection / Transfer Records</span>
        <small class="text-muted" id="rejectTransfersPaginationInfo">—</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="rejectTransfersTable">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Action</th>
                        <th>From Party / Order</th>
                        <th>To Party / Order</th>
                        <th>Trucks</th>
                        <th>Weight (MT)</th>
                        <th>Source Invoice</th>
                        <th>New Invoice</th>
                        <th>Credit Note</th>
                        <th>Reason</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="11" class="text-center text-muted p-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <button class="btn btn-sm btn-outline-secondary" id="rejectTransfersPrevBtn" onclick="changeRejectTransfersPage(-1)" disabled>Previous</button>
        <span class="small text-muted" id="rejectTransfersPageLabel">Page 1</span>
        <button class="btn btn-sm btn-outline-secondary" id="rejectTransfersNextBtn" onclick="changeRejectTransfersPage(1)" disabled>Next</button>
    </div>
</div>

<script>
const rejectTransfersPageSize = 25;
let rejectTransfersPage = 0;
let rejectTransfersTotal = 0;
let rejectTransfersRows = [];
const filterOrderId = new URLSearchParams(window.location.search).get('order_id');

function formatActionType(action) {
    const map = {
        transfer: '<span class="badge bg-info text-dark">Transferred</span>',
        replacement: '<span class="badge bg-warning text-dark">Replacement</span>',
        credit_note: '<span class="badge bg-danger">Credit note only</span>',
    };
    return map[action] || escapeHtml(action || '—');
}

function formatWeightTons(value) {
    if (value == null || value === '') return '—';
    return Number(value).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}

function formatCreditNote(row) {
    if (!row.credit_note_no && row.credit_amount == null) {
        return '<span class="text-muted">—</span>';
    }
    const parts = [];
    if (row.credit_note_no) {
        parts.push(escapeHtml(row.credit_note_no));
    } else {
        parts.push('<span class="text-muted">No CN no.</span>');
    }
    if (row.credit_amount != null) {
        parts.push('₹' + Number(row.credit_amount).toLocaleString('en-IN', { minimumFractionDigits: 2 }));
    }
    return parts.join('<br>');
}

function buildRejectTransfersParams() {
    const params = new URLSearchParams();
    params.set('limit', String(rejectTransfersPageSize));
    params.set('offset', String(rejectTransfersPage * rejectTransfersPageSize));
    const start = document.getElementById('filterStartDate').value;
    const end = document.getElementById('filterEndDate').value;
    const actionType = document.getElementById('filterActionType').value;
    if (start) params.set('start_date', start);
    if (end) params.set('end_date', end);
    if (actionType) params.set('action_type', actionType);
    if (filterOrderId) params.set('order_id', filterOrderId);
    return params;
}

async function loadRejectTransfers(resetPage = false) {
    if (resetPage) rejectTransfersPage = 0;
    const tbody = document.querySelector('#rejectTransfersTable tbody');
    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted p-4">Loading…</td></tr>';

    try {
        const response = await apiCall(`/api/dispatch-transfers?${buildRejectTransfersParams().toString()}`);
        rejectTransfersRows = response.data || [];
        rejectTransfersTotal = response.pagination?.total ?? rejectTransfersRows.length;
        renderRejectTransfersTable();
        updateRejectTransfersPagination();
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="11" class="text-center text-danger p-4">${escapeHtml(error.message)}</td></tr>`;
    }
}

function renderRejectTransfersTable() {
    const tbody = document.querySelector('#rejectTransfersTable tbody');
    if (!rejectTransfersRows.length) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted p-4">No rejected or transferred trucks found for the selected filters.</td></tr>';
        return;
    }

    tbody.innerHTML = rejectTransfersRows.map(row => {
        const fromCell = `
            <div>${escapeHtml(row.source_party_name || '—')}</div>
            <a href="/orders/${row.source_order_id}" class="small"><strong>${escapeHtml(row.source_order_no || '')}</strong></a>
        `;
        const toCell = row.action_type === 'transfer' && row.target_order_id
            ? `<div>${escapeHtml(row.target_party_name || '—')}</div>
               <a href="/orders/${row.target_order_id}" class="small"><strong>${escapeHtml(row.target_order_no || '')}</strong></a>`
            : '<span class="text-muted">—</span>';

        return `<tr>
            <td>${formatDate(row.event_date)}</td>
            <td>${formatActionType(row.action_type)}</td>
            <td>${fromCell}</td>
            <td>${toCell}</td>
            <td>${row.trucks_transferred}</td>
            <td>${row.weight_tons != null ? formatWeightTons(row.weight_tons) : '—'}</td>
            <td>${row.source_invoice_no ? escapeHtml(row.source_invoice_no) : '—'}</td>
            <td>${row.target_invoice_no ? escapeHtml(row.target_invoice_no) : '—'}</td>
            <td>${formatCreditNote(row)}</td>
            <td>${escapeHtml(row.reason || '—')}</td>
            <td>${escapeHtml(row.created_by_name || '—')}</td>
        </tr>`;
    }).join('');
}

function updateRejectTransfersPagination() {
    const totalPages = Math.max(1, Math.ceil(rejectTransfersTotal / rejectTransfersPageSize));
    const from = rejectTransfersTotal === 0 ? 0 : rejectTransfersPage * rejectTransfersPageSize + 1;
    const to = Math.min(rejectTransfersTotal, (rejectTransfersPage + 1) * rejectTransfersPageSize);
    document.getElementById('rejectTransfersPaginationInfo').textContent =
        rejectTransfersTotal ? `Showing ${from}–${to} of ${rejectTransfersTotal}` : 'No records';
    document.getElementById('rejectTransfersPageLabel').textContent = `Page ${rejectTransfersPage + 1} of ${totalPages}`;
    document.getElementById('rejectTransfersPrevBtn').disabled = rejectTransfersPage <= 0;
    document.getElementById('rejectTransfersNextBtn').disabled = (rejectTransfersPage + 1) >= totalPages;
}

function changeRejectTransfersPage(delta) {
    rejectTransfersPage = Math.max(0, rejectTransfersPage + delta);
    loadRejectTransfers();
}

document.addEventListener('DOMContentLoaded', function() {
    if (filterOrderId) {
        const subtitle = document.getElementById('rejectTransfersSubtitle');
        if (subtitle) {
            subtitle.textContent = `Filtered to order #${filterOrderId}. `;
            const link = document.createElement('a');
            link.href = '/dispatch/reject-transfers';
            link.textContent = 'Show all records';
            subtitle.appendChild(link);
        }
    }
    loadRejectTransfers();
});
</script>
