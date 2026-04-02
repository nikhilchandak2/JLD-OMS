<!-- CRM Dashboard – 5-stage funnel (company-based pipeline) -->
<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
        <div>
            <h1 class="page-title mb-1">CRM Dashboard</h1>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="/crm/parties/new" class="btn btn-success px-3"><i class="bi bi-plus-lg me-1"></i>Add New</a>
            <a href="/admin/parties" class="btn btn-primary px-4" title="<?= isset($user) ? htmlspecialchars(ucfirst($user['role'] ?? 'User')) : 'User' ?>"><i class="bi bi-person-circle me-2"></i>Parties</a>
            <a href="/crm/funnel" class="btn btn-primary px-4">
                <i class="bi bi-funnel me-2"></i>Open Funnel
            </a>
        </div>
    </div>
</div>

<div id="error-container" class="error-message mb-3"></div>

<div class="row g-3 mt-0">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Recent activity</span>
                <div class="d-flex gap-2 align-items-center">
                    <small class="text-muted" id="activityFeedStatus">Live</small>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshActivityFeed">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div id="activityFeedList"><p class="text-muted small mb-0">Loading…</p></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-0">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>My Tasks</span>
                <div class="d-flex gap-2 align-items-center">
                    <small class="text-muted" id="tasksFeedStatus">Live</small>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshTasks" title="Refresh">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                <div class="border rounded p-3 bg-light mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="fw-semibold">Assign a task</div>
                        <small class="text-muted">Admin only</small>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Assigned to</label>
                            <select class="form-select" id="taskAssignee"></select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Due date</label>
                            <input type="date" class="form-control" id="taskDueDate">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" id="taskTitle" placeholder="e.g. Follow up meeting">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description (optional)</label>
                            <textarea class="form-control" id="taskDescription" rows="2" placeholder="Add notes for the sales owner"></textarea>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="button" class="btn btn-primary" id="btnCreateTask">
                                <i class="bi bi-plus-lg me-1"></i>Create & assign
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="btnClearTaskForm">
                                Clear
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div id="tasksList"><p class="text-muted small mb-0">Loading…</p></div>
            </div>
        </div>
    </div>
</div>

<script>
let lastActivityId = 0;
let activityFeedTimer = null;
let tasksTimer = null;
const isAdmin = <?= (($user['role'] ?? '') === 'admin') ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', async function() {
    document.getElementById('btnRefreshActivityFeed').addEventListener('click', function() {
        loadActivityFeed(true);
    });
    loadActivityFeed(true);
    activityFeedTimer = setInterval(loadActivityFeed, 6000);

    document.getElementById('btnRefreshTasks').addEventListener('click', function() {
        loadTasks(true);
    });
    loadTasks(true);
    tasksTimer = setInterval(() => loadTasks(false), 10000);

    if (isAdmin) {
        loadTaskAssignees();
        document.getElementById('btnCreateTask').addEventListener('click', createTask);
        document.getElementById('btnClearTaskForm').addEventListener('click', clearTaskForm);
    }
});
function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function timeAgo(iso) {
    if (!iso) return '';
    const dt = new Date(iso.replace(' ', 'T'));
    if (isNaN(dt.getTime())) return iso;
    const sec = Math.floor((Date.now() - dt.getTime()) / 1000);
    if (sec < 60) return sec + 's ago';
    const min = Math.floor(sec / 60);
    if (min < 60) return min + 'm ago';
    const hr = Math.floor(min / 60);
    if (hr < 24) return hr + 'h ago';
    const d = Math.floor(hr / 24);
    return d + 'd ago';
}

async function loadActivityFeed(force = false) {
    const el = document.getElementById('activityFeedList');
    const statusEl = document.getElementById('activityFeedStatus');
    try {
        statusEl.textContent = 'Live';
        const r = await apiCall('/api/crm/activities?limit=15');
        const list = (r.data || []);
        if (!list.length) {
            el.innerHTML = '<p class="text-muted small mb-0">No activities yet.</p>';
            lastActivityId = 0;
            return;
        }
        const newestId = list[0].id || 0;
        if (!force && newestId === lastActivityId) return;
        lastActivityId = newestId;

        const icon = (t) => ({
            call: 'telephone',
            meeting: 'people',
            visit: 'geo-alt',
            whatsapp: 'whatsapp',
            email: 'envelope',
            note: 'journal-text'
        }[t] || 'journal-text');

        el.innerHTML = list.map(a => {
            const party = a.party_name || (a.created_by_name || 'User');
            const subject = a.subject ? escapeHtml(a.subject) : '<span class="text-muted">(no subject)</span>';
            const who = a.created_by_name ? escapeHtml(a.created_by_name) : 'User';
            const when = timeAgo(a.activity_date || a.created_at);
            const desc = a.description ? ('<div class="text-muted small mt-1">' + escapeHtml(a.description).slice(0, 160) + (a.description.length > 160 ? '…' : '') + '</div>') : '';
            return (
                '<div class="d-flex gap-2 py-2 border-bottom">' +
                '<div class="text-primary" style="width:22px;"><i class="bi bi-' + icon(a.type) + '"></i></div>' +
                '<div class="flex-grow-1">' +
                '<div class="d-flex justify-content-between gap-2">' +
                '<div><a href="/crm/parties/' + a.party_id + '" class="text-decoration-none fw-semibold">' + escapeHtml(party) + '</a> · ' + subject + '</div>' +
                '<small class="text-muted">' + escapeHtml(when) + '</small>' +
                '</div>' +
                '<div class="text-muted small">' + escapeHtml(a.type || 'note') + ' · ' + who + '</div>' +
                desc +
                '</div>' +
                '</div>'
            );
        }).join('') + '<div class="pt-2"><a class="small text-decoration-none" href="/crm/funnel">Open funnel</a></div>';
    } catch (e) {
        statusEl.textContent = 'Offline';
        el.innerHTML = '<p class="text-muted small mb-0">Unable to load activity feed.</p>';
    }
}

