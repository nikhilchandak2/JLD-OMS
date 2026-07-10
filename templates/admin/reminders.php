<!-- Email & WhatsApp Reminders – upload bills/receivables CSV and run script (accounts + admin) -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="page-title"><i class="bi bi-envelope-check me-2"></i>Email & WhatsApp Reminders</h1>
            <p class="page-subtitle mb-0">Upload a bills/receivables CSV to read data and send payment reminders</p>
        </div>
        <a href="<?= ($user['role'] ?? '') === 'admin' ? '/dashboard' : '/admin/parties' ?>" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div id="error-container" class="alert alert-danger" style="display:none; white-space: pre-wrap;"></div>
<div id="success-container" class="alert alert-success" style="display:none;"></div>

<?php
$reminderCompanies = $reminder_companies ?? [];
$hasCompanyChoice = count($reminderCompanies) >= 2;
$defaultCompany = $reminderCompanies[0]['id'] ?? '';
?>
<div class="card">
    <div class="card-header"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Upload CSV & send reminders</div>
    <div class="card-body">
        <?php if ($hasCompanyChoice): ?>
        <div class="mb-3">
            <label for="reminderCompany" class="form-label">Company</label>
            <select class="form-select" id="reminderCompany">
                <?php foreach ($reminderCompanies as $c): ?>
                <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php elseif (!empty($defaultCompany)): ?>
        <input type="hidden" id="reminderCompany" value="<?= htmlspecialchars($defaultCompany) ?>">
        <?php endif; ?>
        <p class="text-muted mb-3">Upload a <strong>Busy Bills Receivable export CSV</strong>. The script will read the data and send email and WhatsApp reminders using that company’s config and contacts.</p>
        <div class="mb-3">
            <label for="csvFile" class="form-label">Select CSV file</label>
            <input type="file" class="form-control" id="csvFile" accept=".csv,text/csv,text/plain">
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary" id="btnRunWithCsv" disabled>
                <i class="bi bi-upload me-1"></i> Upload CSV and send reminders
            </button>
            <button type="button" class="btn btn-outline-secondary" id="btnRunReminders" title="Run script without a file (e.g. for testing)">
                <i class="bi bi-play-fill me-1"></i> Run without file
            </button>
        </div>
    </div>
</div>

<div class="card mt-3" id="outputCard" style="display:none;">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-terminal me-2"></i>Script output</span>
        <span class="badge bg-secondary" id="outputStatus">Running…</span>
    </div>
    <div class="card-body">
        <pre id="outputText" class="bg-dark text-light p-3 rounded small mb-0" style="max-height: 400px; overflow: auto; white-space: pre-wrap; word-break: break-all;"></pre>
    </div>
</div>

<div class="card mt-3 border-0">
    <div class="card-body text-muted small">
        <strong>Two companies (BusyPayBot):</strong> Set both in <code>.env</code> to get a company dropdown:<br>
        <code>REMINDERS_SCRIPT_JLD_MINERALS=C:/BusyPayBot/JLD Minerals Private Limited/main.py</code><br>
        <code>REMINDERS_SCRIPT_JAICHAND=C:/BusyPayBot/Jaichand Lal Daga/main.py</code><br>
        <code>PYTHON_PATH=python</code>. For a single company, set only one of the above or use <code>REMINDERS_SCRIPT</code>. The script runs with its folder as working directory so <code>config.json</code> and <code>master_contacts.csv</code> are found.
    </div>
</div>

