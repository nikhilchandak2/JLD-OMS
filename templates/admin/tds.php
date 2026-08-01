<!-- TDS — Accounts: Busy outward vouchers classified by Price slab + Material Centre -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/admin/parties">Administration</a></li>
                    <li class="breadcrumb-item active">TDS</li>
                </ol>
            </nav>
            <h1 class="page-title mt-2"><i class="bi bi-calculator me-2"></i>TDS</h1>
            <p class="page-subtitle mb-0">
                Upload Busy <em>List of Supply Outward Vouchers</em> and sort by Price slabs per Material Centre.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tdsUploadModal">
                <i class="bi bi-upload me-1"></i> Upload report
            </button>
            <button type="button" class="btn btn-success" id="btnExport" disabled onclick="exportCurrent()">
                <i class="bi bi-file-earmark-excel me-1"></i> Export TDS A
            </button>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="alert alert-success" style="display:none;"></div>

<div class="row g-3 mb-3" id="bandCards">
    <div class="col-md-4">
        <div class="fuel-stat">
            <div class="fuel-stat-label">≥ 1000 (all above)</div>
            <div class="fuel-stat-value" id="cardBand1">—</div>
            <div class="small text-muted" id="cardBand1Sub"></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="fuel-stat">
            <div class="fuel-stat-label">≥ 1500 (all above)</div>
            <div class="fuel-stat-value" id="cardBand2">—</div>
            <div class="small text-muted" id="cardBand2Sub"></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="fuel-stat">
            <div class="fuel-stat-label">≥ 2000 (all above)</div>
            <div class="fuel-stat-value" id="cardBand3">—</div>
            <div class="small text-muted" id="cardBand3Sub"></div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small mb-1" for="uploadSelect">Uploaded report</label>
                <select class="form-select" id="uploadSelect" onchange="onUploadChange()">
                    <option value="">Loading…</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="centreFilter">Material Centre</label>
                <select class="form-select" id="centreFilter" onchange="reloadDetail()" disabled>
                    <option value="all">All centres</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="bandFilter">Price band</label>
                <select class="form-select" id="bandFilter" onchange="reloadDetail()" disabled>
                    <option value="all">All prices</option>
                    <option value="above_1000">≥ 1000 (all above)</option>
                    <option value="above_1500">≥ 1500 (all above)</option>
                    <option value="above_2000">≥ 2000 (all above)</option>
                    <option value="below_1000">&lt; 1000</option>
                </select>
            </div>
            <?php if (!empty($can_delete_tds)): ?>
            <div class="col-md-1">
                <button type="button" class="btn btn-outline-danger w-100" id="btnDelete" disabled title="Delete upload (admin only)" onclick="deleteCurrent()">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <p class="small text-muted mb-0 mt-2" id="periodHint">Upload a Busy outward vouchers Excel to begin.</p>
    </div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-summary" data-bs-toggle="tab" data-bs-target="#pane-summary" type="button" role="tab">
            By Material Centre
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-detail" data-bs-toggle="tab" data-bs-target="#pane-detail" type="button" role="tab">
            Voucher detail
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="pane-summary" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-building me-2"></i>Material Centre × Price slab</span>
                <span class="small text-muted" id="summaryCount">0 centres</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover mb-0 align-middle" id="summaryTable">
                        <thead class="table-light">
                            <tr>
                                <th rowspan="2" class="align-middle">Material Centre</th>
                                <th colspan="2" class="text-center">≥ 1000</th>
                                <th colspan="2" class="text-center">≥ 1500</th>
                                <th colspan="2" class="text-center">≥ 2000</th>
                                <th colspan="2" class="text-center">Total (all prices)</th>
                            </tr>
                            <tr>
                                <th class="text-end">Qty</th><th class="text-end">Amount</th>
                                <th class="text-end">Qty</th><th class="text-end">Amount</th>
                                <th class="text-end">Qty</th><th class="text-end">Amount</th>
                                <th class="text-end">Qty</th><th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="9" class="text-center text-muted py-4">No data yet.</td></tr>
                        </tbody>
                        <tfoot class="table-warning fw-semibold"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-detail" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ul me-2"></i>Voucher lines</span>
                <span class="small text-muted" id="detailCount">0 rows</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 65vh;">
                    <table class="table table-sm table-hover mb-0" id="detailTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Date</th>
                                <th>Vch/Bill No</th>
                                <th>Particulars</th>
                                <th>Item Details</th>
                                <th>Material Centre</th>
                                <th class="text-end">Qty</th>
                                <th>Unit</th>
                                <th class="text-end">Price</th>
                                <th class="text-end">Amount</th>
                                <th>Qualifying Slabs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="10" class="text-center text-muted py-4">No data yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Upload modal -->
