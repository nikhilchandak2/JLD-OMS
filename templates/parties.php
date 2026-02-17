<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-building me-2"></i>Party Management
            </h1>
            <p class="page-subtitle">Manage customer and supplier information</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#partyModal" onclick="openPartyModal()">
            <i class="bi bi-plus-circle me-1"></i> Add New Party
        </button>
    </div>
</div>

<!-- Parties Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-2"></i>All Parties
    </div>
    <div class="card-body">
        <div class="table-responsive">
                        <table class="table table-striped" id="partiesTable">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Contact Person</th>
                                    <th>Phone</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Data loaded via JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Party Modal -->
<div class="modal fade" id="partyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="partyModalTitle">Add New Party</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="partyForm">
                <div class="modal-body">
                    <input type="hidden" id="partyId" name="id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="partyName" class="form-label">Party Name *</label>
                                <input type="text" class="form-control" id="partyName" name="name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="contactPerson" class="form-label">Contact Person *</label>
                                <input type="text" class="form-control" id="contactPerson" name="contact_person" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="phone" name="phone">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="isActive" name="is_active" checked>
                            <label class="form-check-label" for="isActive">
                                Active
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Party</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let parties = [];
let editingPartyId = null;

// Load parties on page load
document.addEventListener('DOMContentLoaded', function() {
    loadParties();
});

async function loadParties() {
    try {
        const response = await fetch('/api/parties');
        const result = await response.json();
        
        if (result.success) {
            parties = result.data;
            renderPartiesTable();
        } else {
            showAlert('Error loading parties: ' + result.error, 'danger');
        }
    } catch (error) {
        showAlert('Error loading parties: ' + error.message, 'danger');
    }
}

function renderPartiesTable() {
    const tbody = document.querySelector('#partiesTable tbody');
    tbody.innerHTML = '';
    
    parties.forEach(party => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(party.name)}</td>
            <td>${escapeHtml(party.contact_person || '')}</td>
            <td>${escapeHtml(party.phone || '')}</td>
            <td>${escapeHtml(party.email || '')}</td>
            <td>
                <span class="badge ${party.is_active ? 'bg-success' : 'bg-secondary'}">
                    ${party.is_active ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td>
                <button class="btn btn-sm btn-outline-primary me-1" onclick="editParty(${party.id})">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteParty(${party.id})">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function openPartyModal(partyId = null) {
    editingPartyId = partyId;
    const modal = document.getElementById('partyModal');
    const form = document.getElementById('partyForm');
    const title = document.getElementById('partyModalTitle');
    
    form.reset();
    
    if (partyId) {
        title.textContent = 'Edit Party';
        const party = parties.find(p => p.id === partyId);
        if (party) {
            document.getElementById('partyId').value = party.id;
            document.getElementById('partyName').value = party.name;
            document.getElementById('contactPerson').value = party.contact_person || '';
            document.getElementById('phone').value = party.phone || '';
            document.getElementById('email').value = party.email || '';
            document.getElementById('address').value = party.address || '';
            document.getElementById('isActive').checked = party.is_active;
        }
    } else {
        title.textContent = 'Add New Party';
        document.getElementById('isActive').checked = true;
    }
}

function editParty(partyId) {
    openPartyModal(partyId);
    new bootstrap.Modal(document.getElementById('partyModal')).show();
}

async function deleteParty(partyId) {
    const party = parties.find(p => p.id === partyId);
    if (!party) return;
    
    if (!confirm(`Are you sure you want to delete "${party.name}"?`)) {
        return;
    }
    
    try {
        const response = await fetch(`/api/parties/${partyId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': '<?= $csrf_token ?>'
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('Party deleted successfully', 'success');
            loadParties();
        } else {
            showAlert('Error deleting party: ' + result.error, 'danger');
        }
    } catch (error) {
        showAlert('Error deleting party: ' + error.message, 'danger');
    }
}

// Handle form submission
document.getElementById('partyForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = {
        name: formData.get('name'),
        contact_person: formData.get('contact_person'),
        phone: formData.get('phone'),
        email: formData.get('email'),
        address: formData.get('address'),
        is_active: formData.has('is_active')
    };
    
    try {
        let response;
        if (editingPartyId) {
            response = await fetch(`/api/parties/${editingPartyId}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= $csrf_token ?>'
                },
                body: JSON.stringify(data)
            });
        } else {
            response = await fetch('/api/parties', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= $csrf_token ?>'
                },
                body: JSON.stringify(data)
            });
        }
        
        const result = await response.json();
        
        if (result.success) {
            showAlert(result.message, 'success');
            bootstrap.Modal.getInstance(document.getElementById('partyModal')).hide();
            loadParties();
        } else {
            showAlert('Error: ' + result.error, 'danger');
        }
    } catch (error) {
        showAlert('Error: ' + error.message, 'danger');
    }
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showAlert(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.container-fluid');
    container.insertBefore(alertDiv, container.firstChild);
    
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}
</script>
