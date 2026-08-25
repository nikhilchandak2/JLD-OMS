<div class="page-header mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item active">Pipeline</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2">
        <div>
            <h1 class="page-title mb-0">Pipeline</h1>
            <p class="text-muted small mb-0">Nightly snapshot. Lost and dropped deals are out of the active counts. Hold time is not stall time.</p>
        </div>
        <button type="button" class="btn btn-outline-secondary" id="btnExport">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel
        </button>
    </div>
</div>

<div id="pipelineAsOf" class="alert alert-secondary py-2 small mb-3" role="status">Loading snapshot…</div>
<div id="error-container" class="error-message mb-3"></div>

<form id="pipelineFilters" class="row g-2 align-items-end mb-3">
    <div class="col-6 col-md-3" id="ownerFilterWrap">
        <label class="form-label small mb-1" for="filterOwner">Owner</label>
        <select class="form-select" id="filterOwner">
            <option value="">All owners</option>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="filterGrade">Grade</label>
        <input class="form-control" id="filterGrade" type="text" placeholder="e.g. J-11" autocomplete="off">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="filterFrom">From</label>
        <input class="form-control" id="filterFrom" type="date">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="filterTo">To</label>
        <input class="form-control" id="filterTo" type="date">
    </div>
    <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-primary w-100">Apply</button>
    </div>
</form>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tabByStage" data-bs-toggle="tab" data-bs-target="#paneByStage" type="button" role="tab">By stage</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tabTime" data-bs-toggle="tab" data-bs-target="#paneTime" type="button" role="tab">Time in stage</button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="paneByStage" role="tabpanel">
        <div id="byStageBody"><p class="text-muted">Loading…</p></div>
    </div>
    <div class="tab-pane fade" id="paneTime" role="tabpanel">
        <div id="timeBody"><p class="text-muted">Loading…</p></div>
    </div>
</div>

<script>
function qs() {
    const p = new URLSearchParams();
    const owner = document.getElementById('filterOwner').value;
    const grade = document.getElementById('filterGrade').value.trim();
    const from = document.getElementById('filterFrom').value;
    const to = document.getElementById('filterTo').value;
    if (owner) p.set('owner_user_id', owner);
    if (grade) p.set('grade_code', grade);
    if (from) p.set('date_from', from);
    if (to) p.set('date_to', to);
    const s = p.toString();
    return s ? ('?' + s) : '';
}

function days(v) {
    if (v === null || v === undefined || v === '') return '—';
    return Number(v).toLocaleString('en-IN', { maximumFractionDigits: 1 });
}

function money(v) {
    if (v === null || v === undefined || v === '') return '—';
    return Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });
}

async function loadOwners(canFilter) {
    const wrap = document.getElementById('ownerFilterWrap');
    if (!canFilter) {
        wrap.querySelector('label').textContent = 'Owner';
        document.getElementById('filterOwner').innerHTML = '<option value="">Your deals</option>';
        document.getElementById('filterOwner').disabled = true;
        return;
    }
    try {
        const r = await apiCall('/api/crm/users/options');
        const list = r.data || [];
        document.getElementById('filterOwner').innerHTML = '<option value="">All owners</option>' +
            list.map(function (u) {
                return '<option value="' + u.id + '">' + escapeHtml(u.name || 'User') + '</option>';
            }).join('');
    } catch (e) {
        // Keep All owners.
    }
}

function renderByStage(d) {
    const el = document.getElementById('byStageBody');
    const rows = d.by_stage || [];
    const any = rows.some(function (r) { return r.deal_count > 0; });
    if (!d.refreshed) {
        el.innerHTML = '<p class="text-muted mb-0">Not yet recorded. The nightly job has not written a snapshot.</p>';
        return;
    }
    if (!any) {
        el.innerHTML = '<p class="text-muted mb-0">No active deals in this snapshot for the current filters. Lost and dropped are excluded.</p>';
        return;
    }
    const showValue = !!d.can_view_value;
    let html = '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Stage</th><th class="text-end">Deals</th>';
    if (showValue) html += '<th class="text-end">Indicative value</th>';
    html += '</tr></thead><tbody>';
    html += rows.map(function (r) {
        let row = '<tr><td>' + escapeHtml(r.label) + '</td><td class="text-end">' + r.deal_count + '</td>';
        if (showValue) row += '<td class="text-end">' + money(r.indicative_value) + '</td>';
        return row + '</tr>';
    }).join('');
    el.innerHTML = html + '</tbody></table></div>';
}

