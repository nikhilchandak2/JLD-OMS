<div class="page-header mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item"><a href="/crm/forecasts">Forecast</a></li>
            <li class="breadcrumb-item active">Forecast vs actual</li>
        </ol>
    </nav>
    <h1 class="page-title mb-0">Forecast vs actual</h1>
    <p class="text-muted small mb-0">Production planning by grade and by account. This is not a rep scorecard.</p>
</div>

<div id="forecastPurpose" class="alert alert-info mb-3" role="note"></div>
<div id="error-container" class="error-message mb-3"></div>
<div id="actualsBody"><p class="text-muted">Loading…</p></div>

<script>
function num(v) {
    if (v === null || v === undefined || v === '') return '—';
    return Number(v).toLocaleString('en-IN', { maximumFractionDigits: 1 });
}

document.addEventListener('DOMContentLoaded', async function () {
    try {
        const res = await apiCall('/api/crm/forecasts/actuals');
        const d = res.data || {};
        document.getElementById('forecastPurpose').textContent = d.purpose_line || '';
        const grades = d.by_grade || [];
        const accounts = d.by_account || [];
        let html = '';
        if (d.period) {
            html += '<p class="small text-muted">Period ' + escapeHtml(d.period.year_month) + ' · as-of from the nightly rebuild.</p>';
        }
        html += '<h2 class="h5 mt-3">By grade</h2>';
        if (grades.length === 0) {
            html += '<p class="text-muted">No forecast lines for this month yet.</p>';
        } else {
            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Grade</th><th class="text-end">Forecast low</th><th class="text-end">Forecast high</th><th class="text-end">Actual t</th><th class="text-end">Vs midpoint</th></tr></thead><tbody>' +
                grades.map(function (g) {
                    return '<tr><td>' + escapeHtml(g.grade_code) + '</td><td class="text-end">' + num(g.forecast_low) + '</td><td class="text-end">' + num(g.forecast_high) + '</td><td class="text-end">' + num(g.actual_tonnes) + '</td><td class="text-end">' + num(g.variance_vs_midpoint) + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }
        html += '<h2 class="h5 mt-4">By account</h2>';
        if (accounts.length === 0) {
            html += '<p class="text-muted">No account rows.</p>';
        } else {
            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Account</th><th class="text-end">Forecast low</th><th class="text-end">Forecast high</th><th class="text-end">Actual t</th><th class="text-end">Vs midpoint</th></tr></thead><tbody>' +
                accounts.map(function (a) {
                    return '<tr><td><a href="/crm/parties/' + a.party_id + '">' + escapeHtml(a.party_name) + '</a></td><td class="text-end">' + num(a.forecast_low) + '</td><td class="text-end">' + num(a.forecast_high) + '</td><td class="text-end">' + num(a.actual_tonnes) + '</td><td class="text-end">' + num(a.variance_vs_midpoint) + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }
        document.getElementById('actualsBody').innerHTML = html;
    } catch (e) {
        showError(e.message);
    }
});
</script>
