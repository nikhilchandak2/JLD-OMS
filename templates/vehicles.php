<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-truck me-2"></i>Vehicles
            </h1>
            <p class="page-subtitle">Manage vehicles, GPS devices, and fuel sensors</p>
        </div>
        <button class="btn btn-primary" onclick="showAddVehicleModal()">
            <i class="bi bi-plus-circle me-1"></i> Add Vehicle
        </button>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="success-message"></div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-funnel me-2"></i>Filters
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label for="filterStatus" class="form-label">Status</label>
                <select class="form-select" id="filterStatus">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="filterType" class="form-label">Vehicle Type</label>
                <select class="form-select" id="filterType">
                    <option value="">All Types</option>
                    <option value="dumper">Dumper</option>
                    <option value="excavator">Excavator</option>
                    <option value="loader">Loader</option>
                    <option value="truck">Truck</option>
                </select>
            </div>
            <div class="col-md-4">
                <label for="filterSearch" class="form-label">Search</label>
                <input type="text" class="form-control" id="filterSearch" placeholder="Vehicle number, registration...">
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary d-block w-100" onclick="loadVehicles()">
                    <i class="bi bi-search"></i> Filter
                </button>
            </div>
        </div>
    </div>
</div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p>Loading vehicles...</p>
</div>

<!-- Vehicles Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="vehiclesTable">
                <thead>
                    <tr>
                        <th>Vehicle Number</th>
                        <th>Type</th>
                        <th>Make/Model</th>
                        <th>GPS Device</th>
                        <th>Fuel Sensor</th>
                        <th>Status</th>
                        <th>Last Seen</th>
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

<!-- Add/Edit Vehicle Modal -->
<div class="modal fade" id="vehicleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="vehicleModalTitle">Add Vehicle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="vehicleForm">
                    <input type="hidden" id="vehicleId" name="id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Vehicle Number *</label>
                            <input type="text" class="form-control" id="vehicleNumber" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Vehicle Type *</label>
                            <select class="form-select" id="vehicleType" required>
                                <option value="dumper">Dumper</option>
                                <option value="excavator">Excavator</option>
                                <option value="loader">Loader</option>
                                <option value="truck">Truck</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Make</label>
                            <input type="text" class="form-control" id="make">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Model</label>
                            <input type="text" class="form-control" id="model">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Year</label>
                            <input type="number" class="form-control" id="year" min="1900" max="2099">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Registration Number</label>
                            <input type="text" class="form-control" id="registrationNumber">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">GPS Device IMEI</label>
                            <input type="text" class="form-control" id="gpsDeviceImei" placeholder="Auto-registered if new">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fuel Sensor ID</label>
                            <input type="text" class="form-control" id="fuelSensorId" placeholder="Auto-registered if new">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" rows="3"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveVehicle()">Save Vehicle</button>
            </div>
        </div>
    </div>
</div>

<script>
let vehicles = [];

