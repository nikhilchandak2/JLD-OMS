<!-- CRM Dashboard – 5-stage funnel (company-based pipeline) -->
<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
        <div>
            <h1 class="page-title mb-1">CRM Dashboard</h1>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="/crm/parties/new" class="btn btn-success px-3"><i class="bi bi-plus-lg me-1"></i>Add New</a>
            <a href="/admin/parties" class="btn btn-primary px-4" title="<?= isset($user) ? htmlspecialchars(ucfirst($user['role'] ?? 'User')) : 'User' ?>"><i class="bi bi-person-circle me-2"></i>Parties</a>
            <a href="/crm/funnel" class="btn btn-primary px-4">
                <i class="bi bi-funnel me-2"></i>Open Funnel
            </a>
        </div>
    </div>
</div>

<div id="error-container" class="error-message mb-3"></div>

<div class="row g-3 mt-0">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Recent activity</span>
                <div class="d-flex gap-2 align-items-center">
                    <small class="text-muted" id="activityFeedStatus">Live</small>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshActivityFeed">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div id="activityFeedList"><p class="text-muted small mb-0">Loading…</p></div>
            </div>
        </div>
    </div>
</div>

<script>
let lastActivityId = 0;
let activityFeedTimer = null;

document.addEventListener('DOMContentLoaded', async function() {
    document.getElementById('btnRefreshActivityFeed').addEventListener('click', function() {
        loadActivityFeed(true);
    });
    loadActivityFeed(true);
    activityFeedTimer = setInterval(loadActivityFeed, 6000);
});
function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function timeAgo(iso) {
    if (!iso) return '';
    const dt = new Date(iso.replace(' ', 'T'));
    if (isNaN(dt.getTime())) return iso;
    const sec = Math.floor((Date.now() - dt.getTime()) / 1000);
    if (sec < 60) return sec + 's ago';
    const min = Math.floor(sec / 60);
    if (min < 60) return min + 'm ago';
    const hr = Math.floor(min / 60);
    if (hr < 24) return hr + 'h ago';
    const d = Math.floor(hr / 24);
    return d + 'd ago';
}

async function loadActivityFeed(force = false) {
    const el = document.getElementById('activityFeedList');
    const statusEl = document.getElementById('activityFeedStatus');
    try {
        statusEl.textContent = 'Live';
        const r = await apiCall('/api/crm/activities?limit=15');
        const list = (r.data || []);
        if (!list.length) {
            el.innerHTML = '<p class="text-muted small mb-0">No activities yet.</p>';
            lastActivityId = 0;
            return;
        }
        const newestId = list[0].id || 0;
        if (!force && newestId === lastActivityId) return;
        lastActivityId = newestId;

        const icon = (t) => ({
            call: 'telephone',
            meeting: 'people',
            visit: 'geo-alt',
            whatsapp: 'whatsapp',
            email: 'envelope',
            note: 'journal-text'
        }[t] || 'journal-text');

        el.innerHTML = list.map(a => {
            const party = a.party_name || (a.created_by_name || 'User');
            const subject = a.subject ? escapeHtml(a.subject) : '<span class="text-muted">(no subject)</span>';
            const who = a.created_by_name ? escapeHtml(a.created_by_name) : 'User';
            const when = timeAgo(a.activity_date || a.created_at);
            const desc = a.description ? ('<div class="text-muted small mt-1">' + escapeHtml(a.description).slice(0, 160) + (a.description.length > 160 ? '…' : '') + '</div>') : '';
            return (
                '<div class="d-flex gap-2 py-2 border-bottom">' +
                '<div class="text-primary" style="width:22px;"><i class="bi bi-' + icon(a.type) + '"></i></div>' +
                '<div class="flex-grow-1">' +
                '<div class="d-flex justify-content-between gap-2">' +
                '<div><a href="/crm/parties/' + a.party_id + '" class="text-decoration-none fw-semibold">' + escapeHtml(party) + '</a> · ' + subject + '</div>' +
                '<small class="text-muted">' + escapeHtml(when) + '</small>' +
                '</div>' +
                '<div class="text-muted small">' + escapeHtml(a.type || 'note') + ' · ' + who + '</div>' +
                desc +
                '</div>' +
                '</div>'
            );
        }).join('') + '<div class="pt-2"><a class="small text-decoration-none" href="/crm/funnel">Open funnel</a></div>';
    } catch (e) {
        statusEl.textContent = 'Offline';
        el.innerHTML = '<p class="text-muted small mb-0">Unable to load activity feed.</p>';
    }
}
</script>
