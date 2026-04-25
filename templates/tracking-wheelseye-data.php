<div class="page-header">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="page-title">
                <i class="bi bi-database me-2"></i>WheelsEye Pulled Data
            </h1>
            <p class="page-subtitle">Latest tracking payload pulled from WheelsEye for each mapped vehicle.</p>
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
                <button class="btn btn-outline-primary w-100" type="button" onclick="renderTable()">
                    <i class="bi bi-funnel me-1"></i>Apply Filter
                </button>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-1"></i>Latest Pulled Records</span>
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
    </div>
</div>

<script>
let wheelsEyeRows = [];

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
    const syncPart = syncNow ? '&sync_now=1' : '';
    const url = '/api/tracking/live?path_hours=24&path_limit=2000' + syncPart + '&_=' + Date.now();
    fetch(url, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            if (!res.success || !Array.isArray(res.data)) {
                throw new Error(res.error || 'Failed to load WheelsEye data');
            }
            wheelsEyeRows = res.data.map(vehicle => ({
                vehicle_number: vehicle.vehicle_number || '-',
                device_id: vehicle.latest_tracking?.device_id || '-',
                timestamp: vehicle.latest_tracking?.timestamp || '-',
                speed: vehicle.latest_tracking?.speed,
                ignition: vehicle.latest_tracking?.ignition_status,
                location: vehicle.latest_tracking ? formatLocation(vehicle.latest_tracking) : 'N/A',
                raw_data: vehicle.latest_tracking?.raw_data || null
            }));
            document.getElementById('lastUpdatedText').textContent =
                'Last updated: ' + new Date().toLocaleString();
            renderTable();
            if (syncNow) {
                showSuccess('Sync completed. Latest WheelsEye data loaded.');
            }
        })
        .catch(err => {
            showError(err.message || 'Failed to load data');
        })
        .finally(() => {
            setLoadingState(false);
        });
}

function renderTable() {
    const body = document.getElementById('wheelsEyeDataBody');
    const filter = (document.getElementById('vehicleFilterInput').value || '').trim().toLowerCase();
    const limit = Number(document.getElementById('rowsLimitSelect').value) || 25;
    const filtered = wheelsEyeRows
        .filter(row => !filter || row.vehicle_number.toLowerCase().includes(filter))
        .slice(0, limit);

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

document.addEventListener('DOMContentLoaded', () => {
    loadWheelsEyeData(false);
});
</script>
