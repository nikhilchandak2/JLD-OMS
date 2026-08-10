<?php include __DIR__ . '/partials/dispatch-nav.php'; ?>

<style>
.daily-kpi {
    border: none;
    border-radius: 0.75rem;
    box-shadow: 0 1px 3px rgba(43, 35, 94, 0.08);
    height: 100%;
}
.daily-kpi .kpi-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--jld-primary, #2b235e);
}
.daily-kpi .kpi-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--jld-gray, #6c757d);
}
</style>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <i class="bi bi-calendar3 me-2"></i>Daily Busy Dispatches
            </h1>
            <p class="page-subtitle">
                Invoices from Dispatch upload — including ones not mapped to a portal order.
                After creating missing orders, use <strong>Remap unmapped</strong> (no need to re-upload CSV).
                Remap only maps when an order exists — other unmapped invoices stay on this page.
                Upload CSV once on the <a href="/dispatch">Dispatch Dashboard</a>.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="/dispatch" class="btn btn-outline-primary">
                <i class="bi bi-upload me-1"></i> Go to Dispatch Upload
            </a>
            <a href="/dispatch/invoices" class="btn btn-outline-info">
                <i class="bi bi-list-ul me-1"></i> All invoices
            </a>
            <button type="button" class="btn btn-warning" id="remapUnmappedBtn" onclick="remapUnmappedInvoices()">
                <span class="spinner-border spinner-border-sm d-none" id="remapSpinner"></span>
                <i class="bi bi-link-45deg me-1"></i> Remap unmapped
            </button>
            <button type="button" class="btn btn-outline-danger" id="fixMisfiledBtn" onclick="fixMisfiledAllowlistOrders()">
                <span class="spinner-border spinner-border-sm d-none" id="fixMisfiledSpinner"></span>
                <i class="bi bi-building-gear me-1"></i> Fix wrong-company orders
            </button>
            <button type="button" class="btn btn-primary" onclick="loadDailyBusyDispatches(true)">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="filterDate" class="form-label">Invoice date</label>
                <input type="date" class="form-control" id="filterDate" value="<?= date('Y-m-d') ?>">
                <div class="form-text">Unmapped invoices keep their Busy invoice date — change date or use “All unmapped”.</div>
            </div>
            <div class="col-md-3">
                <label for="filterMapping" class="form-label">Mapping</label>
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
            <div class="col-md-3 d-flex flex-column gap-2">
                <button type="button" class="btn btn-outline-primary w-100" onclick="loadDailyBusyDispatches(true)">
                    <i class="bi bi-funnel me-1"></i> Apply Filters
                </button>
                <button type="button" class="btn btn-outline-warning w-100" onclick="showAllUnmapped()">
                    <i class="bi bi-exclamation-triangle me-1"></i> All unmapped
                </button>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4" id="dailySummaryCards">
    <div class="col-6 col-md-3">
        <div class="card daily-kpi">
            <div class="card-body">
                <div class="kpi-label">Total invoices</div>
                <div class="kpi-value" id="sumTotal">0</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card daily-kpi">
            <div class="card-body">
                <div class="kpi-label">Mapped</div>
                <div class="kpi-value text-success" id="sumMapped">0</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card daily-kpi">
            <div class="card-body">
                <div class="kpi-label">Not mapped</div>
                <div class="kpi-value text-warning" id="sumUnmapped">0</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card daily-kpi">
            <div class="card-body">
                <div class="kpi-label">Trucks / Weight</div>
                <div class="kpi-value" id="sumTrucksWeight">0 / —</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2"></i>Daily invoices</span>
        <small class="text-muted" id="dailyPaginationInfo">—</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="dailyBusyTable">
                <thead class="table-light">
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Party</th>
                        <th>Product</th>
                        <th>Trucks</th>
                        <th>Weight (MT)</th>
                        <th>Rate (₹/MT)</th>
                        <th>Order mapping</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="9" class="text-center text-muted p-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="dailyPrevBtn" onclick="changeDailyPage(-1)" disabled>Previous</button>
        <span class="small text-muted" id="dailyPageLabel">Page 1</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="dailyNextBtn" onclick="changeDailyPage(1)" disabled>Next</button>
    </div>