function renderTime(d) {
    const el = document.getElementById('timeBody');
    if (!d.refreshed) {
        el.innerHTML = '<p class="text-muted mb-0">Not yet recorded. The nightly job has not written a snapshot.</p>';
        return;
    }
    const rows = d.time_in_stage || [];
    const over = d.over_threshold || [];
    let html = '<p class="small text-muted">Average and median are current dwell, excluding technical-hold time. A deal waiting on the lab is not a stalled rep.</p>';
    html += '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr>' +
        '<th>Stage</th><th class="text-end">Deals</th><th class="text-end">Avg days</th><th class="text-end">Median</th>' +
        '<th class="text-end">Avg hold</th><th class="text-end">Over ' + (d.stall_days_default || 14) + 'd</th></tr></thead><tbody>';
    html += rows.map(function (r) {
        const warn = r.over_threshold > 0 ? ' table-warning' : '';
        return '<tr class="' + warn + '"><td>' + escapeHtml(r.label) + '</td><td class="text-end">' + r.deal_count +
            '</td><td class="text-end">' + days(r.avg_days) + '</td><td class="text-end">' + days(r.median_days) +
            '</td><td class="text-end">' + days(r.avg_hold_days) + '</td><td class="text-end">' + r.over_threshold + '</td></tr>';
    }).join('') + '</tbody></table></div>';

    html += '<h2 class="h6 mt-4">Currently over threshold</h2>';
    if (over.length === 0) {
        html += '<p class="text-muted mb-0">None. Current dwell (ex-hold) is within the configured stall days.</p>';
    } else {
        html += '<div class="list-group">';
        html += over.map(function (r) {
            return '<a class="list-group-item list-group-item-action list-group-item-warning" href="/crm/deals/' + r.deal_id + '">' +
                '<div class="d-flex justify-content-between gap-2 flex-wrap">' +
                '<strong>' + escapeHtml(r.party_name) + '</strong>' +
                '<span>' + days(r.dwell_days) + 'd in ' + escapeHtml(r.label) + '</span></div>' +
                '<div class="small text-muted mt-1">' + escapeHtml(r.title) +
                ' · hold ' + days(r.hold_days) + 'd (not stall)' +
                (r.owner_name ? ' · ' + escapeHtml(r.owner_name) : '') + '</div></a>';
        }).join('');
        html += '</div>';
    }
    el.innerHTML = html;
}

async function loadDashboard() {
    const res = await apiCall('/api/crm/pipeline' + qs());
    const d = res.data || {};
    const banner = document.getElementById('pipelineAsOf');
    if (!d.refreshed) {
        banner.textContent = 'Pipeline snapshot: not yet recorded.';
    } else {
        banner.textContent = 'Pipeline snapshot as of ' + d.as_of + '. Refreshed nightly, not live.';
    }
    renderByStage(d);
    renderTime(d);
    return d;
}

document.addEventListener('DOMContentLoaded', async function () {
    try {
        const d = await loadDashboard();
        await loadOwners(!!d.can_filter_owner);
    } catch (e) {
        showError(e.message);
    }
    document.getElementById('pipelineFilters').addEventListener('submit', async function (ev) {
        ev.preventDefault();
        try {
            showError('');
            await loadDashboard();
        } catch (e) {
            showError(e.message);
        }
    });
    document.getElementById('btnExport').addEventListener('click', function () {
        window.location = '/api/crm/pipeline/export' + qs();
    });
});
</script>
