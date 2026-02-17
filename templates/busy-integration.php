<?php
$title = 'Busy Integration';
include 'layout.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-link-45deg"></i> Busy Software Integration
        </h1>
        <div>
            <button type="button" class="btn btn-primary" onclick="syncInvoices()">
                <i class="bi bi-arrow-repeat"></i> Manual Sync
            </button>
            <button type="button" class="btn btn-info" onclick="refreshStatus()">
                <i class="bi bi-arrow-clockwise"></i> Refresh Status
            </button>
        </div>
    </div>

    <!-- Integration Status Card -->
    <div class="row">
        <div class="col-xl-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">Integration Status</h6>
                    <div class="dropdown no-arrow">
                        <span class="badge badge-success" id="statusBadge">Active</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row" id="integrationStatus">
                        <div class="col-md-3">
                            <div class="text-center">
                                <h5 class="text-primary" id="totalWebhooks">-</h5>
                                <small class="text-muted">Total Webhooks (30 days)</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <h5 class="text-success" id="successfulWebhooks">-</h5>
                                <small class="text-muted">Successful</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <h5 class="text-danger" id="failedWebhooks">-</h5>
                                <small class="text-muted">Failed</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <h5 class="text-info" id="lastWebhook">-</h5>
                                <small class="text-muted">Last Webhook</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Configuration Card -->
    <div class="row">
        <div class="col-xl-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Webhook Configuration</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label"><strong>Webhook URL:</strong></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="webhookUrl" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="copyWebhookUrl()">
                                <i class="bi bi-clipboard"></i> Copy
                            </button>
                        </div>
                        <small class="form-text text-muted">Configure this URL in your Busy software to send invoice webhooks</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>Authentication:</strong></label>
                        <p class="text-muted mb-1">Send API key in header: <code>X-API-KEY: your-api-key</code></p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Security Note:</strong> Make sure to configure the API key in your environment variables for production use.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Expected Invoice Format</h6>
                </div>
                <div class="card-body">
                    <pre class="bg-light p-3 rounded"><code>{
  "invoice_no": "INV-2025-001",
  "invoice_date": "2025-10-03",
  "party_name": "ABC Construction Ltd",
  "product_name": "Sand",
  "quantity": 5,
  "vehicle_no": "MH12AB1234",
  "company_name": "JLD Minerals Pvt. Ltd.",
  "remarks": "Optional remarks"
}</code></pre>
                    <small class="text-muted">
                        <strong>Required fields:</strong> invoice_no, invoice_date, party_name, product_name, quantity<br>
                        <strong>Optional fields:</strong> vehicle_no, company_name, remarks
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Manual Sync Card -->
    <div class="row">
        <div class="col-xl-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Manual Invoice Sync</h6>
                </div>
                <div class="card-body">
                    <form id="syncForm">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="startDate" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" id="startDate" name="start_date">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="endDate" class="form-label">End Date</label>
                                    <input type="date" class="form-control" id="endDate" name="end_date">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="partyName" class="form-label">Party Name (Optional)</label>
                                    <input type="text" class="form-control" id="partyName" name="party_name" placeholder="Filter by party name">
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-download"></i> Sync Invoices
                        </button>
                    </form>
                    
                    <div id="syncResults" class="mt-4" style="display: none;">
                        <h6>Sync Results:</h6>
                        <div id="syncResultsContent"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Webhook Logs -->
    <div class="row">
        <div class="col-xl-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Recent Webhook Activity</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="webhookLogsTable">
                            <thead>
                                <tr>
                                    <th>Invoice No</th>
                                    <th>Status</th>
                                    <th>Received At</th>
                                    <th>Processed At</th>
                                    <th>Error Message</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Loading webhook logs...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Load integration status on page load
document.addEventListener('DOMContentLoaded', function() {
    loadIntegrationStatus();
    loadWebhookLogs();
});

async function loadIntegrationStatus() {
    try {
        const response = await apiCall('/api/busy/status');
        const data = response.data;
        
        document.getElementById('webhookUrl').value = data.webhook_url;
        document.getElementById('totalWebhooks').textContent = data.last_30_days.total_webhooks;
        document.getElementById('successfulWebhooks').textContent = data.last_30_days.successful;
        document.getElementById('failedWebhooks').textContent = data.last_30_days.failed;
        
        const lastWebhook = data.last_30_days.last_webhook;
        document.getElementById('lastWebhook').textContent = lastWebhook ? 
            new Date(lastWebhook).toLocaleDateString() : 'Never';
            
    } catch (error) {
        console.error('Failed to load integration status:', error);
        showAlert('Failed to load integration status', 'danger');
    }
}

async function loadWebhookLogs() {
    // This would load recent webhook logs from the database
    // For now, we'll show a placeholder
    const tbody = document.querySelector('#webhookLogsTable tbody');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No recent webhook activity</td></tr>';
}

async function syncInvoices() {
    const form = document.getElementById('syncForm');
    const formData = new FormData(form);
    
    const syncData = {
        start_date: formData.get('start_date'),
        end_date: formData.get('end_date'),
        party_name: formData.get('party_name')
    };
    
    try {
        showAlert('Syncing invoices...', 'info');
        
        const response = await apiCall('/api/busy/sync', {
            method: 'POST',
            body: JSON.stringify(syncData)
        });
        
        const results = response.data;
        displaySyncResults(results);
        
        showAlert(`Sync completed: ${results.successful} successful, ${results.failed} failed`, 'success');
        
    } catch (error) {
        console.error('Sync failed:', error);
        showAlert('Sync failed: ' + error.message, 'danger');
    }
}

function displaySyncResults(results) {
    const container = document.getElementById('syncResults');
    const content = document.getElementById('syncResultsContent');
    
    let html = `
        <div class="alert alert-info">
            <strong>Summary:</strong> ${results.processed} invoices processed, 
            ${results.successful} successful, ${results.failed} failed
        </div>
    `;
    
    if (results.details && results.details.length > 0) {
        html += '<div class="table-responsive"><table class="table table-sm">';
        html += '<thead><tr><th>Invoice No</th><th>Status</th><th>Details</th></tr></thead><tbody>';
        
        results.details.forEach(detail => {
            const statusClass = detail.status === 'success' ? 'success' : 'danger';
            const statusText = detail.status === 'success' ? 'Success' : 'Error';
            const details = detail.status === 'success' ? 
                `Order: ${detail.result?.order_no || 'N/A'}` : 
                detail.error;
                
            html += `
                <tr>
                    <td>${detail.invoice_no}</td>
                    <td><span class="badge badge-${statusClass}">${statusText}</span></td>
                    <td>${details}</td>
                </tr>
            `;
        });
        
        html += '</tbody></table></div>';
    }
    
    content.innerHTML = html;
    container.style.display = 'block';
}

function copyWebhookUrl() {
    const webhookUrl = document.getElementById('webhookUrl');
    webhookUrl.select();
    document.execCommand('copy');
    showAlert('Webhook URL copied to clipboard', 'success');
}

function refreshStatus() {
    loadIntegrationStatus();
    loadWebhookLogs();
    showAlert('Status refreshed', 'success');
}

// Handle sync form submission
document.getElementById('syncForm').addEventListener('submit', function(e) {
    e.preventDefault();
    syncInvoices();
});
</script>

<?php include 'layout_footer.php'; ?>