<div class="modal fade" id="tdsUploadModal" tabindex="-1" aria-labelledby="tdsUploadModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tdsUploadModalLabel">Upload Busy outward vouchers</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="tdsUploadForm">
                <div class="modal-body">
                    <p class="small text-muted">
                        Expected columns: <strong>Date, Vch/Bill No, Particulars, Item Details, Material Centre, Qty., Unit, Price, Amount</strong>
                        (Busy “List of Supply Outward Vouchers”).
                    </p>
                    <div class="mb-3">
                        <label class="form-label" for="tdsFile">Excel / CSV file</label>
                        <input type="file" class="form-control" id="tdsFile" accept=".xlsx,.xls,.csv,.ods" required>
                    </div>
                    <div class="small text-muted">
                        Sorting rules (cumulative — higher prices count in every matching slab):
                        <ul class="mb-0">
                            <li>Price ≥ 1000 — includes all from 1000 upward (no upper cap)</li>
                            <li>Price ≥ 1500 — includes all from 1500 upward (no upper cap)</li>
                            <li>Price ≥ 2000 — includes all from 2000 upward</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnUploadSubmit">
                        <i class="bi bi-upload me-1"></i> Import &amp; classify
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
#summaryTable th, #summaryTable td { white-space: nowrap; font-size: 0.875rem; }
#detailTable th, #detailTable td { font-size: 0.8125rem; }
#detailTable thead.sticky-top { z-index: 2; }
</style>

<script>
const BAND_LABELS = {
    above_1000: '≥ 1000 (all above)',
    above_1500: '≥ 1500 (all above)',
    above_2000: '≥ 2000 (all above)'
};

let currentUploadId = null;
let lastPayload = null;

