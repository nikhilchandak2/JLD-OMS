<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-truck me-2"></i>Dispatches Analytics
            </h1>
            <p class="page-subtitle">Detailed analysis of all dispatches in the system</p>
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
    <p>Loading dispatches analytics...</p>
</div>

<!-- Summary Cards -->
<div class="row mb-4" id="dispatchesSummary">
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
            <div class="col-md-3">
                <label for="filterCompany" class="form-label">Company</label>
                <select class="form-select" id="filterCompany">
                    <option value="">All Companies</option>
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
                <i class="bi bi-bar-chart me-2"></i>Dispatches by Company
            </div>
            <div class="card-body">
                <canvas id="companyChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-graph-up me-2"></i>Daily Dispatch Trend
            </div>
            <div class="card-body">
                <canvas id="trendChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Dispatches Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-2"></i>All Dispatches
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="dispatchesTable">
                <thead>
                    <tr>
                        <th>Dispatch Date</th>
                        <th>Order No.</th>
                        <th>Company</th>
                        <th>Party</th>
                        <th>Product</th>
                        <th class="text-end">Quantity</th>
                        <th>Vehicle No.</th>
                        <th>Driver</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be populated by JavaScript -->
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <nav aria-label="Dispatches pagination" class="mt-3">
            <ul class="pagination justify-content-center" id="pagination">
                <!-- Pagination will be populated by JavaScript -->
            </ul>
        </nav>
    </div>
</div>

<script>
let currentPage = 1;
let companyChart = null;
let trendChart = null;

async function loadAnalytics() {
    const loading = document.getElementById('loading');
    loading.style.display = 'block';
    
    try {
        const startDate = document.getElementById('filterStartDate').value;
        const endDate = document.getElementById('filterEndDate').value;
        const company = document.getElementById('filterCompany').value;
        
        // Build query parameters
        const params = new URLSearchParams({
            start_date: startDate,
            end_date: endDate,
            limit: 50,
            offset: (currentPage - 1) * 50
        });
        
        if (company) params.append('company_id', company);
        
        // Load dispatches data
        const dispatchesResponse = await apiCall(`/api/dispatches?${params.toString()}`);
        
        // Load analytics data
        const analyticsResponse = await apiCall(`/api/analytics/dispatches?start_date=${startDate}&end_date=${endDate}`);
        
        // Update UI
        updateDispatchesSummary(analyticsResponse.data.summary);
        updateDispatchesTable(dispatchesResponse.data);
        updatePagination(dispatchesResponse.pagination);
        updateCharts(analyticsResponse.data);
        
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function updateDispatchesSummary(summary) {
    const container = document.getElementById('dispatchesSummary');
    container.innerHTML = `
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h3>${summary.total_dispatches}</h3>
                    <h6>Total Dispatches</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h3>${summary.total_trucks}</h3>
                    <h6>Total Trucks</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h3>${summary.avg_per_day}</h3>
                    <h6>Avg per Day</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h3>${summary.active_orders}</h3>
                    <h6>Active Orders</h6>
                </div>
            </div>
        </div>
    `;
}

function updateDispatchesTable(dispatches) {
    const tbody = document.querySelector('#dispatchesTable tbody');
    
    if (dispatches.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No dispatches found</td></tr>';
        return;
    }
    
    const rows = dispatches.map(dispatch => `
        <tr>
            <td>${formatDate(dispatch.dispatch_date)}</td>
            <td><strong>${dispatch.order_no}</strong></td>
            <td><span class="badge bg-primary">${dispatch.company_name}</span></td>
            <td>${dispatch.party_name}</td>
            <td>${dispatch.product_name}</td>
            <td class="text-end">${dispatch.dispatch_qty_trucks}</td>
            <td>${dispatch.vehicle_no || '-'}</td>
            <td>${dispatch.driver_name || '-'}</td>
            <td>
                <a href="/orders/${dispatch.order_id}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-eye"></i> View Order
                </a>
            </td>
        </tr>
    `).join('');
    
    tbody.innerHTML = rows;
}

function updateCharts(data) {
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
                label: 'Dispatches',
                data: data.company_breakdown.map(item => item.count),
                backgroundColor: '#198754'
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
    
    // Trend Chart
    if (trendChart) {
        trendChart.destroy();
    }
    
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: data.daily_trend.map(item => formatDate(item.date)),
            datasets: [{
                label: 'Dispatches',
                data: data.daily_trend.map(item => item.count),
                borderColor: '#198754',
                backgroundColor: 'rgba(25, 135, 84, 0.1)',
                tension: 0.4
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



