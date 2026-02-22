<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-geo-alt me-2"></i>Live Tracking
            </h1>
            <p class="page-subtitle">Real-time vehicle location tracking</p>
        </div>
        <div>
            <button class="btn btn-outline-primary me-2" id="syncBtn" onclick="syncFromWheelsEye()" title="Fetch current locations from WheelsEye API">
                <i class="bi bi-cloud-download me-1"></i> Sync from WheelsEye
            </button>
            <button class="btn btn-primary" onclick="loadTracking()">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
            <label class="ms-3">
                <input type="checkbox" id="autoRefresh" onchange="toggleAutoRefresh()"> Auto-refresh (30s)
            </label>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div class="row">
    <div class="col-md-9">
        <div class="card">
            <div class="card-body" style="height: 600px; padding: 0;">
                <div id="map" style="width: 100%; height: 100%;"></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-list-ul me-2"></i>Vehicles
            </div>
            <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                <div id="vehiclesList">
                    <div class="text-center text-muted">Loading vehicles...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
let map;
let markers = {};
let autoRefreshInterval = null;

function initMap() {
    map = L.map('map').setView([23.0225, 72.5714], 13); // Default to Gujarat, India
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
}

function loadTracking() {
    fetch('/api/tracking/live', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                updateMap(data.data);
                updateVehiclesList(data.data);
            } else {
                showError(data.error || 'Failed to load tracking data');
            }
        })
        .catch(e => {
            showError('Error loading tracking: ' + e.message);
        });
}

function syncFromWheelsEye() {
    const btn = document.getElementById('syncBtn');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Syncing...';
    fetch('/api/tracking/sync', { method: 'POST', credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showError(''); // clear any previous error
                const msg = data.synced > 0
                    ? 'Synced ' + data.synced + ' vehicle(s). Refreshing map.'
                    : (data.message || 'Sync completed. No new locations matched.');
                if (data.synced > 0) loadTracking();
                alert(msg + (data.errors && data.errors.length ? '\n\nNotes: ' + data.errors.join('; ') : ''));
            } else {
                showError(data.message || data.error || 'Sync failed');
            }
        })
        .catch(e => {
            showError('Sync failed: ' + e.message);
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        });
}

function updateMap(vehicles) {
    // Clear existing markers
    Object.values(markers).forEach(marker => map.removeLayer(marker));
    markers = {};
    
    vehicles.forEach(vehicle => {
        if (vehicle.latest_tracking && vehicle.latest_tracking.latitude && vehicle.latest_tracking.longitude) {
            const lat = vehicle.latest_tracking.latitude;
            const lng = vehicle.latest_tracking.longitude;
            
            const icon = L.divIcon({
                className: 'vehicle-marker',
                html: `<div style="background: ${getStatusColor(vehicle.status)}; width: 20px; height: 20px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>`,
                iconSize: [20, 20]
            });
            
            const marker = L.marker([lat, lng], { icon }).addTo(map);
            
            const popup = `
                <strong>${escapeHtml(vehicle.vehicle_number)}</strong><br>
                Type: ${escapeHtml(vehicle.vehicle_type)}<br>
                Speed: ${vehicle.latest_tracking.speed ? vehicle.latest_tracking.speed + ' km/h' : 'N/A'}<br>
                Status: ${escapeHtml(vehicle.status)}<br>
                Last Update: ${new Date(vehicle.latest_tracking.timestamp).toLocaleString()}
            `;
            marker.bindPopup(popup);
            
            markers[vehicle.id] = marker;
        }
    });
    
    // Fit map to show all markers
    if (Object.keys(markers).length > 0) {
        const group = new L.featureGroup(Object.values(markers));
        map.fitBounds(group.getBounds().pad(0.1));
    }
}

function updateVehiclesList(vehicles) {
    const container = document.getElementById('vehiclesList');
    
    if (vehicles.length === 0) {
        container.innerHTML = '<div class="text-center text-muted">No vehicles found. Add vehicles in the Vehicles page.</div>';
        return;
    }
    
    const withLocation = vehicles.filter(v => v.latest_tracking && v.latest_tracking.latitude);
    if (withLocation.length === 0) {
        container.innerHTML = `
            <div class="alert alert-info small mb-3">
                <strong>No location data yet.</strong><br>
                Click <strong>Sync from WheelsEye</strong> above to fetch current GPS positions.<br>
                <small>Ensure the vehicle is <strong>Active</strong> and its <strong>Vehicle number</strong> in OMS matches WheelsEye (e.g. RJ07GD5241).</small>
            </div>
            ${vehicles.map(v => vehicleListItem(v)).join('')}
        `;
        return;
    }
    
    container.innerHTML = vehicles.map(v => vehicleListItem(v)).join('');
}

function vehicleListItem(v) {
    const hasLocation = v.latest_tracking && v.latest_tracking.latitude;
    return `
        <div class="mb-3 p-2 border rounded" style="cursor: pointer;" onclick="focusVehicle(${v.id})">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong>${escapeHtml(v.vehicle_number)}</strong>
                    <span class="badge bg-${getStatusBadgeColor(v.status)} ms-2">${escapeHtml(v.status)}</span>
                </div>
            </div>
            <div class="text-muted small mt-1">
                ${hasLocation ?
                    `<i class="bi bi-geo-alt"></i> ${v.latest_tracking.speed ? v.latest_tracking.speed + ' km/h' : 'Stationary'}<br>
                     <small>${new Date(v.latest_tracking.timestamp).toLocaleString()}</small>` :
                    '<span class="text-muted">No location data</span>'
                }
            </div>
        </div>
    `;
}

function focusVehicle(id) {
    if (markers[id]) {
        map.setView(markers[id].getLatLng(), 15);
        markers[id].openPopup();
    }
}

function getStatusColor(status) {
    switch(status) {
        case 'active': return '#28a745';
        case 'maintenance': return '#ffc107';
        default: return '#6c757d';
    }
}

function getStatusBadgeColor(status) {
    switch(status) {
        case 'active': return 'success';
        case 'maintenance': return 'warning';
        default: return 'secondary';
    }
}

function toggleAutoRefresh() {
    const enabled = document.getElementById('autoRefresh').checked;
    
    if (enabled) {
        autoRefreshInterval = setInterval(loadTracking, 30000); // 30 seconds
    } else {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }
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

// Initialize map and load data
document.addEventListener('DOMContentLoaded', () => {
    initMap();
    loadTracking();
});
</script>
