<?php $deal_id = (int)($deal_id ?? 0); ?>
<!-- Deal detail -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
                    <li class="breadcrumb-item"><a href="/crm/deals">Deals</a></li>
                    <li class="breadcrumb-item active">Deal #<?= $deal_id ?></li>
                </ol>
            </nav>
            <h1 class="page-title mt-2" id="dealTitle">–</h1>
            <p class="page-subtitle mb-0" id="dealSubtitle">Loading…</p>
        </div>
        <a href="/crm/deals" class="btn btn-outline-secondary">Back to deals</a>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">Details</div>
            <div class="card-body" id="dealDetails">Loading…</div>
        </div>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Activities</span>
                <button type="button" class="btn btn-sm btn-primary" id="btnAddActivity"><i class="bi bi-plus me-1"></i>Log activity</button>
            </div>
            <div class="card-body">
                <div id="activitiesList">No activities yet.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Actions</div>
            <div class="card-body">
                <a href="#" id="linkParty" class="btn btn-outline-primary w-100 mb-2">View party</a>
                <button type="button" class="btn btn-outline-danger w-100" id="btnDeleteDeal">Delete deal</button>
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
                <input type="hidden" id="activityPartyId">
                <input type="hidden" id="activityDealId" value="<?= $deal_id ?>">
                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <select class="form-select" id="activityType">
                        <option value="call">Call</option>
                        <option value="meeting">Meeting</option>
                        <option value="note">Note</option>
                        <option value="email">Email</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Subject</label>
                    <input type="text" class="form-control" id="activitySubject">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="activityDescription" rows="3"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Date & time</label>
                    <input type="datetime-local" class="form-control" id="activityDate">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveActivity">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
const dealId = <?= $deal_id ?>;
let deal = null;

document.addEventListener('DOMContentLoaded', loadDeal);

async function loadDeal() {
    if (dealId <= 0) { showError('Invalid deal'); return; }
    try {
        const r = await apiCall('/api/crm/deals/' + dealId);
        deal = r.data;
        render();
        loadActivities();
    } catch (e) {
        showError(e.message);
    }
}

function render() {
    if (!deal) return;
    document.getElementById('dealTitle').textContent = deal.title;
    document.getElementById('dealSubtitle').textContent = (deal.party_name || '') + (deal.stage ? ' · ' + deal.stage : '');
    document.getElementById('linkParty').href = '/crm/parties/' + deal.party_id;
    document.getElementById('dealDetails').innerHTML = `
        <dl class="row mb-0">
            <dt class="col-sm-3">Party</dt><dd class="col-sm-9"><a href="/crm/parties/${deal.party_id}">${escapeHtml(deal.party_name || '–')}</a></dd>
            <dt class="col-sm-3">Value</dt><dd class="col-sm-9">${deal.value != null ? '₹' + Number(deal.value).toLocaleString() : '–'}</dd>
            <dt class="col-sm-3">Stage</dt><dd class="col-sm-9"><span class="badge bg-secondary">${escapeHtml(deal.stage || '')}</span></dd>
            <dt class="col-sm-3">Expected close</dt><dd class="col-sm-9">${deal.expected_close_date || '–'}</dd>
            <dt class="col-sm-3">Assigned</dt><dd class="col-sm-9">${escapeHtml(deal.assigned_to_name || '–')}</dd>
            ${deal.notes ? '<dt class="col-sm-3">Notes</dt><dd class="col-sm-9">' + escapeHtml(deal.notes) + '</dd>' : ''}
        </dl>
    `;
    document.getElementById('activityPartyId').value = deal.party_id;
}

async function loadActivities() {
    if (!deal) return;
    try {
        const r = await apiCall('/api/crm/activities?deal_id=' + dealId);
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
    } catch (e) {}
}

document.getElementById('btnAddActivity').addEventListener('click', function() {
    document.getElementById('activitySubject').value = '';
    document.getElementById('activityDescription').value = '';
    document.getElementById('activityDate').value = new Date().toISOString().slice(0, 16);
    new bootstrap.Modal(document.getElementById('activityModal')).show();
});

document.getElementById('btnSaveActivity').addEventListener('click', async function() {
    const partyId = document.getElementById('activityPartyId').value;
    const payload = {
        party_id: parseInt(partyId, 10),
        deal_id: dealId,
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

document.getElementById('btnDeleteDeal').addEventListener('click', async function() {
    if (!deal || !confirm('Delete this deal?')) return;
    try {
        await apiCall('/api/crm/deals/' + dealId, { method: 'DELETE' });
        window.location.href = '/crm/deals';
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
