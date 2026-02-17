<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-arrow-left-right me-2"></i>Trips
            </h1>
            <p class="page-subtitle">Track vehicle trips from pit to stockpile</p>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-funnel me-2"></i>Filters
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Start Date</label>
                <input type="date" class="form-control" id="filterStartDate" value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">End Date</label>
                <input type="date" class="form-control" id="filterEndDate" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Material Type</label>
                <select class="form-select" id="filterMaterial">
                    <option value="">All Materials</option>
                    <option value="ball_clay_1st_grade">Ball Clay 1st Grade</option>
                    <option value="ball_clay_2nd_grade">Ball Clay 2nd Grade</option>
                    <option value="ball_clay_3rd_grade">Ball Clay 3rd Grade</option>
                    <option value="overburden">Overburden</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" id="filterStatus">
                    <option value="">All Status</option>
                    <option value="completed">Completed</option>
                    <option value="in_progress">In Progress</option>
                </select>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" onclick="loadTrips()">
                    <i class="bi bi-search me-1"></i> Filter
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Statistics -->
<div class="row mb-4" id="statistics">
    <!-- Will be populated by JavaScript -->
</div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status"></div>
    <p>Loading trips...</p>
</div>

<!-- Trips Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="tripsTable">
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>Source</th>
                        <th>Destination</th>
                        <th>Material</th>
                        <th>Distance (km)</th>
                        <th>Duration</th>
                        <th>Fuel (L)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be populated by JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function loadTrips() {
    document.getElementById('loading').style.display = 'flex';
    
    const params = new URLSearchParams();
    if (document.getElementById('filterStartDate').value) {
        params.append('start_date', document.getElementById('filterStartDate').value);
    }
    if (document.getElementById('filterEndDate').value) {
        params.append('end_date', document.getElementById('filterEndDate').value);
    }
    if (document.getElementById('filterMaterial').value) {
        params.append('material_type', document.getElementById('filterMaterial').value);
    }
    if (document.getElementById('filterStatus').value) {
        params.append('status', document.getElementById('filterStatus').value);
    }
    
    fetch(`/api/trips?${params}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('loading').style.display = 'none';
            if (data.success) {
                renderStatistics(data.statistics);
                renderTrips(data.data);
            } else {
                showError(data.error || 'Failed to load trips');
            }
        })
        .catch(e => {
            document.getElementById('loading').style.display = 'none';
            showError('Error loading trips: ' + e.message);
        });
}

function renderStatistics(stats) {
    const container = document.getElementById('statistics');
    container.innerHTML = `
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-primary">${stats.total_trips || 0}</h3>
                    <p class="text-muted mb-0">Total Trips</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-success">${stats.completed_trips || 0}</h3>
                    <p class="text-muted mb-0">Completed</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-info">${(stats.total_distance || 0).toFixed(2)}</h3>
                    <p class="text-muted mb-0">Total Distance (km)</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-warning">${(stats.total_fuel_consumed || 0).toFixed(2)}</h3>
                    <p class="text-muted mb-0">Total Fuel (L)</p>
                </div>
            </div>
        </div>
    `;
}

function renderTrips(trips) {
    const tbody = document.querySelector('#tripsTable tbody');
    tbody.innerHTML = '';
    
    if (trips.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center">No trips found</td></tr>';
        return;
    }
    
    trips.forEach(trip => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><strong>${escapeHtml(trip.vehicle_number)}</strong></td>
            <td>${new Date(trip.start_time).toLocaleString()}</td>
            <td>${trip.end_time ? new Date(trip.end_time).toLocaleString() : '-'}</td>
            <td>${escapeHtml(trip.source_geofence_name || 'N/A')}</td>
            <td>${escapeHtml(trip.destination_geofence_name || 'N/A')}</td>
            <td>${escapeHtml(trip.material_type || '-')}</td>
            <td>${trip.distance_km ? trip.distance_km.toFixed(2) : '-'}</td>
            <td>${trip.duration_minutes ? trip.duration_minutes + ' min' : '-'}</td>
            <td>${trip.fuel_consumed_liters ? trip.fuel_consumed_liters.toFixed(2) : '-'}</td>
            <td><span class="badge bg-${trip.status === 'completed' ? 'success' : trip.status === 'in_progress' ? 'warning' : 'secondary'}">${escapeHtml(trip.status)}</span></td>
        `;
        tbody.appendChild(row);
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showError(msg) {
    const container = document.getElementById('error-container');
    container.textContent = msg;
    container.style.display = 'block';
    setTimeout(() => container.style.display = 'none', 5000);
}

document.addEventListener('DOMContentLoaded', loadTrips);
</script>
