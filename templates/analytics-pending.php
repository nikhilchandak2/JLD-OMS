<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-hourglass-split me-2"></i>Pending Orders Analytics
            </h1>
            <p class="page-subtitle">Analysis of orders that require attention</p>
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
    <p>Loading pending orders analytics...</p>
</div>

<!-- Summary Cards -->
<div class="row mb-4" id="pendingSummary">
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
                <label for="filterCompany" class="form-label">Company</label>
                <select class="form-select" id="filterCompany">
                    <option value="">All Companies</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="filterPriority" class="form-label">Priority</label>
                <select class="form-select" id="filterPriority">
                    <option value="">All Priorities</option>
                    <option value="urgent">Urgent</option>
                    <option value="normal">Normal</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="filterAge" class="form-label">Order Age</label>
                <select class="form-select" id="filterAge">
                    <option value="">All Ages</option>
                    <option value="today">Today</option>
                    <option value="week">This Week</option>
                    <option value="month">This Month</option>
                    <option value="old">Older than 30 days</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
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
                <i class="bi bi-pie-chart me-2"></i>Pending by Priority
            </div>
            <div class="card-body">
                <canvas id="priorityChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bar-chart me-2"></i>Pending by Company
            </div>
            <div class="card-body">
                <canvas id="companyChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Urgent Orders Alert -->
<div class="row mb-4" id="urgentAlert" style="display: none;">
    <div class="col-12">
        <div class="alert alert-danger">
            <h5><i class="bi bi-exclamation-triangle me-2"></i>Urgent Orders Requiring Attention</h5>
            <div id="urgentOrdersList"></div>
        </div>
    </div>
</div>

<!-- Pending Orders Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-2"></i>Pending Orders
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="pendingTable">
                <thead>
                    <tr>
                        <th>Order No.</th>
                        <th>Date</th>
                        <th>Age</th>
                        <th>Company</th>
                        <th>Party</th>
                        <th>Product</th>
                        <th class="text-end">Ordered</th>
                        <th class="text-end">Dispatched</th>
                        <th class="text-end">Pending</th>
                        <th>Priority</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be populated by JavaScript -->
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <nav aria-label="Pending orders pagination" class="mt-3">
            <ul class="pagination justify-content-center" id="pagination">
                <!-- Pagination will be populated by JavaScript -->
            </ul>
        </nav>
    </div>
</div>

<script>
let currentPage = 1;
let priorityChart = null;
let companyChart = null;

