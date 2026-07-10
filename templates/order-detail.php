<style>
.order-detail-kpi {
    border: none;
    border-radius: 0.75rem;
    box-shadow: 0 1px 3px rgba(43, 35, 94, 0.08);
    height: 100%;
}
.order-detail-kpi .kpi-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--jld-primary);
}
.order-detail-kpi .kpi-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--jld-gray);
}
.order-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem 1.5rem;
}
.order-info-item label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--jld-gray);
    margin-bottom: 0.25rem;
}
.order-info-item .value {
    font-weight: 600;
    color: var(--jld-primary);
}
.order-progress-wrap {
    background: #eef0f6;
    border-radius: 999px;
    height: 10px;
    overflow: hidden;
}
.order-progress-bar {
    height: 100%;
    border-radius: 999px;
    transition: width 0.35s ease;
}
.dispatch-panel {
    border: 1px solid var(--jld-border);
    border-radius: 0.75rem;
    box-shadow: 0 2px 8px rgba(43, 35, 94, 0.06);
}
.dispatch-panel .card-header {
    background: linear-gradient(135deg, rgba(43, 35, 94, 0.06), rgba(43, 35, 94, 0.02));
    border-bottom: 1px solid var(--jld-border);
}
</style>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1 class="page-title mb-1">
                <i class="bi bi-clipboard-check me-2"></i><span id="headerOrderNo">Order Details</span>
            </h1>
            <p class="page-subtitle mb-2" id="headerSubtitle">Loading order…</p>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span id="headerStatus"></span>
                <span id="headerPriority"></span>
                <span id="headerCreditBadge"></span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if (!empty($can_edit_orders)): ?>
            <button type="button" class="btn btn-outline-primary" id="editOrderBtn" style="display: none;" onclick="openEditOrderModal()">
                <i class="bi bi-pencil me-1"></i> Edit
            </button>
            <?php endif; ?>
            <?php if (!empty($can_delete_orders)): ?>
            <button type="button" class="btn btn-outline-danger" id="deleteOrderBtn" style="display: none;" onclick="deleteCurrentOrder()">
                <i class="bi bi-trash me-1"></i> Delete
            </button>
            <?php endif; ?>
            <a href="#" onclick="goBackToOrders(); return false;" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p>Loading order details...</p>
</div>

