<!-- New Deal -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
                    <li class="breadcrumb-item"><a href="/crm/deals">Deals</a></li>
                    <li class="breadcrumb-item active">New Deal</li>
                </ol>
            </nav>
            <h1 class="page-title mt-2"><i class="bi bi-graph-up-arrow me-2"></i>New Deal</h1>
        </div>
        <a href="/crm/deals" class="btn btn-outline-secondary">Back to deals</a>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div class="card">
    <div class="card-body">
        <form id="dealForm">
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label">Party (customer) *</label>
                    <select class="form-select" id="party_id" name="party_id" required>
                        <option value="">Select party…</option>
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Title *</label>
                    <input type="text" class="form-control" id="title" name="title" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Value (₹)</label>
                    <input type="number" class="form-control" id="value" name="value" step="0.01" min="0">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Expected close date</label>
                    <input type="date" class="form-control" id="expected_close_date" name="expected_close_date">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Create Deal</button>
                <a href="/crm/deals" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
let parties = [];

document.addEventListener('DOMContentLoaded', async function() {
    try {
        const r = await apiCall('/api/parties');
        parties = (r.data || []).filter(p => p.is_active !== false);
        const sel = document.getElementById('party_id');
        parties.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.name;
            sel.appendChild(opt);
        });
        const q = new URLSearchParams(window.location.search);
        const preselect = q.get('party_id');
        if (preselect) sel.value = preselect;
    } catch (e) {
        showError(e.message);
    }
});

document.getElementById('dealForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    showError('');
    const partyId = document.getElementById('party_id').value;
    const payload = {
        party_id: partyId ? parseInt(partyId, 10) : 0,
        title: document.getElementById('title').value.trim(),
        value: document.getElementById('value').value ? parseFloat(document.getElementById('value').value) : null,
        expected_close_date: document.getElementById('expected_close_date').value || null,
        notes: document.getElementById('notes').value.trim(),
    };
    if (!payload.party_id) { showError('Party is required'); return; }
    if (!payload.title) { showError('Title is required'); return; }
    try {
        const r = await apiCall('/api/crm/deals', { method: 'POST', body: JSON.stringify(payload) });
        if (r.success && r.data) window.location.href = '/crm/deals/' + r.data.id;
        else showError(r.error || 'Failed to create');
    } catch (e) {
        showError(e.message);
    }
});
</script>
