<!-- Deal detail — one stage control, and the reason it is disabled is always on screen -->
<?php $dealId = (int)($deal_id ?? 0); ?>
<div class="page-header mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item"><a href="/crm/deals">Deals</a></li>
            <li class="breadcrumb-item active">Deal #<?= $dealId ?></li>
        </ol>
    </nav>
    <h1 class="page-title mb-0" id="dealTitle">Loading…</h1>
    <div class="text-muted small" id="dealSubtitle"></div>
</div>

<div id="dealError" class="alert alert-danger d-none" role="alert"></div>
<div id="dealNotice" class="alert alert-success d-none" role="alert"></div>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <div class="text-muted small">Current stage</div>
                        <div class="h5 mb-1" id="stageLabel">—</div>
                        <div id="holdBadge"></div>
                    </div>
                    <div class="text-end" id="statusBadge"></div>
                </div>

                <hr>

                <div id="criteriaBlock"></div>

                <div class="d-grid gap-2 mt-3">
                    <button class="btn btn-success btn-lg py-3" id="btnAdvance" disabled>Move forward</button>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <button class="btn btn-outline-secondary btn-sm" id="btnMoveBack">Move back a stage</button>
                    <button class="btn btn-outline-danger btn-sm" id="btnClose">Mark lost / dropped</button>
                    <button class="btn btn-outline-primary btn-sm d-none" id="btnReopen">Reopen deal</button>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Sales → Dispatch packet</div>
            <div class="card-body" id="dealHandoffRoot">
                <p class="text-muted small mb-0">Loading…</p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Technical queries</span>
                <button class="btn btn-sm btn-outline-primary" id="btnRaiseFlag">Raise technical query</button>
            </div>
            <div class="card-body" id="flagsBlock"></div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card mb-3">
            <div class="card-header">Deal</div>
            <div class="card-body" id="dealFacts"></div>
        </div>
        <div class="card mb-3">
            <div class="card-header">Account knowledge</div>
            <div class="card-body" id="dealAccountContext"><p class="text-muted small mb-0">Loading…</p></div>
        </div>
        <div class="card">
            <div class="card-header">Stage history</div>
            <div class="card-body p-0"><ul class="list-group list-group-flush" id="historyBlock"></ul></div>
        </div>
    </div>
</div>

<!-- One modal, reused for every reason prompt: no nested modals on this page -->
<div class="modal fade" id="reasonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="reasonModalForm">
            <div class="modal-header">
                <h5 class="modal-title" id="reasonModalTitle">Reason</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="reasonModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
const DEAL_ID = <?= $dealId ?>;
let dealState = null;

document.addEventListener('DOMContentLoaded', function () {
    loadDeal();
    document.getElementById('btnAdvance').addEventListener('click', advanceDeal);
    document.getElementById('btnMoveBack').addEventListener('click', moveBackDeal);
    document.getElementById('btnClose').addEventListener('click', closeDeal);
    document.getElementById('btnReopen').addEventListener('click', reopenDeal);
    document.getElementById('btnRaiseFlag').addEventListener('click', raiseFlag);
});

async function post(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify(body || {})
    });
    const text = await res.text();
    let data = {};
    try { data = text ? JSON.parse(text) : {}; } catch (e) { throw new Error('Server error (HTTP ' + res.status + ')'); }
    if (!res.ok) {
        const error = new Error(data.error || 'Request failed');
        error.unmet = data.unmet || [];
        throw error;
    }
    return data;
}

function notice(message) {
    const box = document.getElementById('dealNotice');
    box.textContent = message;
    box.classList.remove('d-none');
    document.getElementById('dealError').classList.add('d-none');
}

function fail(error) {
    const box = document.getElementById('dealError');
    let html = escapeHtml(error.message);
    if (error.unmet && error.unmet.length) {
        html += '<ul class="mb-0 mt-2">' + error.unmet.map(function (u) {
            return '<li>' + escapeHtml(u.label) + '</li>';
        }).join('') + '</ul>';
    }
    box.innerHTML = html;
    box.classList.remove('d-none');
    document.getElementById('dealNotice').classList.add('d-none');
}

async function loadDeal() {
    try {
        const res = await apiCall('/api/crm/deals/' + DEAL_ID);
        dealState = res.data;
        render();
    } catch (e) {
        fail(e);
    }
}

