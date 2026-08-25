<!-- Deals list — the 7-stage pipeline. Terminal deals are excluded unless asked for. -->
<div class="page-header mb-3">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
                    <li class="breadcrumb-item active">Deals</li>
                </ol>
            </nav>
            <h1 class="page-title mb-0">Deals</h1>
        </div>
        <div class="d-flex gap-2">
            <a href="/crm/deals/new" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>New enquiry</a>
            <a href="/crm/technical-queue" class="btn btn-outline-secondary"><i class="bi bi-tools me-1"></i>Technical queue</a>
        </div>
    </div>
</div>

<div id="dealsError" class="alert alert-danger d-none" role="alert"></div>

<div class="row g-2 mb-3">
    <div class="col-12 col-md-3">
        <select class="form-select" id="filterStatus">
            <option value="active">Active deals</option>
            <option value="">All deals</option>
            <option value="won">Won</option>
            <option value="lost">Lost</option>
            <option value="dropped">Dropped</option>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <select class="form-select" id="filterStage">
            <option value="">All stages</option>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="filterHold">
            <label class="form-check-label" for="filterHold">On technical hold only</label>
        </div>
    </div>
</div>

<div id="stageSummary" class="d-flex flex-wrap gap-2 mb-3"></div>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Deal</th>
                <th>Stage</th>
                <th>Grades</th>
                <th class="text-end">Qty (t)</th>
                <th>Owner</th>
                <th>In stage since</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="dealsBody">
            <tr><td colspan="8" class="text-center text-muted py-4">Loading…</td></tr>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', async function () {
    await loadSummary();
    await loadDeals();
    ['filterStatus', 'filterStage', 'filterHold'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', loadDeals);
    });
});

async function loadSummary() {
    try {
        const res = await apiCall('/api/crm/deals/summary');
        const stages = res.data.stages || [];
        document.getElementById('filterStage').innerHTML = '<option value="">All stages</option>' + stages.map(function (s) {
            return '<option value="' + s.stage + '">' + s.stage + '. ' + escapeHtml(s.label) + '</option>';
        }).join('');
        document.getElementById('stageSummary').innerHTML = stages.map(function (s) {
            return '<span class="badge bg-light text-dark border py-2 px-3">' + s.stage + '. ' +
                escapeHtml(s.label) + ' <strong class="ms-1">' + s.active_deals + '</strong></span>';
        }).join('');
        const qs = new URLSearchParams(window.location.search);
        if (qs.get('stage')) document.getElementById('filterStage').value = qs.get('stage');
    } catch (e) {
        showDealsError(e.message);
    }
}

async function loadDeals() {
    const params = new URLSearchParams();
    const status = document.getElementById('filterStatus').value;
    const stage = document.getElementById('filterStage').value;
    if (status) params.set('status', status);
    if (stage) params.set('stage', stage);
    if (document.getElementById('filterHold').checked) params.set('on_technical_hold', '1');
    const partyId = new URLSearchParams(window.location.search).get('party_id');
    if (partyId) params.set('party_id', partyId);

    const body = document.getElementById('dealsBody');
    body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Loading…</td></tr>';

    try {
        const res = await apiCall('/api/crm/deals?' + params.toString());
        const deals = res.data || [];
        if (deals.length === 0) {
            body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">' +
                'No deals match this view. <a href="/crm/deals/new">Capture an enquiry</a>.</td></tr>';
            return;
        }
        body.innerHTML = deals.map(function (d) {
            const hold = d.is_on_technical_hold
                ? ' <span class="badge bg-warning text-dark" title="Waiting on a technical answer">Technical hold</span>'
                : '';
            const statusBadge = d.status === 'active' ? '' :
                ' <span class="badge bg-secondary text-capitalize">' + escapeHtml(d.status) + '</span>';
            return '<tr>' +
                '<td>' + escapeHtml(d.party_name) + '</td>' +
                '<td><a href="/crm/deals/' + d.id + '">' + escapeHtml(d.title || ('Deal #' + d.id)) + '</a>' + statusBadge + hold + '</td>' +
                '<td>' + d.stage + '. ' + escapeHtml(d.stage_label) + '</td>' +
                '<td>' + escapeHtml((d.grades || []).join(', ')) + '</td>' +
                '<td class="text-end">' + (d.indicative_quantity_tonnes !== null && d.indicative_quantity_tonnes !== undefined ? escapeHtml(d.indicative_quantity_tonnes) : '—') + '</td>' +
                '<td>' + escapeHtml(d.owner_name || '—') + '</td>' +
                '<td>' + escapeHtml(d.stage_entered_at || '—') + '</td>' +
                '<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/crm/deals/' + d.id + '">Open</a></td>' +
                '</tr>';
        }).join('');
    } catch (e) {
        body.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">' + escapeHtml(e.message) + '</td></tr>';
    }
}

function showDealsError(message) {
    const box = document.getElementById('dealsError');
    box.textContent = message;
    box.classList.remove('d-none');
}
</script>
