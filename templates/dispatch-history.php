<?php include __DIR__ . '/partials/dispatch-nav.php'; ?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <i class="bi bi-clock-history me-2"></i>Dispatch History
            </h1>
            <p class="page-subtitle" id="historySubtitle">All dispatches for the active company — including Busy invoice imports</p>
        </div>
        <button class="btn btn-primary" onclick="loadDispatchHistory()">
            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
        </button>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

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
                <label for="filterStatus" class="form-label">Status</label>
                <select class="form-select" id="filterStatus">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="rejected">Rejected</option>
                    <option value="transferred">Transferred</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-primary w-100" onclick="loadDispatchHistory(true)">
                    <i class="bi bi-funnel me-1"></i> Apply Filters
                </button>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2"></i>Dispatch Records</span>
        <small class="text-muted" id="historyPaginationInfo">—</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="dispatchHistoryTable">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Order</th>
                        <th>Party</th>
                        <th>Status</th>
                        <th>Trucks</th>
                        <th>Rate (₹/MT)</th>
                        <th>Weight (MT)</th>
                        <th>Transport Doc</th>
                        <th>Busy Invoice</th>
                        <th>By</th>
                        <?php if (in_array($user['role'], ['entry', 'order_processing', 'admin', 'dispatch'])): ?>
                        <th class="text-end">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="<?= in_array($user['role'], ['entry', 'order_processing', 'admin', 'dispatch']) ? 11 : 10 ?>" class="text-center text-muted p-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <button class="btn btn-sm btn-outline-secondary" id="historyPrevBtn" onclick="changeHistoryPage(-1)" disabled>Previous</button>
        <span class="small text-muted" id="historyPageLabel">Page 1</span>
        <button class="btn btn-sm btn-outline-secondary" id="historyNextBtn" onclick="changeHistoryPage(1)" disabled>Next</button>
    </div>
</div>

<?php if (in_array($user['role'], ['entry', 'order_processing', 'admin', 'dispatch'])): ?>
<div class="modal fade" id="rejectTransferModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>Reject / Transfer Truck</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="rejectTransferForm">
                <div class="modal-body">
                    <p class="text-muted small" id="rejectTransferInfo"></p>
                    <div class="alert alert-info py-2 small">
                        When a party rejects a truck, issue a <strong>credit note</strong> and either
                        <strong>transfer the truck</strong> to another party or
                        <strong>send a replacement truck</strong> later.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">What happened? <span class="text-danger">*</span></label>
                        <select class="form-select" id="rejectAction" name="action" required>
                            <option value="transfer">Transfer truck to another party</option>
                            <option value="replacement">Reject — send replacement truck (same party)</option>
                            <option value="credit_note">Reject — credit note only</option>
                        </select>
                    </div>
                    <div class="mb-3" id="targetOrderGroup">
                        <label for="targetOrderId" class="form-label">Transfer to order <span class="text-danger">*</span></label>
                        <select class="form-select" id="targetOrderId" name="target_order_id"></select>
                    </div>
                    <div class="mb-3">
                        <label for="rejectReason" class="form-label">Reason</label>
                        <textarea class="form-control" id="rejectReason" name="reason" rows="2"></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="issueCreditNote" name="issue_credit_note" checked>
                        <label class="form-check-label" for="issueCreditNote">Issue credit note to rejecting party</label>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="creditNoteNo" class="form-label">Busy credit note no. (optional)</label>
                            <input type="text" class="form-control" id="creditNoteNo" name="credit_note_no">
                        </div>
                        <div class="col-md-6">
                            <label for="creditAmount" class="form-label">Credit amount (₹)</label>
                            <input type="number" class="form-control" id="creditAmount" name="credit_amount" step="0.01" min="0.01">
                        </div>
                    </div>
                    <input type="hidden" id="rejectDispatchId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning" id="rejectTransferBtn">
                        <span class="spinner-border spinner-border-sm d-none" id="rejectTransferSpinner"></span>
                        Confirm
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const canEditDispatch = <?= in_array($user['role'], ['entry', 'order_processing', 'admin', 'dispatch']) ? 'true' : 'false' ?>;
const historyColSpan = canEditDispatch ? 11 : 10;
const historyPageSize = 25;
let historyPage = 0;
let historyTotal = 0;
let historyRows = [];
const filterOrderId = new URLSearchParams(window.location.search).get('order_id');