</div>

<script>
const dailyPageSize = 50;
let dailyPage = 0;
let dailyTotal = 0;
let dailyRows = [];
/** When true, ignore date filter and load every matching invoice (used by All unmapped). */
let dailyAllDates = false;

function buildDailyParams() {
    const params = new URLSearchParams();
    if (dailyAllDates) {
        params.set('all_dates', '1');
    } else {
        params.set('date', document.getElementById('filterDate').value || new Date().toISOString().slice(0, 10));
    }
    params.set('limit', String(dailyPageSize));
    params.set('offset', String(dailyPage * dailyPageSize));
    const mapping = document.getElementById('filterMapping').value;
    if (dailyAllDates && (!mapping || mapping === 'unmapped')) {
        // Include error rows that older remap wrongly flipped from unmapped
        params.set('mapping_status', 'open');
    } else if (mapping) {
        params.set('mapping_status', mapping);
    }
    const search = document.getElementById('filterSearch').value.trim();
    if (search) params.set('search', search);
    return params;
}

function showAllUnmapped() {
    dailyAllDates = true;
    document.getElementById('filterMapping').value = 'unmapped';
    document.getElementById('filterDate').value = '';
    loadDailyBusyDispatches(true);
}

function formatWeightTons(value) {
    if (value == null || value === '') return '—';
    return Number(value).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}

