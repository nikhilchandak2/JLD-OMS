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

<!-- Company profile (BRD 4.3) -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Company profile</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="btnEditProfile">Edit</button>
    </div>
    <div class="card-body" id="companyProfile">Loading…</div>
</div>

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

<!-- Samples & trials (BRD 4.4) -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Samples & trials</span>
        <button type="button" class="btn btn-sm btn-primary" id="btnAddSample"><i class="bi bi-plus me-1"></i>Add sample</button>
    </div>
    <div class="card-body" id="samplesList">Loading…</div>
</div>

<!-- Receivables & credit (BRD 4.8) -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Receivables & credit</span>
        <button type="button" class="btn btn-sm btn-primary" id="btnAddReceivable"><i class="bi bi-plus me-1"></i>Add entry</button>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-4"><strong>Outstanding:</strong> <span id="receivableOutstanding">–</span></div>
            <div class="col-md-4"><strong>Credit limit:</strong> <span id="receivableCreditLimit">–</span></div>
            <div class="col-md-4"><span id="receivableAlert" class="text-danger small"></span></div>
        </div>
        <div id="receivablesEntries">Loading…</div>
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
                        <option value="meeting">Customer Meeting</option>
                        <option value="visit">Sales Visit</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="email">Email</option>
                        <option value="note">Note</option>
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

<!-- Edit company profile modal -->
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Company profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Region</label><input type="text" class="form-control" id="profileRegion" placeholder="e.g. Morbi, Export"></div>
                    <div class="col-md-6"><label class="form-label">Product category</label><select class="form-select" id="profileProductCategory"><option value="">–</option><option value="tiles">Tiles</option><option value="sanitary">Sanitary</option><option value="tableware">Tableware</option><option value="other">Other</option></select></div>
                    <div class="col-md-6"><label class="form-label">Production capacity</label><input type="text" class="form-control" id="profileProductionCapacity"></div>
                    <div class="col-md-6"><label class="form-label">Payment terms (days)</label><input type="number" class="form-control" id="profilePaymentTermsDays" placeholder="90, 180"></div>
                    <div class="col-12"><label class="form-label">Factory locations</label><textarea class="form-control" id="profileFactoryLocations" rows="2"></textarea></div>
                    <div class="col-md-6"><label class="form-label">Credit limit (₹)</label><input type="number" class="form-control" id="profileCreditLimit" step="0.01"></div>
                    <div class="col-12"><label class="form-label">Technical notes (body formulation, clay requirements)</label><textarea class="form-control" id="profileTechnicalNotes" rows="3"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="btnSaveProfile">Save</button></div>
        </div>
    </div>
</div>

<!-- Add sample modal -->
<div class="modal fade" id="sampleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Add sample / trial</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Sample type</label><input type="text" class="form-control" id="sampleType" placeholder="e.g. Ball Clay"></div>
                <div class="mb-2"><label class="form-label">Quantity sent</label><input type="text" class="form-control" id="sampleQuantity"></div>
                <div class="row g-2 mb-2">
                    <div class="col-4"><label class="form-label">Request date</label><input type="date" class="form-control" id="sampleRequestDate"></div>
                    <div class="col-4"><label class="form-label">Dispatch date</label><input type="date" class="form-control" id="sampleDispatchDate"></div>
                    <div class="col-4"><label class="form-label">Trial date</label><input type="date" class="form-control" id="sampleTrialDate"></div>
                </div>
                <div class="mb-2"><label class="form-label">Status</label><select class="form-select" id="sampleStatus"><option value="sample_sent">Sample Sent</option><option value="trial_scheduled">Trial Scheduled</option><option value="trial_successful">Trial Successful</option><option value="trial_failed">Trial Failed</option><option value="trial_retesting">Trial Retesting</option></select></div>
                <div class="mb-2"><label class="form-label">Outcome</label><input type="text" class="form-control" id="sampleOutcome"></div>
                <div class="mb-2"><label class="form-label">Technical feedback</label><textarea class="form-control" id="sampleTechnicalFeedback" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="btnSaveSample">Save</button></div>
        </div>
    </div>
</div>

