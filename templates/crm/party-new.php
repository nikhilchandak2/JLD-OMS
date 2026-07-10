<!-- Add new company – same form as Edit company (full page) -->
<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
        <li class="breadcrumb-item"><a href="/crm/funnel">Funnel</a></li>
        <li class="breadcrumb-item active">Add new company</li>
    </ol>
</nav>

<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
        <div>
            <h1 class="page-title mb-1">Add new company</h1>
            <p class="page-subtitle mb-0">Fill in the company details below. Name, contact, GST, phone and email are required.</p>
        </div>
        <a href="/crm" class="btn btn-outline-secondary">Cancel</a>
    </div>
</div>

<div id="error-container" class="alert alert-danger d-none mb-3" role="alert"></div>

<div class="card">
    <div class="card-body">
        <form id="formNewCompany">
            <!-- Required: Company name & contact -->
            <div class="mb-4">
                <h5 class="text-primary border-bottom pb-2 mb-3">Company & contact</h5>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Company name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="profileName" required placeholder="Name of the company">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact person <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="profileContactPerson" required placeholder="Primary contact">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">GST No. <span class="text-danger">*</span></label>
                        <input type="text" class="form-control text-uppercase" id="profileGstNumber" required maxlength="15" placeholder="15-character GSTIN">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="profilePhone" required placeholder="Phone">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="profileEmail" required placeholder="Email">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" id="profileAddress" rows="2" placeholder="Address"></textarea>
                    </div>
                </div>
            </div>

            <!-- Tabs: same order as Edit company modal (log.md) -->
            <h5 class="text-primary border-bottom pb-2 mb-3">Profile details</h5>
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#profileTabOverview">Overview</button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#profileTabProducts">Products & capacity</button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#profileTabFunnel">Funnel & ratings</button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#profileTabVisitDetails">Visit details</button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#profileTabTechnical">Technical</button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#profileTabCommercial">Commercial</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="profileTabOverview">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label">Region</label><input type="text" class="form-control" id="profileRegion" placeholder="e.g. Morbi, Export"></div>
                        <div class="col-md-6"><label class="form-label">Industry type</label><select class="form-select" id="profileIndustryType"><option value="">–</option></select></div>
                        <div class="col-md-6" id="profileTilesSubtypeWrap" style="display:none;"><label class="form-label">Tiles subtype</label><select class="form-select" id="profileTilesSubtype"><option value="">–</option></select></div>
                        <div class="col-md-4"><label class="form-label">Year of association</label><input type="number" class="form-control" id="profileYearOfAssociation" placeholder="e.g. 2018" min="1900" max="2100"></div>
                        <div class="col-md-4"><label class="form-label">Order frequency</label><select class="form-select" id="profileOrderFrequency"><option value="">–</option><option value="regular">Regular</option><option value="occasional">Occasional</option><option value="trial">Trial</option></select></div>
                        <div class="col-md-4"><label class="form-label">Number of plants</label><input type="number" class="form-control" id="profileNumberOfPlants" min="0"></div>
                        <div class="col-md-4"><label class="form-label">Last order date</label><input type="date" class="form-control" id="profileLastOrderDate"></div>
                        <div class="col-md-4"><label class="form-label">Last visit date</label><input type="date" class="form-control" id="profileLastVisitDate"></div>
                        <div class="col-md-4"><label class="form-label">Next follow-up date</label><input type="date" class="form-control" id="profileNextFollowupDate"></div>
                        <div class="col-md-6"><label class="form-label">Assigned sales owner</label><select class="form-select" id="profileAssignedSalesOwner"><option value="">–</option></select></div>
                        <div class="col-md-6"><label class="form-label">Payment track</label><select class="form-select" id="profilePaymentTrack"><option value="">–</option><option value="good">Good</option><option value="delayed">Delayed</option><option value="overdue">Overdue</option><option value="na">N/A</option></select></div>
                    </div>
                </div>
                <div class="tab-pane fade" id="profileTabProducts">
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label">Products introduced</label><textarea class="form-control" id="profileProductsIntroduced" rows="2" placeholder="e.g. Ball Clay, Kaolin, Feldspar"></textarea></div>
                        <div class="col-md-6"><label class="form-label">Production capacity (monthly)</label><input type="text" class="form-control" id="profileProductionCapacity" placeholder="e.g. 50,000 sq m/day"></div>
                        <div class="col-md-4"><label class="form-label">Monthly consumption (MT)</label><input type="number" class="form-control" id="profileMonthlyConsumptionTon" step="0.01" min="0" placeholder="Display & value calculation"></div>
                        <div class="col-md-4"><label class="form-label">Avg price per ton (₹)</label><input type="number" class="form-control" id="profileAvgPricePerTon" step="0.01" min="0" placeholder="For funnel value"></div>
                        <div class="col-md-4"><label class="form-label">Funnel value</label><input type="text" class="form-control" id="profileFunnelValueDisplay" readonly placeholder="Auto: consumption × price"></div>
                        <div class="col-md-6"><label class="form-label">Target volume (sales target)</label><input type="text" class="form-control" id="profileTargetVolume" placeholder="e.g. 200 MT/year"></div>
                        <div class="col-12"><label class="form-label">Current supplier & other details</label><textarea class="form-control" id="profileCurrentSupplierDetails" rows="3" placeholder="Current supplier and other details"></textarea></div>
                    </div>
                </div>
                <div class="tab-pane fade" id="profileTabFunnel">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label">Funnel stage</label><select class="form-select" id="profileFunnelStage"><option value="">–</option></select></div>
                        <div class="col-12"><strong class="text-muted">Ratings (1–5 stars)</strong></div>
                        <div class="col-md-4"><label class="form-label">Relation with Purchase</label><select class="form-select" id="profileRelationPurchase"><option value="">–</option><option value="1">1 ★</option><option value="2">2 ★★</option><option value="3">3 ★★★</option><option value="4">4 ★★★★</option><option value="5">5 ★★★★★</option></select></div>
                        <div class="col-md-4"><label class="form-label">Relation with Internal Team</label><select class="form-select" id="profileRelationInternal"><option value="">–</option><option value="1">1 ★</option><option value="2">2 ★★</option><option value="3">3 ★★★</option><option value="4">4 ★★★★</option><option value="5">5 ★★★★★</option></select></div>
                        <div class="col-md-4"><label class="form-label">Probability of Conversion</label><select class="form-select" id="profileProbabilityConversion"><option value="">–</option><option value="1">1 ★</option><option value="2">2 ★★</option><option value="3">3 ★★★</option><option value="4">4 ★★★★</option><option value="5">5 ★★★★★</option></select></div>
                    </div>
                </div>
                <div class="tab-pane fade" id="profileTabVisitDetails">
                    <div class="mb-3">
                        <label class="form-label">Samples provided (product & price to client)</label>
                        <div id="visitSamplesContainer">
                            <div class="visit-sample-row row g-2 align-items-end mb-2">
                                <div class="col-md-6"><select class="form-select form-select-sm profileVisitProduct"><option value="">– Select product –</option></select></div>
                                <div class="col-md-4"><input type="number" class="form-control form-control-sm profileVisitPrice" step="0.01" min="0" placeholder="Price (₹)"></div>
                                <div class="col-md-2"><button type="button" class="btn btn-outline-danger btn-sm btnRemoveVisitSample" title="Remove">×</button></div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnAddVisitSample"><i class="bi bi-plus me-1"></i>Add sample</button>
                    </div>
                    <div class="mb-2"><label class="form-label">Description</label><textarea class="form-control" id="profileVisitDescription" rows="3" placeholder="Visit description"></textarea></div>
                    <div class="mb-0"><label class="form-label">Follow-up notes</label><textarea class="form-control" id="profileFollowupNotes" rows="3" placeholder="Follow-up notes"></textarea></div>
                </div>
                <div class="tab-pane fade" id="profileTabTechnical">
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label">Factory locations</label><textarea class="form-control" id="profileFactoryLocations" rows="2" placeholder="Plant addresses or areas"></textarea></div>
                        <div class="col-12"><label class="form-label">Technical notes</label><textarea class="form-control" id="profileTechnicalNotes" rows="4" placeholder="Body formulation, clay requirements"></textarea></div>
                    </div>
                </div>
                <div class="tab-pane fade" id="profileTabCommercial">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label">Credit limit (₹)</label><input type="number" class="form-control" id="profileCreditLimit" step="0.01"></div>
                        <div class="col-md-6"><label class="form-label">Payment terms (days)</label><input type="number" class="form-control" id="profilePaymentTermsDays" placeholder="90, 180"></div>
                        <div class="col-12"><label class="form-label">General notes</label><textarea class="form-control" id="profileGeneralNotes" rows="3" placeholder="Any other notes"></textarea></div>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="btnCreateCompany">
                    <i class="bi bi-check-lg me-1"></i>Save
                </button>
                <a href="/crm" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
