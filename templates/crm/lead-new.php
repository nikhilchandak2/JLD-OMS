<!-- New Lead -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
                    <li class="breadcrumb-item"><a href="/crm/leads">Leads</a></li>
                    <li class="breadcrumb-item active">New Lead</li>
                </ol>
            </nav>
            <h1 class="page-title mt-2"><i class="bi bi-lightning-charge me-2"></i>New Lead</h1>
        </div>
        <a href="/crm/leads" class="btn btn-outline-secondary">Back to leads</a>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div class="card">
    <div class="card-body">
        <form id="leadForm">
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label">Title *</label>
                    <input type="text" class="form-control" id="title" name="title" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Company name</label>
                    <input type="text" class="form-control" id="company_name" name="company_name">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact name</label>
                    <input type="text" class="form-control" id="contact_name" name="contact_name">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" id="phone" name="phone">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Source</label>
                    <input type="text" class="form-control" id="source" name="source" placeholder="e.g. website, referral">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Value (₹)</label>
                    <input type="number" class="form-control" id="value" name="value" step="0.01" min="0">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Create Lead</button>
                <a href="/crm/leads" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('leadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    showError('');
    const payload = {
        title: document.getElementById('title').value.trim(),
        company_name: document.getElementById('company_name').value.trim(),
        contact_name: document.getElementById('contact_name').value.trim(),
        phone: document.getElementById('phone').value.trim(),
        email: document.getElementById('email').value.trim(),
        source: document.getElementById('source').value.trim(),
        value: document.getElementById('value').value ? parseFloat(document.getElementById('value').value) : null,
        notes: document.getElementById('notes').value.trim(),
    };
    if (!payload.title) {
        showError('Title is required');
        return;
    }
    try {
        const r = await apiCall('/api/crm/leads', { method: 'POST', body: JSON.stringify(payload) });
        if (r.success && r.data) window.location.href = '/crm/leads/' + r.data.id;
        else showError(r.error || 'Failed to create');
    } catch (e) {
        showError(e.message);
    }
});
</script>