function formatTransportDoc(d) {
    if (d.eway_bill_no) return `<span class="badge bg-primary">E-way: ${escapeHtml(d.eway_bill_no)}</span>`;
    if (d.rawana_no) return `<span class="badge bg-secondary">Rawana: ${escapeHtml(d.rawana_no)}</span>`;
    return '—';
}

function formatDispatchStatus(status) {
    const s = status || 'active';
    const map = {
        active: '<span class="badge bg-success">Active</span>',
        rejected: '<span class="badge bg-danger">Rejected</span>',
        transferred: '<span class="badge bg-info text-dark">Transferred</span>',
    };
    return map[s] || escapeHtml(s);
}

function formatWeightTons(value) {
    if (value == null || value === '') return '—';
    return Number(value).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}

function buildHistoryParams() {
    const params = new URLSearchParams();
    params.set('limit', String(historyPageSize));
    params.set('offset', String(historyPage * historyPageSize));
    const start = document.getElementById('filterStartDate').value;
    const end = document.getElementById('filterEndDate').value;
    const status = document.getElementById('filterStatus').value;
    if (start) params.set('start_date', start);
    if (end) params.set('end_date', end);
    if (status) params.set('status', status);
    if (filterOrderId) params.set('order_id', filterOrderId);
    return params;
}

