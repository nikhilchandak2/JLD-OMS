<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1 class="page-title"><i class="bi bi-cloud-arrow-up me-2"></i>Data feeds</h1>
            <p class="page-subtitle mb-0">Manual daily upload of ledger and dispatch files. These figures are never live.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="/data-feeds/unmatched" class="btn btn-outline-warning">
                <i class="bi bi-person-x me-1"></i>Unmatched parties
            </a>
            <a href="/data-feeds/upload" class="btn btn-primary">
                <i class="bi bi-upload me-1"></i>Upload a file
            </a>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<?php $feedKey = 'ledger'; $mode = 'group'; include __DIR__ . '/../partials/data-as-of-banner.php'; ?>
<div class="mb-3"></div>
<?php $feedKey = 'dispatch_day_file'; $mode = 'group'; include __DIR__ . '/../partials/data-as-of-banner.php'; ?>

<div class="card mt-4">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="feedsTable">
                <thead>
                    <tr>
                        <th>Feed</th>
                        <th>Company</th>
                        <th>Owner</th>
                        <th>Deadline (IST)</th>
                        <th>Last run</th>
                        <th>As of</th>
                        <th>State</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="8" class="text-muted text-center">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', loadDashboard);

async function loadDashboard() {
    try {
        const response = await apiCall('/api/data-feeds');
        const items = (response.data && response.data.feeds) || [];
        const tbody = document.querySelector('#feedsTable tbody');
        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center">No feeds configured.</td></tr>';
            return;
        }
        tbody.innerHTML = items.map(item => {
            const feed = item.feed;
            const run = item.latest_run;
            const fresh = item.freshness || {};
            const state = fresh.state || 'missing';
            const badge = stateBadge(state);
            const last = run
                ? `<a href="/data-feeds/runs/${run.id}">#${run.id} ${escapeHtml(run.status)}</a><div class="small text-muted">${escapeHtml(run.original_filename || '')}</div>`
                : '<span class="text-muted">None</span>';
            return `<tr>
                <td>${escapeHtml(feed.display_name)}</td>
                <td>${escapeHtml(feed.company_name)}</td>
                <td>${escapeHtml(feed.owner_name || '—')}</td>
                <td>${escapeHtml(feed.deadline_local_time)}</td>
                <td>${last}</td>
                <td>${fresh.as_of ? formatDateTime(fresh.as_of) : '—'}</td>
                <td>${badge}</td>
                <td class="text-end">
                    <a class="btn btn-sm btn-outline-primary" href="/data-feeds/upload?feed_key=${encodeURIComponent(feed.feed_key)}&company_id=${feed.company_id}">Re-upload</a>
                </td>
            </tr>`;
        }).join('');
    } catch (e) {
        showError(e.message);
    }
}

function stateBadge(state) {
    const map = {
        fresh: 'success',
        late: 'warning',
        stale: 'danger',
        missing: 'danger',
        missing_entity: 'danger'
    };
    return `<span class="badge bg-${map[state] || 'secondary'}">${escapeHtml(state)}</span>`;
}
</script>
