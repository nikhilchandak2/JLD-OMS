<!-- This template is for viewing trips for a specific vehicle -->
<!-- It can be accessed via /trips/vehicle/{id} -->
<!-- Similar structure to trips.php but filtered by vehicle -->

<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-arrow-left-right me-2"></i>Vehicle Trips
            </h1>
            <p class="page-subtitle" id="vehicleInfo">Loading vehicle information...</p>
        </div>
        <a href="/trips" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to All Trips
        </a>
    </div>
</div>

<div id="error-container" class="error-message"></div>

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
const vehicleId = window.location.pathname.split('/').pop();

function loadVehicleTrips() {
    document.getElementById('loading').style.display = 'flex';
    
    fetch(`/api/trips/vehicle/${vehicleId}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('loading').style.display = 'none';
            if (data.success) {
                document.getElementById('vehicleInfo').textContent = 
                    `Trips for ${data.vehicle.vehicle_number}`;
                renderStatistics(data.statistics);
                renderTrips(data.data);
            } else {
                showError(data.error || 'Failed to load trips');
            }
        })
        .catch(e => {
            document.getElementById('loading').style.display = 'none';
            showError('Error: ' + e.message);
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
                    <h3 class="text-warning">${(stats.avg_fuel_per_trip || 0).toFixed(2)}</h3>
                    <p class="text-muted mb-0">Avg Fuel/Trip (L)</p>
                </div>
            </div>
        </div>
    `;
}

function renderTrips(trips) {
    const tbody = document.querySelector('#tripsTable tbody');
    tbody.innerHTML = '';
    
    if (trips.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center">No trips found</td></tr>';
        return;
    }
    
    trips.forEach(trip => {
        const row = document.createElement('tr');
        row.innerHTML = `
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

document.addEventListener('DOMContentLoaded', loadVehicleTrips);
</script>
