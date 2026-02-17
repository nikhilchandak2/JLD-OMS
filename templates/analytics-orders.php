<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-clipboard-check me-2"></i>Orders Analytics
            </h1>
            <p class="page-subtitle">Detailed analysis of all orders in the system</p>
        </div>
        <a href="/dashboard" class="btn btn-outline-primary">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="loading" class="loading">
    <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p>Loading orders analytics...</p>
</div>

<!-- Summary Cards -->
<div class="row mb-4" id="ordersSummary">
    <!-- Cards will be populated by JavaScript -->
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-funnel me-2"></i>Filters & Analysis
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
                <label for="filterCompany" class="form-label">Company</label>
                <select class="form-select" id="filterCompany">
                    <option value="">All Companies</option>
                </select>
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
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100" onclick="loadAnalytics()">
                    <i class="bi bi-search me-1"></i> Apply Filters
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-pie-chart me-2"></i>Orders by Status
            </div>
            <div class="card-body">
                <canvas id="statusChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bar-chart me-2"></i>Orders by Company
            </div>
            <div class="card-body">
                <canvas id="companyChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Orders Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-2"></i>All Orders
    </div>
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
        <nav aria-label="Orders pagination" class="mt-3">
            <ul class="pagination justify-content-center" id="pagination">
                <!-- Pagination will be populated by JavaScript -->
            </ul>
        </nav>
    </div>
</div>

<script>
let currentPage = 1;
let statusChart = null;
let companyChart = null;

async function loadAnalytics() {
    const loading = document.getElementById('loading');
    loading.style.display = 'block';
    
    try {
        const startDate = document.getElementById('filterStartDate').value;
        const endDate = document.getElementById('filterEndDate').value;
        const company = document.getElementById('filterCompany').value;
        const status = document.getElementById('filterStatus').value;
        
        // Build query parameters
        const params = new URLSearchParams({
            start_date: startDate,
            end_date: endDate,
            limit: 50,
            offset: (currentPage - 1) * 50
        });
        
        if (company) params.append('company_id', company);
        if (status) params.append('status', status);
        
        // Load orders data
        const ordersResponse = await apiCall(`/api/orders?${params.toString()}`);
        
        // Load analytics data
        const analyticsResponse = await apiCall(`/api/analytics/orders?start_date=${startDate}&end_date=${endDate}`);
        
        // Update UI
        updateOrdersSummary(analyticsResponse.data.summary);
        updateOrdersTable(ordersResponse.data);
        updatePagination(ordersResponse.pagination);
        updateCharts(analyticsResponse.data);
        
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function updateOrdersSummary(summary) {
    const container = document.getElementById('ordersSummary');
    container.innerHTML = `
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h3>${summary.total_orders}</h3>
                    <h6>Total Orders</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h3>${summary.completed_orders}</h3>
                    <h6>Completed Orders</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h3>${summary.pending_orders}</h3>
                    <h6>Pending Orders</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h3>${summary.total_trucks}</h3>
                    <h6>Total Trucks</h6>
                </div>
            </div>
        </div>
    `;
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
                <a href="/orders/${order.id}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-eye"></i> View
                </a>
            </td>
        </tr>
    `).join('');
    
    tbody.innerHTML = rows;
}

function updateCharts(data) {
    // Status Chart
    if (statusChart) {
        statusChart.destroy();
    }
    
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: data.status_breakdown.map(item => item.status.charAt(0).toUpperCase() + item.status.slice(1)),
            datasets: [{
                data: data.status_breakdown.map(item => item.count),
                backgroundColor: ['#ffc107', '#fd7e14', '#198754']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });
    
    // Company Chart
    if (companyChart) {
        companyChart.destroy();
    }
    
    const companyCtx = document.getElementById('companyChart').getContext('2d');
    companyChart = new Chart(companyCtx, {
        type: 'bar',
        data: {
            labels: data.company_breakdown.map(item => item.company_name),
            datasets: [{
                label: 'Orders',
                data: data.company_breakdown.map(item => item.count),
                backgroundColor: '#2b235e'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

function updatePagination(pagination) {
    const paginationContainer = document.getElementById('pagination');
    
    if (pagination.total_pages <= 1) {
        paginationContainer.innerHTML = '';
        return;
    }
    
    let paginationHTML = '';
    
    // Previous button
    if (pagination.current_page > 1) {
        paginationHTML += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${pagination.current_page - 1})">Previous</a></li>`;
    }
    
    // Page numbers
    for (let i = 1; i <= pagination.total_pages; i++) {
        if (i === pagination.current_page) {
            paginationHTML += `<li class="page-item active"><span class="page-link">${i}</span></li>`;
        } else {
            paginationHTML += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${i})">${i}</a></li>`;
        }
    }
    
    // Next button
    if (pagination.current_page < pagination.total_pages) {
        paginationHTML += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${pagination.current_page + 1})">Next</a></li>`;
    }
    
    paginationContainer.innerHTML = paginationHTML;
}

function changePage(page) {
    currentPage = page;
    loadAnalytics();
}

// Load companies for filter
async function loadCompanies() {
    try {
        const response = await apiCall('/api/companies');
        const select = document.getElementById('filterCompany');
        
        const options = response.data.map(company => 
            `<option value="${company.id}">${company.name}</option>`
        ).join('');
        
        select.innerHTML = '<option value="">All Companies</option>' + options;
    } catch (error) {
        console.error('Error loading companies:', error);
    }
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    loadCompanies();
    loadAnalytics();
});
</script>