<div id="orderContent" style="display: none;">
    <!-- KPI summary -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card order-detail-kpi text-center">
                <div class="card-body py-3">
                    <div class="kpi-value" id="kpiOrderedTrucks">—</div>
                    <div class="kpi-label">Ordered Trucks</div>
                    <div class="small text-muted mt-1" id="kpiOrderedWeight"></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card order-detail-kpi text-center">
                <div class="card-body py-3">
                    <div class="kpi-value text-success" id="kpiDispatched">—</div>
                    <div class="kpi-label">Dispatched</div>
                    <div class="small text-muted mt-1" id="kpiDispatchedWeight"></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card order-detail-kpi text-center">
                <div class="card-body py-3">
                    <div class="kpi-value text-warning" id="kpiPending">—</div>
                    <div class="kpi-label">Pending</div>
                    <div class="small text-muted mt-1" id="kpiPendingWeight"></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card order-detail-kpi text-center">
                <div class="card-body py-3">
                    <div class="kpi-value" id="kpiCompletionPct">—</div>
                    <div class="kpi-label">Completion</div>
                    <div class="order-progress-wrap mt-2 mx-2">
                        <div class="order-progress-bar bg-success" id="kpiProgressBar" style="width: 0%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Order Information -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Order Information</h5>
                    <span class="badge bg-primary" id="infoCompanyBadge">—</span>
                </div>
                <div class="card-body">
                    <div class="order-info-grid">
                        <div class="order-info-item">
                            <label>Order Date</label>
                            <div class="value" id="orderDate">—</div>
                        </div>
                        <div class="order-info-item">
                            <label>Party</label>
                            <div class="value" id="partyName">—</div>
                        </div>
                        <div class="order-info-item">
                            <label>Product</label>
                            <div class="value" id="productName">—</div>
                        </div>
                        <div class="order-info-item">
                            <label>Order No.</label>
                            <div class="value" id="orderNo">—</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delivery Schedule (recurring) -->
            <div class="card mb-4" id="deliveryScheduleCard" style="display: none;">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Delivery Schedule</h5>
                </div>
                <div class="card-body">
                    <div id="deliveryScheduleContent"></div>
                </div>
            </div>

            <!-- Dispatch / invoice summary -->
            <div class="card mb-4" id="dispatchSummaryCard" style="display: none;">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Dispatch &amp; Invoice Details</h5>
                </div>
                <div class="card-body" id="dispatchSummaryBody"></div>
            </div>

            <!-- Dispatch History -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Dispatch History</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="dispatchesTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Trucks</th>
                                    <th>Rate (₹/MT)</th>
                                    <th>Weight (MT)</th>
                                    <th>Busy Invoice</th>
                                    <th>Remarks</th>
                                    <th>By</th>
                                    <?php if (in_array($user['role'], ['entry', 'order_processing', 'admin', 'dispatch'])): ?>
                                    <th class="text-end">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if (in_array($user['role'], ['entry', 'order_processing', 'admin', 'dispatch'])): ?>
            <div class="card dispatch-panel sticky-lg-top" style="top: 1rem;">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-truck me-2"></i>Create Dispatch</h5>
                </div>
                <div class="card-body">
                    <form id="dispatchForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                        <div class="mb-3">
                            <label for="dispatchDate" class="form-label">Dispatch Date</label>
                            <input type="date" class="form-control" id="dispatchDate" name="dispatch_date" required value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="mb-3">
                            <label for="dispatchQty" class="form-label">Quantity (Trucks)</label>
                            <input type="number" class="form-control" id="dispatchQty" name="dispatch_qty_trucks" required min="1">
                            <div class="form-text">Available: <strong id="availableQty">—</strong> trucks</div>
                        </div>

                        <div class="mb-3">
                            <label for="productRate" class="form-label">Product Rate (₹/MT) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="productRate" name="product_rate" step="0.01" min="0.01" required placeholder="Rate per MT">
                            <div class="form-text">Enter loading weight from kanta parchi after weighbridge.</div>
                        </div>

                        <div class="mb-3">
                            <label for="remarks" class="form-label">Remarks</label>
                            <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="Optional notes"></textarea>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-success" id="dispatchBtn">
                                <span class="spinner-border spinner-border-sm d-none" id="dispatchSpinner"></span>
                                <i class="bi bi-truck me-1"></i> Create Dispatch
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($can_edit_orders)): ?>
<!-- Edit order modal -->
<div class="modal fade" id="editOrderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editOrderForm">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="editOrderDate" class="form-label">Order Date</label>
                            <input type="date" class="form-control" id="editOrderDate" required>
                        </div>
                        <div class="col-md-4">
                            <label for="editPriority" class="form-label">Priority</label>
                            <select class="form-select" id="editPriority">
                                <option value="normal">Normal</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="editTonsPerTruck" class="form-label">MT per Truck</label>
                            <input type="number" class="form-control" id="editTonsPerTruck" step="0.01" min="1" value="40">
                        </div>
                        <div class="col-md-6">
                            <label for="editPartyId" class="form-label">Party</label>
                            <select class="form-select" id="editPartyId" required></select>
                        </div>
                        <div class="col-md-6">
                            <label for="editProductId" class="form-label">Product</label>
                            <select class="form-select" id="editProductId" required></select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Quantity</label>
                            <div class="btn-group mb-2" role="group">
                                <input type="radio" class="btn-check" name="edit_qty_mode" id="editQtyModeTrucks" value="trucks" checked>
                                <label class="btn btn-outline-primary btn-sm" for="editQtyModeTrucks">Trucks</label>
                                <input type="radio" class="btn-check" name="edit_qty_mode" id="editQtyModeWeight" value="weight">
                                <label class="btn btn-outline-primary btn-sm" for="editQtyModeWeight">Weight (MT)</label>
                            </div>
                        </div>
                        <div class="col-md-6" id="editTrucksGroup">
                            <label for="editOrderQty" class="form-label">Trucks</label>
                            <input type="number" class="form-control" id="editOrderQty" min="1">
                            <div class="form-text" id="editQtyHint"></div>
                        </div>
                        <div class="col-md-6" id="editWeightGroup" style="display:none;">
                            <label for="editOrderWeight" class="form-label">Weight (MT)</label>
                            <input type="number" class="form-control" id="editOrderWeight" step="0.001" min="0.001">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="editOrderSubmitBtn">
                        <span class="spinner-border spinner-border-sm d-none" id="editOrderSpinner"></span>
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (in_array($user['role'], ['entry', 'order_processing', 'admin', 'dispatch'])): ?>
<!-- Update loading weight from kanta parchi -->
<div class="modal fade" id="weightModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Enter Loading Weight</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="weightForm">
                <div class="modal-body">
                    <p class="text-muted small mb-3" id="weightModalInfo"></p>
                    <div class="mb-3">
                        <label for="loadingWeightTons" class="form-label">Loading Weight (MT) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="loadingWeightTons" step="0.001" min="0.001" required placeholder="Net weight from kanta parchi">
                        <div class="form-text">Metric tons from weighbridge receipt</div>
                    </div>
                    <input type="hidden" id="weightDispatchId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="weightSubmitBtn">
                        <span class="spinner-border spinner-border-sm d-none" id="weightSpinner"></span>
                        Save Weight
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
let currentOrder = null;
const orderId = <?= (int)$order_id ?>;
const canEditDispatch = <?= in_array($user['role'], ['entry', 'order_processing', 'admin', 'dispatch']) ? 'true' : 'false' ?>;
const canEditOrders = <?= !empty($can_edit_orders) ? 'true' : 'false' ?>;
const canDeleteOrders = <?= !empty($can_delete_orders) ? 'true' : 'false' ?>;
const canForceDeleteOrders = <?= !empty($can_force_delete_orders) ? 'true' : 'false' ?>;
let editFormParties = [];
let editFormProducts = [];