async function loadTaskAssignees() {
    try {
        const r = await apiCall('/api/crm/users/options');
        const list = (r.data || []);
        const sel = document.getElementById('taskAssignee');
        if (!sel) return;
        sel.innerHTML = list.map(u => '<option value="' + u.id + '">' + escapeHtml(u.name || 'User') + '</option>').join('');
    } catch (e) {
        // If dropdown fails, leave it empty.
        console.error(e);
    }
}

function clearTaskForm() {
    const title = document.getElementById('taskTitle');
    const desc = document.getElementById('taskDescription');
    const due = document.getElementById('taskDueDate');
    const assignee = document.getElementById('taskAssignee');
    if (title) title.value = '';
    if (desc) desc.value = '';
    if (due) due.value = '';
    if (assignee && assignee.options && assignee.options.length) assignee.selectedIndex = 0;
}

async function createTask() {
    const titleEl = document.getElementById('taskTitle');
    const descEl = document.getElementById('taskDescription');
    const dueEl = document.getElementById('taskDueDate');
    const assigneeEl = document.getElementById('taskAssignee');

    const title = (titleEl && titleEl.value) ? titleEl.value.trim() : '';
    const assignedTo = assigneeEl ? assigneeEl.value : '';
    const description = descEl ? descEl.value.trim() : '';
    const due_date = dueEl && dueEl.value ? dueEl.value : '';

    if (!title) {
        showError('Task title is required.');
        return;
    }
    if (!assignedTo) {
        showError('Please select an assignee.');
        return;
    }

    try {
        showError('');
        const r = await apiCall('/api/crm/tasks', {
            method: 'POST',
            body: JSON.stringify({
                title: title,
                description: description || null,
                due_date: due_date || null,
                assigned_to: assignedTo
            })
        });
        clearTaskForm();
        await loadTasks(true);
        showSuccess('Task assigned successfully.');
    } catch (e) {
        showError(e.message || 'Failed to create task');
    }
}

async function loadTasks(force = false) {
    const el = document.getElementById('tasksList');
    const statusEl = document.getElementById('tasksFeedStatus');
    try {
        statusEl.textContent = 'Live';
        const url = isAdmin ? '/api/crm/tasks?all=1' : '/api/crm/tasks';
        const r = await apiCall(url);
        const list = (r.data || []);

        if (!list.length) {
            el.innerHTML = '<p class="text-muted small mb-0">No tasks yet.</p>';
            return;
        }

        el.innerHTML = list.map(t => {
            const dueText = t.due_date ? ('Due: ' + escapeHtml(t.due_date)) : 'No due date';
            const desc = t.description ? ('<div class="text-muted small mt-1">' + escapeHtml(t.description).slice(0, 120) + (t.description.length > 120 ? '…' : '') + '</div>') : '';
            const statusBadge = t.status === 'completed'
                ? '<span class="badge bg-success">Completed</span>'
                : '<span class="badge bg-warning text-dark">Pending</span>';

            const actionBtn = t.status === 'completed'
                ? ''
                : '<button type="button" class="btn btn-sm btn-success btn-mark-done" data-task-id="' + t.id + '">Mark done</button>';

            return (
                '<div class="d-flex gap-2 py-2 border-bottom align-items-start">' +
                    '<div class="flex-grow-1">' +
                        '<div class="d-flex justify-content-between gap-2">' +
                            '<div class="fw-semibold">' + escapeHtml(t.title) + '</div>' +
                            statusBadge +
                        '</div>' +
                        '<div class="text-muted small mt-1">' + dueText + (isAdmin && t.assigned_to_name ? (' · ' + escapeHtml(t.assigned_to_name)) : '') + '</div>' +
                        desc +
                    '</div>' +
                    '<div class="pt-1">' + actionBtn + '</div>' +
                '</div>'
            );
        }).join('');

        // Attach click handlers for "Mark done" buttons
        el.querySelectorAll('.btn-mark-done').forEach(btn => {
            btn.addEventListener('click', async function() {
                const id = this.getAttribute('data-task-id');
                if (!id) return;
                try {
                    await apiCall('/api/crm/tasks/' + encodeURIComponent(id), {
                        method: 'PUT',
                        body: JSON.stringify({ status: 'completed' })
                    });
                    await loadTasks(true);
                    showSuccess('Task marked as completed.');
                } catch (e) {
                    showError(e.message || 'Failed to update task');
                }
            });
        });
    } catch (e) {
        statusEl.textContent = 'Offline';
        el.innerHTML = '<p class="text-muted small mb-0">Unable to load tasks.</p>';
    }
}
</script>