async function loadAnalytics() {
    const loading = document.getElementById('loading');
    loading.style.display = 'block';
    
    try {
        const company = document.getElementById('filterCompany').value;
        const priority = document.getElementById('filterPriority').value;
        const age = document.getElementById('filterAge').value;
        
        // Build query parameters
        const params = new URLSearchParams({
            status: 'pending,partial',
            limit: 50,
            offset: (currentPage - 1) * 50
        });
        
        if (company) params.append('company_id', company);
        if (priority) params.append('priority', priority);
        
        // Add age filter logic
        if (age) {
            const today = new Date();
            let startDate, endDate;
            
            switch(age) {
                case 'today':
                    startDate = endDate = today.toISOString().split('T')[0];
                    break;
                case 'week':
                    startDate = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                    endDate = today.toISOString().split('T')[0];
                    break;
                case 'month':
                    startDate = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                    endDate = today.toISOString().split('T')[0];
                    break;
                case 'old':
                    endDate = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                    startDate = '2020-01-01';
                    break;
            }
            
            if (startDate) params.append('start_date', startDate);
            if (endDate) params.append('end_date', endDate);
        }
        
        // Load pending orders data
        const ordersResponse = await apiCall(`/api/orders?${params.toString()}`);
        
        // Load analytics data
        const analyticsResponse = await apiCall(`/api/analytics/pending`);
        
        // Update UI
        updatePendingSummary(analyticsResponse.data.summary);
        updatePendingTable(ordersResponse.data);
        updatePagination(ordersResponse.pagination);
        updateCharts(analyticsResponse.data);
        updateUrgentAlert(analyticsResponse.data.urgent_orders);
        
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function updatePendingSummary(summary) {
    const container = document.getElementById('pendingSummary');
    container.innerHTML = `
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h3>${summary.total_pending}</h3>
                    <h6>Pending Orders</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body text-center">
                    <h3>${summary.urgent_pending}</h3>
                    <h6>Urgent Pending</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h3>${summary.pending_trucks}</h3>
                    <h6>Pending Trucks</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-secondary text-white">
                <div class="card-body text-center">
                    <h3>${summary.avg_age} days</h3>
                    <h6>Average Age</h6>
                </div>
            </div>
        </div>
    `;
}

function updatePendingTable(orders) {
    const tbody = document.querySelector('#pendingTable tbody');
    
    if (orders.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">No pending orders found</td></tr>';
        return;
    }
    
    const rows = orders.map(order => {
        const orderDate = new Date(order.order_date);
        const today = new Date();
        const ageInDays = Math.floor((today - orderDate) / (1000 * 60 * 60 * 24));
        
        let ageClass = '';
        if (ageInDays > 30) ageClass = 'text-danger';
        else if (ageInDays > 7) ageClass = 'text-warning';
        
        return `
            <tr>
                <td>
                    <strong>${order.order_no}</strong>
                    ${order.is_recurring ? '<span class="badge bg-info ms-1">Recurring</span>' : ''}
                </td>
                <td>${formatDate(order.order_date)}</td>
                <td class="${ageClass}"><strong>${ageInDays} days</strong></td>
                <td><span class="badge bg-primary">${order.company_name}</span></td>
                <td>${order.party_name}</td>
                <td>${order.product_name}</td>
                <td class="text-end">${order.order_qty_trucks}</td>
                <td class="text-end">${order.total_dispatched}</td>
                <td class="text-end"><strong>${order.pending_trucks}</strong></td>
                <td>${formatPriority(order.priority)}</td>
                <td>
                    <a href="/orders/${order.id}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-eye"></i> View
                    </a>
                    ${order.status !== 'completed' ? `
                        <button class="btn btn-sm btn-outline-success ms-1" onclick="createDispatch(${order.id})">
                            <i class="bi bi-truck"></i> Dispatch
                        </button>
                    ` : ''}
                </td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = rows;
}

function updateCharts(data) {
    // Priority Chart
    if (priorityChart) {
        priorityChart.destroy();
    }
    
    const priorityCtx = document.getElementById('priorityChart').getContext('2d');
    priorityChart = new Chart(priorityCtx, {
        type: 'doughnut',
        data: {
            labels: data.priority_breakdown.map(item => item.priority.charAt(0).toUpperCase() + item.priority.slice(1)),
            datasets: [{
                data: data.priority_breakdown.map(item => item.count),
                backgroundColor: ['#ed1d25', '#6c757d']
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
                label: 'Pending Orders',
                data: data.company_breakdown.map(item => item.count),
                backgroundColor: '#ffc107'
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

function updateUrgentAlert(urgentOrders) {
    const alertContainer = document.getElementById('urgentAlert');
    const listContainer = document.getElementById('urgentOrdersList');
    
    if (urgentOrders.length === 0) {
        alertContainer.style.display = 'none';
        return;
    }
    
    alertContainer.style.display = 'block';
    
    const urgentList = urgentOrders.map(order => `
        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
            <div>
                <strong>${order.order_no}</strong> - ${order.party_name}
                <small class="text-muted d-block">${order.pending_trucks} trucks pending</small>
            </div>
            <div>
                <a href="/orders/${order.id}" class="btn btn-sm btn-outline-light me-2">View</a>
                <button class="btn btn-sm btn-light" onclick="createDispatch(${order.id})">Dispatch Now</button>
            </div>
        </div>
    `).join('');
    
    listContainer.innerHTML = urgentList;
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

function createDispatch(orderId) {
    // Redirect to dispatch creation
    window.location.href = `/orders/${orderId}#dispatch`;
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



