<div class="page-header mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item active">Escalations</li>
        </ol>
    </nav>
    <h1 class="page-title mb-0">Escalation inbox</h1>
    <p class="text-muted small mb-0">Context is the state at trigger time. Acknowledging stops the nightly job from raising it again.</p>
</div>

<div id="error-container" class="error-message mb-3"></div>
<div id="escalationNotice" class="alert alert-success d-none" role="status"></div>
<div id="escalationList"><p class="text-muted">Loading…</p></div>

<script>
function snapshotHtml(snap) {
    if (!snap || typeof snap !== 'object') return '';
    const contacts = (snap.contacts || []).map(function (c) {
        return escapeHtml(c.name) + (c.relationship_strength && c.relationship_strength !== 'unknown' ? ' (' + escapeHtml(c.relationship_strength) + ')' : '');
    }).join(', ') || '—';
    const comps = (snap.competitors || []).map(function (c) {
        return escapeHtml(c.competitor_name) + (c.grade_code ? ' / ' + escapeHtml(c.grade_code) : '');
    }).join(', ') || '—';
    const issues = (snap.open_issues || []).map(function (i) {
        return escapeHtml(i.issue_type) + ': ' + escapeHtml((i.description || '').slice(0, 80));
    }).join(' · ') || '—';
    return '<dl class="row small mb-0 mt-2">' +
        '<dt class="col-sm-3">Why</dt><dd class="col-sm-9">' + escapeHtml(snap.reason || '') + '</dd>' +
        '<dt class="col-sm-3">Contacts</dt><dd class="col-sm-9">' + contacts + '</dd>' +
        '<dt class="col-sm-3">Competitors</dt><dd class="col-sm-9">' + comps + '</dd>' +
        '<dt class="col-sm-3">Open issues</dt><dd class="col-sm-9">' + issues + '</dd>' +
        '</dl>';
}

async function act(id, action) {
    let body = {};
    if (action === 'resolve' || action === 'dismiss') {
        const note = prompt(action === 'dismiss' ? 'Why dismiss?' : 'Resolution note');
        if (!note) return;
        body = { resolution_note: note };
    }
    try {
        await apiCall('/api/crm/escalations/' + id + '/' + action, {
            method: 'POST',
            body: JSON.stringify(body)
        });
        const n = document.getElementById('escalationNotice');
        n.textContent = action === 'acknowledge' ? 'Acknowledged — it will not be re-raised tonight.' : 'Saved.';
        n.classList.remove('d-none');
        await loadInbox();
    } catch (e) {
        showError(e.message);
    }
}

async function loadInbox() {
    const el = document.getElementById('escalationList');
    try {
        const res = await apiCall('/api/crm/escalations');
        const list = res.data || [];
        if (list.length === 0) {
            el.innerHTML = '<p class="text-muted mb-0">Inbox is clear.</p>';
            return;
        }
        el.innerHTML = list.map(function (e) {
            const badge = e.status === 'open'
                ? '<span class="badge bg-danger">Open</span>'
                : '<span class="badge bg-secondary">Acknowledged</span>';
            const actions = e.status === 'open'
                ? '<button type="button" class="btn btn-sm btn-outline-primary" data-act="acknowledge" data-id="' + e.id + '">Acknowledge</button>'
                : '';
            return '<div class="card mb-3"><div class="card-body">' +
                '<div class="d-flex justify-content-between flex-wrap gap-2">' +
                '<div><a href="/crm/parties/' + e.party_id + '" class="fw-semibold">' + escapeHtml(e.party_name) + '</a>' +
                '<div class="small text-muted">' + escapeHtml(e.trigger_label) + ' · ' + escapeHtml(e.triggered_on) + '</div></div>' +
                badge + '</div>' +
                snapshotHtml(e.context_snapshot) +
                '<div class="d-flex flex-wrap gap-2 mt-3">' +
                actions +
                '<button type="button" class="btn btn-sm btn-success" data-act="resolve" data-id="' + e.id + '">Resolve</button>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="dismiss" data-id="' + e.id + '">Dismiss</button>' +
                '</div></div></div>';
        }).join('');
        el.querySelectorAll('[data-act]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                act(btn.getAttribute('data-id'), btn.getAttribute('data-act'));
            });
        });
    } catch (e) {
        showError(e.message);
        el.innerHTML = '';
    }
}

document.addEventListener('DOMContentLoaded', loadInbox);
</script>