async function loadOrderDetails() {
    const loading = document.getElementById('loading');
    const content = document.getElementById('orderContent');
    
    loading.style.display = 'block';
    content.style.display = 'none';
    
    try {
        const response = await apiCall(`/api/orders/${orderId}`);
        currentOrder = response.data;
        
        updateOrderDisplay(currentOrder);
        updateDispatchSummary(currentOrder.dispatches || []);
        updateDispatchHistory(currentOrder.dispatches || []);
        
        content.style.display = 'block';
        
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function updateOrderDisplay(order) {
    document.getElementById('headerOrderNo').textContent = order.order_no;
    document.getElementById('headerSubtitle').textContent =
        `${order.party_name} · ${order.product_name} · ${formatDate(order.order_date)}`;

    document.getElementById('headerStatus').innerHTML = formatStatus(order.status);
    document.getElementById('headerPriority').innerHTML = formatPriority(order.priority || 'normal');

    document.getElementById('orderNo').textContent = order.order_no;
    document.getElementById('orderDate').textContent = formatDate(order.order_date);
    document.getElementById('partyName').textContent = order.party_name;
    document.getElementById('productName').textContent = order.product_name;
    document.getElementById('infoCompanyBadge').textContent = order.company_name || '—';

    const ordered = Number(order.order_qty_trucks) || 0;
    const dispatched = Number(order.total_dispatched) || 0;
    const pending = Number(order.pending_trucks) || 0;
    let pct = ordered > 0 ? Math.round((dispatched / ordered) * 100) : 0;
    pct = Math.min(100, Math.max(0, pct));

    document.getElementById('kpiOrderedTrucks').textContent = ordered;
    document.getElementById('kpiDispatched').textContent = dispatched;
    document.getElementById('kpiPending').textContent = pending;
    document.getElementById('kpiCompletionPct').textContent = pct + '%';

    const progressBar = document.getElementById('kpiProgressBar');
    progressBar.style.width = pct + '%';
    progressBar.className = 'order-progress-bar ' + (pct >= 100 ? 'bg-success' : pct > 0 ? 'bg-info' : 'bg-warning');

    const plannedWeight = order.order_weight_tons != null
        ? Number(order.order_weight_tons).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + ' MT'
        : '—';
    const modeHint = order.order_qty_mode === 'weight' ? 'by weight' : `@ ${order.tons_per_truck || 40} MT/truck`;
    document.getElementById('kpiOrderedWeight').textContent = plannedWeight !== '—' ? `${plannedWeight} (${modeHint})` : '';

    const dispWt = Number(order.total_dispatched_weight || 0);
    const pendWt = Number(order.pending_weight_tons || 0);
    document.getElementById('kpiDispatchedWeight').textContent = dispWt > 0
        ? `${dispWt.toLocaleString('en-IN', { minimumFractionDigits: 3 })} MT`
        : '';
    document.getElementById('kpiPendingWeight').textContent = plannedWeight !== '—'
        ? `${pendWt.toLocaleString('en-IN', { minimumFractionDigits: 3 })} MT pending`
        : '';

    const creditApproval = order.credit_approval || null;
    const creditEl = document.getElementById('headerCreditBadge');
    if (creditEl) {
        creditEl.innerHTML = creditApproval
            ? creditApproval.status === 'pending'
                ? '<span class="badge bg-warning text-dark">Credit Pending</span>'
                : creditApproval.status === 'approved'
                    ? '<span class="badge bg-success">Credit Approved</span>'
                    : '<span class="badge bg-danger">Credit Rejected</span>'
            : '';
    }

    document.getElementById('availableQty').textContent = pending;

    const deleteBtn = document.getElementById('deleteOrderBtn');
    if (deleteBtn && canDeleteOrders) {
        const canDeleteThis = canForceDeleteOrders
            || ((Number(order.total_dispatched) || 0) === 0 && order.status !== 'completed');
        deleteBtn.style.display = canDeleteThis ? 'inline-block' : 'none';
    }

    const editBtn = document.getElementById('editOrderBtn');
    if (editBtn && canEditOrders) {
        editBtn.style.display = order.status !== 'completed' ? 'inline-block' : 'none';
    }
    
    // Update dispatch form max quantity
    const dispatchQtyInput = document.getElementById('dispatchQty');
    if (dispatchQtyInput) {
        dispatchQtyInput.max = order.pending_trucks;
        dispatchQtyInput.placeholder = `Max: ${order.pending_trucks}`;
    }

    // If credit approval is not approved yet, block dispatch UI (backend also enforces)
    const dispatchBtn = document.getElementById('dispatchBtn');
    if (dispatchBtn && creditApproval && creditApproval.status !== 'approved') {
        dispatchBtn.disabled = true;
        dispatchBtn.title = 'Admin credit approval required before dispatch';
    }

    // Default bill reference to order number
    // bill generation removed; bills come from Busy import
    
    // Load delivery schedule if this is a recurring order
    if (order.is_recurring) {
        loadDeliverySchedule(order.id);
    } else {
        document.getElementById('deliveryScheduleCard').style.display = 'none';
    }
}

async function loadDeliverySchedule(orderId) {
    try {
        const response = await apiCall(`/api/orders/${orderId}/scheduled-deliveries`);
        const deliveries = response.data;
        
        if (deliveries && deliveries.length > 0) {
            displayDeliverySchedule(deliveries);
            document.getElementById('deliveryScheduleCard').style.display = 'block';
        } else {
            document.getElementById('deliveryScheduleCard').style.display = 'none';
        }
    } catch (error) {
        console.error('Failed to load delivery schedule:', error);
        document.getElementById('deliveryScheduleCard').style.display = 'none';
    }
}

function formatDate(dateString) {
    if (!dateString) return 'Invalid Date';
    
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return 'Invalid Date';
    
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function displayDeliverySchedule(deliveries) {
    const container = document.getElementById('deliveryScheduleContent');
    
    let scheduleHtml = '<div class="row">';
    let totalTrucks = 0;
    
    deliveries.forEach((delivery, index) => {
        totalTrucks += delivery.trucks_quantity;
        
        const statusBadge = delivery.status === 'completed' 
            ? '<span class="badge bg-success">Completed</span>'
            : delivery.status === 'in_progress'
            ? '<span class="badge bg-warning">In Progress</span>'
            : '<span class="badge bg-secondary">Pending</span>';
        
        const safeSequence = escapeHtml(delivery.delivery_sequence);
        const safeQuantity = escapeHtml(delivery.trucks_quantity);
        scheduleHtml += `
            <div class="col-md-6 col-lg-4 mb-3">
                <div class="card border-left-primary h-100 delivery-schedule-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="card-title mb-0">Delivery ${safeSequence}</h6>
                            ${statusBadge}
                        </div>
                        <p class="card-text mb-1">
                            <i class="bi bi-calendar3"></i> 
                            <strong>${formatDate(delivery.scheduled_date)}</strong>
                        </p>
                        <p class="card-text mb-0">
                            <i class="bi bi-truck"></i> 
                            <strong>${safeQuantity} trucks</strong>
                        </p>
                    </div>
                </div>
            </div>
        `;
    });
    
    scheduleHtml += '</div>';
    scheduleHtml += `
        <div class="mt-3 p-3 bg-light rounded">
            <div class="row text-center">
                <div class="col-md-4">
                    <h6 class="text-muted mb-1">Total Deliveries</h6>
                    <h4 class="text-primary">${deliveries.length}</h4>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted mb-1">Total Trucks</h6>
                    <h4 class="text-success">${totalTrucks}</h4>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted mb-1">Frequency</h6>
                    <h4 class="text-info">${currentOrder.delivery_frequency_days} days</h4>
                </div>
            </div>
        </div>
    `;
    
    container.innerHTML = scheduleHtml;
}

function updateDispatchSummary(dispatches) {
    const card = document.getElementById('dispatchSummaryCard');
    const body = document.getElementById('dispatchSummaryBody');
    if (!dispatches.length) {
        card.style.display = 'none';
        return;
    }

    card.style.display = 'block';
    body.innerHTML = dispatches.map(d => `
        <div class="border rounded-3 p-3 mb-2 bg-light">
            <div class="row g-2 small">
                <div class="col-md-3"><span class="text-muted">Date</span><br><strong>${formatDate(d.dispatch_date)}</strong></div>
                <div class="col-md-2"><span class="text-muted">Trucks</span><br><strong>${d.dispatch_qty_trucks}</strong></div>
                <div class="col-md-2"><span class="text-muted">Rate</span><br><strong>${d.product_rate != null ? '₹' + Number(d.product_rate).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '/MT' : '—'}</strong></div>
                <div class="col-md-2"><span class="text-muted">Weight</span><br><strong>${d.loading_weight_tons != null ? Number(d.loading_weight_tons).toLocaleString('en-IN', { minimumFractionDigits: 3 }) + ' MT' : '<span class="text-warning">Awaiting kanta parchi</span>'}</strong></div>
                <div class="col-md-3"><span class="text-muted">Invoice</span><br><strong>${d.busy_invoice_no ? escapeHtml(d.busy_invoice_no) : '—'}</strong></div>
            </div>
            ${d.remarks ? `<div class="small text-muted mt-2 pt-2 border-top">${escapeHtml(d.remarks)}</div>` : ''}
        </div>
    `).join('');
}

function formatWeightTons(value) {
    if (value == null || value === '') return null;
    return Number(value).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}

function updateDispatchHistory(dispatches) {
    const tbody = document.querySelector('#dispatchesTable tbody');
    const colSpan = canEditDispatch ? 8 : 7;
    
    if (dispatches.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center text-muted">No dispatches yet</td></tr>`;
        return;
    }
    
    const rows = dispatches.map(dispatch => {
        const weightCell = dispatch.loading_weight_tons != null
            ? formatWeightTons(dispatch.loading_weight_tons)
            : '<span class="badge bg-warning text-dark">Awaiting kanta parchi</span>';
        const actionCell = canEditDispatch
            ? `<td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openWeightModal(${dispatch.id})" title="Enter weight from kanta parchi">
                        <i class="bi bi-scale"></i>
                    </button>
               </td>`
            : '';
        return `
        <tr>
            <td>${formatDate(dispatch.dispatch_date)}</td>
            <td><span class="badge bg-success">${dispatch.dispatch_qty_trucks}</span></td>
            <td>${dispatch.product_rate != null ? Number(dispatch.product_rate).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : '—'}</td>
            <td>${weightCell}</td>
            <td>${dispatch.busy_invoice_no ? escapeHtml(dispatch.busy_invoice_no) : '—'}</td>
            <td>${escapeHtml(dispatch.remarks || '-')}</td>
            <td>${escapeHtml(dispatch.dispatched_by_name || 'Unknown')}</td>
            ${actionCell}
        </tr>`;
    }).join('');
    
    tbody.innerHTML = rows;
}

function openWeightModal(dispatchId) {
    const dispatch = (currentOrder?.dispatches || []).find(d => Number(d.id) === Number(dispatchId));
    if (!dispatch) return;

    document.getElementById('weightDispatchId').value = dispatch.id;
    document.getElementById('weightModalInfo').textContent =
        `Dispatch on ${formatDate(dispatch.dispatch_date)} — ${dispatch.dispatch_qty_trucks} truck(s)`;
    document.getElementById('loadingWeightTons').value =
        dispatch.loading_weight_tons != null ? dispatch.loading_weight_tons : '';

    new bootstrap.Modal(document.getElementById('weightModal')).show();
}

<?php if (in_array($user['role'], ['entry', 'order_processing', 'admin', 'dispatch'])): ?>
document.getElementById('dispatchForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const dispatchBtn = document.getElementById('dispatchBtn');
    const dispatchSpinner = document.getElementById('dispatchSpinner');
    const formData = new FormData(this);
    
    // Validate quantity
    const qty = parseInt(formData.get('dispatch_qty_trucks'));
    if (qty > currentOrder.pending_trucks) {
        showError(`Cannot dispatch ${qty} trucks. Only ${currentOrder.pending_trucks} trucks available.`);
        return;
    }
    
    // Show loading state
    dispatchBtn.disabled = true;
    dispatchSpinner.classList.remove('d-none');
    
    try {
        const dispatchData = {
            dispatch_date: formData.get('dispatch_date'),
            dispatch_qty_trucks: qty,
            product_rate: parseFloat(formData.get('product_rate')),
            remarks: formData.get('remarks') || null
        };
        
        const response = await apiCall(`/api/orders/${orderId}/dispatches`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': formData.get('csrf_token')
            },
            body: JSON.stringify(dispatchData)
        });
        
        showSuccess('Dispatch created successfully!');
        
        // Reset form
        this.reset();
        document.getElementById('dispatchDate').value = new Date().toISOString().split('T')[0];
        
        // Reload order details to show updated status
        setTimeout(() => {
            loadOrderDetails();
        }, 1000);
        
    } catch (error) {
        showError(error.message);
    } finally {
        dispatchBtn.disabled = false;
        dispatchSpinner.classList.add('d-none');
    }
});
<?php endif; ?>