let stagesConfigNew = { funnel_stages: {}, industry_types: {}, tiles_subtypes: {}, sample_products: {} };
document.addEventListener('DOMContentLoaded', async function() {
    try {
        const [usersRes, stagesRes] = await Promise.all([
            apiCall('/api/crm/users/options').catch(() => ({ data: [] })),
            apiCall('/api/crm/stages').catch(() => ({ data: {} }))
        ]);
        const userOptions = usersRes.data || [];
        stagesConfigNew = stagesRes.data || stagesConfigNew;
        const sel = document.getElementById('profileAssignedSalesOwner');
        sel.innerHTML = '<option value="">–</option>' + userOptions.map(u => `<option value="${escapeHtml(u.id)}">${escapeHtml(u.name)}</option>`).join('');
        const fs = document.getElementById('profileFunnelStage');
        fs.innerHTML = '<option value="">–</option>' + Object.entries(stagesConfigNew.funnel_stages || {}).map(([k,v]) => `<option value="${escapeHtml(k)}">${escapeHtml(v)}</option>`).join('');
        const it = document.getElementById('profileIndustryType');
        it.innerHTML = '<option value="">–</option>' + Object.entries(stagesConfigNew.industry_types || {}).map(([k,v]) => `<option value="${escapeHtml(k)}">${escapeHtml(v)}</option>`).join('');
        const ts = document.getElementById('profileTilesSubtype');
        ts.innerHTML = '<option value="">–</option>' + Object.entries(stagesConfigNew.tiles_subtypes || {}).map(([k,v]) => `<option value="${escapeHtml(k)}">${escapeHtml(v)}</option>`).join('');
        it.addEventListener('change', function() {
            document.getElementById('profileTilesSubtypeWrap').style.display = (this.value === 'tiles') ? 'block' : 'none';
        });
        fillVisitSampleProductDropdownsNew();
        document.getElementById('btnAddVisitSample').addEventListener('click', function() {
            document.getElementById('visitSamplesContainer').insertAdjacentHTML('beforeend', getVisitSampleRowHtmlNew());
            fillVisitSampleProductDropdownsNew();
        });
        document.getElementById('visitSamplesContainer').addEventListener('click', function(e) {
            if (e.target.classList.contains('btnRemoveVisitSample') && document.querySelectorAll('.visit-sample-row').length > 1) {
                e.target.closest('.visit-sample-row').remove();
            }
        });
    } catch (e) {
        document.getElementById('error-container').textContent = e.message || 'Failed to load options';
        document.getElementById('error-container').classList.remove('d-none');
    }

    document.getElementById('profileMonthlyConsumptionTon').addEventListener('input', updateFunnelValueDisplay);
    document.getElementById('profileAvgPricePerTon').addEventListener('input', updateFunnelValueDisplay);

    document.getElementById('formNewCompany').addEventListener('submit', async function(ev) {
        ev.preventDefault();
        const errEl = document.getElementById('error-container');
        errEl.classList.add('d-none');
        const name = document.getElementById('profileName').value.trim();
        const contactPerson = document.getElementById('profileContactPerson').value.trim();
        const gstNumber = document.getElementById('profileGstNumber').value.trim().toUpperCase();
        const phone = document.getElementById('profilePhone').value.trim();
        const email = document.getElementById('profileEmail').value.trim();
        if (!name || !contactPerson || !gstNumber || !phone || !email) {
            errEl.textContent = 'Company name, contact person, GST number, phone and email are required.';
            errEl.classList.remove('d-none');
            return;
        }
        const visitSamples = [];
        document.querySelectorAll('.visit-sample-row').forEach(function(row) {
            const product = (row.querySelector('.profileVisitProduct') || {}).value;
            const priceEl = row.querySelector('.profileVisitPrice');
            const price = priceEl && priceEl.value !== '' ? parseFloat(priceEl.value) : null;
            if (product || price != null) visitSamples.push({ product: product || '', price: price });
        });
        const btn = document.getElementById('btnCreateCompany');
        btn.disabled = true;
        try {
            const payload = {
                name: name,
                contact_person: contactPerson,
                gst_number: gstNumber,
                phone: phone,
                email: email,
                address: document.getElementById('profileAddress').value.trim() || null,
                region: document.getElementById('profileRegion').value.trim() || null,
                year_of_association: document.getElementById('profileYearOfAssociation').value ? parseInt(document.getElementById('profileYearOfAssociation').value, 10) : null,
                order_frequency: document.getElementById('profileOrderFrequency').value || null,
                number_of_plants: document.getElementById('profileNumberOfPlants').value ? parseInt(document.getElementById('profileNumberOfPlants').value, 10) : null,
                last_order_date: document.getElementById('profileLastOrderDate').value || null,
                last_visit_date: document.getElementById('profileLastVisitDate').value || null,
                next_followup_date: document.getElementById('profileNextFollowupDate').value || null,
                assigned_sales_owner: document.getElementById('profileAssignedSalesOwner').value ? parseInt(document.getElementById('profileAssignedSalesOwner').value, 10) : null,
                payment_track: document.getElementById('profilePaymentTrack').value || null,
                products_introduced: document.getElementById('profileProductsIntroduced').value.trim() || null,
                production_capacity: document.getElementById('profileProductionCapacity').value.trim() || null,
                target_volume: document.getElementById('profileTargetVolume').value.trim() || null,
                factory_locations: document.getElementById('profileFactoryLocations').value.trim() || null,
                technical_notes: document.getElementById('profileTechnicalNotes').value.trim() || null,
                credit_limit: document.getElementById('profileCreditLimit').value ? parseFloat(document.getElementById('profileCreditLimit').value) : null,
                payment_terms_days: document.getElementById('profilePaymentTermsDays').value ? parseInt(document.getElementById('profilePaymentTermsDays').value, 10) : null,
                general_notes: document.getElementById('profileGeneralNotes').value.trim() || null,
                funnel_stage: document.getElementById('profileFunnelStage').value || null,
                industry_type: document.getElementById('profileIndustryType').value || null,
                tiles_subtype: document.getElementById('profileTilesSubtype').value || null,
                monthly_consumption_ton: document.getElementById('profileMonthlyConsumptionTon').value ? parseFloat(document.getElementById('profileMonthlyConsumptionTon').value) : null,
                avg_price_per_ton: document.getElementById('profileAvgPricePerTon').value ? parseFloat(document.getElementById('profileAvgPricePerTon').value) : null,
                current_supplier_details: document.getElementById('profileCurrentSupplierDetails').value.trim() || null,
                relation_with_purchase: document.getElementById('profileRelationPurchase').value ? parseInt(document.getElementById('profileRelationPurchase').value, 10) : null,
                relation_with_internal_team: document.getElementById('profileRelationInternal').value ? parseInt(document.getElementById('profileRelationInternal').value, 10) : null,
                probability_of_conversion: document.getElementById('profileProbabilityConversion').value ? parseInt(document.getElementById('profileProbabilityConversion').value, 10) : null,
                visit_description: document.getElementById('profileVisitDescription').value.trim() || null,
                followup_notes: document.getElementById('profileFollowupNotes').value.trim() || null,
                visit_samples_provided: visitSamples.length ? visitSamples : null,
            };
            const r = await apiCall('/api/parties', { method: 'POST', body: JSON.stringify(payload) });
            if (r.success && r.data && r.data.id) {
                window.location.href = '/crm/parties/' + r.data.id;
                return;
            }
            throw new Error(r.error || 'Create failed');
        } catch (e) {
            errEl.textContent = e.message || 'Failed to create company.';
            errEl.classList.remove('d-none');
        } finally {
            btn.disabled = false;
        }
    });
});

