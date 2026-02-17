<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="bi bi-people"></i> User Management</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newUserModal">
        <i class="bi bi-person-plus"></i> New User
    </button>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p>Loading users...</p>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="usersTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be populated by JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- New User Modal -->
<div class="modal fade" id="newUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="newUserForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    
                    <div class="mb-3">
                        <label for="userName" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="userName" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="userEmail" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="userEmail" name="email" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="userPassword" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="userPassword" name="password" required minlength="8">
                        <div class="form-text">Minimum 8 characters</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="userRole" class="form-label">Role <span class="text-danger">*</span></label>
                        <select class="form-select" id="userRole" name="role_id" required>
                            <option value="">Select Role</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="userActive" name="is_active" checked>
                            <label class="form-check-label" for="userActive">
                                Active User
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="createUserBtn">
                        <span class="spinner-border spinner-border-sm d-none" id="createUserSpinner"></span>
                        Create User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
async function loadUsers() {
    const loading = document.getElementById('loading');
    loading.style.display = 'block';
    
    try {
        const response = await apiCall('/api/users');
        updateUsersTable(response.data);
    } catch (error) {
        showError(error.message);
    } finally {
        loading.style.display = 'none';
    }
}

async function loadRoles() {
    try {
        const response = await apiCall('/api/users/roles');
        const select = document.getElementById('userRole');
        
        const options = response.data.map(role => 
            `<option value="${role.id}">${role.name.charAt(0).toUpperCase() + role.name.slice(1)}</option>`
        ).join('');
        
        select.innerHTML = '<option value="">Select Role</option>' + options;
    } catch (error) {
        console.error('Failed to load roles:', error);
    }
}

function updateUsersTable(users) {
    const tbody = document.querySelector('#usersTable tbody');
    
    if (users.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No users found</td></tr>';
        return;
    }
    
    const rows = users.map(user => `
        <tr>
            <td><strong>${user.name}</strong></td>
            <td>${user.email}</td>
            <td><span class="badge bg-info">${user.role_name.charAt(0).toUpperCase() + user.role_name.slice(1)}</span></td>
            <td>
                ${user.is_active 
                    ? '<span class="badge bg-success">Active</span>' 
                    : '<span class="badge bg-danger">Inactive</span>'
                }
            </td>
            <td>${formatDate(user.created_at)}</td>
            <td>
                <button class="btn btn-sm btn-outline-warning" onclick="editUser(${user.id})" title="Edit User">
                    <i class="bi bi-pencil"></i>
                </button>
                ${user.email !== '<?= $user["email"] ?>' ? `
                    <button class="btn btn-sm btn-outline-danger" onclick="toggleUserStatus(${user.id}, ${!user.is_active})" title="${user.is_active ? 'Deactivate' : 'Activate'} User">
                        <i class="bi bi-${user.is_active ? 'person-x' : 'person-check'}"></i>
                    </button>
                ` : ''}
            </td>
        </tr>
    `).join('');
    
    tbody.innerHTML = rows;
}

document.getElementById('newUserForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const createBtn = document.getElementById('createUserBtn');
    const createSpinner = document.getElementById('createUserSpinner');
    const formData = new FormData(this);
    
    // Show loading state
    createBtn.disabled = true;
    createSpinner.classList.remove('d-none');
    
    try {
        const userData = {
            name: formData.get('name'),
            email: formData.get('email'),
            password: formData.get('password'),
            role_id: parseInt(formData.get('role_id')),
            is_active: formData.has('is_active')
        };
        
        const response = await apiCall('/api/users', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': formData.get('csrf_token')
            },
            body: JSON.stringify(userData)
        });
        
        showSuccess('User created successfully!');
        
        // Reset form and close modal
        this.reset();
        bootstrap.Modal.getInstance(document.getElementById('newUserModal')).hide();
        
        // Reload users table
        loadUsers();
        
    } catch (error) {
        showError(error.message);
    } finally {
        createBtn.disabled = false;
        createSpinner.classList.add('d-none');
    }
});

async function toggleUserStatus(userId, activate) {
    try {
        await apiCall(`/api/users/${userId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                is_active: activate
            })
        });
        
        showSuccess(`User ${activate ? 'activated' : 'deactivated'} successfully!`);
        loadUsers();
        
    } catch (error) {
        showError(error.message);
    }
}

function editUser(userId) {
    // This would open an edit modal - for now just show a message
    showError('Edit user functionality not implemented in this demo. Use the toggle status button to activate/deactivate users.');
}

// Load data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadUsers();
    loadRoles();
});
</script>