<script>
(function() {
    const outputCard = document.getElementById('outputCard');
    const outputText = document.getElementById('outputText');
    const outputStatus = document.getElementById('outputStatus');
    const errEl = document.getElementById('error-container');
    const successEl = document.getElementById('success-container');
    const csvInput = document.getElementById('csvFile');
    const btnRunWithCsv = document.getElementById('btnRunWithCsv');
    const btnRunReminders = document.getElementById('btnRunReminders');
    let currentJobId = null;
    let pollTimer = null;

    csvInput.addEventListener('change', function() {
        btnRunWithCsv.disabled = !this.files || this.files.length === 0;
    });

    function showOutput(data) {
        outputCard.style.display = 'block';
        if (data.success) {
            outputStatus.textContent = 'Completed';
            outputStatus.className = 'badge bg-success';
            successEl.textContent = data.used_csv ? 'CSV processed and reminders sent.' : 'Script finished successfully.';
            successEl.style.display = 'block';
        } else {
            outputStatus.textContent = 'Failed';
            outputStatus.className = 'badge bg-danger';
            if (data.error) {
                let msg = data.error;
                if (data.paths_tried && data.paths_tried.length) {
                    msg += '\n\nPaths checked:\n' + data.paths_tried.join('\n');
                } else if (data.path_checked) {
                    msg += '\n\nPath checked: ' + data.path_checked;
                }
                errEl.textContent = msg;
                errEl.style.display = 'block';
            }
        }
        outputText.textContent = data.output || '(no output)';
    }

    function showJob(job) {
        outputCard.style.display = 'block';
        const st = job.status || 'pending';
        if (st === 'pending') {
            outputStatus.textContent = 'Queued';
            outputStatus.className = 'badge bg-secondary';
            outputText.textContent = 'Job queued. Waiting for offline runner PC to pick it up...';
        } else if (st === 'running') {
            outputStatus.textContent = 'Running';
            outputStatus.className = 'badge bg-primary';
            let runMsg = 'Runner started the job. BusyPayBot may still be sending on the PC — keep the runner window open.';
            if (job.started_at) {
                runMsg += '\nStarted: ' + job.started_at;
            }
            runMsg += '\n\nIf this stays running more than ~30 minutes, refresh the page (stale jobs auto-fail) or upload CSV again.';
            outputText.textContent = runMsg;
        } else if (st === 'completed') {
            outputStatus.textContent = 'Completed';
            outputStatus.className = 'badge bg-success';
            successEl.textContent = 'Reminders job completed.';
            successEl.style.display = 'block';
            outputText.textContent = job.output || '(no output)';
        } else if (st === 'failed') {
            outputStatus.textContent = 'Failed';
            outputStatus.className = 'badge bg-danger';
            errEl.textContent = 'Reminders job failed.';
            errEl.style.display = 'block';
            outputText.textContent = job.output || '(no output)';
        } else {
            outputStatus.textContent = st;
            outputStatus.className = 'badge bg-secondary';
            outputText.textContent = job.output || '(no output)';
        }
    }

    function setRunning(btn) {
        errEl.style.display = 'none';
        successEl.style.display = 'none';
        outputCard.style.display = 'block';
        outputText.textContent = 'Submitting…';
        outputStatus.textContent = 'Running…';
        outputStatus.className = 'badge bg-secondary';
        btn.disabled = true;
    }

    function setDone(btn) {
        btn.disabled = false;
    }

    function getSelectedCompany() {
        const el = document.getElementById('reminderCompany');
        return el ? (el.tagName === 'SELECT' ? el.value : el.getAttribute('value')) : '';
    }

    btnRunWithCsv.addEventListener('click', async function() {
        if (!csvInput.files || csvInput.files.length === 0) return;
        const btn = this;
        setRunning(btn);
        try {
            const form = new FormData();
            form.append('csv', csvInput.files[0]);
            const company = getSelectedCompany();
            if (company) form.append('company', company);
            const r = await fetch('/api/reminders/jobs', {
                method: 'POST',
                headers: { 'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : '' },
                body: form
            });
            const data = await r.json().catch(function() { return { success: false, error: 'Invalid response' }; });
            if (!data.success || !data.job) {
                showOutput(data);
                setDone(btn);
                return;
            }
            currentJobId = data.job.id;
            csvInput.value = '';
            showJob(data.job);

            if (pollTimer) clearInterval(pollTimer);
            pollTimer = setInterval(async function() {
                if (!currentJobId) return;
                const rr = await fetch('/api/reminders/jobs/' + encodeURIComponent(currentJobId), {
                    headers: { 'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : '' },
                });
                const st = await rr.json().catch(function() { return null; });
                if (!st || !st.success || !st.job) return;
                showJob(st.job);
                if (st.job.status === 'completed' || st.job.status === 'failed') {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
            }, 2000);
        } catch (e) {
            outputStatus.textContent = 'Error';
            outputStatus.className = 'badge bg-danger';
            outputText.textContent = e.message || 'Request failed';
            errEl.textContent = e.message || 'Failed to run reminders';
            errEl.style.display = 'block';
        }
        setDone(btn);
    });

    btnRunReminders.addEventListener('click', async function() {
        const btn = this;
        setRunning(btn);
        try {
            errEl.textContent = 'Upload a CSV to create a reminders job. The "Run without file" option is disabled in offline-runner mode.';
            errEl.style.display = 'block';
            outputStatus.textContent = 'Info';
            outputStatus.className = 'badge bg-secondary';
            outputText.textContent = 'This setup uses the offline runner PC. Please upload a CSV to proceed.';
        } finally {
            setDone(btn);
        }
    });
})();
</script>
