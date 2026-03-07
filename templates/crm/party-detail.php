<?php $party_id = (int)($party_id ?? 0); ?>
<!-- Party CRM detail: contacts, deals, activities -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
                    <li class="breadcrumb-item active" id="partyBreadcrumb">Party #<?= $party_id ?></li>
                </ol>
            </nav>
            <h1 class="page-title mt-2" id="partyName">–</h1>
            <p class="page-subtitle mb-0" id="partyContact">Loading…</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" id="btnAddContact"><i class="bi bi-person-plus me-1"></i>Add contact</button>
            <a href="/crm/deals/new?party_id=<?= $party_id ?>" class="btn btn-success">New deal</a>
            <a href="/admin/parties" class="btn btn-outline-secondary">All parties</a>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Contacts</span>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddContact2"><i class="bi bi-plus"></i></button>
            </div>
            <div class="card-body" id="contactsList">Loading…</div>
        </div>
    </div>
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Deals</span>
                <a href="/crm/deals/new?party_id=<?= $party_id ?>" class="btn btn-sm btn-outline-primary">New deal</a>
            </div>
            <div class="card-body">
                <div id="dealsList">Loading…</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Activities</span>
        <button type="button" class="btn btn-sm btn-primary" id="btnAddActivity"><i class="bi bi-plus me-1"></i>Log activity</button>
    </div>
    <div class="card-body" id="activitiesList">Loading…</div>
</div>

<!-- Add contact modal -->
<div class="modal fade" id="contactModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="contactModalTitle">Add contact</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="contactId">
                <div class="mb-3"><label class="form-label">Name *</label><input type="text" class="form-control" id="contactName" required></div>
                <div class="mb-3"><label class="form-label">Role</label><input type="text" class="form-control" id="contactRole"></div>
                <div class="mb-3"><label class="form-label">Phone</label><input type="text" class="form-control" id="contactPhone"></div>
                <div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" id="contactEmail"></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="contactIsPrimary"><label class="form-check-label" for="contactIsPrimary">Primary contact</label></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveContact">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Add activity modal -->
<div class="modal fade" id="activityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Log activity</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <select class="form-select" id="activityType">
                        <option value="call">Call</option>
                        <option value="meeting">Meeting</option>
                        <option value="note">Note</option>
                        <option value="email">Email</option>
                    </select>
                </div>
                <div class="mb-3"><label class="form-label">Subject</label><input type="text" class="form-control" id="activitySubject"></div>
                <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" id="activityDescription" rows="3"></textarea></div>
                <div class="mb-3"><label class="form-label">Date & time</label><input type="datetime-local" class="form-control" id="activityDate"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveActivity">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
const partyId = <?= $party_id ?>;
let party = null;

document.addEventListener('DOMContentLoaded', async function() {
    if (partyId <= 0) { showError('Invalid party'); return; }
    try {
        const r = await apiCall('/api/parties/' + partyId);
        party = r.data;
        document.getElementById('partyName').textContent = party.name;
        document.getElementById('partyContact').textContent = party.contact_person || party.email || '';
        document.getElementById('partyBreadcrumb').textContent = party.name;
    } catch (e) {
        showError(e.message);
    }
    loadContacts();
    loadDeals();
    loadActivities();
});

async function loadContacts() {
    try {
        const r = await apiCall('/api/crm/parties/' + partyId + '/contacts');
        const list = r.data || [];
        const el = document.getElementById('contactsList');
        if (list.length === 0) el.innerHTML = '<p class="text-muted mb-0">No contacts. Click Add contact.</p>';
        else {
            el.innerHTML = list.map(c => `
                <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-2">
                    <div>
                        <strong>${escapeHtml(c.name)}</strong> ${c.is_primary ? '<span class="badge bg-primary ms-1">Primary</span>' : ''}
                        <br><small class="text-muted">${escapeHtml(c.role || '')} ${c.phone ? '· ' + escapeHtml(c.phone) : ''}</small>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteContact(${c.id})">Delete</button>
                </div>
            `).join('');
        }
    } catch (e) {
        document.getElementById('contactsList').innerHTML = '<p class="text-danger mb-0">Failed to load contacts.</p>';
    }
}

