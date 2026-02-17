<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-clipboard-check me-2"></i>Orders
            </h1>
            <p class="page-subtitle">Manage and track all customer orders</p>
        </div>
        <?php if (in_array($user['role'], ['entry', 'admin'])): ?>
        <a href="/orders/new" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> New Order
        </a>
        <?php endif; ?>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-funnel me-2"></i>Filters
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label for="filterStartDate" class="form-label">Start Date</label>
                <input type="date" class="form-control" id="filterStartDate" value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
            </div>
            <div class="col-md-3">
                <label for="filterEndDate" class="form-label">End Date</label>
                <input type="date" class="form-control" id="filterEndDate" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-2">
                <label for="filterStatus" class="form-label">Status</label>
                <select class="form-select" id="filterStatus">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="partial">Partial</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="filterParty" class="form-label">Party</label>
                <select class="form-select" id="filterParty">
                    <option value="">All Parties</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary d-block w-100" onclick="loadOrders()">
                    <i class="bi bi-search"></i> Filter
                </button>
            </div>
        </div>
    </div>
</div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p>Loading orders...</p>
</div>

<!-- Orders Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="ordersTable">
                <thead>
                    <tr>
                        <th>Order No.</th>
                        <th>Date</th>
                        <th>Company</th>
                        <th>Party</th>
                        <th>Product</th>
                        <th class="text-end">Ordered</th>
                        <th class="text-end">Dispatched</th>
                        <th class="text-end">Pending</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be populated by JavaScript -->
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <nav aria-label="Orders pagination">
            <ul class="pagination justify-content-center" id="pagination">
                <!-- Pagination will be populated by JavaScript -->
            </ul>
        </nav>
    </div>
</div>

<script>
let currentPage = 0;
const pageSize = 20;

// State management functions
function saveFiltersState() {
    const state = {
        startDate: document.getElementById('filterStartDate').value,
        endDate: document.getElementById('filterEndDate').value,
        status: document.getElementById('filterStatus').value,
        party: document.getElementById('filterParty').value,
        page: currentPage
    };
    
    // Update URL parameters (for back navigation from order details)
    const url = new URL(window.location);
    url.searchParams.set('start_date', state.startDate);
    url.searchParams.set('end_date', state.endDate);
    if (state.status) url.searchParams.set('status', state.status);
    else url.searchParams.delete('status');
    if (state.party) url.searchParams.set('party_id', state.party);
    else url.searchParams.delete('party_id');
    if (state.page > 0) url.searchParams.set('page', state.page);
    else url.searchParams.delete('page');
    
    window.history.replaceState({}, '', url);
}

function restoreFiltersState() {
    const urlParams = new URLSearchParams(window.location.search);
    
    // Check if we have URL parameters (indicating we're coming back from order details)
    const hasUrlParams = urlParams.has('start_date') || urlParams.has('end_date') || 
                         urlParams.has('status') || urlParams.has('party_id') || urlParams.has('page');
    
    if (hasUrlParams) {
        // We're coming back from order details - restore from URL parameters
        const startDate = urlParams.get('start_date') || '<?= date('Y-m-d', strtotime('-30 days')) ?>';
        const endDate = urlParams.get('end_date') || '<?= date('Y-m-d') ?>';
        const status = urlParams.get('status') || '';
        const party = urlParams.get('party_id') || '';
        const page = parseInt(urlParams.get('page') || '0');
        
        document.getElementById('filterStartDate').value = startDate;
        document.getElementById('filterEndDate').value = endDate;
        document.getElementById('filterStatus').value = status;
        document.getElementById('filterParty').value = party;
        
        return page;
    } else {
        // Fresh navigation from dashboard/menu - use default values and clear localStorage
        localStorage.removeItem('ordersFilters');
        
        document.getElementById('filterStartDate').value = '<?= date('Y-m-d', strtotime('-30 days')) ?>';
        document.getElementById('filterEndDate').value = '<?= date('Y-m-d') ?>';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterParty').value = '';
        
        return 0; // Start from page 0
    }
}

