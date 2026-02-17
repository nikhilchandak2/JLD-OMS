<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="bi bi-graph-up"></i> Reports</h1>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<!-- Filters Card -->
<div class="card mb-4">
    <div class="card-header">
        <h5><i class="bi bi-funnel"></i> Report Filters</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label for="reportStartDate" class="form-label">Start Date</label>
                <input type="date" class="form-control" id="reportStartDate" value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
            </div>
            <div class="col-md-3">
                <label for="reportEndDate" class="form-label">End Date</label>
                <input type="date" class="form-control" id="reportEndDate" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
                <label for="reportParty" class="form-label">Party</label>
                <select class="form-select" id="reportParty">
                    <option value="">All Parties</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary d-block w-100" onclick="loadReport()">
                    <i class="bi bi-search"></i> Generate Report
                </button>
            </div>
        </div>
    </div>
</div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p>Loading report data...</p>
</div>

<!-- Report Results -->
<div id="reportResults" style="display: none;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5><i class="bi bi-table"></i> Party-wise Report</h5>
        <div>
            <button class="btn btn-outline-danger btn-sm" onclick="exportReport('pdf')">
                <i class="bi bi-file-earmark-pdf"></i> Export PDF
            </button>
            <button class="btn btn-outline-success btn-sm" onclick="exportReport('xlsx')">
                <i class="bi bi-file-earmark-excel"></i> Export Excel
            </button>
        </div>
    </div>
    
    <!-- Company tables will be populated by JavaScript -->
    <div id="companyTables">
        <!-- Individual company tables will be inserted here -->
    </div>
    
    <!-- Grand Total Summary -->
    <div class="card mt-4" id="grandTotalCard" style="display: none;">
        <div class="card-header bg-dark text-white">
            <h6 class="mb-0"><i class="bi bi-calculator"></i> Grand Total Summary</h6>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-4">
                    <h4 class="text-primary mb-0" id="grandTotalOrdered">0</h4>
                    <small class="text-muted">Total Ordered</small>
                </div>
                <div class="col-md-4">
                    <h4 class="text-success mb-0" id="grandTotalDispatched">0</h4>
                    <small class="text-muted">Total Dispatched</small>
                </div>
                <div class="col-md-4">
                    <h4 class="text-warning mb-0" id="grandTotalPending">0</h4>
                    <small class="text-muted">Total Pending</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Pagination -->
    <nav aria-label="Report pagination" class="mt-3">
        <ul class="pagination justify-content-center" id="reportPagination">
            <!-- Pagination will be populated by JavaScript -->
        </ul>
    </nav>
</div>

<script>
let currentReportPage = 0;
const reportPageSize = 50;

async function loadParties() {
    try {
        const response = await apiCall('/api/reports/parties');
        const select = document.getElementById('reportParty');
        
        const options = response.data.map(party => 
            `<option value="${party.id}">${party.name}</option>`
        ).join('');
        
        select.innerHTML = '<option value="">All Parties</option>' + options;
    } catch (error) {
        console.error('Failed to load parties:', error);
    }
}

