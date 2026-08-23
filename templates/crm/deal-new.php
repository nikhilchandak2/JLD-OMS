<!-- New enquiry (Stage 1 capture) — mobile-first, one-handed: five fields, nothing else -->
<div class="page-header mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item"><a href="/crm/deals">Deals</a></li>
            <li class="breadcrumb-item active">New enquiry</li>
        </ol>
    </nav>
    <h1 class="page-title mb-0">New enquiry</h1>
    <p class="text-muted small mb-0">For new business only — a new customer, or a new grade at an existing customer. Repeat orders go straight to an order.</p>
</div>

<div id="dealNewError" class="alert alert-danger d-none" role="alert"></div>

<form id="dealNewForm" class="card p-3" style="max-width: 640px;" novalidate>
    <div class="mb-3">
        <label class="form-label" for="party_id">Customer <span class="text-danger">*</span></label>
        <select class="form-select form-select-lg" id="party_id" name="party_id" required>
            <option value="">Loading customers…</option>
        </select>
        <div class="invalid-feedback" data-error-for="party_id"></div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="source">How did the enquiry arrive? <span class="text-danger">*</span></label>
        <select class="form-select form-select-lg" id="source" name="source" required>
            <option value="">Select…</option>
        </select>
        <div class="invalid-feedback" data-error-for="source"></div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="grades">Grade(s) enquired <span class="text-danger">*</span></label>
        <input type="text" class="form-control form-control-lg" id="grades" name="grades"
               placeholder="e.g. J-11, BNT-31" autocomplete="off" required>
        <div class="form-text">Separate multiple grades with a comma.</div>
        <div class="invalid-feedback" data-error-for="grades"></div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="indicative_quantity_tonnes">Indicative quantity (tonnes) <span class="text-danger">*</span></label>
        <input type="number" step="0.01" min="0" class="form-control form-control-lg"
               id="indicative_quantity_tonnes" name="indicative_quantity_tonnes" inputmode="decimal" required>
        <div class="invalid-feedback" data-error-for="indicative_quantity_tonnes"></div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="inquiry_date">Enquiry date <span class="text-danger">*</span></label>
        <input type="date" class="form-control form-control-lg" id="inquiry_date" name="inquiry_date" required>
        <div class="invalid-feedback" data-error-for="inquiry_date"></div>
    </div>

    <div class="accordion mb-3" id="dealNewOptional">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#dealNewOptionalBody">
                    Optional details
                </button>
            </h2>
            <div id="dealNewOptionalBody" class="accordion-collapse collapse" data-bs-parent="#dealNewOptional">
                <div class="accordion-body">
                    <div class="mb-3">
                        <label class="form-label" for="title">Deal name</label>
                        <input type="text" class="form-control" id="title" name="title" placeholder="Filled in from customer and grade if left blank">
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-success btn-lg w-100 py-3" id="dealNewSubmit">Save enquiry</button>
</form>

<script>
const SOURCE_FALLBACK = { call: 'Phone call', whatsapp: 'WhatsApp', email: 'Email', exhibition: 'Exhibition', referral: 'Referral', walk_in: 'Walk-in', other: 'Other' };

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('inquiry_date').value = istToday();
    loadParties();
    loadSources();

    document.querySelectorAll('#dealNewForm [required]').forEach(function (field) {
        field.addEventListener('blur', function () { validateField(field); });
    });
    document.getElementById('dealNewForm').addEventListener('submit', submitDeal);
});

function istToday() {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kolkata', year: 'numeric', month: '2-digit', day: '2-digit'
    }).formatToParts(new Date());
    const get = (type) => parts.find(p => p.type === type).value;
    return get('year') + '-' + get('month') + '-' + get('day');
}

async function loadParties() {
    const select = document.getElementById('party_id');
    try {
        const res = await apiCall('/api/parties');
        const parties = (res.data || []).slice().sort((a, b) => String(a.name).localeCompare(String(b.name)));
        select.innerHTML = '<option value="">Select customer…</option>' + parties.map(function (p) {
            return '<option value="' + p.id + '">' + escapeHtml(p.name) + '</option>';
        }).join('');
    } catch (e) {
        select.innerHTML = '<option value="">Could not load customers</option>';
        showFormError(e.message);
    }
}

async function loadSources() {
    const select = document.getElementById('source');
    let sources = SOURCE_FALLBACK;
    try {
        const res = await apiCall('/api/crm/deals/summary');
        if (res.data && res.data.sources) sources = res.data.sources;
    } catch (e) { /* fallback list is used */ }
    select.innerHTML = '<option value="">Select…</option>' + Object.keys(sources).map(function (key) {
        return '<option value="' + escapeHtml(key) + '">' + escapeHtml(sources[key]) + '</option>';
    }).join('');
}

function validateField(field) {
    const feedback = document.querySelector('[data-error-for="' + field.id + '"]');
    const empty = !field.value || String(field.value).trim() === '';
    field.classList.toggle('is-invalid', empty);
    if (feedback) feedback.textContent = empty ? 'This is needed to save the enquiry.' : '';
    return !empty;
}

function showFormError(message) {
    const box = document.getElementById('dealNewError');
    box.textContent = message;
    box.classList.remove('d-none');
}

async function submitDeal(event) {
    event.preventDefault();
    document.getElementById('dealNewError').classList.add('d-none');

    let valid = true;
    document.querySelectorAll('#dealNewForm [required]').forEach(function (field) {
        if (!validateField(field)) valid = false;
    });
    if (!valid) return;

    const button = document.getElementById('dealNewSubmit');
    button.disabled = true;
    button.textContent = 'Saving…';

    try {
        const res = await apiCall('/api/crm/deals', {
            method: 'POST',
            body: JSON.stringify({
                party_id: document.getElementById('party_id').value,
                source: document.getElementById('source').value,
                grades: document.getElementById('grades').value,
                indicative_quantity_tonnes: document.getElementById('indicative_quantity_tonnes').value,
                inquiry_date: document.getElementById('inquiry_date').value,
                title: document.getElementById('title').value,
                notes: document.getElementById('notes').value
            })
        });
        window.location.href = '/crm/deals/' + res.data.id;
    } catch (e) {
        showFormError(e.message);
        button.disabled = false;
        button.textContent = 'Save enquiry';
    }
}
</script>
