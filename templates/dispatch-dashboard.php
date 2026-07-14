<?php include __DIR__ . '/partials/dispatch-nav.php'; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-truck-flatbed me-2"></i>Dispatch Dashboard
            </h1>
            <p class="page-subtitle">Orders pending dispatch — urgent orders first, oldest first</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#busyUploadModal">
                <i class="bi bi-upload me-1"></i> Upload Busy Invoice
            </button>
            <button class="btn btn-primary" onclick="loadDispatchQueue()">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<!-- Summary Cards -->
<div class="row mb-4" id="summaryCards">
    <div class="col-md-3 col-6 mb-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-warning" id="cardPending">-</div>
                <div class="text-muted small">Orders Pending</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-info" id="cardPartial">-</div>
                <div class="text-muted small">Partially Dispatched</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-primary" id="cardTrucksRemaining">-</div>
                <div class="text-muted small">Trucks Remaining</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-success" id="cardDispatchedToday">-</div>
                <div class="text-muted small">Trucks Dispatched Today</div>
            </div>
        </div>
    </div>
</div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p>Loading dispatch queue...</p>
</div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-list-check me-2"></i>Orders Awaiting Dispatch
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped align-middle" id="queueTable">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Party</th>
                        <th>Product</th>
                        <th>Priority</th>
                        <th>Ordered</th>
                        <th>Dispatched</th>
                        <th>Remaining</th>
                        <th>Scheduled</th>
                        <th>Age</th>
                        <th>Party Outstanding</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div id="emptyState" class="text-muted text-center" style="display: none; padding: 1rem 0;">
            No orders pending dispatch. All caught up!
        </div>
    </div>
</div>

<!-- Dispatch Modal -->
<div class="modal fade" id="dispatchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Dispatch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="dispatchForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info py-2" id="dispatchOrderInfo"></div>
                    <input type="hidden" id="dispatchOrderId">
                    <input type="hidden" id="dispatchTransportDocType">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="dispatchDate" class="form-label">Dispatch Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="dispatchDate" required value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="dispatchQty" class="form-label">Trucks <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="dispatchQty" required min="1">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="dispatchProductRate" class="form-label">Rate (₹/MT) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="dispatchProductRate" step="0.01" min="0.01" required>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3" id="dashRawanaGroup" style="display:none;">
                        <label for="dispatchRawanaNo" class="form-label">Rawana No.</label>
                        <input type="text" class="form-control" id="dispatchRawanaNo" name="rawana_no" placeholder="Optional — from invoice">
                    </div>
                    <div id="dashTruckEwaySection" style="display:none;">
                        <label class="form-label fw-semibold">E-way bill per truck <span class="text-danger">*</span></label>
                        <p class="small text-muted">One E-way bill number (and optional upload) per truck.</p>
                        <div id="dashTruckEwayRows" class="d-flex flex-column gap-3 mb-3"></div>
                    </div>
                    <div class="mb-3">
                        <label for="dispatchRemarks" class="form-label">Remarks</label>
                        <textarea class="form-control" id="dispatchRemarks" name="remarks" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="dispatchSubmitBtn">
                        <span class="spinner-border spinner-border-sm d-none" id="dispatchSpinner"></span>
                        <i class="bi bi-truck"></i> Dispatch
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Recent dispatches (incl. Busy invoice imports) -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt me-2"></i>Recent Dispatches</span>
        <div class="d-flex gap-2">
            <a href="/dispatch/history" class="btn btn-sm btn-outline-primary">View full history</a>
            <button class="btn btn-sm btn-outline-secondary" onclick="loadRecentDispatches()"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0" id="recentDispatchesTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Order</th>
                        <th>Party</th>
                        <th>Trucks</th>
                        <th>Rate (₹/MT)</th>
                        <th>Weight (MT)</th>
                        <th>Transport</th>
                        <th>Busy Inv.</th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="8" class="text-center text-muted p-3">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Busy Invoice Upload -->