<?php if (in_array($user['role'], ['entry', 'order_processing', 'admin', 'dispatch'])): ?>
document.getElementById('weightForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const dispatchId = document.getElementById('weightDispatchId').value;
    const submitBtn = document.getElementById('weightSubmitBtn');
    const spinner = document.getElementById('weightSpinner');

    submitBtn.disabled = true;
    spinner.classList.remove('d-none');

    try {
        await apiCall(`/api/dispatches/${dispatchId}`, {
            method: 'PUT',
            body: JSON.stringify({
                loading_weight_tons: parseFloat(document.getElementById('loadingWeightTons').value)
            })
        });

        bootstrap.Modal.getInstance(document.getElementById('weightModal')).hide();
        showSuccess('Loading weight updated.');
        await loadOrderDetails();
    } catch (error) {
        showError(error.message);
    } finally {
        submitBtn.disabled = false;
        spinner.classList.add('d-none');
    }
});
<?php endif; ?>

async function deleteCurrentOrder() {
    if (!currentOrder) return;
    const dispatched = Number(currentOrder.total_dispatched) || 0;
    const msg = dispatched > 0
        ? `Delete order "${currentOrder.order_no}" and its ${dispatched} dispatch record(s)?\n\nThis cannot be undone.`
        : `Delete order "${currentOrder.order_no}"?\n\nThis cannot be undone.`;
    if (!confirm(msg)) return;

    try {
        await apiCall(`/api/orders/${orderId}`, { method: 'DELETE' });
        showSuccess('Order deleted.');
        setTimeout(() => { window.location.href = '/orders'; }, 800);
    } catch (error) {
        showError(error.message);
    }
}

