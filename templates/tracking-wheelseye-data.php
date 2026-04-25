<div class="page-header">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="page-title">
                <i class="bi bi-database me-2"></i>WheelsEye Pulled Data
            </h1>
            <p class="page-subtitle">All pulled tracking payload rows stored in database (newest first).</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="/tracking">
                <i class="bi bi-arrow-left me-1"></i>Back to Live Tracking
            </a>
            <button class="btn btn-primary" id="refreshWheelsEyeDataBtn" type="button" onclick="loadWheelsEyeData(true)">
                <i class="bi bi-arrow-clockwise me-1"></i>Sync + Refresh
            </button>
        </div>
    </div>
</div>

<div id="error-container"></div>
<div id="success-container"></div>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label mb-1">Filter vehicle</label>
                <input type="text" id="vehicleFilterInput" class="form-control" placeholder="e.g. RJ07GD5241">
            </div>
            <div class="col-md-4">
                <label class="form-label mb-1">Show records</label>
                <select id="rowsLimitSelect" class="form-select">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div class="col-md-4">
                <button class="btn btn-outline-primary w-100" type="button" onclick="applyFilter()">
                    <i class="bi bi-funnel me-1"></i>Apply Filter
                </button>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-1"></i>Pulled Records History</span>
        <small id="lastUpdatedText" class="text-muted">Last updated: -</small>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Device</th>
                        <th>Timestamp</th>
                        <th>Location</th>
                        <th>Speed</th>
                        <th>Ignition</th>
                        <th>Raw WheelsEye Data</th>
                    </tr>
                </thead>
                <tbody id="wheelsEyeDataBody">
                    <tr><td colspan="7" class="text-center text-muted">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
            <small id="historyMetaText" class="text-muted">Showing 0 of 0</small>
            <div class="btn-group" role="group">
                <button type="button" id="prevPageBtn" class="btn btn-sm btn-outline-secondary" onclick="changePage(-1)">
                    <i class="bi bi-chevron-left"></i> Previous
                </button>
                <button type="button" id="nextPageBtn" class="btn btn-sm btn-outline-secondary" onclick="changePage(1)">
                    Next <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let wheelsEyeRows = [];
let historyOffset = 0;
let historyTotal = 0;
let activeFilter = '';

function setLoadingState(isLoading) {
    const btn = document.getElementById('refreshWheelsEyeDataBtn');
    if (!btn) return;
    btn.disabled = isLoading;
    btn.innerHTML = isLoading
        ? '<span class="spinner-border spinner-border-sm me-1"></span>Loading...'
        : '<i class="bi bi-arrow-clockwise me-1"></i>Sync + Refresh';
}

function formatIgnition(value) {
    if (value === null || value === undefined) return 'N/A';
    return value ? 'ON' : 'OFF';
}

function formatLocation(track) {
    if (!track || track.latitude === null || track.longitude === null) return 'N/A';
    return Number(track.latitude).toFixed(6) + ', ' + Number(track.longitude).toFixed(6);
}

function loadWheelsEyeData(syncNow) {
    setLoadingState(true);
    const run = syncNow
        ? fetch('/api/tracking/sync', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : '' }
        }).then(r => r.json())
        : Promise.resolve({ success: true });

    run
        .then(syncRes => {
            if (syncNow && !syncRes.success) {
                throw new Error(syncRes.message || syncRes.error || 'Sync failed');
            }
            if (syncNow) {
                const syncedCount = Number(syncRes.synced || 0);
                showSuccess('Sync completed. ' + syncedCount + ' row(s) inserted/updated.');
            }
            return fetchHistoryPage();
        })
        .catch(err => {
            showError(err.message || 'Failed to load data');
        })
        .finally(() => {
            setLoadingState(false);
        });
}

function fetchHistoryPage() {
    const limit = Number(document.getElementById('rowsLimitSelect').value) || 25;
    const params = new URLSearchParams({
        limit: String(limit),
        offset: String(historyOffset)
    });
    if (activeFilter) {
        params.set('vehicle', activeFilter);
    }
    const url = '/api/tracking/pulled-data?' + params.toString() + '&_=' + Date.now();
    return fetch(url, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            if (!res.success || !Array.isArray(res.data)) {
                throw new Error(res.error || 'Failed to load WheelsEye history');
            }
            wheelsEyeRows = res.data.map(row => ({
                vehicle_number: row.vehicle_number || '-',
                device_id: row.device_id || '-',
                timestamp: row.timestamp || '-',
                speed: row.speed,
                ignition: row.ignition_status,
                location: formatLocation(row),
                raw_data: row.raw_data || null
            }));
            historyTotal = Number(res.meta?.total || 0);
            renderTable();
            document.getElementById('lastUpdatedText').textContent =
                'Last updated: ' + new Date().toLocaleString();
            updatePaginationMeta(limit);
        });
}

function renderTable() {
    const body = document.getElementById('wheelsEyeDataBody');
    const filtered = wheelsEyeRows;

    if (!filtered.length) {
        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No matching data found.</td></tr>';
        return;
    }

    body.innerHTML = filtered.map(row => {
        const rawData = row.raw_data ? JSON.stringify(row.raw_data, null, 2) : 'No raw payload available';
        return `
            <tr>
                <td><strong>${escapeHtml(row.vehicle_number)}</strong></td>
                <td>${escapeHtml(String(row.device_id || '-'))}</td>
                <td>${escapeHtml(String(row.timestamp || '-'))}</td>
                <td>${escapeHtml(row.location)}</td>
                <td>${row.speed !== null && row.speed !== undefined ? escapeHtml(String(row.speed)) + ' km/h' : 'N/A'}</td>
                <td>${escapeHtml(formatIgnition(row.ignition))}</td>
                <td>
                    <details>
                        <summary>View JSON</summary>
                        <pre class="small bg-light p-2 rounded mt-2 mb-0" style="white-space: pre-wrap;">${escapeHtml(rawData)}</pre>
                    </details>
                </td>
            </tr>
        `;
    }).join('');
}

function updatePaginationMeta(limit) {
    const meta = document.getElementById('historyMetaText');
    const showingFrom = historyTotal === 0 ? 0 : historyOffset + 1;
    const showingTo = Math.min(historyOffset + wheelsEyeRows.length, historyTotal);
    meta.textContent = 'Showing ' + showingFrom + '-' + showingTo + ' of ' + historyTotal;

    const prevBtn = document.getElementById('prevPageBtn');
    const nextBtn = document.getElementById('nextPageBtn');
    prevBtn.disabled = historyOffset <= 0;
    nextBtn.disabled = (historyOffset + limit) >= historyTotal;
}

function applyFilter() {
    activeFilter = (document.getElementById('vehicleFilterInput').value || '').trim();
    historyOffset = 0;
    fetchHistoryPage().catch(err => showError(err.message || 'Failed to load filtered data'));
}

function changePage(direction) {
    const limit = Number(document.getElementById('rowsLimitSelect').value) || 25;
    const nextOffset = historyOffset + (direction * limit);
    if (nextOffset < 0) return;
    if (direction > 0 && nextOffset >= historyTotal) return;
    historyOffset = nextOffset;
    fetchHistoryPage().catch(err => showError(err.message || 'Failed to load page'));
}

document.addEventListener('DOMContentLoaded', () => {
    loadWheelsEyeData(false);
    document.getElementById('rowsLimitSelect').addEventListener('change', () => {
        historyOffset = 0;
        fetchHistoryPage().catch(err => showError(err.message || 'Failed to load data'));
    });
    document.getElementById('vehicleFilterInput').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyFilter();
        }
    });
});
</script>
