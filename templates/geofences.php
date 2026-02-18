<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-geo-fill me-2"></i>Geofences
            </h1>
            <p class="page-subtitle">Manage pit and stockpile geofences</p>
        </div>
        <button class="btn btn-primary" onclick="showAddGeofenceModal()">
            <i class="bi bi-plus-circle me-1"></i> Add Geofence
        </button>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="success-message"></div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status"></div>
    <p>Loading geofences...</p>
</div>

<!-- Geofences Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="geofencesTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Material Type</th>
                        <th>Location</th>
                        <th>Radius</th>
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

<!-- Add/Edit Geofence Modal -->
<div class="modal fade" id="geofenceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="geofenceModalTitle">Add Geofence</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="geofenceForm">
                    <input type="hidden" id="geofenceId">
                    <div class="mb-3">
                        <label class="form-label">Name *</label>
                        <input type="text" class="form-control" id="geofenceName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type *</label>
                        <select class="form-select" id="geofenceType" required>
                            <option value="pit">Pit</option>
                            <option value="stockpile">Stockpile</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3" id="materialTypeContainer" style="display: none;">
                        <label class="form-label">Material Type</label>
                        <select class="form-select" id="materialType">
                            <option value="">Select Material</option>
                            <option value="ball_clay_1st_grade">Ball Clay 1st Grade</option>
                            <option value="ball_clay_2nd_grade">Ball Clay 2nd Grade</option>
                            <option value="ball_clay_3rd_grade">Ball Clay 3rd Grade</option>
                            <option value="overburden">Overburden</option>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Latitude *</label>
                            <input type="number" step="0.00000001" class="form-control" id="latitude" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Longitude *</label>
                            <input type="number" step="0.00000001" class="form-control" id="longitude" required>
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label class="form-label">Radius (meters) *</label>
                        <input type="number" step="0.01" class="form-control" id="radiusMeters" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="isActive">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveGeofence()">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
let geofences = [];

document.getElementById('geofenceType').addEventListener('change', function() {
    document.getElementById('materialTypeContainer').style.display = 
        this.value === 'stockpile' ? 'block' : 'none';
});

function loadGeofences() {
    document.getElementById('loading').style.display = 'flex';
    
    fetch('/api/geofences')
        .then(r => r.json())
        .then(data => {
            document.getElementById('loading').style.display = 'none';
            if (data.success) {
                geofences = data.data;
                renderGeofences();
            } else {
                showError(data.error || 'Failed to load geofences');
            }
        })
        .catch(e => {
            document.getElementById('loading').style.display = 'none';
            showError('Error: ' + e.message);
        });
}

function renderGeofences() {
    const tbody = document.querySelector('#geofencesTable tbody');
    tbody.innerHTML = '';
    
    if (geofences.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center">No geofences found</td></tr>';
        return;
    }
    
    geofences.forEach(g => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><strong>${escapeHtml(g.name)}</strong></td>
            <td><span class="badge bg-${g.geofence_type === 'pit' ? 'primary' : 'info'}">${escapeHtml(g.geofence_type)}</span></td>
            <td>${escapeHtml(g.material_type || '-')}</td>
            <td>${g.latitude.toFixed(6)}, ${g.longitude.toFixed(6)}</td>
            <td>${g.radius_meters}m</td>
            <td><span class="badge bg-${g.is_active ? 'success' : 'secondary'}">${g.is_active ? 'Active' : 'Inactive'}</span></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editGeofence(${g.id})">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteGeofence(${g.id})">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function showAddGeofenceModal() {
    document.getElementById('geofenceModalTitle').textContent = 'Add Geofence';
    document.getElementById('geofenceForm').reset();
    document.getElementById('geofenceId').value = '';
    document.getElementById('materialTypeContainer').style.display = 'none';
    new bootstrap.Modal(document.getElementById('geofenceModal')).show();
}

function editGeofence(id) {
    const geofence = geofences.find(g => g.id === id);
    if (!geofence) return;
    
    document.getElementById('geofenceModalTitle').textContent = 'Edit Geofence';
    document.getElementById('geofenceId').value = geofence.id;
    document.getElementById('geofenceName').value = geofence.name;
    document.getElementById('geofenceType').value = geofence.geofence_type;
    document.getElementById('materialType').value = geofence.material_type || '';
    document.getElementById('latitude').value = geofence.latitude;
    document.getElementById('longitude').value = geofence.longitude;
    document.getElementById('radiusMeters').value = geofence.radius_meters;
    document.getElementById('isActive').value = geofence.is_active ? '1' : '0';
    document.getElementById('materialTypeContainer').style.display = 
        geofence.geofence_type === 'stockpile' ? 'block' : 'none';
    
    new bootstrap.Modal(document.getElementById('geofenceModal')).show();
}

function saveGeofence() {
    const id = document.getElementById('geofenceId').value;
    const data = {
        name: document.getElementById('geofenceName').value,
        geofence_type: document.getElementById('geofenceType').value,
        material_type: document.getElementById('geofenceType').value === 'stockpile' ? 
            document.getElementById('materialType').value : null,
        latitude: parseFloat(document.getElementById('latitude').value),
        longitude: parseFloat(document.getElementById('longitude').value),
        radius_meters: parseFloat(document.getElementById('radiusMeters').value),
        is_active: document.getElementById('isActive').value === '1' ? 1 : 0
    };
    
    const url = id ? `/api/geofences/${id}` : '/api/geofences';
    const method = id ? 'PUT' : 'POST';
    
    fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?= $csrf_token ?>'
        },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('geofenceModal')).hide();
            showSuccess(id ? 'Geofence updated' : 'Geofence created');
            loadGeofences();
        } else {
            showError(data.error || 'Failed to save geofence');
        }
    })
    .catch(e => showError('Error: ' + e.message));
}

function deleteGeofence(id) {
    if (!confirm('Are you sure you want to delete this geofence?')) return;
    
    fetch(`/api/geofences/${id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-Token': '<?= $csrf_token ?>'
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showSuccess('Geofence deleted');
            loadGeofences();
        } else {
            showError(data.error || 'Failed to delete geofence');
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

document.addEventListener('DOMContentLoaded', loadGeofences);
</script>
