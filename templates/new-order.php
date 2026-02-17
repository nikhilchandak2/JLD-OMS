<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-plus-circle me-2"></i>Create New Order
            </h1>
            <p class="page-subtitle">Add a new customer order to the system</p>
        </div>
        <a href="/orders" class="btn btn-outline-primary">
            <i class="bi bi-arrow-left me-1"></i> Back to Orders
        </a>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-clipboard-plus me-2"></i>Order Details
            </div>
            <div class="card-body">
                <form id="newOrderForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    
                    <!-- Company Selection -->
                    <div class="row">
                        <div class="col-12">
                            <div class="mb-3">
                                <label for="companyId" class="form-label">Company <span class="text-danger">*</span></label>
                                <select class="form-select searchable-select" id="companyId" name="company_id" required>
                                    <option value="">Select company...</option>
                                </select>
                                <div class="form-text">Select which JLD Minerals company is receiving this order</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="orderDate" class="form-label">Order Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="orderDate" name="order_date" required value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="orderQty" class="form-label">Quantity (Trucks) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="orderQty" name="order_qty_trucks" required min="1" placeholder="Enter number of trucks">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="priority" class="form-label">Priority <span class="text-danger">*</span></label>
                                <select class="form-select" id="priority" name="priority" required>
                                    <option value="normal" selected>Normal</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recurring Delivery Options -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="isRecurring" name="is_recurring">
                                <label class="form-check-label" for="isRecurring">
                                    <strong>Recurring Delivery</strong> - Schedule multiple deliveries over time
                                </label>
                            </div>
                        </div>
                        <div class="card-body" id="recurringOptions" style="display: none;">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="trucksPerDelivery" class="form-label">Trucks per Delivery <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="trucksPerDelivery" name="trucks_per_delivery" min="1" placeholder="e.g., 2">
                                        <div class="form-text">Number of trucks to deliver each time</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="deliveryFrequency" class="form-label">Delivery Frequency (Days) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="deliveryFrequency" name="delivery_frequency_days" min="1" placeholder="e.g., 7">
                                        <div class="form-text">Gap between deliveries in days</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="totalDeliveries" class="form-label">Total Deliveries</label>
                                        <input type="number" class="form-control" id="totalDeliveries" name="total_deliveries" readonly style="background-color: #f8f9fa;">
                                        <div class="form-text">Auto-calculated based on order quantity and trucks per delivery</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-info" id="deliveryPreview" style="display: none;">
                                <strong>Delivery Schedule Preview:</strong>
                                <div id="schedulePreview"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="partyId" class="form-label">Party <span class="text-danger">*</span></label>
                                <div class="d-flex">
                                    <select class="form-select searchable-select" id="partyId" name="party_id" required style="flex: 1;">
                                        <option value="">Select or type to search party...</option>
                                    </select>
                                    <button type="button" class="btn btn-outline-primary ms-2" onclick="openQuickAddModal('party')" title="Add New Party">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="productId" class="form-label">Product <span class="text-danger">*</span></label>
                                <div class="d-flex">
                                    <select class="form-select searchable-select" id="productId" name="product_id" required style="flex: 1;">
                                        <option value="">Select or type to search product...</option>
                                    </select>
                                    <button type="button" class="btn btn-outline-primary ms-2" onclick="openQuickAddModal('product')" title="Add New Product">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='/orders'">
                            <i class="bi bi-x-circle"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <span class="spinner-border spinner-border-sm d-none" id="submitSpinner"></span>
                            <i class="bi bi-check-circle"></i> Create Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Quick Add Modals -->
<!-- Party Quick Add Modal -->
<div class="modal fade" id="quickAddPartyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quick Add Party</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="quickAddPartyForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="quickPartyName" class="form-label">Party Name *</label>
                        <input type="text" class="form-control" id="quickPartyName" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="quickContactPerson" class="form-label">Contact Person *</label>
                        <input type="text" class="form-control" id="quickContactPerson" name="contact_person" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="quickPhone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="quickPhone" name="phone">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="quickEmail" class="form-label">Email</label>
                                <input type="email" class="form-control" id="quickEmail" name="email">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="quickAddress" class="form-label">Address</label>
                        <textarea class="form-control" id="quickAddress" name="address" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Party</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Product Quick Add Modal -->
<div class="modal fade" id="quickAddProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quick Add Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="quickAddProductForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="quickProductCode" class="form-label">Product Code *</label>
                        <input type="text" class="form-control" id="quickProductCode" name="code" required placeholder="e.g., PROD-001">
                    </div>
                    <div class="mb-3">
                        <label for="quickProductName" class="form-label">Product Name *</label>
                        <input type="text" class="form-control" id="quickProductName" name="name" required placeholder="e.g., Portland Cement">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
