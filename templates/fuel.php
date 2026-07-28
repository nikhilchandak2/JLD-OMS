<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="page-title">
                <i class="bi bi-fuel-pump me-2"></i>Fuel Management
            </h1>
            <p class="page-subtitle mb-0">Multi-machine monthly reports for Kobelco, JCB &amp; Dumpers</p>
        </div>
        <button type="button" class="btn btn-outline-secondary" id="btnBackCategories" style="display:none;" onclick="showCategories()">
            <i class="bi bi-arrow-left me-1"></i> All categories
        </button>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<!-- Category buttons -->
<div id="categoryView">
    <div class="row g-3 mb-2">
        <div class="col-md-4">
            <button type="button" class="fuel-cat-btn w-100" data-category="kobelco" onclick="openCategory('kobelco')">
                <span class="fuel-cat-label">Kobelco</span>
                <span class="fuel-cat-count" id="count-kobelco">—</span>
                <span class="fuel-cat-hint">machines</span>
            </button>
        </div>
        <div class="col-md-4">
            <button type="button" class="fuel-cat-btn w-100" data-category="jcb" onclick="openCategory('jcb')">
                <span class="fuel-cat-label">JCB</span>
                <span class="fuel-cat-count" id="count-jcb">—</span>
                <span class="fuel-cat-hint">machines</span>
            </button>
        </div>
        <div class="col-md-4">
            <button type="button" class="fuel-cat-btn w-100" data-category="dumpers" onclick="openCategory('dumpers')">
                <span class="fuel-cat-label">Dumpers</span>
                <span class="fuel-cat-count" id="count-dumpers">—</span>
                <span class="fuel-cat-hint">machines</span>
            </button>
        </div>
    </div>
    <p class="text-muted small mt-3 mb-0">
        Upload one monthly report per machine (or batch). Re-uploading the same dates updates existing data.
    </p>
</div>

