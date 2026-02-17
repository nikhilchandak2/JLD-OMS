<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-people me-2"></i>Active Parties Analytics
            </h1>
            <p class="page-subtitle">Analysis of customer and supplier activity</p>
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
    <p>Loading parties analytics...</p>
</div>

<!-- Summary Cards -->
<div class="row mb-4" id="partiesSummary">
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
                <label for="filterActivity" class="form-label">Activity Level</label>
                <select class="form-select" id="filterActivity">
                    <option value="">All Parties</option>
                    <option value="active">Active (Has Orders)</option>
                    <option value="inactive">Inactive (No Orders)</option>
                    <option value="high">High Activity (5+ Orders)</option>
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
                <i class="bi bi-bar-chart me-2"></i>Top Parties by Orders
            </div>
            <div class="card-body">
                <canvas id="topPartiesChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-pie-chart me-2"></i>Activity Distribution
            </div>
            <div class="card-body">
                <canvas id="activityChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Top Performers -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-trophy me-2"></i>Top Performing Parties
            </div>
            <div class="card-body">
                <div class="row" id="topPerformers">
                    <!-- Top performers will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Parties Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-2"></i>All Parties
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="partiesTable">
                <thead>
                    <tr>
                        <th>Party Name</th>
                        <th>Contact Person</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th class="text-end">Total Orders</th>
                        <th class="text-end">Total Trucks</th>
                        <th class="text-end">Pending Orders</th>
                        <th>Last Order</th>
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
        <nav aria-label="Parties pagination" class="mt-3">
            <ul class="pagination justify-content-center" id="pagination">
                <!-- Pagination will be populated by JavaScript -->
            </ul>
        </nav>
    </div>
</div>

<script>
let currentPage = 1;
let topPartiesChart = null;
let activityChart = null;

async function loadAnalytics() {
    const loading = document.getElementById('loading');
    loading.style.display = 'block';
    
    try {
        const startDate = document.getElementById('filterStartDate').value;
        const endDate = document.getElementById('filterEndDate').value;
        const activity = document.getElementById('filterActivity').value;
        
        // Build query parameters for parties
        const params = new URLSearchParams({
            limit: 50,
            offset: (currentPage - 1) * 50
        });
        
        // Load parties data
        const partiesResponse = await apiCall(`/api/parties?${params.toString()}`);
        
        // Load analytics data
        const analyticsResponse = await apiCall(`/api/analytics/parties?start_date=${startDate}&end_date=${endDate}&activity=${activity}`);
        
        // Update UI
        updatePartiesSummary(analyticsResponse.data.summary);
        updatePartiesTable(analyticsResponse.data.parties);
        updateTopPerformers(analyticsResponse.data.top_performers);
        updateCharts(analyticsResponse.data);
        updatePagination({current_page: currentPage, total_pages: Math.ceil(analyticsResponse.data.parties.length / 50)});
        
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function updatePartiesSummary(summary) {
    const container = document.getElementById('partiesSummary');
    container.innerHTML = `
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h3>${summary.total_parties}</h3>
                    <h6>Total Parties</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h3>${summary.active_parties}</h3>
                    <h6>Active Parties</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h3>${summary.inactive_parties}</h3>
                    <h6>Inactive Parties</h6>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h3>${summary.avg_orders_per_party}</h3>
                    <h6>Avg Orders/Party</h6>
                </div>
            </div>
        </div>
    `;
}

function updateTopPerformers(topPerformers) {
    const container = document.getElementById('topPerformers');
    
    if (topPerformers.length === 0) {
        container.innerHTML = '<div class="col-12 text-center text-muted">No data available</div>';
        return;
    }
    
    const performers = topPerformers.slice(0, 3).map((party, index) => {
        const medals = ['🥇', '🥈', '🥉'];
        const colors = ['text-warning', 'text-secondary', 'text-warning'];
        
        return `
            <div class="col-md-4">
                <div class="card border-${index === 0 ? 'warning' : index === 1 ? 'secondary' : 'warning'}">
                    <div class="card-body text-center">
                        <div class="display-4 ${colors[index]}">${medals[index]}</div>
                        <h5 class="card-title">${party.name}</h5>
                        <p class="card-text">
                            <strong>${party.total_orders}</strong> Orders<br>
                            <strong>${party.total_trucks}</strong> Trucks<br>
                            <small class="text-muted">Last order: ${formatDate(party.last_order_date)}</small>
                        </p>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = performers;
}

function updatePartiesTable(parties) {
    const tbody = document.querySelector('#partiesTable tbody');
    
    if (parties.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No parties found</td></tr>';
        return;
    }
    
    const rows = parties.map(party => {
        const lastOrderDate = party.last_order_date ? new Date(party.last_order_date) : null;
        const daysSinceLastOrder = lastOrderDate ? Math.floor((new Date() - lastOrderDate) / (1000 * 60 * 60 * 24)) : null;
        
        let statusBadge = '';
        if (party.total_orders === 0) {
            statusBadge = '<span class="badge bg-secondary">Inactive</span>';
        } else if (daysSinceLastOrder <= 7) {
            statusBadge = '<span class="badge bg-success">Very Active</span>';
        } else if (daysSinceLastOrder <= 30) {
            statusBadge = '<span class="badge bg-primary">Active</span>';
        } else {
            statusBadge = '<span class="badge bg-warning">Low Activity</span>';
        }
        
        return `
            <tr>
                <td><strong>${party.name}</strong></td>
                <td>${party.contact_person || '-'}</td>
                <td>${party.phone || '-'}</td>
                <td>${party.email || '-'}</td>
                <td class="text-end">${party.total_orders}</td>
                <td class="text-end">${party.total_trucks}</td>
                <td class="text-end">${party.pending_orders}</td>
                <td>${party.last_order_date ? formatDate(party.last_order_date) : 'Never'}</td>
                <td>${statusBadge}</td>
                <td>
                    <a href="/admin/parties" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-eye"></i> View
                    </a>
                    <a href="/orders/new?party_id=${party.id}" class="btn btn-sm btn-outline-success ms-1">
                        <i class="bi bi-plus"></i> New Order
                    </a>
                </td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = rows;
}

function updateCharts(data) {
    // Top Parties Chart
    if (topPartiesChart) {
        topPartiesChart.destroy();
    }
    
    const topPartiesCtx = document.getElementById('topPartiesChart').getContext('2d');
    topPartiesChart = new Chart(topPartiesCtx, {
        type: 'bar',
        data: {
            labels: data.top_parties.map(item => item.name.length > 15 ? item.name.substring(0, 15) + '...' : item.name),
            datasets: [{
                label: 'Orders',
                data: data.top_parties.map(item => item.total_orders),
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
    
    // Activity Chart
    if (activityChart) {
        activityChart.destroy();
    }
    
    const activityCtx = document.getElementById('activityChart').getContext('2d');
    activityChart = new Chart(activityCtx, {
        type: 'doughnut',
        data: {
            labels: ['Very Active', 'Active', 'Low Activity', 'Inactive'],
            datasets: [{
                data: [
                    data.activity_distribution.very_active,
                    data.activity_distribution.active,
                    data.activity_distribution.low_activity,
                    data.activity_distribution.inactive
                ],
                backgroundColor: ['#198754', '#0d6efd', '#ffc107', '#6c757d']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
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

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    loadAnalytics();
});
</script>