<div class="modal fade" id="busyUploadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Upload Busy Sales Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="busyUploadForm">
                <div class="modal-body">
                    <p class="text-muted">
                        Upload the tax invoice PDF generated in Busy (or a CSV export).
                        OMS reads party, product, rate per MT, loading weight, and vehicle details, then creates or updates the dispatch.
                    </p>
                    <div class="alert alert-info py-2 small">
                        <strong>PDF (recommended):</strong> Busy tax invoice — party, weight, rate ₹/MT, vehicle no.<br>
                        <strong>JLD Minerals:</strong> invoice must include <strong>E-way Bill No.</strong> (not Rawana).<br>
                        <strong>Jaichand Lal Daga / Mines:</strong> Rawana no. is captured when present on invoice.<br>
                        <strong>CSV:</strong> Invoice No, Party, Product, Rate, Weight (MT), optional Order No.
                    </div>
                    <div class="mb-3">
                        <label for="busyInvoiceFile" class="form-label">Invoice file (PDF or CSV) <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="busyInvoiceFile" name="file" accept=".pdf,.csv,application/pdf" required>
                    </div>
                    <div id="busyUploadResult" class="d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="busyUploadBtn">
                        <span class="spinner-border spinner-border-sm d-none" id="busyUploadSpinner"></span>
                        Import &amp; Update Dispatches
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let queueOrders = [];