<?php if (!empty($can_edit_orders)): ?>
async function loadEditFormOptions() {
    if (editFormParties.length && editFormProducts.length) return;
    const [partiesRes, productsRes] = await Promise.all([
        apiCall('/api/reports/parties'),
        apiCall('/api/reports/products')
    ]);
    editFormParties = partiesRes.data || [];
    editFormProducts = productsRes.data || [];
}

function toggleEditQtyMode() {
    const mode = document.querySelector('input[name="edit_qty_mode"]:checked')?.value || 'trucks';
    document.getElementById('editTrucksGroup').style.display = mode === 'trucks' ? 'block' : 'none';
    document.getElementById('editWeightGroup').style.display = mode === 'weight' ? 'block' : 'none';
}

function updateEditQtyHint() {
    const hint = document.getElementById('editQtyHint');
    if (!currentOrder || !hint) return;
    hint.textContent = `Minimum ${currentOrder.total_dispatched} truck(s) already dispatched`;
    const qtyInput = document.getElementById('editOrderQty');
    if (qtyInput) qtyInput.min = Math.max(1, Number(currentOrder.total_dispatched) || 1);
}

async function openEditOrderModal() {
    if (!currentOrder) return;
    await loadEditFormOptions();

    const partySelect = document.getElementById('editPartyId');
    const productSelect = document.getElementById('editProductId');
    partySelect.innerHTML = editFormParties.map(p => `<option value="${p.id}">${escapeHtml(p.name)}</option>`).join('');
    productSelect.innerHTML = editFormProducts.map(p => `<option value="${p.id}">${escapeHtml(p.name)}</option>`).join('');

    document.getElementById('editOrderDate').value = currentOrder.order_date;
    document.getElementById('editPriority').value = currentOrder.priority || 'normal';
    document.getElementById('editTonsPerTruck').value = currentOrder.tons_per_truck || 40;
    partySelect.value = String(currentOrder.party_id);
    productSelect.value = String(currentOrder.product_id);

    const mode = currentOrder.order_qty_mode === 'weight' ? 'weight' : 'trucks';
    document.getElementById(mode === 'weight' ? 'editQtyModeWeight' : 'editQtyModeTrucks').checked = true;
    document.getElementById('editOrderQty').value = currentOrder.order_qty_trucks;
    document.getElementById('editOrderWeight').value = currentOrder.order_weight_tons || '';
    toggleEditQtyMode();
    updateEditQtyHint();

    new bootstrap.Modal(document.getElementById('editOrderModal')).show();
}

