<div class="page-header">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/data-feeds">Data feeds</a></li>
            <li class="breadcrumb-item active">Unmatched parties</li>
        </ol>
    </nav>
    <h1 class="page-title mt-2">Unmatched parties</h1>
    <p class="page-subtitle mb-0">Names and codes from uploaded files that do not match a party. Resolving one writes an alias so it is never asked again. Parties are never auto-created.</p>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table" id="unmatchedTable">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Identifier</th>
                        <th>Name in file</th>
                        <th>Company / date</th>
                        <th>Map to party id</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', loadUnmatched);

async function loadUnmatched() {
    try {
        const response = await apiCall('/api/data-feeds/unmatched');
        const items = response.data || [];
        const tbody = document.querySelector('#unmatchedTable tbody');
        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-center">Nothing waiting. Every uploaded name has been resolved or no files are pending.</td></tr>';
            return;
        }
        tbody.innerHTML = items.map((item, i) => `<tr>
            <td>${escapeHtml(item.source_system)}</td>
            <td><code>${escapeHtml(item.source_identifier)}</code></td>
            <td>${escapeHtml(item.party_name || item.party_code || '')}</td>
            <td>${escapeHtml(item.company_name)} · ${escapeHtml(item.business_date)} · run #${item.run_id}</td>
            <td>
                <form class="d-flex gap-2 alias-form" data-index="${i}">
                    <input type="number" class="form-control form-control-sm" name="party_id" placeholder="Party id" required>
                    <button class="btn btn-sm btn-primary" type="submit">Save alias</button>
                </form>
            </td>
        </tr>`).join('');
        window.__unmatched = items;
        document.querySelectorAll('.alias-form').forEach(form => {
            form.addEventListener('submit', submitAlias);
        });
    } catch (e) {
        showError(e.message);
    }
}

async function submitAlias(e) {
    e.preventDefault();
    const index = parseInt(e.target.getAttribute('data-index'), 10);
    const item = (window.__unmatched || [])[index];
    const partyId = parseInt(e.target.party_id.value, 10);
    try {
        await apiCall('/api/data-feeds/aliases', {
            method: 'POST',
            body: JSON.stringify({
                source_system: item.source_system,
                source_identifier: item.source_identifier,
                party_id: partyId
            })
        });
        showSuccess('Alias saved. Matching rows will be re-validated.');
        loadUnmatched();
    } catch (err) {
        showError(err.message);
    }
}
</script>
