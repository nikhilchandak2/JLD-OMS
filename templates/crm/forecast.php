<?php
$forecastConfig = require dirname(__DIR__, 2) . '/config/forecast.php';
$purpose = $forecastConfig['purpose_line'];
?>
<!-- Time for 20 accounts if prefill is accepted: 20 Save taps (one per account), under 3 minutes. -->
<div class="page-header mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item active">Monthly forecast</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2">
        <div>
            <h1 class="page-title mb-0">Monthly forecast</h1>
            <p class="text-muted small mb-0">Grade ranges, pre-filled from the last three completed months of dispatches. Adjust what changed.</p>
        </div>
        <a href="/crm/forecasts/actuals" class="btn btn-outline-secondary">Forecast vs actual</a>
    </div>
</div>

<div id="forecastPurpose" class="alert alert-info sticky-top mb-3" role="note" style="top: 0.5rem; z-index: 3;">
    <?= htmlspecialchars($purpose) ?>
</div>

<div id="error-container" class="error-message mb-3"></div>
<div id="forecastNotice" class="alert alert-success d-none" role="status"></div>
<div id="forecastToolbar" class="d-flex flex-wrap gap-2 align-items-center mb-3"></div>
<div id="forecastList"><p class="text-muted">Loading…</p></div>

<script>
let worksheet = null;

function purposeEl() {
    return document.getElementById('forecastPurpose');
}

function renderToolbar() {
    const bar = document.getElementById('forecastToolbar');
    if (!worksheet) return;
    const p = worksheet.period || {};
    let html = '<div class="fw-semibold me-2">' + escapeHtml(p.year_month || '') +
        ' · ' + escapeHtml(p.status || '') + '</div>';
    if (worksheet.can_manage_period && p.status === 'open') {
        html += '<button type="button" class="btn btn-sm btn-outline-danger" id="btnLockPeriod">Lock period</button>';
    }
    bar.innerHTML = html;
    const lock = document.getElementById('btnLockPeriod');
    if (lock) {
        lock.addEventListener('click', async function () {
            if (!confirm('Lock this month? Reps will not be able to edit.')) return;
            try {
                await apiCall('/api/crm/forecasts/periods/' + p.id + '/lock', { method: 'POST', body: '{}' });
                await loadWorksheet();
            } catch (e) { showError(e.message); }
        });
    }
}

function accountCard(acc, canEdit) {
    const lines = acc.lines || [];
    const rows = lines.map(function (l, i) {
        return '<tr data-idx="' + i + '">' +
            '<td class="align-middle">' + escapeHtml(l.grade_code) +
            '<div class="small text-muted">' + escapeHtml(l.source_label || l.source || '') + '</div></td>' +
            '<td><input type="number" class="form-control form-control-sm fc-low" step="0.1" min="0" value="' + l.qty_low_tonnes + '"' + (canEdit ? '' : ' disabled') + '></td>' +
            '<td><input type="number" class="form-control form-control-sm fc-high" step="0.1" min="0" value="' + l.qty_high_tonnes + '"' + (canEdit ? '' : ' disabled') + '></td>' +
            '<td><select class="form-select form-select-sm fc-conf"' + (canEdit ? '' : ' disabled') + '>' +
            '<option value="">–</option><option value="high"' + (l.confidence === 'high' ? ' selected' : '') + '>High</option>' +
            '<option value="medium"' + (l.confidence === 'medium' ? ' selected' : '') + '>Medium</option>' +
            '<option value="low"' + (l.confidence === 'low' ? ' selected' : '') + '>Low</option></select></td></tr>';
    }).join('');
    const empty = lines.length === 0
        ? '<p class="small text-muted mb-2">No recent dispatches. Add a grade if they will take something this month.</p>'
        : '';
    const add = canEdit
        ? '<div class="input-group input-group-sm mt-2 d-none fc-add"><input class="form-control fc-add-grade" list="forecastGradeList" placeholder="Grade code">' +
          '<button type="button" class="btn btn-outline-primary fc-add-btn">Add</button></div>' +
          '<button type="button" class="btn btn-link btn-sm px-0 fc-toggle-add">Add grade</button>'
        : '';
    const save = canEdit
        ? '<button type="button" class="btn btn-primary btn-sm mt-2 fc-save">Save</button>'
        : '';
    return '<div class="card mb-3 forecast-account" data-party-id="' + acc.party_id + '">' +
        '<div class="card-body py-3">' +
        '<div class="d-flex justify-content-between"><strong>' + escapeHtml(acc.party_name) + '</strong></div>' +
        empty +
        '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Grade</th><th>Low t</th><th>High t</th><th>Confidence</th></tr></thead>' +
        '<tbody>' + rows + '</tbody></table></div>' + add + save +
        '</div></div>';
}

