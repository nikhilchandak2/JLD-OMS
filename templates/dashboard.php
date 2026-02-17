<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-speedometer2 me-2"></i>Dashboard
            </h1>
            <p class="page-subtitle">Overview of your order processing operations</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center">
                <label for="dateRange" class="form-label me-2 mb-0">Period:</label>
                <input type="date" id="startDate" class="form-control" style="width: 150px;" value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
                <span class="mx-2 text-muted">to</span>
                <input type="date" id="endDate" class="form-control" style="width: 150px;" value="<?= date('Y-m-d') ?>">
            </div>
            <button class="btn btn-primary" onclick="loadDashboard()">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="loading" class="loading">
    <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p>Loading dashboard data...</p>
</div>

<!-- Summary Cards -->
<div class="row mb-4" id="summaryCards">
    <!-- Cards will be populated by JavaScript -->
</div>

<!-- Company-wise Summary -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-building me-2"></i>Company-wise Summary
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped" id="companyTotalsTable">
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th class="text-end">Total Orders</th>
                                <th class="text-end">Ordered Trucks</th>
                                <th class="text-end">Dispatched Trucks</th>
                                <th class="text-end">Pending Trucks</th>
                                <th class="text-end">Completion %</th>
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
</div>

<!-- Product Totals -->
<div class="row mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bar-chart me-2"></i>Product-wise Totals
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped" id="productTotalsTable">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Ordered</th>
                                <th class="text-end">Dispatched</th>
                                <th class="text-end">Pending</th>
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
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-graph-up"></i> 6-Month Trend</h5>
            </div>
            <div class="card-body">
                <canvas id="trendChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-clock-history"></i> Recent Orders</h5>
            </div>
            <div class="card-body">
                <div id="recentOrders">
                    <!-- Will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-truck"></i> Recent Dispatches</h5>
            </div>
            <div class="card-body">
                <div id="recentDispatches">
                    <!-- Will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let trendChart = null;

