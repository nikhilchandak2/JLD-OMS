<!-- Technical queue — team-routed, overdue first. There is no per-person assignment by design. -->
<div class="page-header mb-3">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
                    <li class="breadcrumb-item active">Technical queue</li>
                </ol>
            </nav>
            <h1 class="page-title mb-0">Technical queue</h1>
        </div>
        <div class="d-flex gap-2">
            <select class="form-select" id="filterQueue" style="min-width: 200px;">
                <option value="">All queues</option>
            </select>
            <select class="form-select" id="filterFlagStatus" style="min-width: 170px;">
                <option value="open_only">Open + claimed</option>
                <option value="">Everything</option>
                <option value="resolved">Resolved</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
    </div>
</div>

<div id="queueError" class="alert alert-danger d-none" role="alert"></div>
<div id="queueNotice" class="alert alert-success d-none" role="alert"></div>

<div id="queueList" class="d-flex flex-column gap-2">
    <div class="text-muted">Loading…</div>
</div>

<div class="modal fade" id="resolveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="resolveForm">
            <div class="modal-header">
                <h5 class="modal-title">Resolve technical query</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="resolveFlagId">
                <div class="mb-3">
                    <label class="form-label" for="resolutionType">How was it resolved?</label>
                    <select class="form-select" id="resolutionType" required>
                        <option value="remote_answer">Answered remotely</option>
                        <option value="site_visit">Site visit</option>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="resolutionNote">What was the answer?</label>
                    <textarea class="form-control" id="resolutionNote" rows="4" required></textarea>
                    <div class="form-text">Written down so the next rep can reuse it.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save resolution</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    loadQueues();
    loadFlags();
    document.getElementById('filterQueue').addEventListener('change', loadFlags);
    document.getElementById('filterFlagStatus').addEventListener('change', loadFlags);
    document.getElementById('resolveForm').addEventListener('submit', submitResolution);
});

function queueNotice(message) {
    const box = document.getElementById('queueNotice');
    box.textContent = message;
    box.classList.remove('d-none');
    document.getElementById('queueError').classList.add('d-none');
}

function queueError(message) {
    const box = document.getElementById('queueError');
    box.textContent = message;
    box.classList.remove('d-none');
    document.getElementById('queueNotice').classList.add('d-none');
}

async function loadQueues() {
    try {
        const res = await apiCall('/api/crm/technical-flags/queues');
        document.getElementById('filterQueue').innerHTML = '<option value="">All queues</option>' +
            (res.data || []).map(function (q) {
                return '<option value="' + q.id + '">' + escapeHtml(q.name) + '</option>';
            }).join('');
    } catch (e) {
        queueError(e.message);
    }
}

async function loadFlags() {
    const params = new URLSearchParams();
    const queueId = document.getElementById('filterQueue').value;
    const status = document.getElementById('filterFlagStatus').value;
    if (queueId) params.set('queue_id', queueId);
    if (status === 'open_only') {
        params.set('open_only', '1');
    } else if (status) {
        params.set('status', status);
    }

    const list = document.getElementById('queueList');
    list.innerHTML = '<div class="text-muted">Loading…</div>';

    try {
        const res = await apiCall('/api/crm/technical-flags?' + params.toString());
        const flags = res.data || [];
        if (flags.length === 0) {
            list.innerHTML = '<div class="text-muted">Nothing in this queue right now.</div>';
            return;
        }
        list.innerHTML = flags.map(renderFlagCard).join('');
        list.querySelectorAll('[data-claim]').forEach(function (btn) {
            btn.addEventListener('click', function () { claimFlag(btn.dataset.claim); });
        });
        list.querySelectorAll('[data-resolve]').forEach(function (btn) {
            btn.addEventListener('click', function () { openResolve(btn.dataset.resolve); });
        });
    } catch (e) {
        list.innerHTML = '';
        queueError(e.message);
    }
}

function renderFlagCard(f) {
    const overdue = f.is_overdue ? '<span class="badge bg-danger ms-2">Overdue</span>' : '';
    const dealLink = f.deal_id
        ? '<a href="/crm/deals/' + f.deal_id + '">' + escapeHtml(f.deal_title || ('Deal #' + f.deal_id)) + '</a>'
        : '<span class="text-muted">Existing account, no deal</span>';
    const actions = (f.status === 'open' || f.status === 'claimed')
        ? '<div class="d-flex gap-2 mt-2">' +
            (f.status === 'open' ? '<button class="btn btn-sm btn-outline-primary" data-claim="' + f.id + '">Claim</button>' : '') +
            '<button class="btn btn-sm btn-primary" data-resolve="' + f.id + '">Resolve</button>' +
          '</div>'
        : (f.resolution_note ? '<div class="small mt-2"><strong>Answer:</strong> ' + escapeHtml(f.resolution_note) + '</div>' : '');

    return '<div class="card ' + (f.is_overdue ? 'border-danger' : '') + '"><div class="card-body py-3">' +
        '<div class="d-flex justify-content-between flex-wrap gap-2">' +
            '<div class="fw-semibold">' + escapeHtml(f.party_name) + overdue + '</div>' +
            '<div class="small text-muted">' + escapeHtml(f.queue_name) + ' · <span class="text-capitalize">' + escapeHtml(f.status) + '</span></div>' +
        '</div>' +
        '<div class="mt-1">' + escapeHtml(f.nature_of_query) + '</div>' +
        '<div class="small text-muted mt-1">' + dealLink +
            (f.raised_from_stage ? ' · raised at stage ' + f.raised_from_stage : '') +
            ' · raised ' + escapeHtml(f.created_at) + ' by ' + escapeHtml(f.raised_by_name || 'unknown') +
            ' · due ' + escapeHtml(f.expected_turnaround_at || '—') +
            (f.claimed_by_name ? ' · claimed by ' + escapeHtml(f.claimed_by_name) : '') +
        '</div>' + actions +
        '</div></div>';
}

async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify(body || {})
    });
    const text = await res.text();
    let data = {};
    try { data = text ? JSON.parse(text) : {}; } catch (e) { throw new Error('Server error (HTTP ' + res.status + ')'); }
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
}

async function claimFlag(id) {
    try {
        const res = await postJson('/api/crm/technical-flags/' + id + '/claim', {});
        queueNotice(res.message || 'Flag claimed.');
        await loadFlags();
    } catch (e) {
        queueError(e.message);
    }
}

function openResolve(id) {
    document.getElementById('resolveFlagId').value = id;
    document.getElementById('resolutionNote').value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('resolveModal')).show();
}

async function submitResolution(event) {
    event.preventDefault();
    const id = document.getElementById('resolveFlagId').value;
    const note = document.getElementById('resolutionNote');
    if (note.value.trim() === '') {
        note.classList.add('is-invalid');
        return;
    }
    note.classList.remove('is-invalid');

    try {
        const res = await postJson('/api/crm/technical-flags/' + id + '/resolve', {
            resolution_type: document.getElementById('resolutionType').value,
            resolution_note: note.value
        });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('resolveModal')).hide();
        queueNotice(res.message || 'Flag resolved.');
        await loadFlags();
    } catch (e) {
        queueError(e.message);
    }
}
</script>