function collectLines(card) {
    const lines = [];
    card.querySelectorAll('tbody tr').forEach(function (tr) {
        const grade = tr.querySelector('td').childNodes[0].textContent.trim();
        lines.push({
            grade_code: grade,
            qty_low_tonnes: tr.querySelector('.fc-low').value,
            qty_high_tonnes: tr.querySelector('.fc-high').value,
            confidence: tr.querySelector('.fc-conf').value || null
        });
    });
    return lines;
}

async function loadWorksheet() {
    const el = document.getElementById('forecastList');
    try {
        const res = await apiCall('/api/crm/forecasts/worksheet');
        worksheet = res.data;
        purposeEl().textContent = worksheet.purpose_line || purposeEl().textContent;
        renderToolbar();
        const grades = worksheet.grades || [];
        const datalist = '<datalist id="forecastGradeList">' + grades.map(function (g) {
            return '<option value="' + escapeHtml(g.code) + '">' + escapeHtml(g.name || g.code) + '</option>';
        }).join('') + '</datalist>';
        const accounts = worksheet.accounts || [];
        if (accounts.length === 0) {
            el.innerHTML = datalist + '<p class="text-muted">No accounts in your book for this month.</p>';
            return;
        }
        el.innerHTML = datalist + accounts.map(function (a) { return accountCard(a, worksheet.can_edit); }).join('');
        el.querySelectorAll('.fc-toggle-add').forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.parentElement.querySelector('.fc-add').classList.toggle('d-none');
            });
        });
        el.querySelectorAll('.fc-add-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const card = btn.closest('.forecast-account');
                const input = card.querySelector('.fc-add-grade');
                const code = (input.value || '').trim().toUpperCase();
                if (!code) return;
                const tbody = card.querySelector('tbody');
                tbody.insertAdjacentHTML('beforeend',
                    '<tr><td class="align-middle">' + escapeHtml(code) + '<div class="small text-muted">Added</div></td>' +
                    '<td><input type="number" class="form-control form-control-sm fc-low" step="0.1" min="0" value="0"></td>' +
                    '<td><input type="number" class="form-control form-control-sm fc-high" step="0.1" min="0" value="0"></td>' +
                    '<td><select class="form-select form-select-sm fc-conf"><option value="">–</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select></td></tr>');
                input.value = '';
            });
        });
        el.querySelectorAll('.fc-save').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                const card = btn.closest('.forecast-account');
                try {
                    await apiCall('/api/crm/forecasts/periods/' + worksheet.period.id + '/parties/' + card.getAttribute('data-party-id'), {
                        method: 'PUT',
                        body: JSON.stringify({ lines: collectLines(card) })
                    });
                    const n = document.getElementById('forecastNotice');
                    n.textContent = 'Saved ' + card.querySelector('strong').textContent + '.';
                    n.classList.remove('d-none');
                    btn.textContent = 'Saved';
                    setTimeout(function () { btn.textContent = 'Save'; }, 1200);
                } catch (e) { showError(e.message); }
            });
        });
    } catch (e) {
        showError(e.message);
        el.innerHTML = '';
    }
}

document.addEventListener('DOMContentLoaded', loadWorksheet);
</script>
