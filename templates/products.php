<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-box me-2"></i>Product Management
            </h1>
            <p class="page-subtitle">Manage product catalog and inventory</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal" onclick="openProductModal()">
            <i class="bi bi-plus-circle me-1"></i> Add New Product
        </button>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-2"></i>All Products
    </div>
    <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="productsTable">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Data loaded via JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Product Modal -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="productModalTitle">Add New Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="productForm">
                <div class="modal-body">
                    <input type="hidden" id="productId" name="id">
                    
                    <div class="mb-3">
                        <label for="productCode" class="form-label">Product Code *</label>
                        <input type="text" class="form-control" id="productCode" name="code" required>
                        <div class="form-text">Unique identifier for the product (e.g., PROD-001)</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="productName" class="form-label">Product Name *</label>
                        <input type="text" class="form-control" id="productName" name="name" required>
                        <div class="form-text">Descriptive name for the product</div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="isActive" name="is_active" checked>
                            <label class="form-check-label" for="isActive">
                                Active
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let products = [];
let editingProductId = null;

// Load products on page load
document.addEventListener('DOMContentLoaded', function() {
    loadProducts();
});

async function loadProducts() {
    try {
        const response = await fetch('/api/products');
        const result = await response.json();
        
        if (result.success) {
            products = result.data;
            renderProductsTable();
        } else {
            showAlert('Error loading products: ' + result.error, 'danger');
        }
    } catch (error) {
        showAlert('Error loading products: ' + error.message, 'danger');
    }
}

function renderProductsTable() {
    const tbody = document.querySelector('#productsTable tbody');
    tbody.innerHTML = '';
    
    products.forEach(product => {
        const row = document.createElement('tr');
        const createdDate = new Date(product.created_at).toLocaleDateString();
        
        row.innerHTML = `
            <td><code>${escapeHtml(product.code)}</code></td>
            <td>${escapeHtml(product.name)}</td>
            <td>
                <span class="badge ${product.is_active ? 'bg-success' : 'bg-secondary'}">
                    ${product.is_active ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td>${createdDate}</td>
            <td>
                <button class="btn btn-sm btn-outline-primary me-1" onclick="editProduct(${product.id})">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteProduct(${product.id})">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function openProductModal(productId = null) {
    editingProductId = productId;
    const modal = document.getElementById('productModal');
    const form = document.getElementById('productForm');
    const title = document.getElementById('productModalTitle');
    
    form.reset();
    
    if (productId) {
        title.textContent = 'Edit Product';
        const product = products.find(p => p.id === productId);
        if (product) {
            document.getElementById('productId').value = product.id;
            document.getElementById('productCode').value = product.code;
            document.getElementById('productName').value = product.name;
            document.getElementById('isActive').checked = product.is_active;
        }
    } else {
        title.textContent = 'Add New Product';
        document.getElementById('isActive').checked = true;
    }
}

function editProduct(productId) {
    openProductModal(productId);
    new bootstrap.Modal(document.getElementById('productModal')).show();
}

async function deleteProduct(productId) {
    const product = products.find(p => p.id === productId);
    if (!product) return;
    
    if (!confirm(`Are you sure you want to delete "${product.name}"?`)) {
        return;
    }
    
    try {
        const response = await fetch(`/api/products/${productId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': '<?= $csrf_token ?>'
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('Product deleted successfully', 'success');
            loadProducts();
        } else {
            showAlert('Error deleting product: ' + result.error, 'danger');
        }
    } catch (error) {
        showAlert('Error deleting product: ' + error.message, 'danger');
    }
}

// Handle form submission
document.getElementById('productForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = {
        code: formData.get('code'),
        name: formData.get('name'),
        is_active: formData.has('is_active')
    };
    
    try {
        let response;
        if (editingProductId) {
            response = await fetch(`/api/products/${editingProductId}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= $csrf_token ?>'
                },
                body: JSON.stringify(data)
            });
        } else {
            response = await fetch('/api/products', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= $csrf_token ?>'
                },
                body: JSON.stringify(data)
            });
        }
        
        const result = await response.json();
        
        if (result.success) {
            showAlert(result.message, 'success');
            bootstrap.Modal.getInstance(document.getElementById('productModal')).hide();
            loadProducts();
        } else {
            showAlert('Error: ' + result.error, 'danger');
        }
    } catch (error) {
        showAlert('Error: ' + error.message, 'danger');
    }
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showAlert(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.container-fluid');
    container.insertBefore(alertDiv, container.firstChild);
    
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}
</script>
