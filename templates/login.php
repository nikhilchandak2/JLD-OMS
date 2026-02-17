<div class="row justify-content-center align-items-center min-vh-100">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow">
            <div class="card-body p-5">
                <div class="text-center mb-4">
                    <i class="bi bi-gem display-4 text-danger"></i>
                    <h2 class="mt-2 text-primary fw-bold">JLD Minerals</h2>
                    <p class="text-muted">Order Processing System</p>
                    <p class="text-muted small">Please sign in to continue</p>
                </div>

                <div id="error-container" class="error-message"></div>

                <form id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary" id="loginBtn">
                            <span class="spinner-border spinner-border-sm d-none" id="loginSpinner"></span>
                            Sign In
                        </button>
                    </div>
                </form>

                <div class="mt-4 text-center">
                    <small class="text-muted">
                        Demo Credentials:<br>
                        Admin: admin@example.com / Passw0rd!<br>
                        Entry: entry@example.com / Passw0rd!<br>
                        View: view@example.com / Passw0rd!
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const loginBtn = document.getElementById('loginBtn');
    const loginSpinner = document.getElementById('loginSpinner');
    const formData = new FormData(this);
    
    // Show loading state
    loginBtn.disabled = true;
    loginSpinner.classList.remove('d-none');
    
    try {
        const response = await fetch('/api/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': formData.get('csrf_token')
            },
            body: JSON.stringify({
                email: formData.get('email'),
                password: formData.get('password')
            })
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            window.location.href = '/dashboard';
        } else {
            showError(data.error || 'Login failed');
        }
    } catch (error) {
        showError('Network error. Please try again.');
    } finally {
        loginBtn.disabled = false;
        loginSpinner.classList.add('d-none');
    }
});

// Auto-fill demo credentials on click
document.addEventListener('DOMContentLoaded', function() {
    const demoText = document.querySelector('.text-muted');
    if (demoText) {
        demoText.style.cursor = 'pointer';
        demoText.addEventListener('click', function() {
            document.getElementById('email').value = 'admin@example.com';
            document.getElementById('password').value = 'Passw0rd!';
        });
    }
});
</script>