<!-- Category detail -->
<div id="detailView" style="display:none;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h2 class="h4 mb-0" id="detailTitle">Machines</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
            <i class="bi bi-upload me-1"></i> Upload report
        </button>
    </div>

    <div class="fuel-toolbar card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-sm-4 col-md-3">
                    <label class="form-label small mb-1" for="monthFilter">Report month</label>
                    <select class="form-select" id="monthFilter" onchange="onMonthFilterChange()">
                        <option value="all">All months</option>
                    </select>
                </div>
                <div class="col-sm-8 col-md-5">
                    <label class="form-label small mb-1" for="machineFilter">Machine</label>
                    <select class="form-select" id="machineFilter" onchange="filterMachineRows()">
                        <option value="all">All machines</option>
                    </select>
                </div>
                <div class="col-md-4 text-md-end">
                    <span class="text-muted small" id="filterHint">Showing all uploaded months</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3" id="summaryCards">
        <div class="col-6 col-md-3">
            <div class="fuel-stat">
                <div class="fuel-stat-label">Machines</div>
                <div class="fuel-stat-value" id="sumMachines">0</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="fuel-stat">
                <div class="fuel-stat-label">Months</div>
                <div class="fuel-stat-value" id="sumMonths">0</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="fuel-stat">
                <div class="fuel-stat-label">Total fuel (L)</div>
                <div class="fuel-stat-value" id="sumFuel">0</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="fuel-stat">
                <div class="fuel-stat-label">Total hours</div>
                <div class="fuel-stat-value" id="sumHours">0</div>
            </div>
        </div>
    </div>

    <div id="detailLoading" class="loading" style="display:none;">
        <div class="spinner-border" role="status"></div>
        <p>Loading machines...</p>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-list-ul me-2"></i>Machines <span class="badge bg-primary" id="machineCountBadge">0</span></span>
            <span class="small text-muted" id="periodLabel">All-time totals</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="machinesTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Machine</th>
                            <th>Serial No.</th>
                            <th>Chassis No.</th>
                            <th>Months</th>
                            <th>Last date</th>
                            <th>Days</th>
                            <th>Fuel (L)</th>
                            <th>Hours</th>
                            <th>Avg L/h</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="11" class="text-center text-muted py-4">No machines yet. Upload a monthly report.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="bi bi-clock-history me-2"></i>Upload history</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0" id="uploadsTable">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Type</th>
                            <th>Report month</th>
                            <th>Machines</th>
                            <th>Days saved</th>
                            <th>Uploaded on</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="7" class="text-center text-muted py-3">No uploads yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Upload modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadModalLabel">Upload monthly report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="uploadForm">
                <div class="modal-body">
                    <p class="small text-muted mb-3" id="uploadHint">
                        Each file is typically one machine for one month (e.g. Kobelco EquipOperationReport).
                        Upload repeatedly for more machines / months. Same dates are replaced on re-upload.
                    </p>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-control" id="uploadCategoryLabel" readonly>
                        <input type="hidden" id="uploadCategory" name="category">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="reportFile">Report file</label>
                        <input type="file" class="form-control" id="reportFile" accept=".xlsx,.xls,.csv,.pdf,.ods" required>
                    </div>
                    <div id="uploadResult" class="small" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnUpload" disabled>
                        <i class="bi bi-upload me-1"></i> Upload
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Readings modal -->
<div class="modal fade" id="readingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="readingsModalTitle">Daily readings</h5>
                    <div class="small text-muted" id="readingsModalMeta"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
                    <div>
                        <label class="form-label small mb-1" for="readingsMonth">Month</label>
                        <select class="form-select form-select-sm" id="readingsMonth" style="min-width: 11rem;" onchange="onReadingsMonthChange()"></select>
                    </div>
                    <div class="ms-auto small text-muted" id="readingsMonthHint"></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0" id="readingsTable">
                        <thead id="readingsHead">
                            <tr>
                                <th>Date</th>
                                <th>Fuel</th>
                                <th>Working Time</th>
                                <th>Avg</th>
                            </tr>
                        </thead>
                        <tbody id="readingsBody"></tbody>
                        <tfoot id="readingsFoot" style="display:none;"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.fuel-cat-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.25rem;
    min-height: 140px;
    padding: 1.5rem 1rem;
    border: 2px solid var(--jld-primary, #2b235e);
    border-radius: var(--jld-radius, 0.75rem);
    background: var(--jld-white, #fff);
    color: var(--jld-primary, #2b235e);
    transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
    cursor: pointer;
}
.fuel-cat-btn:hover,
.fuel-cat-btn:focus {
    background: var(--jld-primary, #2b235e);
    color: #fff;
    box-shadow: var(--jld-shadow-lg, 0 0.5rem 1rem rgba(43, 35, 94, 0.15));
}
.fuel-cat-label { font-size: 1.35rem; font-weight: 600; letter-spacing: 0.02em; }
.fuel-cat-count { font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
.fuel-cat-hint { font-size: 0.8rem; opacity: 0.75; text-transform: uppercase; letter-spacing: 0.04em; }
.fuel-stat {
    background: #fff;
    border: 1px solid var(--jld-border, #e9ecef);
    border-radius: var(--jld-radius-sm, 0.5rem);
    padding: 0.85rem 1rem;
    height: 100%;
    text-align: center;
}
.fuel-stat-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--jld-gray, #6c757d); }
.fuel-stat-value { font-size: 1.35rem; font-weight: 700; color: var(--jld-primary, #2b235e); margin-top: 0.15rem; }
.fuel-toolbar { border: 1px solid var(--jld-border, #e9ecef); }
#machinesTable th,
#machinesTable td,
#uploadsTable th,
#uploadsTable td,
#readingsTable th,
#readingsTable td {
    text-align: center;
    vertical-align: middle;
}
</style>

<script>
const CATEGORY_LABELS = { kobelco: 'Kobelco', jcb: 'JCB', dumpers: 'Dumpers' };
const MONTH_NAMES = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
let currentCategory = null;
let currentMonthFilter = 'all';
let currentMachineId = null;
let machinesCache = [];

function clearAlerts() {
    showError('');
    const ok = document.getElementById('success-container');
    if (ok) {
        ok.innerHTML = '';
        ok.style.display = 'none';
    }
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

function fmtNum(v) {
    if (v === null || v === undefined || v === '') return '—';
    const n = Number(v);
    return Number.isFinite(n)
        ? n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })
        : '—';
}

/** Round numeric part to max 2 decimals; keep HH:MM and unit suffixes (L, L/h). */
function fmtDec2(v) {
    if (v === null || v === undefined || v === '') return '—';
    const s = String(v).trim();
    if (/^\d{1,4}:\d{2}(:\d{2})?$/.test(s)) return s;
    const m = s.match(/^(-?\d+(?:\.\d+)?)(.*)$/);
    if (!m) return s;
    const n = Number(m[1]);
    if (!Number.isFinite(n)) return s;
    const rounded = Number((Math.round(n * 100) / 100).toFixed(2));
    return String(rounded) + (m[2] || '');
}


/** YYYY-MM-DD or Date-like → DD-MM-YYYY */
function fmtDate(v) {
    if (v == null || v === '') return '—';
    const s = String(v).trim();
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return m[3] + '-' + m[2] + '-' + m[1];
    const d = new Date(s);
    if (!Number.isNaN(d.getTime())) {
        const dd = String(d.getDate()).padStart(2, '0');
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        return dd + '-' + mm + '-' + d.getFullYear();
    }
    return s;
}

/** YYYY-MM → Jun 2026 */
function fmtMonthLabel(ym) {
    if (!ym || !/^\d{4}-\d{2}$/.test(ym)) return ym || '—';
    const [y, m] = ym.split('-');
    return MONTH_NAMES[Number(m) - 1] + ' ' + y;
}

function showCategories() {
    currentCategory = null;
    currentMonthFilter = 'all';
    document.getElementById('categoryView').style.display = 'block';
    document.getElementById('detailView').style.display = 'none';
    document.getElementById('btnBackCategories').style.display = 'none';
    clearAlerts();
    loadCategoryCounts();
}

function openCategory(category) {
    currentCategory = category;
    currentMonthFilter = 'all';
    document.getElementById('categoryView').style.display = 'none';
    document.getElementById('detailView').style.display = 'block';
    document.getElementById('btnBackCategories').style.display = 'inline-flex';
    document.getElementById('detailTitle').textContent = CATEGORY_LABELS[category];
    document.getElementById('uploadCategory').value = category;
    document.getElementById('uploadCategoryLabel').value = CATEGORY_LABELS[category];
    const hints = {
        kobelco: 'Upload Kobelco EquipOperationReport (.xls) — one machine per file. Same dates are replaced on re-upload.',
        jcb: 'Upload JCB DI_*_Report (.xlsx). Chassis/Asset ID identifies the machine. Same dates are replaced on re-upload.',
        dumpers: 'Upload Dumpers Fleet_Report_Details (.xlsx). Reg No identifies each vehicle; one file may include multiple dumpers. Same dates are replaced on re-upload.'
    };
    const hintEl = document.getElementById('uploadHint');
    if (hintEl) hintEl.textContent = hints[category] || hints.kobelco;
    document.getElementById('machineFilter').innerHTML = '<option value="all">All machines</option>';
    document.getElementById('machineFilter').value = 'all';
    document.getElementById('monthFilter').value = 'all';
    clearAlerts();
    loadMachines();
}

function loadCategoryCounts() {
    fetch('/api/fuel/categories')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const counts = data.data || {};
            ['kobelco', 'jcb', 'dumpers'].forEach(cat => {
                const el = document.getElementById('count-' + cat);
                if (el) el.textContent = counts[cat] != null ? counts[cat] : 0;
            });
        })
        .catch(() => {});
}

function onMonthFilterChange() {
    currentMonthFilter = document.getElementById('monthFilter').value || 'all';
    loadMachines();
}

function loadMachines() {
    if (!currentCategory) return;
    const loading = document.getElementById('detailLoading');
    loading.style.display = 'flex';
    const monthParam = currentMonthFilter !== 'all' ? ('&month=' + encodeURIComponent(currentMonthFilter)) : '';
    fetch('/api/fuel/machines?category=' + encodeURIComponent(currentCategory) + monthParam)
        .then(r => r.json())
        .then(data => {
            loading.style.display = 'none';
            if (!data.success) {
                showError(data.error || 'Failed to load machines');
                return;
            }
            populateMonthFilter(data.months || [], currentMonthFilter);
            renderSummary(data.summary || {}, currentMonthFilter);
            machinesCache = data.data || [];
            populateMachineFilter(machinesCache);
            renderMachines(machinesCache);
            renderUploads(data.uploads || []);
            document.getElementById('machineCountBadge').textContent = machinesCache.length;
            document.getElementById('periodLabel').textContent =
                currentMonthFilter === 'all' ? 'All-time totals across months' : ('Totals for ' + fmtMonthLabel(currentMonthFilter));
            document.getElementById('filterHint').textContent =
                currentMonthFilter === 'all'
                    ? ((data.months || []).length + ' month(s) of reports available')
                    : ('Filtered to ' + fmtMonthLabel(currentMonthFilter));
            filterMachineRows();
        })
        .catch(e => {
            loading.style.display = 'none';
            showError('Error loading machines: ' + e.message);
        });
}

function populateMonthFilter(months, selected) {
    const sel = document.getElementById('monthFilter');
    const prev = selected || sel.value || 'all';
    sel.innerHTML = '<option value="all">All months</option>' +
        months.map(ym => '<option value="' + escapeHtml(ym) + '">' + escapeHtml(fmtMonthLabel(ym)) + '</option>').join('');
    sel.value = months.includes(prev) || prev === 'all' ? prev : 'all';
    currentMonthFilter = sel.value;
}

function renderSummary(summary, month) {
    document.getElementById('sumMachines').textContent = summary.machine_count != null ? summary.machine_count : 0;
    document.getElementById('sumMonths').textContent = month && month !== 'all' ? 1 : (summary.months_count != null ? summary.months_count : 0);
    document.getElementById('sumFuel').textContent = fmtNum(summary.total_fuel_liters);
    document.getElementById('sumHours').textContent = fmtNum(summary.total_working_hours);
}

function populateMachineFilter(machines) {
    const sel = document.getElementById('machineFilter');
    const prev = sel.value || 'all';
    const opts = ['<option value="all">All machines</option>'];
    machines.forEach(m => {
        const labelParts = [m.name, m.serial_no, m.chassis_no].filter(Boolean);
        const label = labelParts.length ? labelParts.join(' · ') : ('Machine #' + m.id);
        opts.push('<option value="' + Number(m.id) + '">' + escapeHtml(label) + '</option>');
    });
    sel.innerHTML = opts.join('');
    const ids = machines.map(m => String(m.id));
    sel.value = (prev !== 'all' && ids.includes(String(prev))) ? String(prev) : 'all';
}

function renderMachines(machines) {
    const tbody = document.querySelector('#machinesTable tbody');
    if (!machines.length) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">No machines for this filter. Upload a monthly report.</td></tr>';
        return;
    }
    tbody.innerHTML = machines.map((m, i) => {
        const label = m.name || m.serial_no || m.chassis_no || 'Machine';
        return `
        <tr data-machine-id="${Number(m.id)}">
            <td>${i + 1}</td>
            <td class="fw-medium">${escapeHtml(m.name || '—')}</td>
            <td>${escapeHtml(m.serial_no || '—')}</td>
            <td>${escapeHtml(m.chassis_no || '—')}</td>
            <td>${escapeHtml(m.months_count || 0)}</td>
            <td>${fmtDate(m.last_reading_date)}</td>
            <td>${escapeHtml(m.reading_count || 0)}</td>
            <td>${fmtNum(m.total_fuel_liters)}</td>
            <td>${fmtNum(m.total_working_hours)}</td>
            <td>${fmtNum(m.avg_usage)}</td>
            <td>
                <button type="button" class="btn btn-sm btn-outline-primary"
                    data-machine-id="${Number(m.id)}"
                    data-machine-label="${escapeHtml(label)}"
                    onclick="viewReadings(Number(this.dataset.machineId), this.dataset.machineLabel)">
                    View days
                </button>
            </td>
        </tr>`;
    }).join('');
}

function filterMachineRows() {
    const selected = (document.getElementById('machineFilter').value || 'all');
    const rows = document.querySelectorAll('#machinesTable tbody tr[data-machine-id]');
    let visible = 0;
    rows.forEach(tr => {
        const id = tr.getAttribute('data-machine-id') || '';
        const show = selected === 'all' || id === String(selected);
        tr.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    if (rows.length) {
        document.getElementById('machineCountBadge').textContent = visible;
    }
}

function renderUploads(uploads) {
    const tbody = document.querySelector('#uploadsTable tbody');
    if (!uploads.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No uploads yet.</td></tr>';
        return;
    }
    tbody.innerHTML = uploads.map(u => {
        const monthLabel = u.report_month ? fmtMonthLabel(String(u.report_month).slice(0, 7)) : '—';
        const uploaded = u.created_at ? fmtDate(String(u.created_at).slice(0, 10)) : '—';
        const fileLabel = u.original_filename || ('upload #' + u.id);
        return `
        <tr>
            <td>${escapeHtml(u.original_filename)}</td>
            <td>${escapeHtml((u.file_type || '').toUpperCase())}</td>
            <td>${escapeHtml(monthLabel)}</td>
            <td>${escapeHtml(u.machines_found)}</td>
            <td>${escapeHtml(u.readings_saved)}</td>
            <td>${escapeHtml(uploaded)}${u.uploaded_by_name ? ' · ' + escapeHtml(u.uploaded_by_name) : ''}</td>
            <td>
                <button type="button" class="btn btn-sm btn-outline-danger"
                    data-upload-id="${Number(u.id)}"
                    data-upload-name="${escapeHtml(fileLabel)}"
                    onclick="deleteUpload(Number(this.dataset.uploadId), this.dataset.uploadName)">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </td>
        </tr>`;
    }).join('');
}

async function deleteUpload(uploadId, fileName) {
    if (!uploadId) return;
    const label = fileName || ('upload #' + uploadId);
    if (!confirm('Delete "' + label + '" and all daily readings imported from this file?')) {
        return;
    }
    try {
        const data = await apiCall('/api/fuel/reports/' + uploadId, { method: 'DELETE' });
        showSuccess('Deleted ' + label
            + (data.readings_deleted != null ? (' (' + data.readings_deleted + ' day(s) removed)') : '')
            + '.');
        loadMachines();
        loadCategoryCounts();
    } catch (err) {
        showError(err.message || 'Failed to delete upload');
    }
}

function viewReadings(machineId, label) {
    currentMachineId = machineId;
    document.getElementById('readingsModalTitle').textContent = label;
    document.getElementById('readingsModalMeta').textContent = '';
    document.getElementById('readingsBody').innerHTML = '<tr><td colspan="4" class="text-muted text-center">Loading...</td></tr>';
    document.getElementById('readingsFoot').style.display = 'none';
    document.getElementById('readingsMonth').innerHTML = '';
    const preferredMonth = currentMonthFilter !== 'all' ? currentMonthFilter : '';
    const modal = new bootstrap.Modal(document.getElementById('readingsModal'));
    modal.show();
    fetchReadings(machineId, preferredMonth);
}

function onReadingsMonthChange() {
    if (!currentMachineId) return;
    fetchReadings(currentMachineId, document.getElementById('readingsMonth').value);
}

function fetchReadings(machineId, month) {
    let url = '/api/fuel/machines/' + machineId + '/readings';
    if (month) url += '?month=' + encodeURIComponent(month);
    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('readingsBody').innerHTML =
                    '<tr><td colspan="4" class="text-danger text-center">' + escapeHtml(data.error || 'Failed') + '</td></tr>';
                return;
            }
            const machine = data.machine || {};
            const meta = [
                machine.serial_no ? 'Serial: ' + machine.serial_no : null,
                machine.chassis_no ? 'Chassis: ' + machine.chassis_no : null,
            ].filter(Boolean).join(' · ');
            document.getElementById('readingsModalMeta').textContent = meta;

            const months = data.months || [];
            const sel = document.getElementById('readingsMonth');
            const activeMonth = data.month || (months[0] || '');
            sel.innerHTML = months.length
                ? months.map(ym => '<option value="' + escapeHtml(ym) + '">' + escapeHtml(fmtMonthLabel(ym)) + '</option>').join('')
                : '<option value="">No months</option>';
            if (activeMonth) sel.value = activeMonth;

            document.getElementById('readingsMonthHint').textContent =
                months.length > 1 ? (months.length + ' months on file — pick one to view') : '';

            renderReadingRows(data.data || []);
        })
        .catch(e => {
            document.getElementById('readingsBody').innerHTML =
                '<tr><td colspan="4" class="text-danger text-center">' + escapeHtml(e.message) + '</td></tr>';
            document.getElementById('readingsFoot').style.display = 'none';
        });
}

function decimalHoursToHhmm(hours) {
    const h = Number(hours);
    if (!Number.isFinite(h)) return '—';
    const totalMinutes = Math.round(h * 60);
    const hh = Math.floor(totalMinutes / 60);
    const mm = String(totalMinutes % 60).padStart(2, '0');
    return hh + ':' + mm;
}

function decimalHoursToHhmmss(hours) {
    const h = Number(hours);
    if (!Number.isFinite(h)) return '—';
    const totalSeconds = Math.round(h * 3600);
    const hh = Math.floor(totalSeconds / 3600);
    const mm = String(Math.floor((totalSeconds % 3600) / 60)).padStart(2, '0');
    const ss = String(totalSeconds % 60).padStart(2, '0');
    return hh + ':' + mm + ':' + ss;
}

function renderReadingRows(rows) {
    const tbody = document.getElementById('readingsBody');
    const thead = document.getElementById('readingsHead');
    const tfoot = document.getElementById('readingsFoot');
    if (!rows.length) {
        thead.innerHTML = '<tr><th>Date</th><th>Fuel</th><th>Working Time</th><th>Avg</th></tr>';
        tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center">No daily readings for this month.</td></tr>';
        tfoot.style.display = 'none';
        tfoot.innerHTML = '';
        return;
    }

    const isJcb = rows.some(r => (r.vendor || (r.extra && r.extra.vendor) || '') === 'jcb'
        || r.engine_on_display != null || (r.distance_display != null && (r.vendor || '') === 'jcb'));
    const isDumpers = rows.some(r => (r.vendor || (r.extra && r.extra.vendor) || '') === 'dumpers'
        || r.mileage_display != null || r.idle_fuel_display != null || r.halt_display != null);

    if (isDumpers) {
        thead.innerHTML = `
            <tr>
                <th>Date</th>
                <th>Fuel Consumed(ltr)</th>
                <th>Running Time</th>
                <th>Distance Covered(KM)</th>
                <th>Mileage</th>
                <th>Idling Fuel(ltr)</th>
                <th>Idling Time</th>
            </tr>`;
        tbody.innerHTML = rows.map(r => {
            const fuel = r.fuel_display != null && r.fuel_display !== '' ? r.fuel_display : (r.fuel_consumed_liters != null ? String(r.fuel_consumed_liters) : '—');
            const hours = r.working_hrs_display != null && r.working_hrs_display !== '' ? r.working_hrs_display : (r.working_hours != null ? String(r.working_hours) : '—');
            const dist = r.distance_display != null && r.distance_display !== '' ? r.distance_display : '—';
            const mileage = r.mileage_display != null && r.mileage_display !== ''
                ? r.mileage_display
                : (r.avg_display != null && r.avg_display !== '' ? r.avg_display : '—');
            const idleFuel = r.idle_fuel_display != null && r.idle_fuel_display !== '' ? r.idle_fuel_display : '—';
            const idle = r.idle_display != null && r.idle_display !== '' ? r.idle_display : '—';
            return `<tr>
                <td>${fmtDate(r.reading_date)}</td>
                <td>${escapeHtml(fmtDec2(fuel))}</td>
                <td>${escapeHtml(fmtDec2(hours))}</td>
                <td>${escapeHtml(fmtDec2(dist))}</td>
                <td>${escapeHtml(fmtDec2(mileage))}</td>
                <td>${escapeHtml(fmtDec2(idleFuel))}</td>
                <td>${escapeHtml(fmtDec2(idle))}</td>
            </tr>`;
        }).join('');
    } else if (isJcb) {
        thead.innerHTML = `
            <tr>
                <th>Date</th>
                <th>FuelUsedInWorking</th>
                <th>Working Time</th>
                <th>EngineOn Time</th>
                <th>Idle Time</th>
                <th>DistanceTravelledInRoading</th>
                <th>Avg</th>
            </tr>`;
        tbody.innerHTML = rows.map(r => {
            const fuel = r.fuel_display != null && r.fuel_display !== '' ? r.fuel_display : (r.fuel_consumed_liters != null ? String(r.fuel_consumed_liters) : '—');
            const hours = r.working_hrs_display != null && r.working_hrs_display !== '' ? r.working_hrs_display : (r.working_hours != null ? String(r.working_hours) : '—');
            const engine = r.engine_on_display != null && r.engine_on_display !== '' ? r.engine_on_display : '—';
            const idle = r.idle_display != null && r.idle_display !== '' ? r.idle_display : '—';
            const dist = r.distance_display != null && r.distance_display !== '' ? r.distance_display : '—';
            const avg = r.avg_display != null && r.avg_display !== '' ? r.avg_display : '—';
            return `<tr>
                <td>${fmtDate(r.reading_date)}</td>
                <td>${escapeHtml(fmtDec2(fuel))}</td>
                <td>${escapeHtml(fmtDec2(hours))}</td>
                <td>${escapeHtml(fmtDec2(engine))}</td>
                <td>${escapeHtml(fmtDec2(idle))}</td>
                <td>${escapeHtml(fmtDec2(dist))}</td>
                <td>${escapeHtml(fmtDec2(avg))}</td>
            </tr>`;
        }).join('');
    } else {
        thead.innerHTML = `
            <tr>
                <th>Date</th>
                <th>Total Fuel Consump.</th>
                <th>Working Hrs</th>
                <th>Ave. Fuel Consump.</th>
            </tr>`;
        tbody.innerHTML = rows.map(r => {
            const fuel = r.fuel_display || (r.fuel_consumed_liters != null ? (fmtNum(r.fuel_consumed_liters) + ' L') : '—');
            const hours = r.working_hrs_display || decimalHoursToHhmm(r.working_hours);
            const avg = r.avg_display || (r.average_usage != null ? (fmtNum(r.average_usage) + ' L/h') : '—');
            return `<tr>
                <td>${fmtDate(r.reading_date)}</td>
                <td>${escapeHtml(fmtDec2(fuel))}</td>
                <td>${escapeHtml(fmtDec2(hours))}</td>
                <td>${escapeHtml(fmtDec2(avg))}</td>
            </tr>`;
        }).join('');
    }

    let totalFuel = 0, totalHours = 0, totalEngine = 0, totalIdle = 0, totalDist = 0, totalIdleFuel = 0;
    let hasEngine = false, hasIdle = false, hasDist = false, hasIdleFuel = false;
    rows.forEach(r => {
        const f = Number(r.fuel_consumed_liters);
        const h = Number(r.working_hours);
        if (Number.isFinite(f)) totalFuel += f;
        if (Number.isFinite(h)) totalHours += h;
        const eng = Number(r.extra && r.extra.engine_on_hours);
        const idle = Number(r.extra && r.extra.idle_hours);
        const dist = Number(r.extra && (r.extra.distance_km != null ? r.extra.distance_km : r.extra.distance_roading));
        const idleFuel = Number(r.extra && r.extra.idle_fuel_liters);
        if (Number.isFinite(eng)) { totalEngine += eng; hasEngine = true; }
        if (Number.isFinite(idle)) { totalIdle += idle; hasIdle = true; }
        if (Number.isFinite(dist)) { totalDist += dist; hasDist = true; }
        if (Number.isFinite(idleFuel)) { totalIdleFuel += idleFuel; hasIdleFuel = true; }
    });
    const overallAvg = totalHours > 0 ? totalFuel / totalHours : null;
    const overallMileage = (hasDist && totalFuel > 0) ? (totalDist / totalFuel) : null;

    if (isDumpers) {
        tfoot.innerHTML = `<tr class="fw-semibold table-light">
            <td>Total</td>
            <td>${escapeHtml(fmtDec2(totalFuel))}</td>
            <td>${escapeHtml(decimalHoursToHhmmss(totalHours))}</td>
            <td>${escapeHtml(hasDist ? fmtDec2(totalDist) : '—')}</td>
            <td>${escapeHtml(overallMileage != null ? fmtDec2(overallMileage) : '—')}</td>
            <td>${escapeHtml(hasIdleFuel ? fmtDec2(totalIdleFuel) : '—')}</td>
            <td>${escapeHtml(hasIdle ? decimalHoursToHhmmss(totalIdle) : '—')}</td>
        </tr>`;
    } else if (isJcb) {
        tfoot.innerHTML = `<tr class="fw-semibold table-light">
            <td>Total</td>
            <td>${escapeHtml(fmtDec2(totalFuel))}</td>
            <td>${escapeHtml(fmtDec2(totalHours))}</td>
            <td>${escapeHtml(hasEngine ? fmtDec2(totalEngine) : '—')}</td>
            <td>${escapeHtml(hasIdle ? fmtDec2(totalIdle) : '—')}</td>
            <td>${escapeHtml(hasDist ? fmtDec2(totalDist) : '—')}</td>
            <td>${escapeHtml(overallAvg != null ? fmtDec2(overallAvg) + ' L/h' : '—')}</td>
        </tr>`;
    } else {
        tfoot.innerHTML = `<tr class="fw-semibold table-light">
            <td>Total</td>
            <td>${escapeHtml(fmtDec2(totalFuel) + ' L')}</td>
            <td>${escapeHtml(decimalHoursToHhmm(totalHours))}</td>
            <td>${escapeHtml(overallAvg != null ? fmtDec2(overallAvg) + ' L/h' : '—')}</td>
        </tr>`;
    }
    tfoot.style.display = '';
}

document.getElementById('reportFile').addEventListener('change', function() {
    document.getElementById('btnUpload').disabled = !this.files.length;
});

document.getElementById('uploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fileInput = document.getElementById('reportFile');
    const category = document.getElementById('uploadCategory').value;
    if (!fileInput.files || !fileInput.files[0] || !category) {
        showError('Select a category and file');
        return;
    }

    const btn = document.getElementById('btnUpload');
    const resultEl = document.getElementById('uploadResult');
    btn.disabled = true;
    resultEl.style.display = 'none';
    resultEl.innerHTML = '';

    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('category', category);
    if (typeof csrfToken !== 'undefined' && csrfToken) {
        formData.append('csrf_token', csrfToken);
    }

    try {
        const response = await fetch('/api/fuel/reports/upload', {
            method: 'POST',
            headers: { 'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : '' },
            body: formData
        });
        const raw = await response.text();
        let data = {};
        try {
            data = raw ? JSON.parse(raw) : {};
        } catch (parseErr) {
            resultEl.style.display = 'block';
            resultEl.className = 'small text-danger';
            resultEl.innerHTML = '<strong>Upload failed</strong> (HTTP ' + response.status + '). ' +
                'Server did not return JSON. ' + escapeHtml((raw || '').replace(/\s+/g, ' ').slice(0, 180));
            btn.disabled = false;
            return;
        }

        resultEl.style.display = 'block';
        if (!response.ok || data.success === false) {
            const errs = (data.errors && data.errors.length)
                ? data.errors
                : [data.error || data.detail || ('Import failed (HTTP ' + response.status + ')')];
            resultEl.className = 'small text-danger';
            resultEl.innerHTML = '<strong>Could not import:</strong><ul class="mb-0">' +
                errs.map(x => '<li>' + escapeHtml(x) + '</li>').join('') + '</ul>';
            if (data.detail && data.error && data.detail !== data.error) {
                resultEl.innerHTML += '<p class="mt-2 mb-0 text-muted">' + escapeHtml(data.detail) + '</p>';
            }
            btn.disabled = false;
            return;
        }

        const monthTxt = data.report_month ? fmtMonthLabel(String(data.report_month).slice(0, 7)) : '';
        resultEl.className = 'small text-success';
        resultEl.innerHTML = 'Imported <strong>' + escapeHtml(data.machines_found) + '</strong> machine(s), ' +
            '<strong>' + escapeHtml(data.readings_saved) + '</strong> day(s)' +
            (monthTxt ? ' for <strong>' + escapeHtml(monthTxt) + '</strong>' : '') + '.';
        showSuccess('Report uploaded. Add more machine/month files anytime.');
        fileInput.value = '';
        loadMachines();
        loadCategoryCounts();

        setTimeout(() => {
            const modalEl = document.getElementById('uploadModal');
            const inst = bootstrap.Modal.getInstance(modalEl);
            if (inst) inst.hide();
            resultEl.style.display = 'none';
        }, 900);
    } catch (err) {
        resultEl.style.display = 'block';
        resultEl.className = 'small text-danger';
        resultEl.textContent = 'Upload failed: ' + (err && err.message ? err.message : String(err));
        btn.disabled = false;
    }
});

document.getElementById('uploadModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('reportFile').value = '';
    document.getElementById('btnUpload').disabled = true;
    document.getElementById('uploadResult').style.display = 'none';
});

loadCategoryCounts();
</script>