<!-- Add receivable entry modal -->
<div class="modal fade" id="receivableModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Add entry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Type</label><select class="form-select" id="receivableType"><option value="invoice">Invoice</option><option value="payment">Payment</option><option value="adjustment">Adjustment</option></select></div>
                <div class="mb-2"><label class="form-label">Amount (₹) *</label><input type="number" class="form-control" id="receivableAmount" step="0.01" required></div>
                <div class="mb-2"><label class="form-label">Date</label><input type="date" class="form-control" id="receivableDate"></div>
                <div class="mb-2"><label class="form-label">Reference</label><input type="text" class="form-control" id="receivableReference" placeholder="Invoice no. / Chq no."></div>
                <div class="mb-2"><label class="form-label">Description</label><textarea class="form-control" id="receivableDescription" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="btnSaveReceivable">Save</button></div>
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
        renderCompanyProfile();
    } catch (e) {
        showError(e.message);
    }
    loadContacts();
    loadDeals();
    loadSamples();
    loadReceivables();
    loadActivities();
});

function renderCompanyProfile() {
    if (!party) return;
    const el = document.getElementById('companyProfile');
    el.innerHTML = `
        <dl class="row mb-0">
            <dt class="col-sm-3">Region</dt><dd class="col-sm-9">${escapeHtml(party.region || '–')}</dd>
            <dt class="col-sm-3">Product category</dt><dd class="col-sm-9">${escapeHtml(party.product_category || '–')}</dd>
            <dt class="col-sm-3">Production capacity</dt><dd class="col-sm-9">${escapeHtml(party.production_capacity || '–')}</dd>
            <dt class="col-sm-3">Factory locations</dt><dd class="col-sm-9">${escapeHtml(party.factory_locations || '–')}</dd>
            <dt class="col-sm-3">Credit limit</dt><dd class="col-sm-9">${party.credit_limit != null ? '₹' + Number(party.credit_limit).toLocaleString() : '–'}</dd>
            <dt class="col-sm-3">Payment terms</dt><dd class="col-sm-9">${party.payment_terms_days != null ? party.payment_terms_days + ' days' : '–'}</dd>
            <dt class="col-sm-3">Technical notes</dt><dd class="col-sm-9">${escapeHtml(party.technical_notes || '–')}</dd>
        </dl>
    `;
}

document.getElementById('btnEditProfile').addEventListener('click', function() {
    if (!party) return;
    document.getElementById('profileRegion').value = party.region || '';
    document.getElementById('profileProductCategory').value = party.product_category || '';
    document.getElementById('profileProductionCapacity').value = party.production_capacity || '';
    document.getElementById('profileFactoryLocations').value = party.factory_locations || '';
    document.getElementById('profileCreditLimit').value = party.credit_limit != null ? party.credit_limit : '';
    document.getElementById('profilePaymentTermsDays').value = party.payment_terms_days != null ? party.payment_terms_days : '';
    document.getElementById('profileTechnicalNotes').value = party.technical_notes || '';
    new bootstrap.Modal(document.getElementById('profileModal')).show();
});
document.getElementById('btnSaveProfile').addEventListener('click', async function() {
    try {
        await apiCall('/api/parties/' + partyId, { method: 'PUT', body: JSON.stringify({
            region: document.getElementById('profileRegion').value.trim() || null,
            product_category: document.getElementById('profileProductCategory').value || null,
            production_capacity: document.getElementById('profileProductionCapacity').value.trim() || null,
            factory_locations: document.getElementById('profileFactoryLocations').value.trim() || null,
            credit_limit: document.getElementById('profileCreditLimit').value ? parseFloat(document.getElementById('profileCreditLimit').value) : null,
            payment_terms_days: document.getElementById('profilePaymentTermsDays').value ? parseInt(document.getElementById('profilePaymentTermsDays').value, 10) : null,
            technical_notes: document.getElementById('profileTechnicalNotes').value.trim() || null,
        }) });
        const r = await apiCall('/api/parties/' + partyId);
        party = r.data;
        renderCompanyProfile();
        bootstrap.Modal.getInstance(document.getElementById('profileModal')).hide();
    } catch (e) { showError(e.message); }
});

async function loadSamples() {
    try {
        const r = await apiCall('/api/crm/samples?party_id=' + partyId);
        const list = r.data || [];
        const el = document.getElementById('samplesList');
        if (list.length === 0) el.innerHTML = '<p class="text-muted mb-0">No samples yet. Click Add sample.</p>';
        else {
            const statusLabels = { sample_sent: 'Sample Sent', trial_scheduled: 'Trial Scheduled', trial_successful: 'Trial Successful', trial_failed: 'Trial Failed', trial_retesting: 'Trial Retesting' };
            el.innerHTML = '<table class="table table-sm mb-0"><thead><tr><th>Type</th><th>Qty</th><th>Dates</th><th>Status</th><th>Outcome</th></tr></thead><tbody>' +
                list.map(s => `<tr><td>${escapeHtml(s.sample_type || '–')}</td><td>${escapeHtml(s.quantity_sent || '–')}</td><td>${s.request_date || ''} / ${s.dispatch_date || ''} / ${s.trial_date || ''}</td><td><span class="badge bg-secondary">${statusLabels[s.status] || s.status}</span></td><td>${escapeHtml(s.outcome || '–')}</td></tr>`).join('') + '</tbody></table>';
        }
    } catch (e) {
        document.getElementById('samplesList').innerHTML = '<p class="text-muted mb-0">Samples not available.</p>';
    }
}