function formatRate(value) {
    if (value == null || value === '') return '—';
    return Number(value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatMappingCell(row) {
    if (row.mapping_status === 'mapped' && row.order_id) {
        return `<a href="/orders/${row.order_id}"><strong>${escapeHtml(row.order_no || ('#' + row.order_id))}</strong></a>
            <div class="small text-success">Mapped to order</div>`;
    }
    if (row.mapping_status === 'error') {
        return `<span class="badge bg-danger">Import error</span>`;
    }
    return `<span class="badge bg-warning text-dark">Dispatch not mapped to any order</span>
        ${row.order_no_from_invoice ? `<div class="small text-muted mt-1">Invoice Order No: ${escapeHtml(row.order_no_from_invoice)}</div>` : ''}`;
}

async function loadDailyBusyDispatches(resetPage = false) {
    if (resetPage) dailyPage = 0;
    const dateVal = document.getElementById('filterDate').value;
    // Any explicit date selection exits "all dates" mode
    if (dateVal) {
        dailyAllDates = false;
    }
    const tbody = document.querySelector('#dailyBusyTable tbody');
    tbody.innerHTML = `<tr><td colspan="9" class="text-center text-muted p-4">Loading…</td></tr>`;

    try {
        const response = await apiCall(`/api/busy/daily-invoices?${buildDailyParams().toString()}`);
        dailyRows = response.data || [];
        dailyTotal = response.pagination?.total ?? dailyRows.length;
        renderDailySummary(response.summary || {});
        renderDailyTable();
        updateDailyPagination();
        showError('');
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger p-4">${escapeHtml(error.message || 'Failed to load')}</td></tr>`;
        showError(error.message || 'Failed to load daily dispatches');
    }
}

async function remapUnmappedInvoices() {
    const btn = document.getElementById('remapUnmappedBtn');
    const spinner = document.getElementById('remapSpinner');
    const filterDate = (document.getElementById('filterDate')?.value || '').trim();

    const scopeHint = filterDate
        ? `invoice date ${filterDate}`
        : 'ALL dates (batched — may take several rounds)';

    if (!confirm(
        `Re-match unmapped invoices (${scopeHint}) against portal orders?\n\n`
        + 'Allowlisted parties (Gargi, Sneha, etc.) get auto-created same-day orders when still unmapped.\n'
        + 'No CSV re-upload needed.'
    )) {
        return;
    }

    btn.disabled = true;
    spinner.classList.remove('d-none');
    showError('');
    showSuccess('');

    try {
        let totalProcessed = 0;
        let totalMapped = 0;
        let totalStill = 0;
        let totalAutoOrders = 0;
        let rounds = 0;
        let afterId = 0;
        const maxRounds = 40;
        const sampleHints = [];

        while (rounds < maxRounds) {
            rounds++;
            const body = {
                limit: 75,
                all_dates: !filterDate,
            };
            if (filterDate) {
                body.date = filterDate;
                body.only_selected_date = true;
            }
            if (afterId > 0) {
                body.after_id = afterId;
            }

            const response = await apiCall('/api/busy/daily-invoices/remap', {
                method: 'POST',
                body: JSON.stringify(body),
            });

            const data = response.data || {};
            totalProcessed += Number(data.processed || 0);
            totalMapped += Number(data.mapped || 0);
            totalStill = Number(data.still_unmapped || 0);
            totalAutoOrders += Number(data.auto_orders_created || 0);
            if (data.next_after_id) {
                afterId = Number(data.next_after_id);
            }

            const details = data.details || [];
            for (const d of details) {
                if (d.status === 'unmapped' && d.error && sampleHints.length < 3) {
                    sampleHints.push(`#${d.invoice_no}: ${d.error}`);
                }
            }

            if (!data.has_more || Number(data.processed || 0) === 0) {
                break;
            }
        }

        let msg = `Remap finished — ${totalProcessed} processed, ${totalMapped} mapped`
            + (totalAutoOrders ? `, ${totalAutoOrders} auto-order(s)` : '')
            + ` (${rounds} batch${rounds === 1 ? '' : 'es'}).`;
        if (totalStill > 0 && rounds >= maxRounds) {
            msg += ' More may remain — click Remap again.';
        }

        const uniqueHints = [...new Set(sampleHints)].slice(0, 3);
        if (uniqueHints.length) {
            msg += '\n\nStill unmapped (examples):\n' + uniqueHints.join('\n');
        }

        // Reload table first — loadDailyBusyDispatches clears alerts, so show result after.
        await loadDailyBusyDispatches(true);

        if (totalMapped === 0 && totalProcessed > 0) {
            showError(msg);
        } else {
            showSuccess(msg);
        }
    } catch (error) {
        showError(error.message || 'Remap failed');
    } finally {
        btn.disabled = false;
        spinner.classList.add('d-none');
    }
}

async function fixMisfiledAllowlistOrders() {
    const btn = document.getElementById('fixMisfiledBtn');
    const spinner = document.getElementById('fixMisfiledSpinner');

    if (!confirm(
        'Move allowlisted-party orders that were created under the wrong company '
        + '(e.g. Sneha/Gargi under JL Daga Mines / JLDMM) to Jaichand Lal Daga (JLD)?\n\n'
        + 'Order numbers will be reassigned to the JLD series. Dispatches stay linked.\n'
        + 'Admin only. This cannot be undone automatically.'
    )) {
        return;
    }

    btn.disabled = true;
    spinner.classList.remove('d-none');
    showError('');
    showSuccess('');

    try {
        const response = await apiCall('/api/busy/daily-invoices/fix-misfiled-orders', {
            method: 'POST',
            body: JSON.stringify({}),
        });

        const data = response.data || {};
        const details = data.details || [];
        const samples = details
            .filter((d) => d.status === 'moved')
            .slice(0, 5)
            .map((d) => `${d.old_order_no} → ${d.new_order_no} (${d.party_name})`);

        let msg = response.message || 'Fix finished.';
        if (samples.length) {
            msg += '\n\nExamples:\n' + samples.join('\n');
        }
        const errors = details.filter((d) => d.status === 'error' || d.status === 'skipped').slice(0, 3);
        if (errors.length) {
            msg += '\n\nNotes:\n' + errors.map((d) => d.error || d.party_name).join('\n');
        }

        await loadDailyBusyDispatches(true);
        if ((data.moved || 0) > 0) {
            showSuccess(msg);
        } else {
            showError(msg + (errors.length ? '' : '\nNo misfiled orders found (or company prefix JLD missing).'));
        }
    } catch (error) {
        showError(error.message || 'Fix misfiled orders failed');
    } finally {
        btn.disabled = false;
        spinner.classList.add('d-none');
    }
}

function renderDailySummary(summary) {
    document.getElementById('sumTotal').textContent = summary.total ?? 0;
    document.getElementById('sumMapped').textContent = summary.mapped ?? 0;
    document.getElementById('sumUnmapped').textContent = summary.unmapped ?? 0;
    const weight = summary.weight_tons != null
        ? Number(summary.weight_tons).toLocaleString('en-IN', { maximumFractionDigits: 3 }) + ' MT'
        : '—';
    document.getElementById('sumTrucksWeight').textContent = `${summary.trucks ?? 0} / ${weight}`;
}

function renderDailyTable() {
    const tbody = document.querySelector('#dailyBusyTable tbody');
    if (!dailyRows.length) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center text-muted p-4">
            No Busy invoices for this filter.
            Unmapped rows keep the <strong>invoice date from Busy</strong> (not today’s date).
            Try <button type="button" class="btn btn-link p-0 align-baseline" onclick="showAllUnmapped()">All unmapped</button>
            or pick the invoice date, then refresh.
            Upload CSV once from the <a href="/dispatch">Dispatch Dashboard</a> if nothing was imported yet.
        </td></tr>`;
        return;
    }

    tbody.innerHTML = dailyRows.map(row => {
        const rowClass = row.mapping_status === 'unmapped'
            ? 'table-warning'
            : (row.mapping_status === 'error' ? 'table-danger' : '');
        const notes = row.error_message
            ? `<span class="small text-muted" title="${escapeHtml(row.error_message)}">${escapeHtml(row.error_message.length > 80 ? row.error_message.slice(0, 80) + '…' : row.error_message)}</span>`
            : '—';
        return `<tr class="${rowClass}">
            <td><strong>${escapeHtml(row.invoice_no || '')}</strong></td>
            <td>${formatDate(row.invoice_date || '')}</td>
            <td>${escapeHtml(row.party_name || '—')}</td>
            <td>${escapeHtml(row.product_name || '—')}</td>
            <td>${row.quantity_trucks ?? '—'}</td>
            <td>${formatWeightTons(row.loading_weight_tons)}</td>
            <td>${formatRate(row.product_rate)}</td>
            <td>${formatMappingCell(row)}</td>
            <td>${notes}</td>
        </tr>`;
    }).join('');
}

function updateDailyPagination() {
    const totalPages = Math.max(1, Math.ceil(dailyTotal / dailyPageSize));
    const from = dailyTotal === 0 ? 0 : dailyPage * dailyPageSize + 1;
    const to = Math.min(dailyTotal, (dailyPage + 1) * dailyPageSize);
    document.getElementById('dailyPaginationInfo').textContent =
        dailyTotal ? `Showing ${from}–${to} of ${dailyTotal}` : 'No records';
    document.getElementById('dailyPageLabel').textContent = `Page ${dailyPage + 1} of ${totalPages}`;
    document.getElementById('dailyPrevBtn').disabled = dailyPage <= 0;
    document.getElementById('dailyNextBtn').disabled = (dailyPage + 1) >= totalPages;
}

function changeDailyPage(delta) {
    dailyPage = Math.max(0, dailyPage + delta);
    loadDailyBusyDispatches();
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('filterSearch').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') loadDailyBusyDispatches(true);
    });
    loadDailyBusyDispatches(true);
});
</script>