document.querySelectorAll('input[name="edit_qty_mode"]').forEach(r => r.addEventListener('change', toggleEditQtyMode));

document.getElementById('editOrderForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const submitBtn = document.getElementById('editOrderSubmitBtn');
    const spinner = document.getElementById('editOrderSpinner');
    const mode = document.querySelector('input[name="edit_qty_mode"]:checked')?.value || 'trucks';

    submitBtn.disabled = true;
    spinner.classList.remove('d-none');

    try {
        const payload = {
            order_date: document.getElementById('editOrderDate').value,
            party_id: parseInt(document.getElementById('editPartyId').value),
            product_id: parseInt(document.getElementById('editProductId').value),
            priority: document.getElementById('editPriority').value,
            order_qty_mode: mode,
            tons_per_truck: parseFloat(document.getElementById('editTonsPerTruck').value) || 40,
            order_qty_trucks: mode === 'trucks' ? parseInt(document.getElementById('editOrderQty').value) : null,
            order_weight_tons: mode === 'weight' ? parseFloat(document.getElementById('editOrderWeight').value) : null
        };

        await apiCall(`/api/orders/${orderId}`, {
            method: 'PUT',
            body: JSON.stringify(payload)
        });

        bootstrap.Modal.getInstance(document.getElementById('editOrderModal')).hide();
        showSuccess('Order updated successfully.');
        await loadOrderDetails();
    } catch (error) {
        showError(error.message);
    } finally {
        submitBtn.disabled = false;
        spinner.classList.add('d-none');
    }
});
<?php endif; ?>

// Handle back navigation with preserved state
function goBackToOrders() {
    const urlParams = new URLSearchParams(window.location.search);
    const returnUrl = urlParams.get('return');
    
    if (returnUrl) {
        const decodedReturnUrl = decodeURIComponent(returnUrl);
        if (decodedReturnUrl.startsWith('/orders')) {
            window.location.href = decodedReturnUrl;
            return;
        }
    }
    window.location.href = '/orders';
}

// Load order details on page load
document.addEventListener('DOMContentLoaded', function() {
    loadOrderDetails();
});
</script>