document.getElementById('btnAddSample').addEventListener('click', function() {
    document.getElementById('sampleType').value = '';
    document.getElementById('sampleQuantity').value = '';
    document.getElementById('sampleRequestDate').value = '';
    document.getElementById('sampleDispatchDate').value = '';
    document.getElementById('sampleTrialDate').value = '';
    document.getElementById('sampleStatus').value = 'sample_sent';
    document.getElementById('sampleOutcome').value = '';
    document.getElementById('sampleTechnicalFeedback').value = '';
    new bootstrap.Modal(document.getElementById('sampleModal')).show();
});
document.getElementById('btnSaveSample').addEventListener('click', async function() {
    try {
        await apiCall('/api/crm/samples', { method: 'POST', body: JSON.stringify({
            party_id: partyId,
            sample_type: document.getElementById('sampleType').value.trim(),
            quantity_sent: document.getElementById('sampleQuantity').value.trim(),
            request_date: document.getElementById('sampleRequestDate').value || null,
            dispatch_date: document.getElementById('sampleDispatchDate').value || null,
            trial_date: document.getElementById('sampleTrialDate').value || null,
            status: document.getElementById('sampleStatus').value,
            outcome: document.getElementById('sampleOutcome').value.trim(),
            technical_feedback: document.getElementById('sampleTechnicalFeedback').value.trim(),
        }) });
        loadSamples();
        bootstrap.Modal.getInstance(document.getElementById('sampleModal')).hide();
    } catch (e) { showError(e.message); }
});

async function loadReceivables() {
    try {
        const r = await apiCall('/api/crm/parties/' + partyId + '/receivables');
        const data = r.data || {};
        const entries = data.entries || [];
        const out = data.outstanding != null ? data.outstanding : 0;
        const limit = data.credit_limit;
        document.getElementById('receivableOutstanding').textContent = '₹' + Number(out).toLocaleString();
        document.getElementById('receivableCreditLimit').textContent = limit != null ? '₹' + Number(limit).toLocaleString() : '–';
        const alertEl = document.getElementById('receivableAlert');
        if (limit != null && out > limit) alertEl.textContent = 'Over credit limit';
        else alertEl.textContent = '';
        const el = document.getElementById('receivablesEntries');
        if (entries.length === 0) el.innerHTML = '<p class="text-muted mb-0">No entries. Add invoice or payment.</p>';
        else el.innerHTML = '<table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Reference</th></tr></thead><tbody>' +
            entries.map(e => `<tr><td>${e.entry_date}</td><td>${e.entry_type}</td><td>₹${Number(e.amount).toLocaleString()}</td><td>${escapeHtml(e.reference || '')}</td></tr>`).join('') + '</tbody></table>';
    } catch (e) {
        document.getElementById('receivableOutstanding').textContent = '–';
        document.getElementById('receivablesEntries').innerHTML = '<p class="text-muted mb-0">Receivables not available.</p>';
    }
}

document.getElementById('btnAddReceivable').addEventListener('click', function() {
    document.getElementById('receivableAmount').value = '';
    document.getElementById('receivableDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('receivableReference').value = '';
    document.getElementById('receivableDescription').value = '';
    new bootstrap.Modal(document.getElementById('receivableModal')).show();
});
document.getElementById('btnSaveReceivable').addEventListener('click', async function() {
    const amount = parseFloat(document.getElementById('receivableAmount').value);
    if (!amount || amount <= 0) { showError('Amount is required'); return; }
    try {
        await apiCall('/api/crm/receivables', { method: 'POST', body: JSON.stringify({
            party_id: partyId,
            entry_type: document.getElementById('receivableType').value,
            amount,
            entry_date: document.getElementById('receivableDate').value || new Date().toISOString().slice(0, 10),
            reference: document.getElementById('receivableReference').value.trim(),
            description: document.getElementById('receivableDescription').value.trim(),
        }) });
        loadReceivables();
        bootstrap.Modal.getInstance(document.getElementById('receivableModal')).hide();
    } catch (e) { showError(e.message); }
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
