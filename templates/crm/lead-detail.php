<?php $lead_id = (int)($lead_id ?? 0); ?>
<!-- Lead detail -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
                    <li class="breadcrumb-item"><a href="/crm/leads">Leads</a></li>
                    <li class="breadcrumb-item active">Lead #<?= $lead_id ?></li>
                </ol>
            </nav>
            <h1 class="page-title mt-2" id="leadTitle">–</h1>
            <p class="page-subtitle mb-0" id="leadSubtitle">Loading…</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-success" id="btnConvertToDeal"><i class="bi bi-arrow-right-circle me-1"></i> Convert to deal</button>
            <a href="/crm/leads" class="btn btn-outline-secondary">Back to leads</a>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">Details</div>
            <div class="card-body" id="leadDetails">Loading…</div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Actions</div>
            <div class="card-body">
                <button type="button" class="btn btn-outline-primary w-100 mb-2" id="btnEditLead">Edit lead</button>
                <button type="button" class="btn btn-outline-danger w-100" id="btnDeleteLead">Delete lead</button>
            </div>
        </div>
    </div>
</div>

<script>
const leadId = <?= $lead_id ?>;
let lead = null;
let leadStages = {};

document.addEventListener('DOMContentLoaded', loadLead);

async function loadLead() {
    if (leadId <= 0) { showError('Invalid lead'); return; }
    try {
        const [leadRes, stagesRes] = await Promise.all([
            apiCall('/api/crm/leads/' + leadId),
            apiCall('/api/crm/stages').catch(() => ({ data: {} }))
        ]);
        lead = leadRes.data;
        leadStages = stagesRes.data.lead_stages || {};
        render();
    } catch (e) {
        showError(e.message);
    }
}

function stageLabel(s) {
    return leadStages[s] || s || 'New Lead';
}

function render() {
    if (!lead) return;
    document.getElementById('leadTitle').textContent = lead.title;
    document.getElementById('leadSubtitle').textContent = (lead.company_name || '') + (lead.stage ? ' · ' + stageLabel(lead.stage) : '');
    const d = document.getElementById('leadDetails');
    d.innerHTML = `
        <dl class="row mb-0">
            <dt class="col-sm-3">Company</dt><dd class="col-sm-9">${escapeHtml(lead.company_name || '–')}</dd>
            <dt class="col-sm-3">Contact</dt><dd class="col-sm-9">${escapeHtml(lead.contact_name || '–')}</dd>
            <dt class="col-sm-3">Phone</dt><dd class="col-sm-9">${escapeHtml(lead.phone || '–')}</dd>
            <dt class="col-sm-3">Email</dt><dd class="col-sm-9">${escapeHtml(lead.email || '–')}</dd>
            <dt class="col-sm-3">Source</dt><dd class="col-sm-9">${escapeHtml(lead.source || '–')}</dd>
            <dt class="col-sm-3">Value</dt><dd class="col-sm-9">${lead.value != null ? '₹' + Number(lead.value).toLocaleString() : '–'}</dd>
            <dt class="col-sm-3">Stage</dt><dd class="col-sm-9"><span class="badge bg-secondary">${escapeHtml(stageLabel(lead.stage))}</span></dd>
            <dt class="col-sm-3">Assigned</dt><dd class="col-sm-9">${escapeHtml(lead.assigned_to_name || '–')}</dd>
            ${lead.notes ? '<dt class="col-sm-3">Notes</dt><dd class="col-sm-9">' + escapeHtml(lead.notes) + '</dd>' : ''}
        </dl>
    `;
    document.getElementById('btnConvertToDeal').style.display = (lead.stage === 'converted' || lead.stage === 'converted_customer') ? 'none' : 'inline-block';
}

document.getElementById('btnConvertToDeal').addEventListener('click', async function() {
    if (!lead) return;
    if (!confirm('Convert this lead to a deal? A party will be created from company name if not linked.')) return;
    try {
        const r = await apiCall('/api/crm/leads/' + leadId + '/convert-to-deal', { method: 'POST', body: JSON.stringify({}) });
        if (r.success && r.data && r.data.deal) window.location.href = '/crm/deals/' + r.data.deal.id;
        else showError(r.error || 'Convert failed');
    } catch (e) {
        showError(e.message);
    }
});

document.getElementById('btnEditLead').addEventListener('click', function() {
    if (!lead) return;
    if (!document.getElementById('editLeadModal')) {
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = 'editLeadModal';
        modal.innerHTML = `
            <div class="modal-dialog"><div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Edit lead</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Title *</label><input type="text" class="form-control" id="editTitle"></div>
                    <div class="mb-3"><label class="form-label">Stage</label><select class="form-select" id="editStage"></select></div>
                    <div class="mb-3"><label class="form-label">Value (₹)</label><input type="number" class="form-control" id="editValue" step="0.01"></div>
                    <div class="mb-3"><label class="form-label">Notes</label><textarea class="form-control" id="editNotes" rows="2"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="btnUpdateLead">Save</button></div>
            </div></div>
        `;
        document.body.appendChild(modal);
        document.getElementById('btnUpdateLead').addEventListener('click', async function() {
            const title = document.getElementById('editTitle').value.trim();
            if (!title) { showError('Title is required'); return; }
            try {
                const r = await apiCall('/api/crm/leads/' + leadId, { method: 'PUT', body: JSON.stringify({
                    title,
                    stage: document.getElementById('editStage').value,
                    value: document.getElementById('editValue').value ? parseFloat(document.getElementById('editValue').value) : null,
                    notes: document.getElementById('editNotes').value.trim(),
                }) });
                if (r.success) { lead = r.data; render(); bootstrap.Modal.getInstance(modal).hide(); }
            } catch (e) { showError(e.message); }
        });
    }
    document.getElementById('editTitle').value = lead.title;
    document.getElementById('editValue').value = lead.value != null ? lead.value : '';
    document.getElementById('editNotes').value = lead.notes || '';
    const stageSel = document.getElementById('editStage');
    stageSel.innerHTML = '';
    Object.entries(leadStages).forEach(([key, label]) => {
        const o = document.createElement('option');
        o.value = key;
        o.textContent = label;
        if (lead.stage === key) o.selected = true;
        stageSel.appendChild(o);
    });
    if (stageSel.options.length === 0) {
        ['new_lead','contacted','interested','trial_stage','commercial_negotiation','converted_customer','lost'].forEach(s => {
            const o = document.createElement('option');
            o.value = s;
            o.textContent = s.replace(/_/g, ' ');
            if (lead.stage === s) o.selected = true;
            stageSel.appendChild(o);
        });
    }
    new bootstrap.Modal(document.getElementById('editLeadModal')).show();
});
document.getElementById('btnDeleteLead').addEventListener('click', async function() {
    if (!lead || !confirm('Delete this lead?')) return;
    try {
        await apiCall('/api/crm/leads/' + leadId, { method: 'DELETE' });
        window.location.href = '/crm/leads';
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