async function loadFormData() {
    try {
        // Load companies
        const companiesResponse = await apiCall('/api/companies');
        const companySelect = document.getElementById('companyId');
        
        const companyOptions = companiesResponse.data.map(company => 
            `<option value="${company.id}">${company.name} (${company.code})</option>`
        ).join('');
        
        companySelect.innerHTML = '<option value="">Select company...</option>' + companyOptions;
        
        // Load parties
        const partiesResponse = await apiCall('/api/reports/parties');
        const partySelect = document.getElementById('partyId');
        
        const partyOptions = partiesResponse.data.map(party => 
            `<option value="${party.id}">${party.name}</option>`
        ).join('');
        
        partySelect.innerHTML = '<option value="">Select or type to search party...</option>' + partyOptions;
        
        // Load products
        const productsResponse = await apiCall('/api/reports/products');
        const productSelect = document.getElementById('productId');
        
        const productOptions = productsResponse.data.map(product => 
            `<option value="${product.id}">${product.name}</option>`
        ).join('');
        
        productSelect.innerHTML = '<option value="">Select or type to search product...</option>' + productOptions;
        
        // Initialize Select2 for searchable dropdowns after a short delay
        setTimeout(() => {
            if (typeof $.fn.select2 !== 'undefined') {
                $('.searchable-select').select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    placeholder: function() {
                        return $(this).data('placeholder') || 'Select an option...';
                    },
                    allowClear: true,
                    dropdownParent: $('body')
                });
            } else {
                console.warn('Select2 not loaded, using regular dropdowns');
            }
        }, 100);
        
    } catch (error) {
        showError('Failed to load form data: ' + error.message);
    }
}

// Quick Add Modal Functions
function openQuickAddModal(type) {
    if (type === 'party') {
        new bootstrap.Modal(document.getElementById('quickAddPartyModal')).show();
    } else if (type === 'product') {
        new bootstrap.Modal(document.getElementById('quickAddProductModal')).show();
    }
}

// Quick Add Party Form Handler
document.getElementById('quickAddPartyForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = {
        name: formData.get('name'),
        contact_person: formData.get('contact_person'),
        phone: formData.get('phone') || '',
        email: formData.get('email') || '',
        address: formData.get('address') || '',
        is_active: true
    };
    
    try {
        const response = await fetch('/api/parties', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Add new option to select
            const partySelect = document.getElementById('partyId');
            const newOption = new Option(result.data.name, result.data.id, true, true);
            partySelect.add(newOption);
            
            // Refresh Select2 if available
            if (typeof $.fn.select2 !== 'undefined') {
                $('#partyId').trigger('change');
            }
            
            // Close modal and reset form
            bootstrap.Modal.getInstance(document.getElementById('quickAddPartyModal')).hide();
            this.reset();
            
            showSuccess('Party added successfully!');
        } else {
            showError('Error adding party: ' + result.error);
        }
    } catch (error) {
        showError('Error adding party: ' + error.message);
    }
});

// Quick Add Product Form Handler
document.getElementById('quickAddProductForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = {
        code: formData.get('code'),
        name: formData.get('name'),
        is_active: true
    };
    
    try {
        const response = await fetch('/api/products', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Add new option to select
            const productSelect = document.getElementById('productId');
            const newOption = new Option(result.data.name, result.data.id, true, true);
            productSelect.add(newOption);
            
            // Refresh Select2 if available
            if (typeof $.fn.select2 !== 'undefined') {
                $('#productId').trigger('change');
            }
            
            // Close modal and reset form
            bootstrap.Modal.getInstance(document.getElementById('quickAddProductModal')).hide();
            this.reset();
            
            showSuccess('Product added successfully!');
        } else {
            showError('Error adding product: ' + result.error);
        }
    } catch (error) {
        showError('Error adding product: ' + error.message);
    }
});

