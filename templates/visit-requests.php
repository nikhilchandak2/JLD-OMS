<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-geo-alt-fill me-2"></i>Client Visit Requests
            </h1>
            <p class="page-subtitle">Marketing raises visit requests; the technical team accepts, schedules and completes them</p>
        </div>
        <div>
            <?php if (in_array($user['role'] ?? '', ['admin', 'marketing', 'crm'])): ?>
            <button class="btn btn-warning me-2" onclick="openVisitRequestModal()">
                <i class="bi bi-plus-circle me-1"></i> Raise Visit Request
            </button>
            <?php endif; ?>
            <button class="btn btn-primary" onclick="loadVisitRequests()">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3 col-6 mb-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-warning" id="cardPendingVisits">-</div>
                <div class="text-muted small">Pending</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-info" id="cardAcceptedVisits">-</div>
                <div class="text-muted small">Accepted / Scheduled</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-success" id="cardCompletedVisits">-</div>
                <div class="text-muted small">Completed</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-secondary" id="cardCancelledVisits">-</div>
                <div class="text-muted small">Cancelled</div>
            </div>
        </div>
    </div>
</div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p>Loading visit requests...</p>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-check me-2"></i>Visit Requests</span>
        <div class="d-flex align-items-center">
            <div class="form-check form-switch me-3">
                <input class="form-check-input" type="checkbox" id="mineOnly" onchange="loadVisitRequests()">
                <label class="form-check-label small" for="mineOnly">Mine only</label>
            </div>
            <select class="form-select form-select-sm" id="statusFilter" style="width: auto;" onchange="loadVisitRequests()">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="accepted">Accepted</option>
                <option value="scheduled">Scheduled</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped align-middle" id="visitsTable">
                <thead>
                    <tr>
                        <th>Party</th>
                        <th>Purpose</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Preferred / Scheduled</th>
                        <th>Requested By</th>
                        <th>Assigned To</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div id="emptyState" class="text-muted text-center" style="display: none; padding: 1rem 0;">
            No visit requests found.
        </div>
    </div>
</div>

<!-- Raise Visit Request Modal -->
<div class="modal fade" id="visitRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Raise Visit Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="visitRequestForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="visitPartyId" class="form-label">Party <span class="text-danger">*</span></label>
                        <select class="form-select" id="visitPartyId" required>
                            <option value="">Select party...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="visitPurpose" class="form-label">Purpose <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="visitPurpose" rows="2" maxlength="500" required
                            placeholder="e.g., Product quality complaint, technical demonstration, application support..."></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="visitPreferredDate" class="form-label">Preferred Date</label>
                                <input type="date" class="form-control" id="visitPreferredDate">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="visitPriority" class="form-label">Priority</label>
                                <select class="form-select" id="visitPriority">
                                    <option value="normal" selected>Normal</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-send"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Schedule Modal -->
<div class="modal fade" id="scheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Schedule Visit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="scheduleForm">
                <div class="modal-body">
                    <input type="hidden" id="scheduleRequestId">
                    <div class="alert alert-info py-2" id="scheduleInfo"></div>
                    <div class="mb-3">
                        <label for="scheduleDate" class="form-label">Visit Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="scheduleDate" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-calendar-check"></i> Schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Complete Modal -->
