<div class="page-header mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item active">Overdue follow-ups</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2">
        <div>
            <h1 class="page-title mb-0">Overdue follow-ups</h1>
            <p class="text-muted small mb-0">Visits whose next touchpoint has passed, with no later visit or order.</p>
        </div>
        <a href="/crm/visits/new" class="btn btn-success"><i class="bi bi-geo-alt me-1"></i>Log visit</a>
    </div>
</div>

<div id="error-container" class="error-message mb-3"></div>
<div id="overdueList"><p class="text-muted">Loading…</p></div>

<script>
document.addEventListener('DOMContentLoaded', async function () {
    try {
        const res = await apiCall('/api/crm/visits/overdue');
        const list = res.data || [];
        const el = document.getElementById('overdueList');
        if (list.length === 0) {
            el.innerHTML = '<p class="text-muted mb-0">None overdue. Next touchpoints are still in the future, or a later visit or order closed them.</p>';
            return;
        }
        el.innerHTML = '<div class="list-group">' + list.map(function (v) {
            const people = (v.contacts || []).map(function (c) { return escapeHtml(c.name); }).join(', ') || '—';
            return '<a class="list-group-item list-group-item-action" href="/crm/visits/new?party_id=' + v.party_id + '">' +
                '<div class="d-flex justify-content-between gap-2">' +
                '<strong>' + escapeHtml(v.party_name) + '</strong>' +
                '<span class="badge bg-danger">Due ' + escapeHtml(v.next_planned_touchpoint) + '</span></div>' +
                '<div class="small text-muted">Visited ' + escapeHtml(v.visit_date) + ' · met ' + people + '</div>' +
                (v.outcome ? '<div class="small mt-1">' + escapeHtml(v.outcome) + '</div>' : '') +
                '</a>';
        }).join('') + '</div>';
    } catch (e) {
        showError(e.message);
    }
});
</script>
