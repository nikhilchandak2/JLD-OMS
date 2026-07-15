<?php include __DIR__ . '/partials/reports-nav.php'; ?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <i class="bi bi-calendar-day me-2"></i>Daily Dispatch Report
            </h1>
            <p class="page-subtitle">Company-wise and product-wise dispatch summary</p>
        </div>
        <button class="btn btn-outline-success" onclick="exportDailyDispatchReport()">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
        </button>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-funnel me-2"></i>Filters</div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="filterDate" class="form-label">Date</label>
                <input type="date" class="form-control" id="filterDate" value="<?= date('Y-m-d') ?>">
                <div class="form-text">Single-day report (use range below for multiple days)</div>
            </div>
            <div class="col-md-2">
                <label for="filterStartDate" class="form-label">From</label>
                <input type="date" class="form-control" id="filterStartDate" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-2">
                <label for="filterEndDate" class="form-label">To</label>
                <input type="date" class="form-control" id="filterEndDate" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
                <label for="filterCompany" class="form-label">Company</label>
                <select class="form-select" id="filterCompany">
                    <?php if (!empty($can_view_all_companies)): ?>
                    <option value="all">All companies</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="filterProduct" class="form-label">Product</label>
                <select class="form-select" id="filterProduct">
                    <option value="">All products</option>
                </select>
            </div>
            <div class="col-12 col-md-auto">
                <button class="btn btn-primary" onclick="loadDailyDispatchReport()">
                    <i class="bi bi-search me-1"></i> Generate Report
                </button>
            </div>
        </div>
    </div>
</div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status"></div>
    <p>Loading dispatch report…</p>
</div>

<div id="reportContent" style="display:none;">
    <div class="row g-3 mb-4" id="summaryCards"></div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-building me-2"></i>By Company</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="companyTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Company</th>
                                    <th class="text-end">Trucks</th>
                                    <th class="text-end">Weight (MT)</th>
                                    <th class="text-end">Dispatches</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-box me-2"></i>By Product</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="productTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Trucks</th>
                                    <th class="text-end">Weight (MT)</th>
                                    <th class="text-end">Dispatches</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-grid-3x3-gap me-2"></i>Daily Breakdown (Date × Company × Product)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0" id="dailyTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Company</th>
                            <th>Product</th>
                            <th class="text-end">Trucks</th>
                            <th class="text-end">Weight (MT)</th>
                            <th class="text-end">Dispatches</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-2"></i>Dispatch Details</span>
            <small class="text-muted" id="detailsCount">—</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="detailsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Company</th>
                            <th>Product</th>
                            <th>Party</th>
                            <th>Order</th>
                            <th class="text-end">Trucks</th>
                            <th class="text-end">Weight</th>
                            <th class="text-end">Rate</th>
                            <th>Invoice</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const defaultCompanyId = <?= json_encode((int)($active_company['id'] ?? 0)) ?>;
const canViewAllCompanies = <?= !empty($can_view_all_companies) ? 'true' : 'false' ?>;

function formatWeight(value) {
    if (value == null || value === '' || Number(value) === 0) return '—';
    return Number(value).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}

function formatNumber(value) {
    return Number(value || 0).toLocaleString('en-IN');
}

function getReportFilters() {
    const singleDate = document.getElementById('filterDate').value;
    const startDate = document.getElementById('filterStartDate').value || singleDate;
    const endDate = document.getElementById('filterEndDate').value || singleDate;
    const company = document.getElementById('filterCompany').value;
    const product = document.getElementById('filterProduct').value;

    const params = new URLSearchParams({
        start_date: startDate,
        end_date: endDate
    });
    if (company) params.append('company_id', company);
    if (product) params.append('product_id', product);
    return params;
}

function syncDateFilters(fromSingle) {
    const single = document.getElementById('filterDate');
    const start = document.getElementById('filterStartDate');
    const end = document.getElementById('filterEndDate');
    if (fromSingle && single.value) {
        start.value = single.value;
        end.value = single.value;
    } else if (start.value && !end.value) {
        end.value = start.value;
    }
}

async function loadFilterOptions() {
    const [companiesRes, productsRes] = await Promise.all([
        apiCall('/api/companies'),
        apiCall('/api/reports/products')
    ]);

    const companySelect = document.getElementById('filterCompany');
    const existingAll = companySelect.querySelector('option[value="all"]');
    companySelect.innerHTML = '';
    if (existingAll || canViewAllCompanies) {
        companySelect.innerHTML += '<option value="all">All companies</option>';
    }
    (companiesRes.data || []).forEach(c => {
        companySelect.innerHTML += `<option value="${c.id}">${escapeHtml(c.name)}</option>`;
    });

    if (defaultCompanyId > 0) {
        companySelect.value = String(defaultCompanyId);
    }

    const productSelect = document.getElementById('filterProduct');
    productSelect.innerHTML = '<option value="">All products</option>';
    (productsRes.data || []).forEach(p => {
        productSelect.innerHTML += `<option value="${p.id}">${escapeHtml(p.name)}</option>`;
    });
}

