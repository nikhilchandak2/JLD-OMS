<?php $openId = (int)($override_id ?? 0); ?>
<style>
.credit-tier3 { border: 2px solid #dc3545; background: #fff5f5; }
.credit-tier2 { border: 1px solid #ffc107; }
.credit-action-btn { min-height: 48px; font-size: 1.05rem; }
.credit-breakdown { display: none; }
.credit-breakdown.open { display: block; }
</style>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="page-title">
                <i class="bi bi-shield-check me-2"></i>Credit gate
            </h1>
            <p class="page-subtitle mb-0">Director queue. Tier 3 needs a decision before anything proceeds. Tier 2 may already be moving.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="/admin/reminders" class="btn btn-outline-secondary" title="Send payment reminders">
                <i class="bi bi-envelope-check me-1"></i> Reminders
            </a>
            <button class="btn btn-primary" type="button" onclick="loadQueue()">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>
<?php $feedKey = 'ledger'; $mode = 'group'; include dirname(__DIR__) . '/partials/data-as-of-banner.php'; ?>

<div id="volumeStrip" class="small text-muted mb-3"></div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div>
    <p>Loading credit overrides…</p>
</div>

<div id="detailPanel" class="d-none mb-4"></div>

<div class="card credit-tier3 mb-4">
    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-exclamation-octagon me-2"></i>Tier 3 — decide now</strong>
        <span class="badge bg-light text-danger" id="tier3Count">0</span>
    </div>
    <div class="card-body" id="tier3List"></div>
</div>

<div class="card credit-tier2 mb-4">
    <div class="card-header bg-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong><i class="bi bi-collection me-2"></i>Tier 2 — batch queue</strong>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-success" id="btnBatchApprove" disabled>Approve selected</button>
            <span class="badge bg-dark" id="tier2Count">0</span>
        </div>
    </div>
    <div class="card-body" id="tier2List"></div>
</div>

<script>
const openOverrideId = <?= $openId ?>;
let queueState = { tier2: [], tier3: [] };

function money(v) {
    if (v === null || v === undefined || v === '') return '—';
    return Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function loadQueue() {
    const loading = document.getElementById('loading');
    loading.style.display = 'block';
    try {
        const [queueRes, volRes] = await Promise.all([
            apiCall('/api/credit/overrides?open_only=1'),
            apiCall('/api/credit/overrides/volume')
        ]);
        queueState = queueRes.data || { tier2: [], tier3: [] };
        renderVolume(volRes.data || {});
        renderLists();
        if (openOverrideId) {
            await openDetail(openOverrideId);
        }
    } catch (e) {
        showError(e.message);
    } finally {
        loading.style.display = 'none';
    }
}

function renderVolume(vol) {
    const parts = [];
    [1, 2, 3].forEach(function (tier) {
        const row = vol[tier] || {};
        parts.push('T' + tier + ': ' + (row.total || 0) + ' total' + (row.pending ? ' · ' + row.pending + ' pending' : ''));
    });
    document.getElementById('volumeStrip').textContent = 'Override volume — ' + parts.join(' · ');
}

function renderLists() {
    const t3 = queueState.tier3 || [];
    const t2 = queueState.tier2 || [];
    document.getElementById('tier3Count').textContent = String(t3.length);
    document.getElementById('tier2Count').textContent = String(t2.length);
    document.getElementById('tier3List').innerHTML = t3.length
        ? t3.map(cardHtml).join('')
        : '<p class="text-muted mb-0">No Tier 3 items waiting.</p>';
    document.getElementById('tier2List').innerHTML = t2.length
        ? '<div class="mb-2"><label class="small"><input type="checkbox" id="t2SelectAll" onchange="toggleAllT2(this.checked)"> Select all</label></div>' + t2.map(tier2Row).join('')
        : '<p class="text-muted mb-0">No Tier 2 items waiting.</p>';
    syncBatchButton();
}

function cardHtml(r) {
    return '<button type="button" class="btn btn-outline-danger w-100 text-start mb-2 py-3 credit-action-btn" onclick="openDetail(' + r.id + ')">' +
        '<div class="fw-bold">' + escapeHtml(r.party_name) + '</div>' +
        '<div class="small">₹' + money(r.proposed_order_value) + ' · overage ₹' + money(r.computed_overage) + '</div>' +
        '<div class="small text-truncate">' + escapeHtml(r.rep_reason || '') + '</div></button>';
}

function tier2Row(r) {
    return '<div class="d-flex align-items-center gap-2 border rounded p-2 mb-2">' +
        '<input type="checkbox" class="form-check-input t2-check" value="' + r.id + '" onchange="syncBatchButton()" style="min-width:1.25rem;min-height:1.25rem">' +
        '<button type="button" class="btn btn-link text-start flex-grow-1 p-0 text-decoration-none" onclick="openDetail(' + r.id + ')">' +
        '<div class="fw-semibold text-dark">' + escapeHtml(r.party_name) + '</div>' +
        '<div class="small text-muted">₹' + money(r.proposed_order_value) + ' · ' + escapeHtml(r.rep_reason || '') + '</div></button></div>';
}

function toggleAllT2(on) {
    document.querySelectorAll('.t2-check').forEach(function (el) { el.checked = on; });
    syncBatchButton();
}

function syncBatchButton() {
    const n = document.querySelectorAll('.t2-check:checked').length;
    const btn = document.getElementById('btnBatchApprove');
    btn.disabled = n === 0;
    btn.textContent = n ? ('Approve selected (' + n + ')') : 'Approve selected';
}

document.getElementById('btnBatchApprove').addEventListener('click', async function () {
    const ids = Array.from(document.querySelectorAll('.t2-check:checked')).map(function (el) { return parseInt(el.value, 10); });
    if (!ids.length) return;
    try {
        await apiCall('/api/credit/overrides/batch-approve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: ids })
        });
        showSuccess('Approved ' + ids.length + ' Tier 2 override(s).');
        document.getElementById('detailPanel').classList.add('d-none');
        await loadQueue();
    } catch (e) {
        showError(e.message);
    }
});

async function openDetail(id) {
    const panel = document.getElementById('detailPanel');
    panel.classList.remove('d-none');
    panel.innerHTML = '<div class="card"><div class="card-body">Loading…</div></div>';
    try {
        const res = await apiCall('/api/credit/overrides/' + id);
        panel.innerHTML = detailHtml(res.data);
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
        showError(e.message);
        panel.classList.add('d-none');
    }
}

function detailHtml(r) {
    const lag = r.lagging_entity || (r.incomplete_feed_entities && r.incomplete_feed_entities[0]) || null;
    const lagName = lag ? (lag.company_name || ('#' + lag.company_id)) : null;
    const missing = (r.incomplete_feed_entities || []).map(function (e) { return e.company_name || ('#' + e.company_id); }).join(', ');
    const breakdown = r.outstanding_breakdown || [];
    const history = r.prior_overrides || [];
    const asOf = r.ledger_as_of ? new Date(r.ledger_as_of.replace(' ', 'T')).toLocaleString() : 'no contributing feed';
    const tierClass = Number(r.tier) === 3 ? 'border-danger' : 'border-warning';

    return '<div class="card ' + tierClass + '">' +
        '<div class="card-body">' +
        '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">' +
        '<div><div class="text-muted small">Party</div><div class="h4 mb-0">' + escapeHtml(r.party_name) + '</div></div>' +
        '<span class="badge ' + (Number(r.tier) === 3 ? 'bg-danger' : 'bg-warning text-dark') + '">Tier ' + r.tier + '</span></div>' +
        '<div class="mb-2"><div class="text-muted small">Aggregate outstanding</div>' +
        '<div class="h5 mb-0">₹' + money(r.outstanding_snapshot) + '</div>' +
        '<button type="button" class="btn btn-link btn-sm px-0" onclick="this.nextElementSibling.classList.toggle(\'open\')">Per-entity breakdown</button>' +
        '<div class="credit-breakdown small">' + breakdown.map(function (b) {
            return '<div class="d-flex justify-content-between border-bottom py-1"><span>' + escapeHtml(b.company_name || 'Entity') +
                '</span><span>₹' + money(b.outstanding) + (b.as_of ? ' · ' + escapeHtml(b.as_of) : ' · missing') + '</span></div>';
        }).join('') + '</div></div>' +
        '<p class="small mb-2">Ledger as of <strong>' + escapeHtml(asOf) + '</strong>' +
        (lagName ? '. Lagging entity: <strong>' + escapeHtml(lagName) + '</strong>' : '') +
        (missing ? '. Missing: ' + escapeHtml(missing) : '') +
        '. Not live.</p>' +
        '<p class="mb-1">Proposed value <strong>₹' + money(r.proposed_order_value) + '</strong> · overage <strong>₹' + money(r.computed_overage) + '</strong></p>' +
        '<p class="mb-3">Rep reason: ' + escapeHtml(r.rep_reason || '—') + '</p>' +
        (history.length ? '<details class="mb-3"><summary class="small">Prior overrides for this party (' + history.length + ')</summary>' +
            history.map(function (h) {
                return '<div class="small border-bottom py-1">' + escapeHtml(h.status) + ' · T' + h.tier + ' · ₹' + money(h.proposed_order_value) + ' · ' + escapeHtml(h.requested_at || '') + '</div>';
            }).join('') + '</details>' : '') +
        (Number(r.tier) !== 3 && (r.status === 'pending' || r.status === 'call_requested') ? '' : '') +
        '<div class="d-grid gap-2">' +
        '<button type="button" class="btn btn-success credit-action-btn" onclick="decide(' + r.id + ', \'approve\')">Approve</button>' +
        '<button type="button" class="btn btn-outline-success credit-action-btn" onclick="approveModified(' + r.id + ')">Approve with modified limit</button>' +
        '<button type="button" class="btn btn-outline-danger credit-action-btn" onclick="rejectOverride(' + r.id + ')">Reject</button>' +
        (r.status === 'call_requested'
            ? '<div class="alert alert-info py-2 mb-0">Call requested — still waiting on you.</div>'
            : '<button type="button" class="btn btn-outline-primary credit-action-btn" onclick="decide(' + r.id + ', \'call\')">Call me first</button>') +
        '</div></div></div>';
}

async function decide(id, action, extra) {
    try {
        await apiCall('/api/credit/overrides/' + id + '/decide', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action: action }, extra || {}))
        });
        showSuccess('Updated.');
        document.getElementById('detailPanel').classList.add('d-none');
        await loadQueue();
    } catch (e) {
        showError(e.message);
    }
}

function approveModified(id) {
    const raw = prompt('New group-wide credit limit (₹):', '');
    if (raw === null) return;
    const val = Number(raw);
    if (!Number.isFinite(val) || val <= 0) {
        showError('Modified limit must be a positive number.');
        return;
    }
    decide(id, 'approve_modified', { modified_limit_value: val });
}

function rejectOverride(id) {
    const note = prompt('Rejection note (required):', '');
    if (note === null) return;
    if (!String(note).trim()) {
        showError('A decision note is required to reject.');
        return;
    }
    decide(id, 'reject', { decision_note: String(note).trim() });
}

document.addEventListener('DOMContentLoaded', loadQueue);
</script>
