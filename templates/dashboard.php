<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-speedometer2 me-2"></i>Dashboard
            </h1>
            <p class="page-subtitle">Operations Management System - Overview of vehicle tracking and operations</p>
        </div>
        <button class="btn btn-primary" onclick="loadDashboard()">
            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
        </button>
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

<!-- Quick Links -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card clickable-card" onclick="window.location.href='/orders'" style="cursor: pointer;">
            <div class="card-body text-center">
                <i class="bi bi-clipboard-check display-4 text-primary mb-3"></i>
                <h5>Orders & Dispatches</h5>
                <p class="text-muted mb-0">View all orders, dispatches, and analytics</p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card clickable-card" onclick="window.location.href='/tracking'" style="cursor: pointer;">
            <div class="card-body text-center">
                <i class="bi bi-geo-alt display-4 text-success mb-3"></i>
                <h5>Live Vehicle Tracking</h5>
                <p class="text-muted mb-0">Monitor vehicles in real-time</p>
            </div>
        </div>
    </div>
</div>

<script>
async function loadDashboard() {
    const loading = document.getElementById('loading');
    loading.style.display = 'block';
    
    try {
        // Load summary stats
        const summaryData = await apiCall('/api/dashboard/summary');
        
        // Update UI
        updateSummaryCards(summaryData.data);
        
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function updateSummaryCards(summary) {
    const container = document.getElementById('summaryCards');
    const vehicleStats = summary.vehicle_tracking || {};
    
    container.innerHTML = `
        <div class="col-md-3">
            <div class="card bg-primary text-white clickable-card" onclick="window.location.href='/orders'" style="cursor: pointer;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Total Orders</h6>
                            <h3>${summary.totals.orders || 0}</h3>
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
            <div class="card bg-success text-white clickable-card" onclick="window.location.href='/vehicles'" style="cursor: pointer;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Active Vehicles</h6>
                            <h3>${vehicleStats.active_vehicles || 0}</h3>
                            <small class="opacity-75">${vehicleStats.total_vehicles || 0} total vehicles</small>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-truck fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white clickable-card" onclick="window.location.href='/trips'" style="cursor: pointer;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Today's Trips</h6>
                            <h3>${vehicleStats.today_trips || 0}</h3>
                            <small class="opacity-75">${vehicleStats.total_trips || 0} total trips</small>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-arrow-left-right fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white clickable-card" onclick="window.location.href='/reports'" style="cursor: pointer;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Pending Orders</h6>
                            <h3>${summary.pending.orders || 0}</h3>
                            <small class="opacity-75">View reports</small>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-hourglass-split fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
}


// Load dashboard on page load
document.addEventListener('DOMContentLoaded', function() {
    loadDashboard();
});
</script>

