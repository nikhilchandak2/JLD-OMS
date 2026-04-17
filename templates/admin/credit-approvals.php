<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-shield-check me-2"></i>Credit Approval Requests
            </h1>
            <p class="page-subtitle">Approve or reject orders that exceed party credit limits</p>
        </div>
        <button class="btn btn-primary" onclick="loadApprovals()">
            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
        </button>
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
                        <th>Order</th>
                        <th>Party</th>
                        <th>Outstanding / Limit</th>
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
                    <div class="fw-bold">${a.order_no}</div>
                    <small class="text-muted">${a.company_name}</small>
                </td>
                <td>
                    <div>${a.party_name}</div>
                    <small class="text-muted">${a.product_name}</small>
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
                <td>${a.requested_by_name || '-'}</td>
                <td>${a.requested_at ? new Date(a.requested_at).toLocaleString() : '-'}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-success" onclick="decideApproval(${a.id}, 'approved')">
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

async function decideApproval(approvalId, decision) {
    let note = null;
    let creditLimitIncrease = null;
    if (decision === 'approved') {
        const raw = prompt('Credit limit increase amount (number):', '');
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

