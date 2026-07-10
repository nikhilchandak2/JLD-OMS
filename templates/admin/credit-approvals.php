<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-shield-check me-2"></i>Credit Approval Requests
            </h1>
            <p class="page-subtitle">Approve or reject credit requests for parties over their credit limit (max 2 requests per party per month)</p>
        </div>
        <div>
            <a href="/admin/reminders" class="btn btn-outline-secondary me-2" title="Send payment reminders to over-limit parties">
                <i class="bi bi-envelope-check me-1"></i> Payment Reminders
            </a>
            <button class="btn btn-primary" onclick="loadApprovals()">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p>Loading credit approval requests...</p>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="approvalsTable">
                <thead>
                    <tr>
                        <th>Party</th>
                        <th>Order</th>
                        <th>Outstanding / Limit</th>
                        <th>Requested Increase / Reason</th>
                        <th>Requests This Month</th>
                        <th>Requested By</th>
                        <th>Requested At</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div id="emptyState" class="text-muted text-center" style="display: none; padding: 1rem 0;">
            No pending credit approvals.
        </div>
    </div>
</div>

<script>
async function loadApprovals() {
    const loading = document.getElementById('loading');
    const tbody = document.querySelector('#approvalsTable tbody');
    const emptyState = document.getElementById('emptyState');

    loading.style.display = 'block';
    tbody.innerHTML = '';
    emptyState.style.display = 'none';

    try {
        const response = await apiCall('/api/orders/credit-approvals/pending');
        const approvals = response.data || [];

        if (approvals.length === 0) {
            emptyState.style.display = 'block';
            return;
        }

        tbody.innerHTML = approvals.map(a => `
            <tr>
                <td>
                    <div class="fw-bold">${escapeHtml(a.party_name)}</div>
                </td>
                <td>
                    ${a.order_no
                        ? `<div class="fw-bold">${escapeHtml(a.order_no)}</div>
                           <small class="text-muted">${escapeHtml(a.company_name || '')}${a.product_name ? ' &middot; ' + escapeHtml(a.product_name) : ''}</small>`
                        : '<span class="badge bg-info">Party credit request</span>'}
                </td>
                <td>
                    <span class="badge bg-warning">
                        ${Number(a.outstanding).toFixed(2)}
                    </span>
                    <span class="text-muted"> / </span>
                    <span class="badge bg-secondary">
                        ${Number(a.credit_limit).toFixed(2)}
                    </span>
                </td>
                <td>
                    ${a.requested_limit_increase ? `<div>+${Number(a.requested_limit_increase).toFixed(2)}</div>` : '<span class="text-muted">-</span>'}
                    ${a.reason ? `<small class="text-muted">${escapeHtml(a.reason)}</small>` : ''}
                </td>
                <td>
                    <span class="badge ${Number(a.requests_this_month) >= 2 ? 'bg-danger' : 'bg-secondary'}">
                        ${a.requests_this_month ?? '-'} / 2
                    </span>
                </td>
                <td>${escapeHtml(a.requested_by_name || '-')}</td>
                <td>${a.requested_at ? new Date(a.requested_at).toLocaleString() : '-'}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-success" onclick="decideApproval(${a.id}, 'approved', ${Number(a.requested_limit_increase) || 0})">
                        <i class="bi bi-check-circle"></i> Approve
                    </button>
                    <button class="btn btn-sm btn-outline-danger ms-2" onclick="decideApproval(${a.id}, 'rejected')">
                        <i class="bi bi-x-circle"></i> Reject
                    </button>
                </td>
            </tr>
        `).join('');
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

async function decideApproval(approvalId, decision, suggestedIncrease) {
    let note = null;
    let creditLimitIncrease = null;
    if (decision === 'approved') {
        const raw = prompt('Credit limit increase amount (number):', suggestedIncrease > 0 ? String(suggestedIncrease) : '');
        if (raw === null) return; // cancelled by user
        const parsed = Number(raw);
        if (!Number.isFinite(parsed) || parsed <= 0) {
            showError('Credit limit increase must be a positive number.');
            return;
        }
        creditLimitIncrease = parsed;
        note = prompt('Optional approval note:', '') || null;
    } else if (decision === 'rejected') {
        note = prompt('Optional rejection note:', '') || null;
    }

    try {
        await apiCall(`/api/orders/credit-approvals/${approvalId}/decide`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ decision, note, credit_limit_increase: creditLimitIncrease })
        });
        showSuccess(decision === 'approved' ? 'Approved successfully' : 'Rejected successfully');
        await loadApprovals();
    } catch (error) {
        showError(error.message);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    loadApprovals();
});
</script>