function render() {
    const d = dealState;
    const criteria = d.exit_criteria;

    document.getElementById('dealTitle').textContent = d.title || ('Deal #' + d.id);
    document.getElementById('dealSubtitle').textContent = d.party_name + ' · enquiry ' + (d.inquiry_date || '—');
    document.getElementById('stageLabel').textContent = d.stage + '. ' + d.stage_label;
    document.getElementById('statusBadge').innerHTML = d.status === 'active'
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-secondary text-capitalize">' + escapeHtml(d.status) + '</span>' +
          (d.lost_reason_label ? '<div class="small text-muted mt-1">' + escapeHtml(d.lost_reason_label) + '</div>' : '');
    document.getElementById('holdBadge').innerHTML = d.is_on_technical_hold
        ? '<span class="badge bg-warning text-dark">Technical hold — waiting on an answer</span>'
        : '';

    renderCriteria(criteria);
    renderStageButtons(criteria);
    renderFacts(d);
    renderFlags(d);
    renderHistory(d);
    if (window.AccountContext) AccountContext.mountDeal(d);
    if (window.HandoffUI) HandoffUI.mountDeal(d);
}

function renderCriteria(criteria) {
    const rows = (criteria.criteria || []).map(function (c) {
        const tick = c.satisfied
            ? '<i class="bi bi-check-circle-fill text-success me-2"></i>'
            : '<i class="bi bi-circle text-muted me-2"></i>';
        const required = c.is_mandatory ? '' : ' <span class="badge bg-light text-muted border">optional</span>';
        if (c.source === 'derived') {
            return '<div class="mb-2">' + tick + escapeHtml(c.label) + required +
                '<div class="small text-muted ps-4">' + escapeHtml(c.value || 'Not recorded yet') + '</div></div>';
        }
        return '<div class="mb-3">' +
            '<label class="form-label small mb-1" for="crit_' + escapeHtml(c.field_key) + '">' + tick + escapeHtml(c.label) + required + '</label>' +
            '<input type="text" class="form-control" id="crit_' + escapeHtml(c.field_key) + '" ' +
            'data-field-key="' + escapeHtml(c.field_key) + '" value="' + escapeHtml(c.value || '') + '" ' +
            'placeholder="' + escapeHtml(c.help_text || '') + '">' +
            '</div>';
    }).join('');

    const unmet = criteria.unmet || [];
    const blocker = unmet.length
        ? '<div class="alert alert-warning py-2 mb-3">Still needed before leaving this stage:<ul class="mb-0">' +
          unmet.map(function (u) { return '<li>' + escapeHtml(u.label) + '</li>'; }).join('') + '</ul></div>'
        : '';

    document.getElementById('criteriaBlock').innerHTML = blocker + rows +
        '<button class="btn btn-outline-secondary btn-sm" id="btnSaveCriteria">Save stage details</button>';

    const saveBtn = document.getElementById('btnSaveCriteria');
    if (saveBtn) saveBtn.addEventListener('click', saveCriteria);
}

function renderStageButtons(criteria) {
    const d = dealState;
    const advance = document.getElementById('btnAdvance');
    const terminal = d.status !== 'active';

    document.getElementById('btnReopen').classList.toggle('d-none', !terminal);
    document.getElementById('btnMoveBack').classList.toggle('d-none', terminal || d.stage <= 1);
    document.getElementById('btnClose').classList.toggle('d-none', terminal);

    if (terminal) {
        advance.classList.add('d-none');
        return;
    }
    advance.classList.remove('d-none');

    if (criteria.next_stage) {
        advance.textContent = 'Move to ' + criteria.next_stage + '. ' + criteria.next_stage_label;
        advance.dataset.action = 'advance';
        advance.disabled = !criteria.can_advance;
    } else {
        advance.textContent = 'Mark deal won';
        advance.dataset.action = 'win';
        advance.disabled = !criteria.can_win;
    }
}

function renderFacts(d) {
    const facts = [
        ['Customer', d.party_name],
        ['Owner', d.owner_name || '—'],
        ['Enquiry source', d.source || '—'],
        ['Indicative quantity', d.indicative_quantity_tonnes ? d.indicative_quantity_tonnes + ' t' : '—'],
        ['Grades', (d.grades || []).map(function (g) { return g.grade_code; }).join(', ') || '—'],
        ['In this stage since', d.stage_entered_at || '—']
    ];
    if (d.value !== undefined) facts.push(['Deal value', d.value === null ? '—' : d.value]);

    const gate = d.credit_gate;
    if (gate && Number(d.stage) >= 5) {
        const tier = gate.tier ? ('Tier ' + gate.tier) : 'Credit';
        const headroom = gate.headroom == null ? '—' : ('₹' + Number(gate.headroom).toLocaleString('en-IN'));
        const asOf = gate.ledger_as_of || 'no contributing feed';
        facts.push([tier + ' status', (gate.credit_gate_status || '—') + ' · headroom ' + headroom]);
        facts.push(['Ledger as-of', asOf + ' · not live']);
    }

    document.getElementById('dealFacts').innerHTML = facts.map(function (f) {
        return '<div class="d-flex justify-content-between border-bottom py-1"><span class="text-muted small">' +
            escapeHtml(f[0]) + '</span><span class="small fw-semibold text-end">' + escapeHtml(f[1]) + '</span></div>';
    }).join('');
}