async function loadDashboard() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    
    if (!startDate || !endDate) {
        showError('Please select both start and end dates');
        return;
    }
    
    if (new Date(startDate) > new Date(endDate)) {
        showError('Start date cannot be after end date');
        return;
    }
    
    const loading = document.getElementById('loading');
    loading.style.display = 'block';
    
    try {
        // Load dashboard data
        const dashboardData = await apiCall(`/api/dashboard?start_date=${startDate}&end_date=${endDate}`);
        
        // Load summary stats
        const summaryData = await apiCall('/api/dashboard/summary');
        
        // Update UI
        updateSummaryCards(summaryData.data);
        updateCompanyTotals(dashboardData.data.company_totals || []);
        updateProductTotals(dashboardData.data.product_totals);
        updateTrendChart(dashboardData.data.trend_data);
        
        // Load recent activity
        await loadRecentActivity();
        
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function updateSummaryCards(summary) {
    const container = document.getElementById('summaryCards');
    container.innerHTML = `
        <div class="col-md-3">
            <div class="card bg-primary text-white clickable-card" onclick="window.location.href='/analytics/orders'" style="cursor: pointer;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Total Orders</h6>
                            <h3>${summary.totals.orders}</h3>
                            <small class="opacity-75">View all orders</small>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-clipboard-check fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white clickable-card" onclick="window.location.href='/analytics/dispatches'" style="cursor: pointer;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Total Dispatches</h6>
                            <h3>${summary.totals.dispatches}</h3>
                            <small class="opacity-75">View all dispatches</small>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-truck fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white clickable-card" onclick="window.location.href='/analytics/pending'" style="cursor: pointer;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Pending Orders</h6>
                            <h3>${summary.pending.orders}</h3>
                            <small class="opacity-75">View pending orders</small>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-hourglass-split fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white clickable-card" onclick="window.location.href='/analytics/parties'" style="cursor: pointer;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Active Parties</h6>
                            <h3>${summary.totals.parties}</h3>
                            <small class="opacity-75">View all parties</small>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-people fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function updateCompanyTotals(companyTotals) {
    const tbody = document.querySelector('#companyTotalsTable tbody');
    
    if (companyTotals.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No data available for selected period</td></tr>';
        return;
    }
    
    const rows = companyTotals.map(company => {
        const completionRate = company.total_ordered > 0 ? ((company.total_dispatched / company.total_ordered) * 100).toFixed(1) : 0;
        const pendingTrucks = company.total_ordered - company.total_dispatched;
        
        return `
            <tr>
                <td>
                    <span class="badge bg-primary">${company.name}</span>
                    <small class="text-muted d-block">${company.code}</small>
                </td>
                <td class="text-end">${company.total_orders}</td>
                <td class="text-end">${company.total_ordered}</td>
                <td class="text-end">${company.total_dispatched}</td>
                <td class="text-end">${pendingTrucks}</td>
                <td class="text-end">
                    <div class="d-flex align-items-center justify-content-end">
                        <span class="me-2">${completionRate}%</span>
                        <div class="progress" style="width: 60px; height: 8px;">
                            <div class="progress-bar ${completionRate >= 80 ? 'bg-success' : completionRate >= 50 ? 'bg-warning' : 'bg-danger'}" 
                                 style="width: ${completionRate}%"></div>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = rows;
}

function updateProductTotals(productTotals) {
    const tbody = document.querySelector('#productTotalsTable tbody');
    
    if (productTotals.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No data available for selected period</td></tr>';
        return;
    }
    
    let totalOrdered = 0;
    let totalDispatched = 0;
    let totalPending = 0;
    
    const rows = productTotals.map(product => {
        totalOrdered += parseInt(product.total_ordered);
        totalDispatched += parseInt(product.total_dispatched);
        totalPending += parseInt(product.pending_trucks);
        
        return `
            <tr>
                <td>${product.product_name}</td>
                <td class="text-end">${product.total_ordered}</td>
                <td class="text-end">${product.total_dispatched}</td>
                <td class="text-end">${product.pending_trucks}</td>
            </tr>
        `;
    }).join('');
    
    const totalRow = `
        <tr class="table-dark fw-bold">
            <td>TOTAL</td>
            <td class="text-end">${totalOrdered}</td>
            <td class="text-end">${totalDispatched}</td>
            <td class="text-end">${totalPending}</td>
        </tr>
    `;
    
    tbody.innerHTML = rows + totalRow;
}

function updateTrendChart(trendData) {
    const ctx = document.getElementById('trendChart').getContext('2d');
    
    if (trendChart) {
        trendChart.destroy();
    }
    
    const labels = trendData.map(item => item.month);
    const data = trendData.map(item => parseInt(item.trucks_ordered));
    
    trendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Trucks Ordered',
                data: data,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.1
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

async function loadRecentActivity() {
    try {
        // Load recent orders
        const ordersResponse = await apiCall('/api/orders?limit=5');
        updateRecentOrders(ordersResponse.data);
        
        // Load recent dispatches
        const dispatchesResponse = await apiCall('/api/dispatches?limit=5');
        updateRecentDispatches(dispatchesResponse.data);
        
    } catch (error) {
        console.error('Failed to load recent activity:', error);
    }
}

function updateRecentOrders(orders) {
    const container = document.getElementById('recentOrders');
    
    if (orders.length === 0) {
        container.innerHTML = '<p class="text-muted">No recent orders</p>';
        return;
    }
    
    const ordersList = orders.map(order => `
        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
            <div>
                <strong>${order.order_no}</strong> ${formatPriority(order.priority)}<br>
                <small class="text-muted">${order.party_name} - ${order.product_name}</small>
            </div>
            <div class="text-end">
                <div>${formatStatus(order.status)}</div>
                <small class="text-muted">${formatDate(order.order_date)}</small>
            </div>
        </div>
    `).join('');
    
    container.innerHTML = ordersList;
}

function updateRecentDispatches(dispatches) {
    const container = document.getElementById('recentDispatches');
    
    if (dispatches.length === 0) {
        container.innerHTML = '<p class="text-muted">No recent dispatches</p>';
        return;
    }
    
    const dispatchesList = dispatches.map(dispatch => `
        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
            <div>
                <strong>${dispatch.order_no}</strong><br>
                <small class="text-muted">${dispatch.dispatch_qty_trucks} trucks</small>
            </div>
            <div class="text-end">
                <div><span class="badge bg-success">Dispatched</span></div>
                <small class="text-muted">${formatDate(dispatch.dispatch_date)}</small>
            </div>
        </div>
    `).join('');
    
    container.innerHTML = dispatchesList;
}

// Load dashboard on page load
document.addEventListener('DOMContentLoaded', function() {
    loadDashboard();
});
</script>