function fmtNum(n, digits = 2) {
    const v = Number(n || 0);
    return v.toLocaleString('en-IN', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function slabLabelsForPrice(price) {
    const p = Number(price || 0);
    const labels = [];
    if (p >= 1000) labels.push('≥ 1000');
    if (p >= 1500) labels.push('≥ 1500');
    if (p >= 2000) labels.push('≥ 2000');
    return labels.length ? labels.join(', ') : '< 1000';
}

function showTdsError(msg) {
    const el = document.getElementById('error-container');
    if (!msg) { el.style.display = 'none'; el.textContent = ''; return; }
    el.style.display = 'block';
    el.textContent = msg;
}

function showTdsSuccess(msg) {
    const el = document.getElementById('success-container');
    if (!msg) { el.style.display = 'none'; el.innerHTML = ''; return; }
    el.style.display = 'block';
    el.textContent = msg;
}

async function loadUploads(selectId = null) {
    const sel = document.getElementById('uploadSelect');
    try {
        const res = await fetch('/api/tds/uploads');
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to load uploads');

        const list = data.data || [];
        if (!list.length) {
            sel.innerHTML = '<option value="">No uploads yet</option>';
            currentUploadId = null;
            setControlsEnabled(false);
            clearViews();
            return;
        }

        sel.innerHTML = list.map(u => {
            const label = (u.period_label || u.original_filename || ('Upload #' + u.id))
                + ' — ' + (u.rows_imported || 0) + ' rows'
                + (u.created_at ? ' (' + String(u.created_at).slice(0, 16) + ')' : '');
            return `<option value="${u.id}">${escapeHtml(label)}</option>`;
        }).join('');

        const pick = selectId || list[0].id;
        sel.value = String(pick);
        await loadUpload(Number(pick));
    } catch (e) {
        sel.innerHTML = '<option value="">Error loading</option>';
        showTdsError(e.message || 'Failed to load uploads');
    }
}

function setControlsEnabled(on) {
    document.getElementById('btnExport').disabled = !on;
    const btnDelete = document.getElementById('btnDelete');
    if (btnDelete) btnDelete.disabled = !on;
    document.getElementById('centreFilter').disabled = !on;
    document.getElementById('bandFilter').disabled = !on;
}

function clearViews() {
    document.getElementById('summaryTable').querySelector('tbody').innerHTML =
        '<tr><td colspan="9" class="text-center text-muted py-4">No data yet.</td></tr>';
    document.getElementById('summaryTable').querySelector('tfoot').innerHTML = '';
    document.getElementById('detailTable').querySelector('tbody').innerHTML =
        '<tr><td colspan="10" class="text-center text-muted py-4">No data yet.</td></tr>';
    ['cardBand1','cardBand2','cardBand3'].forEach(id => document.getElementById(id).textContent = '—');
    ['cardBand1Sub','cardBand2Sub','cardBand3Sub'].forEach(id => document.getElementById(id).textContent = '');
    document.getElementById('periodHint').textContent = 'Upload a Busy outward vouchers Excel to begin.';
    document.getElementById('summaryCount').textContent = '0 centres';
    document.getElementById('detailCount').textContent = '0 rows';
}

async function onUploadChange() {
    const id = Number(document.getElementById('uploadSelect').value || 0);
    if (!id) return;
    await loadUpload(id);
}

async function loadUpload(id) {
    showTdsError('');
    try {
        const centre = document.getElementById('centreFilter').value || 'all';
        const band = document.getElementById('bandFilter').value || 'all';
        const qs = new URLSearchParams();
        if (centre && centre !== 'all') qs.set('material_centre', centre);
        if (band && band !== 'all') qs.set('price_band', band);
        const url = '/api/tds/uploads/' + id + (qs.toString() ? ('?' + qs.toString()) : '');
        const res = await fetch(url);
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to load report');

        currentUploadId = id;
        lastPayload = data;
        setControlsEnabled(true);
        renderMeta(data);
        renderBandCards(data.band_totals || {});
        renderSummary(data.summary || []);
        renderDetail(data.lines || []);
        fillCentreFilter(data.material_centres || [], centre);
    } catch (e) {
        showTdsError(e.message || 'Failed to load report');
    }
}

async function reloadDetail() {
    if (!currentUploadId) return;
    await loadUpload(currentUploadId);
}

function renderMeta(data) {
    const u = data.upload || {};
    const parts = [];
    if (u.period_label) parts.push(u.period_label);
    parts.push((u.rows_imported || 0) + ' voucher lines');
    if (u.original_filename) parts.push(u.original_filename);
    document.getElementById('periodHint').textContent = parts.join(' · ');
}

function fillCentreFilter(centres, selected) {
    const sel = document.getElementById('centreFilter');
    const keep = selected || 'all';
    sel.innerHTML = '<option value="all">All centres</option>' +
        centres.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
    sel.value = centres.includes(keep) ? keep : 'all';
}

function renderBandCards(bands) {
    const map = {
        above_1000: ['cardBand1', 'cardBand1Sub'],
        above_1500: ['cardBand2', 'cardBand2Sub'],
        above_2000: ['cardBand3', 'cardBand3Sub'],
    };
    Object.keys(map).forEach(band => {
        const t = bands[band] || { n: 0, qty: 0, amount: 0 };
        document.getElementById(map[band][0]).textContent = fmtNum(t.amount, 0);
        document.getElementById(map[band][1]).textContent =
            `${t.n || 0} vouchers · Qty ${fmtNum(t.qty)}`;
    });
}

function renderSummary(rows) {
    const tbody = document.getElementById('summaryTable').querySelector('tbody');
    const tfoot = document.getElementById('summaryTable').querySelector('tfoot');
    document.getElementById('summaryCount').textContent = rows.length + ' centre' + (rows.length === 1 ? '' : 's');

    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No data yet.</td></tr>';
        tfoot.innerHTML = '';
        return;
    }

    const totals = {
        above_1000_qty: 0, above_1000_amt: 0,
        above_1500_qty: 0, above_1500_amt: 0,
        above_2000_qty: 0, above_2000_amt: 0,
        total_qty: 0, total_amt: 0
    };

    tbody.innerHTML = rows.map(r => {
        Object.keys(totals).forEach(k => { totals[k] += Number(r[k] || 0); });
        return `<tr>
            <td>${escapeHtml(r.material_centre)}</td>
            <td class="text-end">${fmtNum(r.above_1000_qty)}</td>
            <td class="text-end">${fmtNum(r.above_1000_amt)}</td>
            <td class="text-end">${fmtNum(r.above_1500_qty)}</td>
            <td class="text-end">${fmtNum(r.above_1500_amt)}</td>
            <td class="text-end">${fmtNum(r.above_2000_qty)}</td>
            <td class="text-end">${fmtNum(r.above_2000_amt)}</td>
            <td class="text-end">${fmtNum(r.total_qty)}</td>
            <td class="text-end">${fmtNum(r.total_amt)}</td>
        </tr>`;
    }).join('');

    tfoot.innerHTML = `<tr>
        <td>TOTAL</td>
        <td class="text-end">${fmtNum(totals.above_1000_qty)}</td>
        <td class="text-end">${fmtNum(totals.above_1000_amt)}</td>
        <td class="text-end">${fmtNum(totals.above_1500_qty)}</td>
        <td class="text-end">${fmtNum(totals.above_1500_amt)}</td>
        <td class="text-end">${fmtNum(totals.above_2000_qty)}</td>
        <td class="text-end">${fmtNum(totals.above_2000_amt)}</td>
        <td class="text-end">${fmtNum(totals.total_qty)}</td>
        <td class="text-end">${fmtNum(totals.total_amt)}</td>
    </tr>`;
}

function renderDetail(lines) {
    const tbody = document.getElementById('detailTable').querySelector('tbody');
    document.getElementById('detailCount').textContent = lines.length + ' row' + (lines.length === 1 ? '' : 's');
    if (!lines.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">No matching lines.</td></tr>';
        return;
    }
    tbody.innerHTML = lines.map(l => {
        const slabs = slabLabelsForPrice(l.price);
        return `<tr>
            <td>${escapeHtml(l.voucher_date || l.voucher_date_raw || '')}</td>
            <td>${escapeHtml(l.voucher_no || '')}</td>
            <td>${escapeHtml(l.particulars || '')}</td>
            <td>${escapeHtml(l.item_details || '')}</td>
            <td>${escapeHtml(l.material_centre || '')}</td>
            <td class="text-end">${fmtNum(l.qty)}</td>
            <td>${escapeHtml(l.unit || '')}</td>
            <td class="text-end">${fmtNum(l.price)}</td>
            <td class="text-end">${fmtNum(l.amount)}</td>
            <td><span class="badge text-bg-light border">${escapeHtml(slabs)}</span></td>
        </tr>`;
    }).join('');
}

function exportCurrent() {
    if (!currentUploadId) return;
    window.location.href = '/api/tds/uploads/' + currentUploadId + '/export';
}

async function deleteCurrent() {
    if (!currentUploadId) return;
    if (!confirm('Delete this TDS upload and all classified lines?')) return;
    try {
        const res = await fetch('/api/tds/uploads/' + currentUploadId, {
            method: 'DELETE',
            headers: { 'X-CSRF-Token': csrfToken, 'Content-Type': 'application/json' }
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Delete failed');
        showTdsSuccess('Upload deleted.');
        currentUploadId = null;
        await loadUploads();
    } catch (e) {
        showTdsError(e.message || 'Delete failed');
    }
}

document.getElementById('tdsUploadForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const fileInput = document.getElementById('tdsFile');
    if (!fileInput.files || !fileInput.files[0]) {
        showTdsError('Please select a file');
        return;
    }
    showTdsError('');
    showTdsSuccess('');
    const btn = document.getElementById('btnUploadSubmit');
    btn.disabled = true;

    const formData = new FormData();
    formData.append('file', fileInput.files[0]);

    try {
        const res = await fetch('/api/tds/upload', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Import failed');

        const modal = bootstrap.Modal.getInstance(document.getElementById('tdsUploadModal'));
        if (modal) modal.hide();
        fileInput.value = '';
        showTdsSuccess(`Imported ${data.rows_imported} lines` + (data.period_label ? ` (${data.period_label})` : '') + '.');
        await loadUploads(data.upload_id);
    } catch (err) {
        showTdsError(err.message || 'Import failed');
    } finally {
        btn.disabled = false;
    }
});

document.addEventListener('DOMContentLoaded', () => loadUploads());
</script>
