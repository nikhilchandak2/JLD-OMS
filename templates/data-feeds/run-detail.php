<?php $runId = (int)($run_id ?? 0); ?>
<div class="page-header">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/data-feeds">Data feeds</a></li>
            <li class="breadcrumb-item active">Run #<?= $runId ?></li>
        </ol>
    </nav>
    <h1 class="page-title mt-2">Feed run #<?= $runId ?></h1>
    <p class="page-subtitle mb-0">Review rejected rows, resolve unmatched parties, then promote. Promote writes live tables in one transaction.</p>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<div class="card mb-3">
    <div class="card-body" id="runSummary">Loading…</div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-secondary" id="btnRejections" href="/api/data-feeds/runs/<?= $runId ?>/rejections">Download rejection report</a>
    <a class="btn btn-outline-warning" href="/data-feeds/unmatched">Resolve unmatched parties</a>
    <button class="btn btn-outline-primary" type="button" id="btnRevalidate">Re-validate</button>
    <button class="btn btn-primary" type="button" id="btnPromote" disabled>Promote to live tables</button>
</div>

<div class="card">
    <div class="card-header">Rejected rows (first 25)</div>
    <div class="card-body" id="rejectedBody">—</div>
</div>

<script>
const runId = <?= $runId ?>;

document.addEventListener('DOMContentLoaded', loadRun);
document.getElementById('btnRevalidate').addEventListener('click', async () => {
    try {
        await apiCall('/api/data-feeds/runs/' + runId + '/validate', { method: 'POST' });
        showSuccess('Re-validated.');
        loadRun();
    } catch (e) { showError(e.message); }
});
document.getElementById('btnPromote').addEventListener('click', async () => {
    if (!confirm('Promote this file? Live tables will change for this business date.')) return;
    try {
        await apiCall('/api/data-feeds/runs/' + runId + '/promote', { method: 'POST' });
        showSuccess('Promoted.');
        loadRun();
        if (window.DataAsOfBanner) DataAsOfBanner.hydrateAll();
    } catch (e) { showError(e.message); }
});

async function loadRun() {
    try {
        const response = await apiCall('/api/data-feeds/runs/' + runId);
        const data = response.data;
        const run = data.run;
        document.getElementById('runSummary').innerHTML = `
            <div class="row g-3">
                <div class="col-md-3"><div class="text-muted small">Feed</div><div>${escapeHtml(run.feed_key)}</div></div>
                <div class="col-md-3"><div class="text-muted small">Company</div><div>${escapeHtml(run.company_name)}</div></div>
                <div class="col-md-3"><div class="text-muted small">Business date</div><div>${escapeHtml(run.business_date)}</div></div>
                <div class="col-md-3"><div class="text-muted small">Status</div><div>${escapeHtml(run.status)}</div></div>
                <div class="col-md-3"><div class="text-muted small">File</div><div>${escapeHtml(run.original_filename)}</div></div>
                <div class="col-md-3"><div class="text-muted small">Rows</div><div>${run.rows_accepted} accepted / ${run.rows_rejected} rejected / ${run.rows_total} total</div></div>
                <div class="col-md-3"><div class="text-muted small">As of</div><div>${run.as_of ? formatDateTime(run.as_of) : '—'}</div></div>
                <div class="col-md-3"><div class="text-muted small">Error</div><div>${escapeHtml(run.error_summary || '—')}</div></div>
            </div>`;
        document.getElementById('btnPromote').disabled = !data.can_promote;
        const rejected = data.rejected_preview || [];
        if (rejected.length === 0) {
            document.getElementById('rejectedBody').innerHTML = '<p class="text-muted mb-0">No rejected rows.</p>';
        } else {
            document.getElementById('rejectedBody').innerHTML = '<table class="table table-sm"><thead><tr><th>#</th><th>Reason</th><th>Party</th></tr></thead><tbody>' +
                rejected.map(r => `<tr><td>${r.row_number}</td><td>${escapeHtml(r.rejection_reason)}</td><td>${escapeHtml((r.raw && (r.raw.party_name || r.raw.party_code)) || '')}</td></tr>`).join('') +
                '</tbody></table>';
        }
    } catch (e) {
        showError(e.message);
    }
}
</script>