function renderEmptyRow(tbody, colSpan, message) {
    tbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center text-muted py-4">${escapeHtml(message)}</td></tr>`;
}

function updateSummary(summary) {
    document.getElementById('summaryCards').innerHTML = `
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card text-center h-100"><div class="card-body py-3">
                <div class="fs-4 fw-bold text-primary">${formatNumber(summary.dispatch_count)}</div>
                <div class="small text-muted">Dispatches</div>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card text-center h-100"><div class="card-body py-3">
                <div class="fs-4 fw-bold text-success">${formatNumber(summary.total_trucks)}</div>
                <div class="small text-muted">Trucks</div>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card text-center h-100"><div class="card-body py-3">
                <div class="fs-4 fw-bold text-info">${formatWeight(summary.total_weight_tons)}</div>
                <div class="small text-muted">Weight (MT)</div>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card text-center h-100"><div class="card-body py-3">
                <div class="fs-4 fw-bold">${formatNumber(summary.company_count)}</div>
                <div class="small text-muted">Companies</div>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card text-center h-100"><div class="card-body py-3">
                <div class="fs-4 fw-bold">${formatNumber(summary.product_count)}</div>
                <div class="small text-muted">Products</div>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card text-center h-100"><div class="card-body py-3">
                <div class="fs-4 fw-bold">${formatNumber(summary.party_count)}</div>
                <div class="small text-muted">Parties</div>
            </div></div>
        </div>`;
}

function updateBreakdownTable(tbody, rows, nameKey) {
    if (!rows.length) {
        renderEmptyRow(tbody, 4, 'No dispatches for selected period');
        return;
    }
    tbody.innerHTML = rows.map(r => `
        <tr>
            <td>${escapeHtml(r[nameKey] || '—')}</td>
            <td class="text-end fw-semibold">${formatNumber(r.total_trucks)}</td>
            <td class="text-end">${formatWeight(r.total_weight_tons)}</td>
            <td class="text-end">${formatNumber(r.dispatch_count)}</td>
        </tr>
    `).join('');
}

async function loadDailyDispatchReport() {
    syncDateFilters(true);
    const loading = document.getElementById('loading');
    const content = document.getElementById('reportContent');
    loading.style.display = 'block';
    content.style.display = 'none';

    try {
        const response = await apiCall(`/api/reports/daily-dispatch?${getReportFilters()}`);
        const data = response.data;

        updateSummary(data.summary || {});
        updateBreakdownTable(document.querySelector('#companyTable tbody'), data.by_company || [], 'company_name');
        updateBreakdownTable(document.querySelector('#productTable tbody'), data.by_product || [], 'product_name');

        const dailyBody = document.querySelector('#dailyTable tbody');
        if (!(data.daily_breakdown || []).length) {
            renderEmptyRow(dailyBody, 6, 'No dispatches for selected period');
        } else {
            dailyBody.innerHTML = data.daily_breakdown.map(r => `
                <tr>
                    <td>${formatDate(r.dispatch_date)}</td>
                    <td>${escapeHtml(r.company_name)}</td>
                    <td>${escapeHtml(r.product_name)}</td>
                    <td class="text-end fw-semibold">${formatNumber(r.total_trucks)}</td>
                    <td class="text-end">${formatWeight(r.total_weight_tons)}</td>
                    <td class="text-end">${formatNumber(r.dispatch_count)}</td>
                </tr>
            `).join('');
        }

        const details = data.details || [];
        document.getElementById('detailsCount').textContent = `${details.length} record(s)`;
        const detailsBody = document.querySelector('#detailsTable tbody');
        if (!details.length) {
            renderEmptyRow(detailsBody, 10, 'No dispatch details');
        } else {
            detailsBody.innerHTML = details.map(d => `
                <tr>
                    <td>${formatDate(d.dispatch_date)}</td>
                    <td>${escapeHtml(d.company_name)}</td>
                    <td>${escapeHtml(d.product_name)}</td>
                    <td>${escapeHtml(d.party_name)}</td>
                    <td><a href="/orders/${d.order_id}">${escapeHtml(d.order_no)}</a></td>
                    <td class="text-end">${formatNumber(d.dispatch_qty_trucks)}</td>
                    <td class="text-end">${formatWeight(d.loading_weight_tons)}</td>
                    <td class="text-end">${d.product_rate != null ? Number(d.product_rate).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : '—'}</td>
                    <td>${d.busy_invoice_no ? escapeHtml(d.busy_invoice_no) : '—'}</td>
                    <td>${escapeHtml(d.status || 'active')}</td>
                </tr>
            `).join('');
        }

        content.style.display = 'block';
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

function exportDailyDispatchReport() {
    syncDateFilters(true);
    window.location.href = `/api/reports/daily-dispatch/export?format=xlsx&${getReportFilters()}`;
}

document.getElementById('filterDate').addEventListener('change', () => syncDateFilters(true));
document.addEventListener('DOMContentLoaded', async function() {
    try {
        await loadFilterOptions();
        await loadDailyDispatchReport();
    } catch (error) {
        showError(error.message);
    }
});
</script>
