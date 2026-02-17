<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="bi bi-clipboard-check"></i> Order Details</h1>
    <a href="#" onclick="goBackToOrders()" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Back to Orders
    </a>
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
    <!-- Order Information Card -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-info-circle"></i> Order Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Order No:</strong></td>
                                    <td id="orderNo">-</td>
                                </tr>
                                <tr>
                                    <td><strong>Order Date:</strong></td>
                                    <td id="orderDate">-</td>
                                </tr>
                                <tr>
                                    <td><strong>Party:</strong></td>
                                    <td id="partyName">-</td>
                                </tr>
                                <tr>
                                    <td><strong>Product:</strong></td>
                                    <td id="productName">-</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Ordered Trucks:</strong></td>
                                    <td><span id="orderedQty" class="badge bg-primary fs-6">-</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Dispatched:</strong></td>
                                    <td><span id="dispatchedQty" class="badge bg-success fs-6">-</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Pending:</strong></td>
                                    <td><span id="pendingQty" class="badge bg-warning fs-6">-</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Status:</strong></td>
                                    <td><span id="orderStatus">-</span></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <?php if (in_array($user['role'], ['entry', 'admin'])): ?>
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-truck"></i> Create Dispatch</h5>
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
                            <div class="form-text">Available: <span id="availableQty">-</span> trucks</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="vehicleNo" class="form-label">Vehicle No (Optional)</label>
                            <input type="text" class="form-control" id="vehicleNo" name="vehicle_no" placeholder="e.g., TRK-001">
                        </div>
                        
                        <div class="mb-3">
                            <label for="remarks" class="form-label">Remarks (Optional)</label>
                            <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="Any additional notes"></textarea>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success" id="dispatchBtn">
                                <span class="spinner-border spinner-border-sm d-none" id="dispatchSpinner"></span>
                                <i class="bi bi-truck"></i> Create Dispatch
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Delivery Schedule (for recurring orders) -->
    <div class="card mb-4" id="deliveryScheduleCard" style="display: none;">
        <div class="card-header">
            <h5><i class="bi bi-calendar-event"></i> Delivery Schedule</h5>
        </div>
        <div class="card-body">
            <div id="deliveryScheduleContent">
                <!-- Schedule will be populated by JavaScript -->
            </div>
        </div>
    </div>
    
    <!-- Dispatch History -->
    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-clock-history"></i> Dispatch History</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" id="dispatchesTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Quantity</th>
                            <th>Vehicle No</th>
                            <th>Remarks</th>
                            <th>Dispatched By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
let currentOrder = null;
const orderId = <?= $order_id ?>;

async function loadOrderDetails() {
    const loading = document.getElementById('loading');
    const content = document.getElementById('orderContent');
    
    loading.style.display = 'block';
    content.style.display = 'none';
    
    try {
        const response = await apiCall(`/api/orders/${orderId}`);
        currentOrder = response.data;
        
        updateOrderDisplay(currentOrder);
        updateDispatchHistory(currentOrder.dispatches || []);
        
        content.style.display = 'block';
        
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function updateOrderDisplay(order) {
    document.getElementById('orderNo').textContent = order.order_no;
    document.getElementById('orderDate').textContent = formatDate(order.order_date);
    document.getElementById('partyName').textContent = order.party_name;
    document.getElementById('productName').textContent = order.product_name;
    document.getElementById('orderedQty').textContent = order.order_qty_trucks;
    document.getElementById('dispatchedQty').textContent = order.total_dispatched;
    document.getElementById('pendingQty').textContent = order.pending_trucks;
    document.getElementById('orderStatus').innerHTML = formatStatus(order.status);
    document.getElementById('availableQty').textContent = order.pending_trucks;
    
    // Update dispatch form max quantity
    const dispatchQtyInput = document.getElementById('dispatchQty');
    if (dispatchQtyInput) {
        dispatchQtyInput.max = order.pending_trucks;
        dispatchQtyInput.placeholder = `Max: ${order.pending_trucks}`;
    }
    
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
        
        scheduleHtml += `
            <div class="col-md-6 col-lg-4 mb-3">
                <div class="card border-left-primary h-100 delivery-schedule-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="card-title mb-0">Delivery ${delivery.delivery_sequence}</h6>
                            ${statusBadge}
                        </div>
                        <p class="card-text mb-1">
                            <i class="bi bi-calendar3"></i> 
                            <strong>${formatDate(delivery.scheduled_date)}</strong>
                        </p>
                        <p class="card-text mb-0">
                            <i class="bi bi-truck"></i> 
                            <strong>${delivery.trucks_quantity} trucks</strong>
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

function updateDispatchHistory(dispatches) {
    const tbody = document.querySelector('#dispatchesTable tbody');
    
    if (dispatches.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No dispatches yet</td></tr>';
        return;
    }
    
    const rows = dispatches.map(dispatch => `
        <tr>
            <td>${formatDate(dispatch.dispatch_date)}</td>
            <td><span class="badge bg-success">${dispatch.dispatch_qty_trucks}</span></td>
            <td>${dispatch.vehicle_no || '-'}</td>
            <td>${dispatch.remarks || '-'}</td>
            <td>${dispatch.dispatched_by_name || 'Unknown'}</td>
        </tr>
    `).join('');
    
    tbody.innerHTML = rows;
}

<?php if (in_array($user['role'], ['entry', 'admin'])): ?>
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
            vehicle_no: formData.get('vehicle_no') || null,
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

// Handle back navigation with preserved state
function goBackToOrders() {
    const urlParams = new URLSearchParams(window.location.search);
    const returnUrl = urlParams.get('return');
    
    if (returnUrl) {
        // Go back to the specific orders page with filters preserved
        window.location.href = decodeURIComponent(returnUrl);
    } else {
        // Fallback to orders page
        window.location.href = '/orders';
    }
}

// Load order details on page load
document.addEventListener('DOMContentLoaded', function() {
    loadOrderDetails();
});
</script>
