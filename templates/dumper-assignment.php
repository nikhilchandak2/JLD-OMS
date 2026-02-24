<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="page-title">
                <i class="bi bi-truck me-2"></i>Dumper Assignment
            </h1>
            <p class="page-subtitle">Assign dumpers to excavating machines by date (4–5 dumpers per machine)</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <label class="mb-0 fw-medium">Date</label>
            <input type="date" class="form-control" id="assignmentDate" value="<?= date('Y-m-d') ?>" style="width: auto;">
            <button class="btn btn-primary" onclick="loadAssignments()">
                <i class="bi bi-arrow-clockwise me-1"></i> Load
            </button>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status"></div>
    <p>Loading...</p>
</div>

<div id="assignmentCards" class="row g-3">
    <!-- Filled by JS -->
</div>

<script>
function showError(msg) {
    const el = document.getElementById('error-container');
    if (!el) return;
    el.textContent = msg || '';
    el.style.display = msg ? 'block' : 'none';
}

function loadAssignments() {
    const date = document.getElementById('assignmentDate').value;
    if (!date) return;
    document.getElementById('loading').style.display = 'flex';
    document.getElementById('assignmentCards').innerHTML = '';
    showError('');
    Promise.all([
        fetch('/api/dumper-assignments?date=' + encodeURIComponent(date), { credentials: 'same-origin' }).then(r => r.json()),
        fetch('/api/excavating-machines', { credentials: 'same-origin' }).then(r => r.json()),
        fetch('/api/vehicles?vehicle_type=dumper&status=active', { credentials: 'same-origin' }).then(r => r.json())
    ]).then(([assignRes, machinesRes, vehiclesRes]) => {
        document.getElementById('loading').style.display = 'none';
        if (!assignRes.success || !machinesRes.success) {
            showError(assignRes.error || machinesRes.error || 'Failed to load');
            return;
        }
        const machines = assignRes.data || [];
        const allDumpers = (vehiclesRes.data || []).map(v => ({ id: v.id, vehicle_number: v.vehicle_number }));
        const assignedVehicleIds = new Set();
        machines.forEach(m => (m.assignments || []).forEach(a => assignedVehicleIds.add(a.vehicle_id)));
        const availableDumpers = allDumpers.filter(d => !assignedVehicleIds.has(d.id));
        renderCards(machines, availableDumpers, date);
    }).catch(e => {
        document.getElementById('loading').style.display = 'none';
        showError('Error: ' + e.message);
    });
}

function renderCards(machines, availableDumpers, date) {
    const container = document.getElementById('assignmentCards');
    container.innerHTML = machines.map(m => {
        const assignments = m.assignments || [];
        const options = availableDumpers.map(d =>
            `<option value="${d.id}">${escapeHtml(d.vehicle_number)}</option>`
        ).join('');
        return `
            <div class="col-12 col-lg-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><strong>${escapeHtml(m.machine_name)}</strong> <span class="text-muted small">${escapeHtml(m.mine_name)}</span></span>
                        <span class="badge bg-primary">${assignments.length} dumper(s)</span>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush mb-3">
                            ${assignments.length === 0 ? '<li class="list-group-item text-muted small">No dumpers assigned</li>' : ''}
                            ${assignments.map(a => `
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    ${escapeHtml(a.vehicle_number)}
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeAssignment(${a.id})" title="Remove">×</button>
                                </li>
                            `).join('')}
                        </ul>
                        <div class="d-flex gap-2">
                            <select class="form-select form-select-sm" id="dumperSelect-${m.excavating_machine_id}">
                                <option value="">Add dumper...</option>
                                ${options}
                            </select>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addAssignment(${m.excavating_machine_id}, '${date}')">Add</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function addAssignment(machineId, date) {
    const sel = document.getElementById('dumperSelect-' + machineId);
    const vehicleId = sel && sel.value ? parseInt(sel.value, 10) : 0;
    if (!vehicleId) {
        showError('Select a dumper first');
        return;
    }
    showError('');
    fetch('/api/dumper-assignments', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : '' },
        body: JSON.stringify({ date: date, excavating_machine_id: machineId, vehicle_id: vehicleId })
    }).then(r => r.json()).then(data => {
        if (data.success) loadAssignments();
        else showError(data.error || 'Failed to add');
    }).catch(e => showError('Error: ' + e.message));
}

function removeAssignment(assignmentId) {
    showError('');
    fetch('/api/dumper-assignments/' + assignmentId, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : '' }
    }).then(r => r.json()).then(data => {
        if (data.success) loadAssignments();
        else showError(data.error || 'Failed to remove');
    }).catch(e => showError('Error: ' + e.message));
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.getElementById('assignmentDate').addEventListener('change', loadAssignments);
document.addEventListener('DOMContentLoaded', loadAssignments);
</script>