function renderFlags(d) {
    const flags = d.open_technical_flags || [];
    if (flags.length === 0) {
        document.getElementById('flagsBlock').innerHTML =
            '<p class="text-muted mb-0">No open technical query. Raise one if the customer needs a technical answer.</p>';
        return;
    }
    document.getElementById('flagsBlock').innerHTML = flags.map(function (f) {
        const overdue = f.is_overdue ? ' <span class="badge bg-danger">Overdue</span>' : '';
        return '<div class="border rounded p-2 mb-2">' +
            '<div class="fw-semibold">' + escapeHtml(f.queue_name) + ' · <span class="text-capitalize">' + escapeHtml(f.status) + '</span>' + overdue + '</div>' +
            '<div class="small">' + escapeHtml(f.nature_of_query) + '</div>' +
            '<div class="small text-muted">Raised ' + escapeHtml(f.created_at) + ' · due ' + escapeHtml(f.expected_turnaround_at || '—') +
            (f.claimed_by_name ? ' · claimed by ' + escapeHtml(f.claimed_by_name) : '') + '</div>' +
            '</div>';
    }).join('');
}

function renderHistory(d) {
    const history = d.history || [];
    if (history.length === 0) {
        document.getElementById('historyBlock').innerHTML =
            '<li class="list-group-item text-muted">Captured at Stage 1. Nothing has moved yet.</li>';
        return;
    }
    document.getElementById('historyBlock').innerHTML = history.slice().reverse().map(function (h) {
        const from = h.from_stage_label ? h.from_stage_label : h.from_status;
        const to = h.to_status === 'active' ? h.to_stage_label : h.to_status;
        return '<li class="list-group-item">' +
            '<div class="small fw-semibold">' + escapeHtml(from) + ' → ' + escapeHtml(to) + '</div>' +
            '<div class="small text-muted">' + escapeHtml(h.occurred_at) + ' · ' + escapeHtml(h.actor_name || 'system') + '</div>' +
            (h.reason_label ? '<div class="small">Reason: ' + escapeHtml(h.reason_label) + '</div>' : '') +
            (h.reason_note ? '<div class="small text-muted">' + escapeHtml(h.reason_note) + '</div>' : '') +
            '</li>';
    }).join('');
}

async function saveCriteria() {
    const values = {};
    document.querySelectorAll('#criteriaBlock [data-field-key]').forEach(function (input) {
        values[input.dataset.fieldKey] = input.value;
    });
    try {
        await post('/api/crm/deals/' + DEAL_ID + '/criteria', { values: values });
        notice('Stage details saved.');
        await loadDeal();
    } catch (e) {
        fail(e);
    }
}

async function advanceDeal() {
    const action = document.getElementById('btnAdvance').dataset.action;
    try {
        const res = await post('/api/crm/deals/' + DEAL_ID + '/' + (action === 'win' ? 'win' : 'advance'), {});
        notice(res.message || 'Deal moved.');
        await loadDeal();
    } catch (e) {
        fail(e);
    }
}

/**
 * Ask for structured input in the shared modal. Resolves with a values object, or null if
 * the user cancels.
 */