function formatMoney(value) {
    return Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function loadDispatchQueue() {
    const loading = document.getElementById('loading');
    const tbody = document.querySelector('#queueTable tbody');
    const emptyState = document.getElementById('emptyState');

    loading.style.display = 'block';
    tbody.innerHTML = '';
    emptyState.style.display = 'none';

    try {
        const response = await apiCall('/api/dispatch/pending');
        const summary = response.data.summary || {};
        queueOrders = response.data.orders || [];

        document.getElementById('cardPending').textContent = summary.pending_orders ?? 0;
        document.getElementById('cardPartial').textContent = summary.partial_orders ?? 0;
        document.getElementById('cardTrucksRemaining').textContent = summary.trucks_remaining ?? 0;
        document.getElementById('cardDispatchedToday').textContent = summary.dispatched_today_trucks ?? 0;

        if (queueOrders.length === 0) {
            emptyState.style.display = 'block';
            return;
        }

        tbody.innerHTML = queueOrders.map(o => {
            const overLimit = o.party_credit_limit !== null
                && Number(o.party_credit_limit) > 0
                && Number(o.party_outstanding) > Number(o.party_credit_limit);
            return `
            <tr>
                <td>
                    <a href="/orders/${o.id}" class="fw-bold">${escapeHtml(o.order_no)}</a>
                    <div><small class="text-muted">${escapeHtml(o.company_name || '')}</small></div>
                </td>
                <td>${escapeHtml(o.party_name)}</td>
                <td>${escapeHtml(o.product_name)}</td>
                <td>
                    ${o.priority === 'urgent'
                        ? '<span class="badge bg-danger">Urgent</span>'
                        : '<span class="badge bg-secondary">Normal</span>'}
                </td>
                <td>${o.order_qty_trucks}</td>
                <td>${o.total_dispatched}</td>
                <td><span class="badge bg-primary">${o.remaining_trucks}</span></td>
                <td>${o.scheduled_dispatch_date
                    ? `<span class="text-primary fw-semibold">${escapeHtml(o.scheduled_dispatch_date)}</span>`
                    : '<span class="text-muted">—</span>'}</td>
                <td>${o.age_days} day${Number(o.age_days) === 1 ? '' : 's'}</td>
                <td>
                    <span class="${overLimit ? 'text-danger fw-bold' : ''}">${formatMoney(o.party_outstanding)}</span>
                    ${overLimit ? '<div><small class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Over credit limit</small></div>' : ''}
                </td>
                <td class="text-end">
                    <button class="btn btn-sm btn-primary" onclick="openDispatchModal(${o.id})">
                        <i class="bi bi-truck"></i> Dispatch
                    </button>
                </td>
            </tr>`;
        }).join('');
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function toggleDashTransportDoc(docType) {
    const isEway = docType === 'eway_bill';
    document.getElementById('dashTruckEwaySection').style.display = isEway ? '' : 'none';
    document.getElementById('dashRawanaGroup').style.display = isEway ? 'none' : '';
    if (isEway) buildDashTruckEwayRows();
}

function buildDashTruckEwayRows() {
    const container = document.getElementById('dashTruckEwayRows');
    const orderId = document.getElementById('dispatchOrderId').value;
    const order = queueOrders.find(o => Number(o.id) === Number(orderId));
    const max = Number(order?.remaining_trucks) || 1;
    let qty = parseInt(document.getElementById('dispatchQty').value, 10) || 1;
    if (qty > max) qty = max;

    container.innerHTML = Array.from({ length: qty }, (_, i) => `
        <div class="border rounded p-3 bg-light">
            <div class="fw-semibold small mb-2">Truck ${i + 1}</div>
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label small">E-way Bill No. <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm dash-truck-eway-no" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Upload (PDF/image)</label>
                    <input type="file" class="form-control form-control-sm" name="eway_file_${i}" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*">
                </div>
            </div>
        </div>
    `).join('');
}

function formatTransportDoc(d) {
    let text = '—';
    if (d.eway_bill_no) text = escapeHtml(d.eway_bill_no) + ' <span class="badge bg-primary">E-way</span>';
    else if (d.rawana_no) text = escapeHtml(d.rawana_no) + ' <span class="badge bg-secondary">Rawana</span>';
    if (d.has_eway_bill_file || d.eway_bill_file_path) {
        text += ` <a href="/api/dispatches/${d.id}/eway-bill-file" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-1"><i class="bi bi-file-earmark-pdf"></i></a>`;
    }
    return text;
}

function openDispatchModal(orderId) {
    const order = queueOrders.find(o => Number(o.id) === Number(orderId));
    if (!order) return;

    document.getElementById('dispatchOrderId').value = order.id;
    document.getElementById('dispatchTransportDocType').value = order.transport_doc_type || 'rawana';
    document.getElementById('dispatchOrderInfo').innerHTML = `
        <strong>${escapeHtml(order.order_no)}</strong> — ${escapeHtml(order.party_name)} · ${escapeHtml(order.product_name)}<br>
        <small>Remaining: <strong>${order.remaining_trucks}</strong> of ${order.order_qty_trucks} trucks</small>`;
    const qtyInput = document.getElementById('dispatchQty');
    qtyInput.max = order.remaining_trucks;
    qtyInput.value = '';
    document.getElementById('dispatchDate').value = order.scheduled_dispatch_date || new Date().toISOString().split('T')[0];
    document.getElementById('dispatchProductRate').value = '';
    document.getElementById('dispatchRemarks').value = '';
    document.getElementById('dispatchRawanaNo').value = '';
    toggleDashTransportDoc(order.transport_doc_type || 'rawana');

    new bootstrap.Modal(document.getElementById('dispatchModal')).show();
}

document.getElementById('dispatchQty').addEventListener('input', function() {
    if (document.getElementById('dispatchTransportDocType').value === 'eway_bill') {
        buildDashTruckEwayRows();
    }
});

document.getElementById('dispatchForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const orderId = document.getElementById('dispatchOrderId').value;
    const submitBtn = document.getElementById('dispatchSubmitBtn');
    const spinner = document.getElementById('dispatchSpinner');
    const qty = parseInt(document.getElementById('dispatchQty').value, 10);
    const isEway = document.getElementById('dispatchTransportDocType').value === 'eway_bill';

    const formData = new FormData();
    formData.append('dispatch_date', document.getElementById('dispatchDate').value);
    formData.append('dispatch_qty_trucks', String(qty));
    formData.append('product_rate', document.getElementById('dispatchProductRate').value);
    formData.append('remarks', document.getElementById('dispatchRemarks').value || '');
    formData.append('csrf_token', <?= json_encode($csrf_token ?? '') ?>);

    if (isEway) {
        const truckBills = [];
        let valid = true;
        document.querySelectorAll('.dash-truck-eway-no').forEach((input) => {
            const no = input.value.trim();
            if (!no) valid = false;
            else truckBills.push({ eway_bill_no: no });
        });
        if (!valid || truckBills.length !== qty) {
            showError(`Enter E-way bill number for each of ${qty} truck(s).`);
            return;
        }
        formData.append('truck_eway_bills', JSON.stringify(truckBills));
        document.querySelectorAll('#dashTruckEwayRows input[type="file"]').forEach(fileInput => {
            if (fileInput.files && fileInput.files[0]) {
                formData.append(fileInput.name, fileInput.files[0]);
            }
        });
    } else {
        formData.append('rawana_no', document.getElementById('dispatchRawanaNo').value.trim() || '');
    }

    submitBtn.disabled = true;
    spinner.classList.remove('d-none');

    try {
        const response = await fetch(`/api/orders/${orderId}/dispatches`, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': <?= json_encode($csrf_token ?? '') ?> }
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Dispatch failed');

        bootstrap.Modal.getInstance(document.getElementById('dispatchModal')).hide();
        showSuccess(data.message || 'Dispatch recorded successfully.');
        await loadDispatchQueue();
        loadRecentDispatches();
    } catch (error) {
        showError(error.message);
    } finally {
        submitBtn.disabled = false;
        spinner.classList.add('d-none');
    }
});

document.addEventListener('DOMContentLoaded', function() {
    loadDispatchQueue();
    loadRecentDispatches();
});

async function loadRecentDispatches() {
    const tbody = document.querySelector('#recentDispatchesTable tbody');
    try {
        const response = await apiCall('/api/dispatches?limit=10');
        const rows = response.data || [];
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted p-3">No dispatches yet</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(d => `
            <tr>
                <td>${formatDate(d.dispatch_date)}</td>
                <td><a href="/orders/${d.order_id}"><strong>${escapeHtml(d.order_no || '')}</strong></a></td>
                <td>${escapeHtml(d.party_name || '—')}</td>
                <td>${d.dispatch_qty_trucks}</td>
                <td>${d.product_rate != null ? Number(d.product_rate).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : '—'}</td>
                <td>${d.loading_weight_tons != null ? Number(d.loading_weight_tons).toLocaleString('en-IN', { minimumFractionDigits: 3 }) : '—'}</td>
                <td>${formatTransportDoc(d)}</td>
                <td>${d.busy_invoice_no ? escapeHtml(d.busy_invoice_no) : '—'}</td>
            </tr>
        `).join('');
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger p-3">${escapeHtml(error.message)}</td></tr>`;
    }
}

document.getElementById('busyUploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const fileInput = document.getElementById('busyInvoiceFile');
    const submitBtn = document.getElementById('busyUploadBtn');
    const spinner = document.getElementById('busyUploadSpinner');
    const resultBox = document.getElementById('busyUploadResult');

    if (!fileInput.files || !fileInput.files[0]) {
        showError('Please select a Busy invoice PDF or CSV file.');
        return;
    }

    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('csrf_token', <?= json_encode($csrf_token ?? '') ?>);

    submitBtn.disabled = true;
    spinner.classList.remove('d-none');
    resultBox.classList.add('d-none');

    try {
        const response = await fetch('/api/busy/invoices/upload', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': <?= json_encode($csrf_token ?? '') ?>
            }
        });

        const data = await response.json();
        if (!response.ok) {
            const details = Array.isArray(data.details) ? data.details : [];
            if (details.length > 0) {
                resultBox.className = 'alert alert-danger';
                resultBox.innerHTML = `<strong>${escapeHtml(data.error || 'Upload failed')}</strong><ul class="mb-0 mt-2">${details.map(d => `<li>${escapeHtml(d)}</li>`).join('')}</ul>`;
                resultBox.classList.remove('d-none');
                showError('');
                return;
            }
            throw new Error(data.error || data.message || 'Upload failed');
        }

        const details = (data.data?.details || []).map(row => {
            if (row.status === 'success') {
                const orderLink = row.order_id
                    ? `<a href="/orders/${row.order_id}" class="ms-1">View order</a>`
                    : '';
                return `<li class="text-success">${escapeHtml(row.invoice_no)} → Order ${escapeHtml(row.order_no)} (${row.action})${orderLink}</li>`;
            }
            return `<li class="text-danger">${escapeHtml(row.invoice_no)}: ${escapeHtml(row.error || 'Failed')}</li>`;
        }).join('');

        resultBox.className = 'alert ' + (data.data?.failed ? 'alert-warning' : 'alert-success');
        resultBox.innerHTML = `<strong>${escapeHtml(data.message || 'Done')}</strong><ul class="mb-0 mt-2">${details}</ul>`;
        resultBox.classList.remove('d-none');

        if (data.data?.successful > 0) {
            showSuccess(data.message || 'Invoices imported.');
            await loadDispatchQueue();
            await loadRecentDispatches();
        }
    } catch (error) {
        showError(error.message);
    } finally {
        submitBtn.disabled = false;
        spinner.classList.add('d-none');
    }
});
</script>