async function loadOrders(page = 0) {
    const loading = document.getElementById('loading');
    loading.style.display = 'block';
    
    try {
        const params = new URLSearchParams({
            start_date: document.getElementById('filterStartDate').value,
            end_date: document.getElementById('filterEndDate').value,
            limit: pageSize,
            offset: page * pageSize
        });
        
        const status = document.getElementById('filterStatus').value;
        if (status) params.append('status', status);
        
        const party = document.getElementById('filterParty').value;
        if (party) params.append('party_id', party);
        
        const response = await apiCall(`/api/orders?${params}`);
        
        updateOrdersTable(response.data);
        updatePagination(response.pagination);
        currentPage = page;
        
        // Save current state
        saveFiltersState();
        
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function updateOrdersTable(orders) {
    const tbody = document.querySelector('#ordersTable tbody');
    
    if (orders.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">No orders found</td></tr>';
        return;
    }
    
    const rows = orders.map(order => `
        <tr>
            <td>
                <strong>${order.order_no}</strong>
                ${order.is_recurring ? '<span class="badge bg-info ms-1">Recurring</span>' : ''}
            </td>
            <td>${formatDate(order.order_date)}</td>
            <td><span class="badge bg-primary">${order.company_name}</span></td>
            <td>${order.party_name}</td>
            <td>${order.product_name}</td>
            <td class="text-end">${order.order_qty_trucks}</td>
            <td class="text-end">${order.total_dispatched}</td>
            <td class="text-end">${order.pending_trucks}</td>
            <td>${formatPriority(order.priority)}</td>
            <td>${formatStatus(order.status)}</td>
            <td>
                <a href="/orders/${order.id}?return=${encodeURIComponent(window.location.pathname + window.location.search)}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-eye"></i> View
                </a>
                ${order.status !== 'completed' && '<?= $user["role"] ?>' !== 'view' ? `
                    <button class="btn btn-sm btn-outline-warning" onclick="editOrder(${order.id})">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                ` : ''}
                ${'<?= $user["role"] ?>' === 'admin' && order.total_dispatched === 0 ? `
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteOrder(${order.id}, '${order.order_no}')">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                ` : ''}
            </td>
        </tr>
    `).join('');
    
    tbody.innerHTML = rows;
}

function updatePagination(pagination) {
    const paginationEl = document.getElementById('pagination');
    
    if (pagination.total <= pagination.limit) {
        paginationEl.innerHTML = '';
        return;
    }
    
    const totalPages = Math.ceil(pagination.total / pagination.limit);
    const currentPageNum = Math.floor(pagination.offset / pagination.limit);
    
    let paginationHTML = '';
    
    // Previous button
    if (currentPageNum > 0) {
        paginationHTML += `
            <li class="page-item">
                <a class="page-link" href="#" onclick="loadOrders(${currentPageNum - 1})">Previous</a>
            </li>
        `;
    }
    
    // Page numbers
    const startPage = Math.max(0, currentPageNum - 2);
    const endPage = Math.min(totalPages - 1, currentPageNum + 2);
    
    for (let i = startPage; i <= endPage; i++) {
        paginationHTML += `
            <li class="page-item ${i === currentPageNum ? 'active' : ''}">
                <a class="page-link" href="#" onclick="loadOrders(${i})">${i + 1}</a>
            </li>
        `;
    }
    
    // Next button
    if (currentPageNum < totalPages - 1) {
        paginationHTML += `
            <li class="page-item">
                <a class="page-link" href="#" onclick="loadOrders(${currentPageNum + 1})">Next</a>
            </li>
        `;
    }
    
    paginationEl.innerHTML = paginationHTML;
}

async function loadParties() {
    try {
        const response = await apiCall('/api/reports/parties');
        const select = document.getElementById('filterParty');
        
        const options = response.data.map(party => 
            `<option value="${party.id}">${party.name}</option>`
        ).join('');
        
        select.innerHTML = '<option value="">All Parties</option>' + options;
    } catch (error) {
        console.error('Failed to load parties:', error);
    }
}

function editOrder(orderId) {
    // This would open a modal or redirect to edit page
    // For now, just redirect to order detail page
    window.location.href = `/orders/${orderId}`;
}

async function deleteOrder(orderId, orderNo) {
    if (!confirm(`Are you sure you want to delete order "${orderNo}"?\n\nThis action cannot be undone and will only work if the order has no dispatches.`)) {
        return;
    }
    
    console.log('Attempting to delete order:', orderId); // Debug log
    
    try {
        const response = await fetch(`/api/orders/${orderId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': '<?= $csrf_token ?>'
            }
        });
        
        console.log('Delete response status:', response.status); // Debug log
        console.log('Delete response headers:', response.headers); // Debug log
        
        const result = await response.json();
        console.log('Delete response result:', result); // Debug log
        
        if (result.success) {
            showAlert('Order deleted successfully', 'success');
            loadOrders(); // Reload the orders list
        } else {
            showAlert('Error: ' + result.error, 'danger');
        }
    } catch (error) {
        console.error('Delete error:', error); // Debug log
        showAlert('Error deleting order: ' + error.message, 'danger');
    }
}

function showAlert(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.container-fluid');
    container.insertBefore(alertDiv, container.firstChild);
    
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}

// Clean up URL parameters when navigating away (except to order details)
function cleanupUrlOnNavigation() {
    // Clean up URL parameters when leaving the orders page
    // (except when going to order details which needs the return URL)
    const currentUrl = new URL(window.location);
    if (currentUrl.searchParams.has('start_date') || currentUrl.searchParams.has('end_date') || 
        currentUrl.searchParams.has('status') || currentUrl.searchParams.has('party_id') || 
        currentUrl.searchParams.has('page')) {
        
        // Only clean up if we're not going to an order detail page
        const links = document.querySelectorAll('a:not([href*="/orders/"]):not([href="#"])');
        links.forEach(link => {
            link.addEventListener('click', function() {
                // Clean the URL when navigating to non-order pages
                const cleanUrl = new URL(window.location);
                cleanUrl.search = '';
                window.history.replaceState({}, '', cleanUrl);
            });
        });
    }
}

// Load data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadParties();
    
    // Restore saved state first
    const savedPage = restoreFiltersState();
    
    // Add event listeners for filter changes
    document.getElementById('filterStartDate').addEventListener('change', () => loadOrders(0));
    document.getElementById('filterEndDate').addEventListener('change', () => loadOrders(0));
    document.getElementById('filterStatus').addEventListener('change', () => loadOrders(0));
    document.getElementById('filterParty').addEventListener('change', () => loadOrders(0));
    
    // Setup URL cleanup for navigation
    cleanupUrlOnNavigation();
    
    // Load orders with restored state
    loadOrders(savedPage);
});
</script>