<div class="modal fade" id="completeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Complete Visit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="completeForm">
                <div class="modal-body">
                    <input type="hidden" id="completeRequestId">
                    <div class="alert alert-info py-2" id="completeInfo"></div>
                    <div class="mb-3">
                        <label for="visitOutcome" class="form-label">Visit Outcome <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="visitOutcome" rows="3" maxlength="1000" required
                            placeholder="What was done / found during the visit?"></textarea>
                        <div class="form-text">Logged as a CRM visit activity on the party profile.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Mark Completed</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const currentUserRole = <?= json_encode((string)($user['role'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const currentUserId = <?= (int)($user['id'] ?? 0) ?>;
let visitRequests = [];
let partiesLoaded = false;

const statusBadges = {
    pending: 'bg-warning text-dark',
    accepted: 'bg-info',
    scheduled: 'bg-primary',
    completed: 'bg-success',
    cancelled: 'bg-secondary'
};

async function loadVisitRequests() {
    const loading = document.getElementById('loading');
    const tbody = document.querySelector('#visitsTable tbody');
    const emptyState = document.getElementById('emptyState');

    loading.style.display = 'block';
    tbody.innerHTML = '';
    emptyState.style.display = 'none';

    try {
        const params = new URLSearchParams();
        const status = document.getElementById('statusFilter').value;
        if (status) params.set('status', status);
        if (document.getElementById('mineOnly').checked) params.set('mine', '1');

        const response = await apiCall('/api/visit-requests' + (params.toString() ? '?' + params.toString() : ''));
        visitRequests = response.data || [];

        const counts = { pending: 0, accepted: 0, scheduled: 0, completed: 0, cancelled: 0 };
        visitRequests.forEach(v => { counts[v.status] = (counts[v.status] || 0) + 1; });
        document.getElementById('cardPendingVisits').textContent = counts.pending;
        document.getElementById('cardAcceptedVisits').textContent = counts.accepted + counts.scheduled;
        document.getElementById('cardCompletedVisits').textContent = counts.completed;
        document.getElementById('cardCancelledVisits').textContent = counts.cancelled;

        if (visitRequests.length === 0) {
            emptyState.style.display = 'block';
            return;
        }

        const canTech = currentUserRole === 'technical' || currentUserRole === 'admin';

        tbody.innerHTML = visitRequests.map(v => {
            const actions = [];
            if (canTech && v.status === 'pending') {
                actions.push(`<button class="btn btn-sm btn-outline-info" onclick="visitAction(${v.id}, 'accept')"><i class="bi bi-hand-thumbs-up"></i> Accept</button>`);
            }
            if (canTech && (v.status === 'pending' || v.status === 'accepted')) {
                actions.push(`<button class="btn btn-sm btn-outline-primary" onclick="openScheduleModal(${v.id})"><i class="bi bi-calendar-plus"></i> Schedule</button>`);
            }
            if (canTech && ['pending', 'accepted', 'scheduled'].includes(v.status)) {
                actions.push(`<button class="btn btn-sm btn-outline-success" onclick="openCompleteModal(${v.id})"><i class="bi bi-check-circle"></i> Complete</button>`);
            }
            const canCancel = currentUserRole === 'admin' || Number(v.requested_by) === currentUserId;
            if (canCancel && !['completed', 'cancelled'].includes(v.status)) {
                actions.push(`<button class="btn btn-sm btn-outline-danger" onclick="visitAction(${v.id}, 'cancel')"><i class="bi bi-x-circle"></i> Cancel</button>`);
            }

            return `
            <tr>
                <td class="fw-bold">${escapeHtml(v.party_name)}</td>
                <td>
                    ${escapeHtml(v.purpose)}
                    ${v.visit_outcome ? `<div><small class="text-success"><i class="bi bi-chat-left-text"></i> ${escapeHtml(v.visit_outcome)}</small></div>` : ''}
                </td>
                <td>${v.priority === 'urgent' ? '<span class="badge bg-danger">Urgent</span>' : '<span class="badge bg-secondary">Normal</span>'}</td>
                <td><span class="badge ${statusBadges[v.status] || 'bg-secondary'}">${escapeHtml(v.status)}</span></td>
                <td>
                    ${v.scheduled_date
                        ? `<strong>${v.scheduled_date}</strong>`
                        : (v.preferred_date ? `<span class="text-muted">${v.preferred_date} (preferred)</span>` : '<span class="text-muted">-</span>')}
                </td>
                <td>${escapeHtml(v.requested_by_name || '-')}</td>
                <td>${escapeHtml(v.assigned_to_name || '-')}</td>
                <td class="text-end"><div class="btn-group">${actions.join(' ')}</div></td>
            </tr>`;
        }).join('');
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

async function openVisitRequestModal() {
    if (!partiesLoaded) {
        try {
            const response = await apiCall('/api/reports/parties');
            const select = document.getElementById('visitPartyId');
            select.innerHTML = '<option value="">Select party...</option>';
            (response.data || []).forEach(party => {
                const option = document.createElement('option');
                option.value = String(party.id ?? '');
                option.textContent = String(party.name ?? '');
                select.appendChild(option);
            });
            partiesLoaded = true;
        } catch (error) {
            showError('Failed to load parties: ' + error.message);
            return;
        }
    }
    new bootstrap.Modal(document.getElementById('visitRequestModal')).show();
}

document.getElementById('visitRequestForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    try {
        const response = await apiCall('/api/visit-requests', {
            method: 'POST',
            body: JSON.stringify({
                party_id: parseInt(document.getElementById('visitPartyId').value),
                purpose: document.getElementById('visitPurpose').value,
                preferred_date: document.getElementById('visitPreferredDate').value || null,
                priority: document.getElementById('visitPriority').value
            })
        });
        bootstrap.Modal.getInstance(document.getElementById('visitRequestModal')).hide();
        this.reset();
        showSuccess(response.message || 'Visit request raised.');
        await loadVisitRequests();
    } catch (error) {
        showError(error.message);
    }
});

async function visitAction(id, action, extra = {}) {
    if (action === 'cancel' && !confirm('Cancel this visit request?')) return;

    try {
        await apiCall(`/api/visit-requests/${id}`, {
            method: 'PUT',
            body: JSON.stringify({ action, ...extra })
        });
        showSuccess('Visit request updated.');
        await loadVisitRequests();
        return true;
    } catch (error) {
        showError(error.message);
        return false;
    }
}

function openScheduleModal(id) {
    const request = visitRequests.find(v => Number(v.id) === Number(id));
    if (!request) return;
    document.getElementById('scheduleRequestId').value = id;
    document.getElementById('scheduleInfo').innerHTML =
        `<strong>${escapeHtml(request.party_name)}</strong> — ${escapeHtml(request.purpose)}`;
    document.getElementById('scheduleDate').value = request.preferred_date || '';
    new bootstrap.Modal(document.getElementById('scheduleModal')).show();
}

document.getElementById('scheduleForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const id = document.getElementById('scheduleRequestId').value;
    const ok = await visitAction(id, 'schedule', { scheduled_date: document.getElementById('scheduleDate').value });
    if (ok) bootstrap.Modal.getInstance(document.getElementById('scheduleModal')).hide();
});

function openCompleteModal(id) {
    const request = visitRequests.find(v => Number(v.id) === Number(id));
    if (!request) return;
    document.getElementById('completeRequestId').value = id;
    document.getElementById('completeInfo').innerHTML =
        `<strong>${escapeHtml(request.party_name)}</strong> — ${escapeHtml(request.purpose)}`;
    document.getElementById('visitOutcome').value = '';
    new bootstrap.Modal(document.getElementById('completeModal')).show();
}

document.getElementById('completeForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const id = document.getElementById('completeRequestId').value;
    const ok = await visitAction(id, 'complete', { visit_outcome: document.getElementById('visitOutcome').value });
    if (ok) bootstrap.Modal.getInstance(document.getElementById('completeModal')).hide();
});

document.addEventListener('DOMContentLoaded', async function() {
    await loadVisitRequests();

    // Deep link from CRM party page: ?raise_party_id=X opens the modal with the party preselected
    const raisePartyId = new URLSearchParams(window.location.search).get('raise_party_id');
    if (raisePartyId) {
        await openVisitRequestModal();
        document.getElementById('visitPartyId').value = raisePartyId;
    }
});
</script>