async function loadDeals() {
    try {
        const r = await apiCall('/api/crm/deals?party_id=' + partyId);
        const list = r.data || [];
        const el = document.getElementById('dealsList');
        if (list.length === 0) el.innerHTML = '<p class="text-muted mb-0">No deals. <a href="/crm/deals/new?party_id=' + partyId + '">Create one</a>.</p>';
        else {
            el.innerHTML = '<table class="table table-sm mb-0"><thead><tr><th>Title</th><th>Stage</th><th>Value</th><th></th></tr></thead><tbody>' +
                list.map(d => `<tr><td><a href="/crm/deals/${d.id}">${escapeHtml(d.title)}</a></td><td><span class="badge bg-secondary">${escapeHtml(d.stage)}</span></td><td>${d.value != null ? '₹' + Number(d.value).toLocaleString() : '–'}</td><td><a href="/crm/deals/${d.id}" class="btn btn-sm btn-outline-primary">View</a></td></tr>`).join('') +
                '</tbody></table>';
        }
    } catch (e) {
        document.getElementById('dealsList').innerHTML = '<p class="text-danger mb-0">Failed to load deals.</p>';
    }
}

async function loadActivities() {
    try {
        const r = await apiCall('/api/crm/activities?party_id=' + partyId);
        const list = r.data || [];
        const el = document.getElementById('activitiesList');
        if (list.length === 0) el.innerHTML = 'No activities yet.';
        else {
            el.innerHTML = list.map(a => `
                <div class="border-bottom pb-2 mb-2">
                    <strong>${escapeHtml(a.type)}</strong> ${escapeHtml(a.subject || '')}
                    <br><small class="text-muted">${a.activity_date} · ${escapeHtml(a.created_by_name || '')}</small>
                    ${a.description ? '<p class="mb-0 mt-1 small">' + escapeHtml(a.description) + '</p>' : ''}
                </div>
            `).join('');
        }
    } catch (e) {
        document.getElementById('activitiesList').innerHTML = 'Failed to load activities.';
    }
}

document.getElementById('btnAddContact').addEventListener('click', openContactModal);
document.getElementById('btnAddContact2').addEventListener('click', openContactModal);
function openContactModal() {
    document.getElementById('contactModalTitle').textContent = 'Add contact';
    document.getElementById('contactId').value = '';
    document.getElementById('contactName').value = '';
    document.getElementById('contactRole').value = '';
    document.getElementById('contactPhone').value = '';
    document.getElementById('contactEmail').value = '';
    document.getElementById('contactIsPrimary').checked = false;
    new bootstrap.Modal(document.getElementById('contactModal')).show();
}

document.getElementById('btnSaveContact').addEventListener('click', async function() {
    const name = document.getElementById('contactName').value.trim();
    if (!name) { showError('Name is required'); return; }
    const id = document.getElementById('contactId').value;
    const payload = {
        name,
        role: document.getElementById('contactRole').value.trim(),
        phone: document.getElementById('contactPhone').value.trim(),
        email: document.getElementById('contactEmail').value.trim(),
        is_primary: document.getElementById('contactIsPrimary').checked,
    };
    try {
        if (id) {
            await apiCall('/api/crm/contacts/' + id, { method: 'PUT', body: JSON.stringify(payload) });
        } else {
            await apiCall('/api/crm/parties/' + partyId + '/contacts', { method: 'POST', body: JSON.stringify(payload) });
        }
        bootstrap.Modal.getInstance(document.getElementById('contactModal')).hide();
        loadContacts();
    } catch (e) {
        showError(e.message);
    }
});

async function deleteContact(id) {
    if (!confirm('Delete this contact?')) return;
    try {
        await apiCall('/api/crm/contacts/' + id, { method: 'DELETE' });
        loadContacts();
    } catch (e) {
        showError(e.message);
    }
}

document.getElementById('btnAddActivity').addEventListener('click', function() {
    document.getElementById('activitySubject').value = '';
    document.getElementById('activityDescription').value = '';
    document.getElementById('activityDate').value = new Date().toISOString().slice(0, 16);
    new bootstrap.Modal(document.getElementById('activityModal')).show();
});

document.getElementById('btnSaveActivity').addEventListener('click', async function() {
    const payload = {
        party_id: partyId,
        type: document.getElementById('activityType').value,
        subject: document.getElementById('activitySubject').value.trim(),
        description: document.getElementById('activityDescription').value.trim(),
        activity_date: document.getElementById('activityDate').value || new Date().toISOString().slice(0, 19).replace('T', ' '),
    };
    try {
        await apiCall('/api/crm/activities', { method: 'POST', body: JSON.stringify(payload) });
        bootstrap.Modal.getInstance(document.getElementById('activityModal')).hide();
        loadActivities();
    } catch (e) {
        showError(e.message);
    }
});

function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>
