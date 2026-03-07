<!-- Deals list -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="page-title"><i class="bi bi-graph-up-arrow me-2"></i>Deals</h1>
            <p class="page-subtitle">Sales pipeline</p>
        </div>
        <a href="/crm/deals/new" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> New Deal</a>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>All deals</span>
        <div class="d-flex gap-2">
            <select class="form-select form-select-sm" id="filterStage" style="width: auto;">
                <option value="">All stages</option>
            </select>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="loadDeals()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="dealsTable">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Party</th>
                        <th>Stage</th>
                        <th>Value</th>
                        <th>Expected close</th>
                        <th>Assigned</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
let deals = [];
let stages = {};

document.addEventListener('DOMContentLoaded', async function() {
    try {
        const r = await apiCall('/api/crm/stages');
        if (r.success && r.data && r.data.deal_stages) {
            stages = r.data.deal_stages;
            const sel = document.getElementById('filterStage');
            for (const [k, v] of Object.entries(stages)) {
                const opt = document.createElement('option');
                opt.value = k;
                opt.textContent = v;
                sel.appendChild(opt);
            }
        }
        await loadDeals();
    } catch (e) {
        showError(e.message);
    }
});

async function loadDeals() {
    const stage = document.getElementById('filterStage').value;
    let url = '/api/crm/deals';
    if (stage) url += '?stage=' + encodeURIComponent(stage);
    try {
        const r = await apiCall(url);
        deals = r.data || [];
        renderTable();
    } catch (e) {
        showError(e.message);
    }
}

function renderTable() {
    const tbody = document.querySelector('#dealsTable tbody');
    tbody.innerHTML = '';
    deals.forEach(d => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><a href="/crm/deals/${d.id}">${escapeHtml(d.title)}</a></td>
            <td><a href="/crm/parties/${d.party_id}">${escapeHtml(d.party_name || '–')}</a></td>
            <td><span class="badge bg-secondary">${escapeHtml(stages[d.stage] || d.stage)}</span></td>
            <td>${d.value != null ? '₹' + Number(d.value).toLocaleString() : '–'}</td>
            <td>${d.expected_close_date || '–'}</td>
            <td>${escapeHtml(d.assigned_to_name || '–')}</td>
            <td><a href="/crm/deals/${d.id}" class="btn btn-sm btn-outline-primary">View</a></td>
        `;
        tbody.appendChild(tr);
    });
}

function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>
