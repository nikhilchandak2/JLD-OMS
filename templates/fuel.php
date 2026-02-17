<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-fuel-pump me-2"></i>Fuel Management
            </h1>
            <p class="page-subtitle">Monitor fuel consumption and alerts</p>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<!-- Fuel Alerts -->
<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <i class="bi bi-exclamation-triangle me-2"></i>Active Alerts
    </div>
    <div class="card-body">
        <div id="alertsList">
            <div class="text-center text-muted">Loading alerts...</div>
        </div>
    </div>
</div>

<!-- Vehicles with Fuel Data -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul me-2"></i>Vehicles
    </div>
    <div class="card-body">
        <div id="loading" class="loading">
            <div class="spinner-border" role="status"></div>
            <p>Loading fuel data...</p>
        </div>
        <div class="table-responsive">
            <table class="table table-striped" id="fuelTable">
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Current Level</th>
                        <th>Percentage</th>
                        <th>Last Reading</th>
                        <th>Status</th>
                        <th>Actions</th>
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
function loadFuelData() {
    document.getElementById('loading').style.display = 'flex';
    
    Promise.all([
        fetch('/api/fuel/vehicles').then(r => r.json()),
        fetch('/api/fuel/alerts').then(r => r.json())
    ])
    .then(([vehiclesData, alertsData]) => {
        document.getElementById('loading').style.display = 'none';
        
        if (vehiclesData.success) {
            renderVehicles(vehiclesData.data);
        }
        
        if (alertsData.success) {
            renderAlerts(alertsData.data);
        }
    })
    .catch(e => {
        document.getElementById('loading').style.display = 'none';
        showError('Error loading fuel data: ' + e.message);
    });
}

function renderAlerts(alerts) {
    const container = document.getElementById('alertsList');
    
    if (alerts.length === 0) {
        container.innerHTML = '<div class="text-center text-muted">No active alerts</div>';
        return;
    }
    
    container.innerHTML = alerts.map(alert => {
        const alertClass = {
            'low_fuel': 'danger',
            'fuel_theft': 'danger',
            'rapid_consumption': 'warning',
            'sensor_fault': 'info'
        }[alert.alert_type] || 'warning';
        
        return `
            <div class="alert alert-${alertClass} mb-2">
                <strong>${escapeHtml(alert.vehicle_number)}</strong> - 
                ${escapeHtml(alert.message)}
                <small class="text-muted ms-2">${new Date(alert.created_at).toLocaleString()}</small>
            </div>
        `;
    }).join('');
}

function renderVehicles(vehicles) {
    const tbody = document.querySelector('#fuelTable tbody');
    tbody.innerHTML = '';
    
    if (vehicles.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">No vehicles with fuel sensors</td></tr>';
        return;
    }
    
    vehicles.forEach(v => {
        const reading = v.latest_fuel_reading;
        const percentage = reading ? reading.fuel_percentage : null;
        const level = reading ? reading.fuel_level : null;
        
        let statusBadge = '<span class="badge bg-secondary">No Data</span>';
        let statusClass = '';
        
        if (percentage !== null) {
            if (percentage < 20) {
                statusBadge = '<span class="badge bg-danger">Low</span>';
                statusClass = 'table-danger';
            } else if (percentage < 50) {
                statusBadge = '<span class="badge bg-warning">Medium</span>';
                statusClass = 'table-warning';
            } else {
                statusBadge = '<span class="badge bg-success">Good</span>';
            }
        }
        
        const row = document.createElement('tr');
        row.className = statusClass;
        row.innerHTML = `
            <td><strong>${escapeHtml(v.vehicle_number)}</strong></td>
            <td>${level !== null ? level.toFixed(2) + ' L' : '-'}</td>
            <td>
                ${percentage !== null ? `
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar bg-${percentage < 20 ? 'danger' : percentage < 50 ? 'warning' : 'success'}" 
                             style="width: ${percentage}%">${percentage.toFixed(1)}%</div>
                    </div>
                ` : '-'}
            </td>
            <td>${reading ? new Date(reading.timestamp).toLocaleString() : '-'}</td>
            <td>${statusBadge}</td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="viewVehicleFuel(${v.id})">
                    <i class="bi bi-eye"></i> View Details
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function viewVehicleFuel(id) {
    window.location.href = `/fuel/vehicle/${id}`;
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

document.addEventListener('DOMContentLoaded', loadFuelData);
</script>
