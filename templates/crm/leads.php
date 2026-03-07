<!-- Leads list -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="page-title"><i class="bi bi-lightning-charge me-2"></i>Leads</h1>
            <p class="page-subtitle">Track and convert leads to deals</p>
        </div>
        <a href="/crm/leads/new" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> New Lead</a>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>All leads</span>
        <div class="d-flex gap-2">
            <select class="form-select form-select-sm" id="filterStage" style="width: auto;">
                <option value="">All stages</option>
            </select>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="loadLeads()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="leadsTable">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Company</th>
                        <th>Contact</th>
                        <th>Stage</th>
                        <th>Value</th>
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
let leads = [];
let stages = {};

document.addEventListener('DOMContentLoaded', async function() {
    try {
        const r = await apiCall('/api/crm/stages');
        if (r.success && r.data && r.data.lead_stages) {
            stages = r.data.lead_stages;
            const sel = document.getElementById('filterStage');
            for (const [k, v] of Object.entries(stages)) {
                const opt = document.createElement('option');
                opt.value = k;
                opt.textContent = v;
                sel.appendChild(opt);
            }
        }
        await loadLeads();
    } catch (e) {
        showError(e.message);
    }
});

async function loadLeads() {
    const stage = document.getElementById('filterStage').value;
    let url = '/api/crm/leads';
    if (stage) url += '?stage=' + encodeURIComponent(stage);
    try {
        const r = await apiCall(url);
        leads = r.data || [];
        renderTable();
    } catch (e) {
        showError(e.message);
    }
}

function renderTable() {
    const tbody = document.querySelector('#leadsTable tbody');
    tbody.innerHTML = '';
    leads.forEach(l => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><a href="/crm/leads/${l.id}">${escapeHtml(l.title)}</a></td>
            <td>${escapeHtml(l.company_name || '–')}</td>
            <td>${escapeHtml(l.contact_name || '–')}</td>
            <td><span class="badge bg-secondary">${escapeHtml(stages[l.stage] || l.stage)}</span></td>
            <td>${l.value != null ? formatMoney(l.value) : '–'}</td>
            <td>${escapeHtml(l.assigned_to_name || '–')}</td>
            <td>
                <a href="/crm/leads/${l.id}" class="btn btn-sm btn-outline-primary">View</a>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function formatMoney(n) {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(n);
}
function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>
