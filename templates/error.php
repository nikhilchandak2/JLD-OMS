<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body text-center p-5">
                <i class="bi bi-exclamation-triangle display-1 text-warning"></i>
                <h2 class="mt-3"><?= htmlspecialchars($title ?? 'Error') ?></h2>
                <p class="lead text-muted">
                    <?= htmlspecialchars($message ?? 'An error occurred. Please try again.') ?>
                </p>
                <div class="mt-4">
                    <a href="/dashboard" class="btn btn-primary">
                        <i class="bi bi-house"></i> Go to Dashboard
                    </a>
                    <button onclick="history.back()" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Go Back
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>