function askInModal(title, fields, submitLabel) {
    return new Promise(function (resolve) {
        const modalEl = document.getElementById('reasonModal');
        const form = document.getElementById('reasonModalForm');
        document.getElementById('reasonModalTitle').textContent = title;
        form.querySelector('button[type="submit"]').textContent = submitLabel || 'Confirm';
        document.getElementById('reasonModalBody').innerHTML = fields.map(function (f) {
            const id = 'modal_' + f.name;
            if (f.type === 'select') {
                return '<div class="mb-3"><label class="form-label" for="' + id + '">' + escapeHtml(f.label) + '</label>' +
                    '<select class="form-select" id="' + id + '" name="' + escapeHtml(f.name) + '"' + (f.required ? ' required' : '') + '>' +
                    f.options.map(function (o) {
                        return '<option value="' + escapeHtml(o.value) + '">' + escapeHtml(o.label) + '</option>';
                    }).join('') + '</select>' +
                    (f.help ? '<div class="form-text">' + escapeHtml(f.help) + '</div>' : '') + '</div>';
            }
            return '<div class="mb-3"><label class="form-label" for="' + id + '">' + escapeHtml(f.label) + '</label>' +
                '<textarea class="form-control" rows="3" id="' + id + '" name="' + escapeHtml(f.name) + '"' +
                (f.required ? ' required' : '') + '></textarea>' +
                (f.help ? '<div class="form-text">' + escapeHtml(f.help) + '</div>' : '') + '</div>';
        }).join('');

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        let submitted = false;

        function onSubmit(event) {
            event.preventDefault();
            const values = {};
            let valid = true;
            fields.forEach(function (f) {
                const input = document.getElementById('modal_' + f.name);
                values[f.name] = input.value;
                if (f.required && String(input.value).trim() === '') {
                    input.classList.add('is-invalid');
                    valid = false;
                } else {
                    input.classList.remove('is-invalid');
                }
            });
            if (!valid) return;
            submitted = true;
            cleanup();
            modal.hide();
            resolve(values);
        }

        function onHidden() {
            if (!submitted) {
                cleanup();
                resolve(null);
            }
        }

        function cleanup() {
            form.removeEventListener('submit', onSubmit);
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
        }

        form.addEventListener('submit', onSubmit);
        modalEl.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
    });
}

async function moveBackDeal() {
    const answer = await askInModal('Move back a stage', [{
        name: 'reason_note',
        label: 'Why is this deal going back a stage?',
        required: true,
        help: 'Recorded permanently against the deal.'
    }], 'Move back');
    if (answer === null) return;
    const reason = answer.reason_note;
    try {
        const res = await post('/api/crm/deals/' + DEAL_ID + '/move-back', { reason_note: reason });
        notice(res.message || 'Deal moved back.');
        await loadDeal();
    } catch (e) {
        fail(e);
    }
}

async function closeDeal() {
    let codes = [];
    try {
        const res = await apiCall('/api/crm/deals/reason-codes');
        codes = res.data || [];
    } catch (e) {
        fail(e);
        return;
    }
    const answer = await askInModal('Close this deal', [
        {
            name: 'status', type: 'select', label: 'Outcome', required: true,
            options: [
                { value: 'lost', label: 'Lost — the customer said no' },
                { value: 'dropped', label: 'Dropped — we walked away' }
            ]
        },
        {
            name: 'reason_code_id', type: 'select', label: 'Reason', required: true,
            options: codes.map(function (c) {
                return { value: String(c.id), label: c.label + ' (' + c.applies_to + ')' };
            }),
            help: 'A reason is required so lost-reason patterns can be reviewed later.'
        },
        { name: 'reason_note', label: 'Anything to add? (optional)', required: false }
    ], 'Close deal');
    if (answer === null) return;

    try {
        const res = await post('/api/crm/deals/' + DEAL_ID + '/close', {
            status: answer.status,
            reason_code_id: answer.reason_code_id,
            reason_note: answer.reason_note
        });
        notice(res.message || 'Deal closed.');
        await loadDeal();
    } catch (e) {
        fail(e);
    }
}

async function reopenDeal() {
    const answer = await askInModal('Reopen this deal', [{
        name: 'reason_note', label: 'Why is this deal being reopened?', required: true
    }], 'Reopen');
    if (answer === null) return;
    try {
        const res = await post('/api/crm/deals/' + DEAL_ID + '/reopen', { reason_note: answer.reason_note });
        notice(res.message || 'Deal reopened.');
        await loadDeal();
    } catch (e) {
        fail(e);
    }
}

async function raiseFlag() {
    let queues = [];
    try {
        const res = await apiCall('/api/crm/technical-flags/queues');
        queues = res.data || [];
    } catch (e) {
        fail(e);
        return;
    }
    if (queues.length === 0) {
        fail(new Error('No technical queue is configured.'));
        return;
    }
    const answer = await askInModal('Raise a technical query', [
        {
            name: 'routed_to_queue_id', type: 'select', label: 'Send to', required: true,
            options: queues.map(function (q) { return { value: String(q.id), label: q.name }; }),
            help: 'Goes to the whole team, not to one person.'
        },
        { name: 'nature_of_query', label: 'What does the customer need technically?', required: true }
    ], 'Send to queue');
    if (answer === null) return;

    try {
        const res = await post('/api/crm/technical-flags', {
            deal_id: DEAL_ID,
            nature_of_query: answer.nature_of_query,
            routed_to_queue_id: answer.routed_to_queue_id
        });
        notice(res.message || 'Technical query raised.');
        await loadDeal();
    } catch (e) {
        fail(e);
    }
}
</script>