document.getElementById('newOrderForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const submitBtn = document.getElementById('submitBtn');
    const submitSpinner = document.getElementById('submitSpinner');
    const formData = new FormData(this);
    
    // Show loading state
    submitBtn.disabled = true;
    submitSpinner.classList.remove('d-none');
    
    try {
        const orderData = {
            company_id: parseInt(formData.get('company_id')),
            order_date: formData.get('order_date'),
            product_id: parseInt(formData.get('product_id')),
            order_qty_trucks: parseInt(formData.get('order_qty_trucks')),
            party_id: parseInt(formData.get('party_id')),
            priority: formData.get('priority'),
            is_recurring: formData.has('is_recurring'),
            trucks_per_delivery: formData.get('trucks_per_delivery') ? parseInt(formData.get('trucks_per_delivery')) : null,
            delivery_frequency_days: formData.get('delivery_frequency_days') ? parseInt(formData.get('delivery_frequency_days')) : null,
            total_deliveries: formData.get('total_deliveries') ? parseInt(formData.get('total_deliveries')) : null
        };
        
        const response = await apiCall('/api/orders', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': formData.get('csrf_token')
            },
            body: JSON.stringify(orderData)
        });
        
        showSuccess('Order created successfully! Order No: ' + response.data.order_no);
        
        // Reset form
        this.reset();
        document.getElementById('orderDate').value = new Date().toISOString().split('T')[0];
        
        // Redirect to orders page after 2 seconds
        setTimeout(() => {
            window.location.href = '/orders';
        }, 2000);
        
    } catch (error) {
        showError(error.message);
    } finally {
        submitBtn.disabled = false;
        submitSpinner.classList.add('d-none');
    }
});

// Handle recurring delivery checkbox
document.getElementById('isRecurring').addEventListener('change', function() {
    const recurringOptions = document.getElementById('recurringOptions');
    const trucksPerDelivery = document.getElementById('trucksPerDelivery');
    const deliveryFrequency = document.getElementById('deliveryFrequency');
    const totalDeliveries = document.getElementById('totalDeliveries');
    
    if (this.checked) {
        recurringOptions.style.display = 'block';
        trucksPerDelivery.required = true;
        deliveryFrequency.required = true;
        totalDeliveries.required = true;
    } else {
        recurringOptions.style.display = 'none';
        trucksPerDelivery.required = false;
        deliveryFrequency.required = false;
        totalDeliveries.required = false;
        document.getElementById('deliveryPreview').style.display = 'none';
    }
});

// Handle recurring delivery preview
function updateDeliveryPreview() {
    const orderDate = document.getElementById('orderDate').value;
    const trucksPerDelivery = parseInt(document.getElementById('trucksPerDelivery').value);
    const deliveryFrequency = parseInt(document.getElementById('deliveryFrequency').value);
    const totalTrucks = parseInt(document.getElementById('orderQty').value);
    
    if (!orderDate || !trucksPerDelivery || !deliveryFrequency || !totalTrucks) {
        document.getElementById('deliveryPreview').style.display = 'none';
        document.getElementById('totalDeliveries').value = '';
        return;
    }
    
    // Auto-calculate total deliveries
    const totalDeliveries = Math.ceil(totalTrucks / trucksPerDelivery);
    document.getElementById('totalDeliveries').value = totalDeliveries;
    
    // Generate preview schedule with proper truck distribution
    let currentDate = new Date(orderDate);
    let scheduleHtml = '<div class="row">';
    let remainingTrucks = totalTrucks;
    
    for (let i = 1; i <= Math.min(totalDeliveries, 5); i++) {
        let trucksForThisDelivery;
        
        if (i === totalDeliveries) {
            // Last delivery gets remaining trucks (handles odd figures)
            trucksForThisDelivery = remainingTrucks;
        } else {
            // Regular delivery gets standard quantity
            trucksForThisDelivery = Math.min(trucksPerDelivery, remainingTrucks);
            remainingTrucks -= trucksForThisDelivery;
        }
        
        scheduleHtml += `
            <div class="col-md-6 mb-2">
                <strong>Delivery ${i}:</strong> ${currentDate.toLocaleDateString()} - ${trucksForThisDelivery} trucks
                ${i === totalDeliveries && trucksForThisDelivery !== trucksPerDelivery ? '<span class="text-info">(adjusted)</span>' : ''}
            </div>
        `;
        currentDate.setDate(currentDate.getDate() + deliveryFrequency);
    }
    
    if (totalDeliveries > 5) {
        scheduleHtml += `<div class="col-12"><em>... and ${totalDeliveries - 5} more deliveries</em></div>`;
    }
    
    scheduleHtml += '</div>';
    scheduleHtml += `<div class="mt-2 text-muted"><small>Total: ${totalTrucks} trucks across ${totalDeliveries} deliveries</small></div>`;
    
    document.getElementById('schedulePreview').innerHTML = scheduleHtml;
    document.getElementById('deliveryPreview').style.display = 'block';
}

// Add event listeners for preview updates
['trucksPerDelivery', 'deliveryFrequency', 'orderQty', 'orderDate'].forEach(id => {
    document.getElementById(id).addEventListener('input', updateDeliveryPreview);
});

// Load form data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadFormData();
});
</script>