async function loadReport(page = 0) {
    const startDate = document.getElementById('reportStartDate').value;
    const endDate = document.getElementById('reportEndDate').value;
    
    if (!startDate || !endDate) {
        showError('Please select both start and end dates');
        return;
    }
    
    if (new Date(startDate) > new Date(endDate)) {
        showError('Start date cannot be after end date');
        return;
    }
    
    const loading = document.getElementById('loading');
    const results = document.getElementById('reportResults');
    
    loading.style.display = 'block';
    results.style.display = 'none';
    
    try {
        const params = new URLSearchParams({
            start_date: startDate,
            end_date: endDate,
            limit: reportPageSize,
            offset: page * reportPageSize
        });
        
        const party = document.getElementById('reportParty').value;
        if (party) params.append('party_id', party);
        
        const response = await apiCall(`/api/reports/partywise?${params}`);
        
        updateReportTable(response.data);
        updateReportPagination(response.pagination);
        
        results.style.display = 'block';
        currentReportPage = page;
        
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function updateReportTable(data) {
    const companyTablesContainer = document.getElementById('companyTables');
    const grandTotalCard = document.getElementById('grandTotalCard');
    
    if (data.length === 0) {
        companyTablesContainer.innerHTML = '<div class="alert alert-info text-center"><i class="bi bi-info-circle"></i> No data found for selected criteria</div>';
        grandTotalCard.style.display = 'none';
        return;
    }
    
    let totalOrdered = 0;
    let totalDispatched = 0;
    let totalPending = 0;
    
    // Group data by company
    const groupedData = {};
    data.forEach(row => {
        if (!groupedData[row.company_name]) {
            groupedData[row.company_name] = [];
        }
        groupedData[row.company_name].push(row);
    });
    
    let allTables = '';
    
    // Create separate table for each company
    Object.keys(groupedData).forEach((companyName, companyIndex) => {
        const companyData = groupedData[companyName];
        let companyOrdered = 0;
        let companyDispatched = 0;
        let companyPending = 0;
        
        // Start company table
        allTables += `
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0">
                        <i class="bi bi-building"></i> ${companyName}
                        <small class="float-end">${companyData.length} orders</small>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0 report-table" style="table-layout: fixed;">
                            <style>
                                .report-table td, .report-table th {
                                    vertical-align: middle !important;
                                    line-height: 1.4;
                                }
                                .report-table .badge {
                                    vertical-align: middle;
                                    margin-left: 8px;
                                }
                            </style>
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 20%; text-align: center; padding: 12px 8px; vertical-align: middle;">Party Name</th>
                                    <th style="width: 14%; text-align: center; padding: 12px 8px; vertical-align: middle;">Order No.</th>
                                    <th style="width: 18%; text-align: center; padding: 12px 8px; vertical-align: middle;">Product Type</th>
                                    <th style="width: 10%; text-align: center; padding: 12px 8px; vertical-align: middle;">Priority</th>
                                    <th style="width: 13%; text-align: center; padding: 12px 8px; vertical-align: middle;">Ordered Trucks</th>
                                    <th style="width: 13%; text-align: center; padding: 12px 8px; vertical-align: middle;">Dispatched Trucks</th>
                                    <th style="width: 12%; text-align: center; padding: 12px 8px; vertical-align: middle;">Pending Trucks</th>
                                </tr>
                            </thead>
                            <tbody>
        `;
        
        // Add company data rows
        companyData.forEach((row, index) => {
            companyOrdered += parseInt(row.ordered_trucks);
            companyDispatched += parseInt(row.dispatched_trucks);
            companyPending += parseInt(row.pending_trucks);
            
            const priorityBadge = row.priority === 'urgent' 
                ? '<span class="badge bg-danger">URGENT</span>' 
                : '<span class="badge bg-secondary">NORMAL</span>';
            
            allTables += `
                <tr>
                    <td style="padding: 12px 8px; text-align: center; vertical-align: middle;">${row.party_name}</td>
                    <td style="padding: 12px 8px; text-align: center; vertical-align: middle;"><strong>${row.order_no}</strong></td>
                    <td style="padding: 12px 8px; text-align: center; vertical-align: middle;">${row.product_name}</td>
                    <td style="padding: 12px 8px; text-align: center; vertical-align: middle;">${priorityBadge}</td>
                    <td style="padding: 12px 8px; text-align: center; vertical-align: middle; font-weight: bold;">${row.ordered_trucks}</td>
                    <td style="padding: 12px 8px; text-align: center; vertical-align: middle; font-weight: bold;">${row.dispatched_trucks}</td>
                    <td style="padding: 12px 8px; text-align: center; vertical-align: middle; font-weight: bold;">${row.pending_trucks}</td>
                </tr>
            `;
        });
        
        // Add company subtotal row
        allTables += `
                            </tbody>
                            <tfoot class="table-secondary">
                                <tr class="fw-bold border-top border-2">
                                    <td colspan="4" style="padding: 15px 8px; text-align: center; font-weight: bold; vertical-align: middle;">Company Total:</td>
                                    <td style="padding: 15px 8px; text-align: center; font-weight: bold; color: #0d6efd; vertical-align: middle;">${companyOrdered}</td>
                                    <td style="padding: 15px 8px; text-align: center; font-weight: bold; color: #198754; vertical-align: middle;">${companyDispatched}</td>
                                    <td style="padding: 15px 8px; text-align: center; font-weight: bold; color: #fd7e14; vertical-align: middle;">${companyPending}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        `;
        
        totalOrdered += companyOrdered;
        totalDispatched += companyDispatched;
        totalPending += companyPending;
    });
    
    // Update the DOM
    companyTablesContainer.innerHTML = allTables;
    
    // Update grand total summary
    document.getElementById('grandTotalOrdered').textContent = totalOrdered;
    document.getElementById('grandTotalDispatched').textContent = totalDispatched;
    document.getElementById('grandTotalPending').textContent = totalPending;
    grandTotalCard.style.display = 'block';
}

function updateReportPagination(pagination) {
    const paginationEl = document.getElementById('reportPagination');
    
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
                <a class="page-link" href="#" onclick="loadReport(${currentPageNum - 1})">Previous</a>
            </li>
        `;
    }
    
    // Page numbers
    const startPage = Math.max(0, currentPageNum - 2);
    const endPage = Math.min(totalPages - 1, currentPageNum + 2);
    
    for (let i = startPage; i <= endPage; i++) {
        paginationHTML += `
            <li class="page-item ${i === currentPageNum ? 'active' : ''}">
                <a class="page-link" href="#" onclick="loadReport(${i})">${i + 1}</a>
            </li>
        `;
    }
    
    // Next button
    if (currentPageNum < totalPages - 1) {
        paginationHTML += `
            <li class="page-item">
                <a class="page-link" href="#" onclick="loadReport(${currentPageNum + 1})">Next</a>
            </li>
        `;
    }
    
    paginationEl.innerHTML = paginationHTML;
}

async function exportReport(format) {
    const startDate = document.getElementById('reportStartDate').value;
    const endDate = document.getElementById('reportEndDate').value;
    
    if (!startDate || !endDate) {
        showError('Please generate a report first');
        return;
    }
    
    try {
        const params = new URLSearchParams({
            format: format,
            start_date: startDate,
            end_date: endDate
        });
        
        const party = document.getElementById('reportParty').value;
        if (party) params.append('party_id', party);
        
        const url = `/api/reports/partywise/export?${params}`;
        
        // Create a temporary link to download the file
        const link = document.createElement('a');
        link.href = url;
        link.download = `partywise_report_${startDate}_to_${endDate}.${format}`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        showSuccess(`${format.toUpperCase()} export started successfully`);
        
    } catch (error) {
        showError('Export failed: ' + error.message);
    }
}

// Load parties on page load
document.addEventListener('DOMContentLoaded', function() {
    loadParties();
});
</script>