function loadVehicles() {
    document.getElementById('loading').style.display = 'flex';
    
    const params = new URLSearchParams();
    if (document.getElementById('filterStatus').value) {
        params.append('status', document.getElementById('filterStatus').value);
    }
    if (document.getElementById('filterType').value) {
        params.append('vehicle_type', document.getElementById('filterType').value);
    }
    if (document.getElementById('filterSearch').value) {
        params.append('search', document.getElementById('filterSearch').value);
    }
    
    fetch(`/api/vehicles?${params}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('loading').style.display = 'none';
            if (data.success) {
                vehicles = data.data;
                renderVehicles();
            } else {
                showError(data.error || 'Failed to load vehicles');
            }
        })
        .catch(e => {
            document.getElementById('loading').style.display = 'none';
            showError('Error loading vehicles: ' + e.message);
        });
}

function renderVehicles() {
    const tbody = document.querySelector('#vehiclesTable tbody');
    tbody.innerHTML = '';
    
    if (vehicles.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center">No vehicles found</td></tr>';
        return;
    }
    
    vehicles.forEach(v => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><strong>${escapeHtml(v.vehicle_number)}</strong></td>
            <td><span class="badge bg-secondary">${escapeHtml(v.vehicle_type)}</span></td>
            <td>${escapeHtml(v.make || '')} ${escapeHtml(v.model || '')}</td>
            <td>${v.gps_device_imei ? '<span class="badge bg-success">' + escapeHtml(v.gps_device_imei) + '</span>' : '<span class="text-muted">None</span>'}</td>
            <td>${v.fuel_sensor_id_string ? '<span class="badge bg-info">' + escapeHtml(v.fuel_sensor_id_string) + '</span>' : '<span class="text-muted">None</span>'}</td>
            <td><span class="badge bg-${v.status === 'active' ? 'success' : v.status === 'maintenance' ? 'warning' : 'secondary'}">${escapeHtml(v.status)}</span></td>
            <td>${v.last_seen ? new Date(v.last_seen).toLocaleString() : '<span class="text-muted">Never</span>'}</td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editVehicle(${v.id})">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteVehicle(${v.id})">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function showAddVehicleModal() {
    document.getElementById('vehicleModalTitle').textContent = 'Add Vehicle';
    document.getElementById('vehicleForm').reset();
    document.getElementById('vehicleId').value = '';
    new bootstrap.Modal(document.getElementById('vehicleModal')).show();
}

function editVehicle(id) {
    const vehicle = vehicles.find(v => v.id === id);
    if (!vehicle) return;
    
    document.getElementById('vehicleModalTitle').textContent = 'Edit Vehicle';
    document.getElementById('vehicleId').value = vehicle.id;
    document.getElementById('vehicleNumber').value = vehicle.vehicle_number;
    document.getElementById('vehicleType').value = vehicle.vehicle_type;
    document.getElementById('make').value = vehicle.make || '';
    document.getElementById('model').value = vehicle.model || '';
    document.getElementById('year').value = vehicle.year || '';
    document.getElementById('registrationNumber').value = vehicle.registration_number || '';
    document.getElementById('status').value = vehicle.status;
    document.getElementById('gpsDeviceImei').value = vehicle.gps_device_imei || '';
    document.getElementById('fuelSensorId').value = vehicle.fuel_sensor_id_string || '';
    document.getElementById('notes').value = vehicle.notes || '';
    
    new bootstrap.Modal(document.getElementById('vehicleModal')).show();
}

function saveVehicle() {
    const id = document.getElementById('vehicleId').value;
    const data = {
        vehicle_number: document.getElementById('vehicleNumber').value,
        vehicle_type: document.getElementById('vehicleType').value,
        make: document.getElementById('make').value,
        model: document.getElementById('model').value,
        year: document.getElementById('year').value || null,
        registration_number: document.getElementById('registrationNumber').value,
        status: document.getElementById('status').value,
        gps_device_imei: document.getElementById('gpsDeviceImei').value,
        fuel_sensor_id: document.getElementById('fuelSensorId').value,
        notes: document.getElementById('notes').value
    };
    
    const url = id ? `/api/vehicles/${id}` : '/api/vehicles';
    const method = id ? 'PUT' : 'POST';
    
    fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?= CsrfMiddleware::getToken() ?>'
        },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('vehicleModal')).hide();
            showSuccess(id ? 'Vehicle updated successfully' : 'Vehicle created successfully');
            loadVehicles();
        } else {
            showError(data.error || 'Failed to save vehicle');
        }
    })
    .catch(e => showError('Error: ' + e.message));
}

function deleteVehicle(id) {
    if (!confirm('Are you sure you want to delete this vehicle?')) return;
    
    fetch(`/api/vehicles/${id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-Token': '<?= CsrfMiddleware::getToken() ?>'
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showSuccess('Vehicle deleted successfully');
            loadVehicles();
        } else {
            showError(data.error || 'Failed to delete vehicle');
        }
    })
    .catch(e => showError('Error: ' + e.message));
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

function showSuccess(msg) {
    const container = document.getElementById('success-container');
    container.textContent = msg;
    container.style.display = 'block';
    setTimeout(() => container.style.display = 'none', 5000);
}

// Load on page load
document.addEventListener('DOMContentLoaded', loadVehicles);
</script>
