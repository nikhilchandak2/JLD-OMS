<!-- CRM – Customer Relationship Management. -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="page-title">
                <i class="bi bi-person-lines-fill me-2"></i>CRM
            </h1>
            <p class="page-subtitle">Customer Relationship Management – leads, deals, contacts, and activities. Uses Parties as customer accounts.</p>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div class="alert alert-info border-0 mb-4">
    <strong><i class="bi bi-info-circle me-2"></i>CRM section</strong><br>
    Manage leads, deals, samples/trials, and receivables. Go to <strong>Administration → Parties</strong>, then click <strong>CRM</strong> on any party to open its full profile (company profile, contacts, deals, samples &amp; trials, receivables, activities).
</div>

<!-- Summary cards (loaded via API) -->
<div class="row g-4 mb-4" id="crmSummaryRow">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6 class="card-title">Leads</h6>
                <p class="mb-0 display-6" id="summaryTotalLeads">–</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6 class="card-title">Deals</h6>
                <p class="mb-0 display-6" id="summaryTotalDeals">–</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h6 class="card-title">Activities today</h6>
                <p class="mb-0 display-6" id="summaryActivitiesToday">–</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <i class="bi bi-building display-5 text-primary mb-2"></i>
                <h6>Customers (Parties)</h6>
                <a href="/admin/parties" class="btn btn-outline-primary btn-sm mt-2">Open Parties</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card h-100 clickable-card" onclick="window.location.href='/crm/leads'" style="cursor: pointer;">
            <div class="card-body text-center">
                <i class="bi bi-lightning-charge display-5 text-warning mb-3"></i>
                <h5 class="card-title">Leads</h5>
                <p class="card-text text-muted small">Incoming opportunities – track and convert to deals.</p>
                <a href="/crm/leads" class="btn btn-outline-primary btn-sm">View leads</a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 clickable-card" onclick="window.location.href='/crm/deals'" style="cursor: pointer;">
            <div class="card-body text-center">
                <i class="bi bi-graph-up-arrow display-5 text-success mb-3"></i>
                <h5 class="card-title">Deals</h5>
                <p class="card-text text-muted small">Sales pipeline – qualified, proposal, won/lost.</p>
                <a href="/crm/deals" class="btn btn-outline-primary btn-sm">View deals</a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body text-center">
                <i class="bi bi-telephone display-5 text-info mb-3"></i>
                <h5 class="card-title">Activities</h5>
                <p class="card-text text-muted small">Calls, meetings, notes – log from party or deal detail.</p>
                <a href="/admin/parties" class="btn btn-outline-primary btn-sm">Parties → CRM view</a>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><i class="bi bi-link-45deg me-2"></i>Quick links</div>
    <div class="card-body">
        <div class="row g-2">
            <div class="col-auto">
                <a href="/crm/leads/new" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus me-1"></i>New lead</a>
            </div>
            <div class="col-auto">
                <a href="/crm/deals/new" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus me-1"></i>New deal</a>
            </div>
            <div class="col-auto">
                <a href="/admin/parties" class="btn btn-outline-primary btn-sm"><i class="bi bi-building me-1"></i>Parties</a>
            </div>
            <div class="col-auto">
                <a href="/orders" class="btn btn-outline-primary btn-sm"><i class="bi bi-clipboard-check me-1"></i>Orders</a>
            </div>
            <div class="col-auto">
                <a href="#" class="btn btn-outline-primary btn-sm" id="linkReceivablesAging"><i class="bi bi-currency-rupee me-1"></i>Receivables aging</a>
            </div>
        </div>
        <div class="mt-3" id="receivablesAgingBox" style="display:none;">
            <h6 class="text-primary">Receivables (outstanding by party)</h6>
            <div id="receivablesAgingList"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', async function() {
    try {
        const r = await apiCall('/api/crm/summary');
        if (r.success && r.data) {
            document.getElementById('summaryTotalLeads').textContent = r.data.total_leads ?? 0;
            document.getElementById('summaryTotalDeals').textContent = r.data.total_deals ?? 0;
            document.getElementById('summaryActivitiesToday').textContent = r.data.activities_today ?? 0;
        }
    } catch (e) {
        document.getElementById('summaryTotalLeads').textContent = '0';
        document.getElementById('summaryTotalDeals').textContent = '0';
        document.getElementById('summaryActivitiesToday').textContent = '0';
    }
});

document.getElementById('linkReceivablesAging').addEventListener('click', async function(e) {
    e.preventDefault();
    const box = document.getElementById('receivablesAgingBox');
    const list = document.getElementById('receivablesAgingList');
    if (box.style.display === 'none') {
        try {
            const r = await apiCall('/api/crm/receivables/aging');
            if (r.success && r.data && r.data.length) {
                list.innerHTML = '<table class="table table-sm"><thead><tr><th>Party</th><th>Outstanding</th><th>Status</th></tr></thead><tbody>' +
                    r.data.map(p => `<tr><td><a href="/crm/parties/${p.party_id}">${escapeHtml(p.party_name)}</a></td><td>₹${Number(p.outstanding).toLocaleString()}</td><td>${p.over_limit ? '<span class="badge bg-danger">Over limit</span>' : '–'}</td></tr>`).join('') + '</tbody></table>';
            } else {
                list.innerHTML = '<p class="text-muted mb-0">No outstanding receivables.</p>';
            }
        } catch (err) {
            list.innerHTML = '<p class="text-muted mb-0">Unable to load.</p>';
        }
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
});
function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>
