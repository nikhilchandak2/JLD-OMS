<!-- CRM Funnel – kanban-style pipeline dashboard -->
<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
                    <li class="breadcrumb-item active">Funnel</li>
                </ol>
            </nav>
            <h1 class="page-title mb-1">Sales Funnel</h1>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="/crm/parties/new" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Add New</a>
            <a href="/admin/parties" class="btn btn-primary px-4" title="<?= isset($user) ? htmlspecialchars(ucfirst($user['role'] ?? 'User')) : 'User' ?>"><i class="bi bi-person-circle me-2"></i>Parties</a>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRefreshFunnel" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
    </div>
</div>

<div id="error-container" class="error-message mb-3"></div>

<!-- Kanban board: 5 columns, each with stage + company cards -->
<div class="crm-funnel-board" id="funnelBoard">
    <!-- Filled by JS -->
</div>

<script>
const STAGE_COLORS = [
    { bg: '#2b235e', label: 'Sampling' },
    { bg: '#0d6efd', label: 'Technical Support' },
    { bg: '#fd7e14', label: 'Re-Sampling' },
    { bg: '#198754', label: 'Trial Order' },
    { bg: '#6c757d', label: 'Closed' }
];

document.addEventListener('DOMContentLoaded', loadFunnelBoard);
document.getElementById('btnRefreshFunnel').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    loadFunnelBoard().finally(function() { btn.disabled = false; });
});

async function loadFunnelBoard() {
    const board = document.getElementById('funnelBoard');
    try {
        const summaryRes = await apiCall('/api/crm/funnel');
        const stages = summaryRes.data || [];
        const stagesWithKeys = stages.map(function(s, i) {
            const c = STAGE_COLORS[i] || { bg: '#6c757d', label: s.label };
            return { key: s.stage, label: s.label || c.label, count: s.count || 0, total_value: s.total_value || 0, color: c.bg };
        });

        board.innerHTML = stagesWithKeys.map(function(col) {
            const valueStr = col.total_value > 0 ? '₹' + Number(col.total_value).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '₹0';
            return '<div class="crm-funnel-column" data-stage="' + escapeAttr(col.key) + '">' +
                '<div class="crm-funnel-column-header" style="background:' + col.color + '">' + escapeHtml(col.label) + '</div>' +
                '<div class="crm-funnel-column-cards" id="col-cards-' + escapeAttr(col.key) + '">' +
                '<div class="text-center py-3 text-muted small">Loading…</div></div>' +
                '<div class="crm-funnel-column-meta">' + col.count + ' companies · ' + valueStr + '</div></div>';
        }).join('');

        const stageKeys = stagesWithKeys.map(function(s) { return s.key; });
        const companiesByStage = {};
        await Promise.all(stageKeys.map(async function(stage) {
            try {
                const r = await apiCall('/api/crm/funnel?stage=' + encodeURIComponent(stage));
                companiesByStage[stage] = r.data || [];
            } catch (e) {
                companiesByStage[stage] = [];
            }
        }));

        stageKeys.forEach(function(stage) {
            const list = companiesByStage[stage] || [];
            const container = document.getElementById('col-cards-' + stage);
            if (!container) return;
            if (list.length === 0) {
                container.innerHTML = '<p class="text-muted small text-center py-3 mb-0">No companies</p>';
            } else {
                container.innerHTML = list.map(function(p) {
                    const val = p.funnel_value != null ? '₹' + Number(p.funnel_value).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '–';
                    const name = escapeHtml(p.name || 'Unnamed');
                    const contact = escapeHtml(p.contact_person || '');
                    const email = escapeHtml((p.email || '').slice(0, 25)) + (p.email && p.email.length > 25 ? '…' : '');
                    return '<a href="/crm/parties/' + p.id + '" class="crm-company-card">' +
                        '<div class="company-name">' + name + '</div>' +
                        (contact ? '<div class="company-meta">' + contact + '</div>' : '') +
                        (email ? '<div class="company-meta">' + email + '</div>' : '') +
                        '<div class="company-value">' + val + '</div></a>';
                }).join('');
            }
        });
    } catch (e) {
        document.getElementById('error-container').textContent = e.message || 'Failed to load funnel';
        board.innerHTML = '<p class="text-muted">Unable to load pipeline. Check your connection and try again.</p>';
    }
}

function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
function escapeAttr(s) {
    if (s == null) return '';
    return String(s).replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
</script>