function updateFunnelValueDisplay() {
    const qty = parseFloat(document.getElementById('profileMonthlyConsumptionTon').value) || 0;
    const price = parseFloat(document.getElementById('profileAvgPricePerTon').value) || 0;
    const el = document.getElementById('profileFunnelValueDisplay');
    el.value = (qty && price) ? '₹' + (qty * price).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '';
}

function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
function getVisitSampleRowHtmlNew() {
    const opts = Object.entries(stagesConfigNew.sample_products || {}).map(([k,v]) => `<option value="${escapeHtml(k)}">${escapeHtml(v)}</option>`).join('');
    return '<div class="visit-sample-row row g-2 align-items-end mb-2"><div class="col-md-6"><select class="form-select form-select-sm profileVisitProduct"><option value="">– Select product –</option>' + opts + '</select></div><div class="col-md-4"><input type="number" class="form-control form-control-sm profileVisitPrice" step="0.01" min="0" placeholder="Price (₹)"></div><div class="col-md-2"><button type="button" class="btn btn-outline-danger btn-sm btnRemoveVisitSample" title="Remove">×</button></div></div>';
}
function fillVisitSampleProductDropdownsNew() {
    const opts = Object.entries(stagesConfigNew.sample_products || {}).map(([k,v]) => `<option value="${escapeHtml(k)}">${escapeHtml(v)}</option>`).join('');
    document.querySelectorAll('.profileVisitProduct').forEach(function(sel) {
        if (sel.options.length <= 1) sel.innerHTML = '<option value="">– Select product –</option>' + opts;
    });
}
</script>
