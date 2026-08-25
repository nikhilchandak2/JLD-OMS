<div class="page-header mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item active">Dormant accounts</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2">
        <div>
            <h1 class="page-title mb-0">Dormant accounts</h1>
            <p class="text-muted small mb-0">Refreshed nightly from orders. A visit only changes how loud the signal is — it does not clear it.</p>
        </div>
        <a href="/crm/visits/new" class="btn btn-success"><i class="bi bi-geo-alt me-1"></i>Log visit</a>
    </div>
</div>

<div id="error-container" class="error-message mb-3"></div>
<div id="dormancyList"><p class="text-muted">Loading…</p></div>

<script>
document.addEventListener('DOMContentLoaded', async function () {
    try {
        const res = await apiCall('/api/crm/dormancy');
        const list = res.data || [];
        const el = document.getElementById('dormancyList');
        if (list.length === 0) {
            el.innerHTML = '<p class="text-muted mb-0">None on the latest nightly run. Accounts here last ordered (or never ordered) beyond the configured gap.</p>';
            return;
        }
        el.innerHTML = '<div class="list-group">' + list.map(function (s) {
            const badge = s.severity === 'urgent'
                ? '<span class="badge bg-danger">Urgent</span>'
                : '<span class="badge bg-warning text-dark">Watch</span>';
            const lastOrder = s.last_order_date ? ('Last order ' + escapeHtml(s.last_order_date)) : 'Never ordered';
            const lastVisit = s.last_visit_date ? ('Last visit ' + escapeHtml(s.last_visit_date)) : 'No visit logged';
            return '<a class="list-group-item list-group-item-action" href="/crm/parties/' + s.party_id + '">' +
                '<div class="d-flex justify-content-between gap-2 flex-wrap">' +
                '<strong>' + escapeHtml(s.party_name) + '</strong>' + badge + '</div>' +
                '<div class="mt-1">' + escapeHtml(s.reason_summary) + '</div>' +
                '<div class="small text-muted mt-1">' + lastOrder + ' · ' + lastVisit +
                (s.owner_name ? ' · ' + escapeHtml(s.owner_name) : '') + '</div></a>';
        }).join('') + '</div>';
    } catch (e) {
        showError(e.message);
    }
});
</script>