async function loadDispatchHistory(resetPage = false) {
    if (resetPage) historyPage = 0;
    const tbody = document.querySelector('#dispatchHistoryTable tbody');
    tbody.innerHTML = `<tr><td colspan="${historyColSpan}" class="text-center text-muted p-4">Loading…</td></tr>`;

    try {
        const response = await apiCall(`/api/dispatches?${buildHistoryParams().toString()}`);
        historyRows = response.data || [];
        historyTotal = response.pagination?.total ?? historyRows.length;
        renderHistoryTable();
        updateHistoryPagination();
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="${historyColSpan}" class="text-center text-danger p-4">${escapeHtml(error.message)}</td></tr>`;
    }
}

function renderHistoryTable() {
    const tbody = document.querySelector('#dispatchHistoryTable tbody');
    if (!historyRows.length) {
        tbody.innerHTML = `<tr><td colspan="${historyColSpan}" class="text-center text-muted p-4">No dispatches found for the selected filters.</td></tr>`;
        return;
    }

    tbody.innerHTML = historyRows.map(d => {
        const isActive = (d.status || 'active') === 'active';
        const actions = canEditDispatch ? `<td class="text-end text-nowrap">
            ${isActive ? `<button type="button" class="btn btn-sm btn-outline-warning me-1" onclick="openRejectTransferModal(${d.id})" title="Party rejected — transfer or credit note"><i class="bi bi-arrow-left-right"></i></button>` : ''}
            <a href="/orders/${d.order_id}" class="btn btn-sm btn-outline-primary" title="View order"><i class="bi bi-eye"></i></a>
        </td>` : '';
        return `<tr class="${!isActive ? 'table-secondary' : ''}">
            <td>${formatDate(d.dispatch_date)}</td>
            <td><a href="/orders/${d.order_id}"><strong>${escapeHtml(d.order_no || '')}</strong></a></td>
            <td>${escapeHtml(d.party_name || '—')}</td>
            <td>${formatDispatchStatus(d.status)}</td>
            <td>${d.dispatch_qty_trucks}</td>
            <td>${d.product_rate != null ? Number(d.product_rate).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : '—'}</td>
            <td>${d.loading_weight_tons != null ? formatWeightTons(d.loading_weight_tons) : '<span class="badge bg-warning text-dark">Pending</span>'}</td>
            <td>${formatTransportDoc(d)}</td>
            <td>${d.busy_invoice_no ? escapeHtml(d.busy_invoice_no) : '—'}</td>
            <td>${escapeHtml(d.dispatched_by_name || '—')}</td>
            ${actions}
        </tr>`;
    }).join('');
}

function updateHistoryPagination() {
    const totalPages = Math.max(1, Math.ceil(historyTotal / historyPageSize));
    const from = historyTotal === 0 ? 0 : historyPage * historyPageSize + 1;
    const to = Math.min(historyTotal, (historyPage + 1) * historyPageSize);
    document.getElementById('historyPaginationInfo').textContent =
        historyTotal ? `Showing ${from}–${to} of ${historyTotal}` : 'No records';
    document.getElementById('historyPageLabel').textContent = `Page ${historyPage + 1} of ${totalPages}`;
    document.getElementById('historyPrevBtn').disabled = historyPage <= 0;
    document.getElementById('historyNextBtn').disabled = (historyPage + 1) >= totalPages;
}

function changeHistoryPage(delta) {
    historyPage = Math.max(0, historyPage + delta);
    loadDispatchHistory();
}

<?php if (in_array($user['role'], ['entry', 'order_processing', 'admin', 'dispatch'])): ?>
let selectedDispatch = null;

async function openRejectTransferModal(dispatchId) {
    selectedDispatch = historyRows.find(d => Number(d.id) === Number(dispatchId));
    if (!selectedDispatch) return;

    document.getElementById('rejectDispatchId').value = selectedDispatch.id;
    document.getElementById('rejectTransferInfo').textContent =
        `${selectedDispatch.order_no} · ${selectedDispatch.party_name} · ` +
        `${selectedDispatch.dispatch_qty_trucks} truck(s) · ` +
        `${selectedDispatch.busy_invoice_no || 'no invoice'}`;

    const autoAmount = (selectedDispatch.loading_weight_tons && selectedDispatch.product_rate)
        ? Math.round(selectedDispatch.loading_weight_tons * selectedDispatch.product_rate * 100) / 100
        : '';
    document.getElementById('creditAmount').value = autoAmount;
    document.getElementById('rejectReason').value = '';
    document.getElementById('issueCreditNote').checked = true;

    const targetSelect = document.getElementById('targetOrderId');
    targetSelect.innerHTML = '<option value="">Loading orders…</option>';
    try {
        const response = await apiCall(`/api/dispatches/${selectedDispatch.id}/transfer-targets`);
        const targets = response.data || [];
        targetSelect.innerHTML = targets.length
            ? '<option value="">Select target order</option>' + targets.map(t =>
                `<option value="${t.order_id}">${escapeHtml(t.order_no)} — ${escapeHtml(t.party_name)} (${t.remaining_trucks} left)</option>`
            ).join('')
            : '<option value="">No pending orders with capacity</option>';
    } catch (error) {
        targetSelect.innerHTML = '<option value="">Could not load orders</option>';
        showError(error.message);
    }

    toggleRejectTransferFields();
    new bootstrap.Modal(document.getElementById('rejectTransferModal')).show();
}

function toggleRejectTransferFields() {
    const action = document.getElementById('rejectAction').value;
    const showTarget = action === 'transfer';
    document.getElementById('targetOrderGroup').style.display = showTarget ? '' : 'none';
    document.getElementById('targetOrderId').required = showTarget;
}

document.getElementById('rejectAction').addEventListener('change', toggleRejectTransferFields);

document.getElementById('rejectTransferForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const dispatchId = document.getElementById('rejectDispatchId').value;
    const action = document.getElementById('rejectAction').value;
    const submitBtn = document.getElementById('rejectTransferBtn');
    const spinner = document.getElementById('rejectTransferSpinner');

    if (action === 'transfer' && !document.getElementById('targetOrderId').value) {
        showError('Select the order to transfer this truck to.');
        return;
    }

    submitBtn.disabled = true;
    spinner.classList.remove('d-none');

    const payload = {
        action,
        reason: document.getElementById('rejectReason').value.trim() || null,
        issue_credit_note: document.getElementById('issueCreditNote').checked,
        credit_note_no: document.getElementById('creditNoteNo').value.trim() || null,
    };
    const creditAmount = document.getElementById('creditAmount').value;
    if (creditAmount) payload.credit_amount = parseFloat(creditAmount);
    if (action === 'transfer') {
        payload.target_order_id = parseInt(document.getElementById('targetOrderId').value, 10);
    }

    try {
        const response = await apiCall(`/api/dispatches/${dispatchId}/reject-transfer`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        bootstrap.Modal.getInstance(document.getElementById('rejectTransferModal')).hide();
        showSuccess(response.message || 'Rejection processed.');
        await loadDispatchHistory();
    } catch (error) {
        showError(error.message);
    } finally {
        submitBtn.disabled = false;
        spinner.classList.add('d-none');
    }
});
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    if (filterOrderId) {
        const subtitle = document.getElementById('historySubtitle');
        if (subtitle) {
            subtitle.textContent = `Filtered to order #${filterOrderId}. Clear filter: `;
            const link = document.createElement('a');
            link.href = '/dispatch/history';
            link.textContent = 'show all dispatches';
            subtitle.appendChild(link);
        }
    }
    loadDispatchHistory();
});
</script>
