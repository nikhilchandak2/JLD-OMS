<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/data-feeds">Data feeds</a></li>
                    <li class="breadcrumb-item active">Upload</li>
                </ol>
            </nav>
            <h1 class="page-title mt-2"><i class="bi bi-upload me-2"></i>Upload a daily file</h1>
            <p class="page-subtitle mb-0">CSV or Excel. Nothing reaches live tables until you review the validation report and confirm promote.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="/api/data-feeds/template/ledger">Ledger template</a>
            <a class="btn btn-outline-secondary" href="/api/data-feeds/template/dispatch_day_file">Dispatch template</a>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<div class="card mb-4">
    <div class="card-body">
        <form id="uploadForm">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Feed</label>
                    <select class="form-select" id="feedKey" required>
                        <option value="ledger">Ledger (Busy outstanding)</option>
                        <option value="dispatch_day_file">Dispatch day file</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Company</label>
                    <select class="form-select" id="companyId" required>
                        <?php foreach (($companies_list ?? []) as $company): ?>
                            <option value="<?= (int)$company['id'] ?>" <?= !empty($active_company['id']) && (int)$active_company['id'] === (int)$company['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($company['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Business date (the date the data describes)</label>
                    <input type="date" class="form-control" id="businessDate" required>
                </div>
                <div class="col-12">
                    <label class="form-label">File (CSV / Excel)</label>
                    <input type="file" class="form-control" id="file" accept=".csv,.xlsx,.xls" required>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="confirmSupersede">
                        <label class="form-check-label" for="confirmSupersede">
                            Replace an already-completed file for this business date (supersession). Never silent.
                        </label>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary mt-3" id="btnUpload">
                <i class="bi bi-upload me-1"></i> Upload and validate
            </button>
        </form>
    </div>
</div>

<div id="resultCard" class="card" style="display:none;">
    <div class="card-header">Validation report</div>
    <div class="card-body" id="resultBody"></div>
</div>

<script>
const params = new URLSearchParams(location.search);
if (params.get('feed_key')) document.getElementById('feedKey').value = params.get('feed_key');
if (params.get('company_id')) document.getElementById('companyId').value = params.get('company_id');
document.getElementById('businessDate').value = new Date().toISOString().slice(0, 10);

document.getElementById('uploadForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const file = document.getElementById('file').files[0];
    if (!file) { showError('Choose a file.'); return; }

    const body = new FormData();
    body.append('file', file);
    body.append('feed_key', document.getElementById('feedKey').value);
    body.append('company_id', document.getElementById('companyId').value);
    body.append('business_date', document.getElementById('businessDate').value);
    if (document.getElementById('confirmSupersede').checked) {
        body.append('confirm_supersede', '1');
    }

    try {
        const response = await fetch('/api/data-feeds/runs', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            body
        });
        const data = await response.json();
        if (!response.ok) {
            if (data.supersede_required) {
                showError(data.error + ' Tick “Replace an already-completed file” and upload again.');
                return;
            }
            throw new Error(data.error || 'Upload failed');
        }
        const run = data.data.run;
        if (data.data.already_processed) {
            showSuccess(data.data.message);
        } else {
            showSuccess('File staged and validated.');
        }
        window.location.href = '/data-feeds/runs/' + run.id;
    } catch (err) {
        showError(err.message);
    }
});
</script>
