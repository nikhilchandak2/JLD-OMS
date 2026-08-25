<?php $party_id = (int)($party_id ?? 0); ?>
<!-- Company CRM profile – attractive layout -->
<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
        <li class="breadcrumb-item"><a href="/crm/funnel">Funnel</a></li>
        <li class="breadcrumb-item active" id="partyBreadcrumb">Company</li>
    </ol>
</nav>

<div id="error-container" class="error-message mb-3"></div>

<!-- Hero: company name + primary contact + actions -->
<div class="crm-profile-hero">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <h1 class="profile-name mb-1" id="partyName">–</h1>
            <p class="profile-meta mb-0" id="partyContact"><i class="bi bi-person"></i> <span id="partyContactText">Loading…</span></p>
            <p class="profile-meta mb-0 mt-1" id="partyEmailLine" style="display:none;"><i class="bi bi-envelope"></i> <a href="#" id="partyEmailLink" class="text-white text-decoration-none"></a></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="/crm/parties/<?= $party_id ?>/briefing" class="btn btn-light btn-sm"><i class="bi bi-journal-text me-1"></i>Briefing</a>
            <button type="button" class="btn btn-light btn-sm" id="btnEditProfile"><i class="bi bi-pencil me-1"></i>Edit company</button>
            <button type="button" class="btn btn-outline-light btn-sm" id="btnAddContact"><i class="bi bi-person-plus me-1"></i>Add contact</button>
            <a href="/crm/visits/new?party_id=<?= $party_id ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-geo-alt me-1"></i>Log visit</a>
            <?php if (in_array($user['role'] ?? '', ['admin', 'crm', 'sales'], true)): ?>
            <button type="button" class="btn btn-outline-light btn-sm" id="btnSeniorAttention"><i class="bi bi-exclamation-octagon me-1"></i>Needs senior attention</button>
            <?php endif; ?>
            <?php if (in_array($user['role'] ?? '', ['admin', 'marketing', 'crm'])): ?>
            <a href="/visit-requests?raise_party_id=<?= $party_id ?>" class="btn btn-warning btn-sm"><i class="bi bi-geo-alt me-1"></i>Request technical visit</a>
            <?php endif; ?>
            <a href="/orders?party_id=<?= $party_id ?>" class="btn btn-outline-light btn-sm">Orders</a>
            <a href="/admin/parties" class="btn btn-outline-light btn-sm">All parties</a>
        </div>
    </div>
</div>

<!-- At a glance: pills -->
<div class="mb-4">
    <div class="crm-glance-pills" id="atAGlance">
        <span class="crm-glance-pill"><span class="pill-label">Funnel</span> <span id="glanceFunnelStage">–</span></span>
        <span class="crm-glance-pill"><span class="pill-label">Year with us</span> <span id="glanceYear">–</span></span>
        <span class="crm-glance-pill"><span class="pill-label">Order freq.</span> <span id="glanceOrderFreq">–</span></span>
        <span class="crm-glance-pill"><span class="pill-label">Last order</span> <span id="glanceLastOrder">–</span></span>
        <span class="crm-glance-pill"><span class="pill-label">Last visit</span> <span id="glanceLastVisit">–</span></span>
        <span class="crm-glance-pill"><span class="pill-label">Next follow-up</span> <span id="glanceNextFollowup">–</span></span>
        <span class="crm-glance-pill"><span class="pill-label">Sales owner</span> <span id="glanceSalesOwner">–</span></span>
        <span class="crm-glance-pill"><span class="pill-label">Payment</span> <span id="glancePaymentTrack">–</span></span>
    </div>
</div>

<!-- Company profile sections (rendered by JS) -->
<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0 text-primary">Company profile</h5>
        <button type="button" class="btn btn-sm btn-outline-primary" id="btnEditProfile2"><i class="bi bi-pencil me-1"></i>Edit</button>
    </div>
    <div id="companyProfile">Loading…</div>
</div>

<div class="row g-4">
    <div class="col-12">
        <div class="card crm-section-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-people"></i> Contacts</span>
                <button type="button" class="btn btn-sm btn-outline-primary py-0" id="btnAddContact2"><i class="bi bi-plus"></i></button>
            </div>
            <div class="card-body" id="contactEditor">Loading…</div>
        </div>
    </div>
</div>

<div class="row g-4 mt-0">
    <div class="col-12">
        <div class="card crm-section-card" id="competitorPanel">
            <div class="card-header"><span><i class="bi bi-diagram-3"></i> Competitors</span></div>
            <div class="card-body" data-competitor-body>Loading…</div>
        </div>
    </div>
</div>

<div class="row g-4 mt-0">
    <div class="col-lg-7">
        <div class="card crm-section-card" id="issuesPanel">
            <div class="card-header"><span><i class="bi bi-exclamation-octagon"></i> Issues &amp; complaints</span></div>
            <div class="card-body" data-issues-body>Loading…</div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card crm-section-card" id="accountContextPanel">
            <div class="card-header"><span><i class="bi bi-building"></i> Account context</span></div>
            <div class="card-body" data-context-body>Loading…</div>
        </div>
    </div>
</div>

<div class="row g-4 mt-0">
    <div class="col-12">
        <div class="card crm-section-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-geo-alt"></i> Visits</span>
                <a href="/crm/visits/new?party_id=<?= $party_id ?>" class="btn btn-sm btn-primary py-0"><i class="bi bi-plus me-1"></i>Log visit</a>
            </div>
            <div class="card-body" id="visitsList">Loading…</div>
        </div>
    </div>
</div>

<div class="row g-4 mt-0">
    <div class="col-12">
        <div class="card crm-section-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-box-seam"></i> Samples & trials</span>
                <button type="button" class="btn btn-sm btn-primary py-0" id="btnAddSample"><i class="bi bi-plus me-1"></i>Add sample</button>
            </div>
            <div class="card-body" id="samplesList">Loading…</div>
        </div>
    </div>
</div>

<div class="row g-4 mt-0">
    <div class="col-12">
        <div class="card crm-section-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-currency-rupee"></i> Receivables & credit</span>
                <button type="button" class="btn btn-sm btn-primary py-0" id="btnAddReceivable"><i class="bi bi-plus me-1"></i>Add entry</button>
            </div>
            <div class="card-body">
                <?php $feedKey = 'ledger'; $mode = 'group'; include __DIR__ . '/../partials/data-as-of-banner.php'; ?>
                <div class="crm-receivable-summary">
                    <div class="item"><strong>Outstanding</strong> <span id="receivableOutstanding">–</span></div>
                    <div class="item"><strong>Credit limit</strong> <span id="receivableCreditLimit">–</span></div>
                    <div class="item"><span id="receivableAlert" class="text-danger small"></span></div>
                </div>
                <div id="receivablesEntries">Loading…</div>
            </div>
        </div>
    </div>
</div>

<div class="card crm-section-card mt-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-activity"></i> Activities</span>
        <button type="button" class="btn btn-sm btn-primary py-0" id="btnAddActivity"><i class="bi bi-plus me-1"></i>Log activity</button>
    </div>
    <div class="card-body" id="activitiesList">Loading…</div>
</div>

<!-- Add activity modal -->
<div class="modal fade" id="activityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Log activity</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <select class="form-select" id="activityType">
                        <option value="call">Call</option>
                        <option value="meeting">Customer Meeting</option>
                        <option value="visit">Sales Visit</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="email">Email</option>
                        <option value="note">Note</option>
                    </select>
                </div>
                <div class="mb-3"><label class="form-label">Subject</label><input type="text" class="form-control" id="activitySubject"></div>
                <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" id="activityDescription" rows="3"></textarea></div>
                <div class="mb-3"><label class="form-label">Date & time</label><input type="datetime-local" class="form-control" id="activityDate"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveActivity">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit company profile modal -->
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Edit company profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#profileTabOverview">Overview</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#profileTabProducts">Products & capacity</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#profileTabFunnel">Funnel & ratings</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#profileTabVisitDetails">Visit details</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#profileTabTechnical">Technical</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#profileTabCommercial">Commercial</button></li>
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
                            <div class="col-12"><div class="alert alert-secondary py-2 small mb-0">The star ratings below are superseded by structured contact influence / relationship strength. They are kept for historical rows and will be deprecated — do not treat them as the account score.</div></div>
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
                            <div class="col-12"><label class="form-label">Technical notes (body formulation, clay requirements)</label><textarea class="form-control" id="profileTechnicalNotes" rows="4"></textarea></div>
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
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="btnSaveProfile">Save</button></div>
        </div>
    </div>
</div>

<!-- Add sample modal -->
<div class="modal fade" id="sampleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Add sample / trial</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Sample type</label><input type="text" class="form-control" id="sampleType" placeholder="e.g. Ball Clay"></div>
                <div class="mb-2"><label class="form-label">Quantity sent</label><input type="text" class="form-control" id="sampleQuantity"></div>
                <div class="row g-2 mb-2">
                    <div class="col-4"><label class="form-label">Request date</label><input type="date" class="form-control" id="sampleRequestDate"></div>
                    <div class="col-4"><label class="form-label">Dispatch date</label><input type="date" class="form-control" id="sampleDispatchDate"></div>
                    <div class="col-4"><label class="form-label">Trial date</label><input type="date" class="form-control" id="sampleTrialDate"></div>
                </div>
                <div class="mb-2"><label class="form-label">Status</label><select class="form-select" id="sampleStatus"><option value="sample_sent">Sample Sent</option><option value="trial_scheduled">Trial Scheduled</option><option value="trial_successful">Trial Successful</option><option value="trial_failed">Trial Failed</option><option value="trial_retesting">Trial Retesting</option></select></div>
                <div class="mb-2"><label class="form-label">Outcome</label><input type="text" class="form-control" id="sampleOutcome"></div>
                <div class="mb-2"><label class="form-label">Technical feedback</label><textarea class="form-control" id="sampleTechnicalFeedback" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="btnSaveSample">Save</button></div>
        </div>
    </div>
</div>

<!-- Add receivable entry modal -->
<div class="modal fade" id="receivableModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Add entry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Type</label><select class="form-select" id="receivableType"><option value="invoice">Invoice</option><option value="payment">Payment</option><option value="adjustment">Adjustment</option></select></div>
                <div class="mb-2"><label class="form-label">Amount (₹) *</label><input type="number" class="form-control" id="receivableAmount" step="0.01" required></div>
                <div class="mb-2"><label class="form-label">Date</label><input type="date" class="form-control" id="receivableDate"></div>
                <div class="mb-2"><label class="form-label">Reference</label><input type="text" class="form-control" id="receivableReference" placeholder="Invoice no. / Chq no."></div>
                <div class="mb-2"><label class="form-label">Description</label><textarea class="form-control" id="receivableDescription" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="btnSaveReceivable">Save</button></div>
        </div>
    </div>
</div>

<script>
const partyId = <?= $party_id ?>;
let party = null;
let userOptions = [];
let stagesConfig = { funnel_stages: {}, industry_types: {}, tiles_subtypes: {}, sample_products: {} };

document.addEventListener('DOMContentLoaded', async function() {
    if (partyId <= 0) { showError('Invalid party'); return; }
    try {
        const [partyRes, usersRes, stagesRes] = await Promise.all([
            apiCall('/api/parties/' + partyId),
            apiCall('/api/crm/users/options').catch(() => ({ data: [] })),
            apiCall('/api/crm/stages').catch(() => ({ data: {} }))
        ]);
        party = partyRes.data;
        userOptions = (usersRes.data || []);
        stagesConfig = stagesRes.data || stagesConfig;
        const sel = document.getElementById('profileAssignedSalesOwner');
        sel.innerHTML = '<option value="">–</option>' + userOptions.map(u => `<option value="${u.id}">${escapeHtml(u.name)}</option>`).join('');
        const fs = document.getElementById('profileFunnelStage');
        fs.innerHTML = '<option value="">–</option>' + Object.entries(stagesConfig.funnel_stages || {}).map(([k,v]) => `<option value="${escapeHtml(k)}">${escapeHtml(v)}</option>`).join('');
        const it = document.getElementById('profileIndustryType');
        it.innerHTML = '<option value="">–</option>' + Object.entries(stagesConfig.industry_types || {}).map(([k,v]) => `<option value="${escapeHtml(k)}">${escapeHtml(v)}</option>`).join('');
        const ts = document.getElementById('profileTilesSubtype');
        ts.innerHTML = '<option value="">–</option>' + Object.entries(stagesConfig.tiles_subtypes || {}).map(([k,v]) => `<option value="${escapeHtml(k)}">${escapeHtml(v)}</option>`).join('');
        document.getElementById('profileIndustryType').addEventListener('change', function() {
            document.getElementById('profileTilesSubtypeWrap').style.display = (this.value === 'tiles') ? 'block' : 'none';
        });
        document.getElementById('partyName').textContent = party.name;
        const contactLine = party.contact_person || '';
        const emailLine = party.email || '';
        document.getElementById('partyContactText').textContent = contactLine || 'No contact set';
        if (emailLine) {
            const emailEl = document.getElementById('partyEmailLine');
            const linkEl = document.getElementById('partyEmailLink');
            emailEl.style.display = 'block';
            linkEl.href = 'mailto:' + emailLine;
            linkEl.textContent = emailLine;
        }
        document.getElementById('partyBreadcrumb').textContent = party.name;
        renderAtAGlance();
        renderCompanyProfile();
    } catch (e) {
        showError(e.message);
    }
    if (window.AccountContext) {
        AccountContext.mountParty({ partyId: partyId, users: userOptions }).catch(function (e) { showError(e.message); });
    }
    loadSamples();
    loadReceivables();
    loadActivities();
    loadVisits();
    document.getElementById('profileMonthlyConsumptionTon').addEventListener('input', updateFunnelValueDisplay);
    document.getElementById('profileAvgPricePerTon').addEventListener('input', updateFunnelValueDisplay);
    fillVisitSampleProductDropdowns();
    document.getElementById('btnAddVisitSample').addEventListener('click', function() {
        const container = document.getElementById('visitSamplesContainer');
        container.insertAdjacentHTML('beforeend', getVisitSampleRowHtml());
        fillVisitSampleProductDropdowns();
    });
    document.getElementById('visitSamplesContainer').addEventListener('click', function(e) {
        if (e.target.classList.contains('btnRemoveVisitSample') && document.querySelectorAll('.visit-sample-row').length > 1) {
            e.target.closest('.visit-sample-row').remove();
        }
    });
});
function getVisitSampleRowHtml() {
    const opts = Object.entries(stagesConfig.sample_products || {}).map(([k,v]) => `<option value="${escapeHtml(k)}">${escapeHtml(v)}</option>`).join('');
    return '<div class="visit-sample-row row g-2 align-items-end mb-2"><div class="col-md-6"><select class="form-select form-select-sm profileVisitProduct"><option value="">– Select product –</option>' + opts + '</select></div><div class="col-md-4"><input type="number" class="form-control form-control-sm profileVisitPrice" step="0.01" min="0" placeholder="Price (₹)"></div><div class="col-md-2"><button type="button" class="btn btn-outline-danger btn-sm btnRemoveVisitSample" title="Remove">×</button></div></div>';
}
function fillVisitSampleProductDropdowns() {
    const opts = Object.entries(stagesConfig.sample_products || {}).map(([k,v]) => `<option value="${escapeHtml(k)}">${escapeHtml(v)}</option>`).join('');
    document.querySelectorAll('.profileVisitProduct').forEach(function(sel) {
        if (sel.options.length <= 1) sel.innerHTML = '<option value="">– Select product –</option>' + opts;
    });
}

function updateFunnelValueDisplay() {
    const qty = parseFloat(document.getElementById('profileMonthlyConsumptionTon').value) || 0;
    const price = parseFloat(document.getElementById('profileAvgPricePerTon').value) || 0;
    const el = document.getElementById('profileFunnelValueDisplay');
    el.value = (qty && price) ? '₹' + (qty * price).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '';
}

function renderAtAGlance() {
    if (!party) return;
    const funnelLabel = (stagesConfig.funnel_stages || {})[party.funnel_stage] || party.funnel_stage || '–';
    document.getElementById('glanceFunnelStage').textContent = funnelLabel;
    const y = party.year_of_association;
    const orderFreq = { regular: 'Regular', occasional: 'Occasional', trial: 'Trial' }[party.order_frequency] || party.order_frequency || '–';
    const paymentTrack = { good: 'Good', delayed: 'Delayed', overdue: 'Overdue', na: 'N/A' }[party.payment_track] || party.payment_track || '–';
    const owner = party.assigned_sales_owner ? (userOptions.find(u => u.id === party.assigned_sales_owner) || {}).name : '–';
    document.getElementById('glanceYear').textContent = y ? String(y) : '–';
    document.getElementById('glanceOrderFreq').textContent = orderFreq;
    document.getElementById('glanceLastOrder').textContent = party.last_order_date || '–';
    document.getElementById('glanceLastVisit').textContent = party.last_visit_date || '–';
    document.getElementById('glanceNextFollowup').textContent = party.next_followup_date || '–';
    document.getElementById('glanceSalesOwner').textContent = owner;
    document.getElementById('glancePaymentTrack').textContent = paymentTrack;
}

function renderCompanyProfile() {
    if (!party) return;
    const el = document.getElementById('companyProfile');
    const v = function(x) { return x != null && x !== '' ? escapeHtml(String(x)) : '–'; };
    const fs = (stagesConfig.funnel_stages || {})[party.funnel_stage] || party.funnel_stage || '–';
    const it = (stagesConfig.industry_types || {})[party.industry_type] || party.industry_type || '–';
    const ts = (stagesConfig.tiles_subtypes || {})[party.tiles_subtype] || party.tiles_subtype || '–';
    const credit = party.credit_limit != null ? '₹' + Number(party.credit_limit).toLocaleString() : '–';
    const terms = party.payment_terms_days != null ? party.payment_terms_days + ' days' : '–';
    const funnelVal = party.funnel_value != null ? '₹' + Number(party.funnel_value).toLocaleString('en-IN', { maximumFractionDigits: 0 }) : '–';
    const star = function(n) { return n != null ? '★'.repeat(n) + ' (' + n + '/5)' : '–'; };
    const sampleProducts = stagesConfig.sample_products || {};
    const samplesProvided = party.visit_samples_provided && Array.isArray(party.visit_samples_provided) ? party.visit_samples_provided : [];
    const samplesHtml = samplesProvided.length ? '<table class="table table-sm mb-0"><thead><tr><th>Product</th><th>Price</th></tr></thead><tbody>' + samplesProvided.map(function(s) {
        const pLabel = (s.product && sampleProducts[s.product]) ? sampleProducts[s.product] : (s.product || '–');
        const priceVal = s.price != null ? '₹' + Number(s.price).toLocaleString() : '–';
        return '<tr><td>' + escapeHtml(pLabel) + '</td><td>' + priceVal + '</td></tr>';
    }).join('') + '</tbody></table>' : '<p class="text-muted small mb-0">None</p>';
    const visitSection = (party.visit_description || party.followup_notes || samplesProvided.length) ? '<div class="crm-profile-section mt-3"><div class="crm-profile-section-title"><i class="bi bi-journal-check"></i> Visit details</div><div class="crm-profile-section-body"><strong class="d-block mb-1">Samples provided</strong>' + samplesHtml + (party.visit_description ? '<p class="mt-2 mb-0"><strong>Description</strong><br>' + escapeHtml(party.visit_description) + '</p>' : '') + (party.followup_notes ? '<p class="mt-2 mb-0"><strong>Follow-up notes</strong><br>' + escapeHtml(party.followup_notes) + '</p>' : '') + '</div></div>' : '';
    el.innerHTML =
        '<div class="row g-3">' +
        '<div class="col-md-6"><div class="crm-profile-section">' +
        '<div class="crm-profile-section-title"><i class="bi bi-person-lines-fill"></i> Overview & contact</div>' +
        '<div class="crm-profile-section-body"><dl class="crm-profile-dl two-cols">' +
        '<dt>Contact person</dt><dd>' + v(party.contact_person) + '</dd>' +
        '<dt>Phone</dt><dd>' + v(party.phone) + '</dd>' +
        '<dt>Email</dt><dd>' + v(party.email) + '</dd>' +
        '<dt>Address</dt><dd>' + v(party.address) + '</dd>' +
        '<dt>Region</dt><dd>' + v(party.region) + '</dd>' +
        '<dt>Industry type</dt><dd>' + escapeHtml(it) + '</dd>' +
        (party.industry_type === 'tiles' ? '<dt>Tiles subtype</dt><dd>' + escapeHtml(ts) + '</dd>' : '') +
        '<dt>Year of association</dt><dd>' + (party.year_of_association || '–') + '</dd>' +
        '<dt>Order frequency</dt><dd>' + v(party.order_frequency) + '</dd>' +
        '<dt>Number of plants</dt><dd>' + (party.number_of_plants != null ? party.number_of_plants : '–') + '</dd>' +
        '</dl></div></div></div>' +
        '<div class="col-md-6"><div class="crm-profile-section">' +
        '<div class="crm-profile-section-title"><i class="bi bi-box-seam"></i> Products & capacity</div>' +
        '<div class="crm-profile-section-body"><dl class="crm-profile-dl two-cols">' +
        '<dt>Products introduced</dt><dd>' + v(party.products_introduced) + '</dd>' +
        '<dt>Monthly production</dt><dd>' + v(party.production_capacity) + '</dd>' +
        '<dt>Monthly consumption (MT)</dt><dd>' + (party.monthly_consumption_ton != null ? party.monthly_consumption_ton : '–') + '</dd>' +
        '<dt>Avg price/ton</dt><dd>' + (party.avg_price_per_ton != null ? '₹' + Number(party.avg_price_per_ton).toLocaleString() : '–') + '</dd>' +
        '<dt>Funnel value</dt><dd><strong class="text-primary">' + funnelVal + '</strong></dd>' +
        '<dt>Target volume</dt><dd>' + v(party.target_volume) + '</dd>' +
        '<dt>Current supplier</dt><dd>' + v(party.current_supplier_details) + '</dd>' +
        '</dl></div></div></div>' +
        '</div>' +
        '<div class="row g-3 mt-0">' +
        '<div class="col-md-6"><div class="crm-profile-section">' +
        '<div class="crm-profile-section-title"><i class="bi bi-funnel"></i> Funnel & ratings</div>' +
        '<div class="crm-profile-section-body"><dl class="crm-profile-dl two-cols">' +
        '<dt>Funnel stage</dt><dd>' + escapeHtml(fs) + '</dd>' +
        '<dt>Relation (Purchase)</dt><dd>' + star(party.relation_with_purchase) + '</dd>' +
        '<dt>Relation (Internal)</dt><dd>' + star(party.relation_with_internal_team) + '</dd>' +
        '<dt>Probability of conversion</dt><dd>' + star(party.probability_of_conversion) + '</dd>' +
        '</dl></div></div></div>' +
        '<div class="col-md-6"><div class="crm-profile-section">' +
        '<div class="crm-profile-section-title"><i class="bi bi-gear"></i> Technical & commercial</div>' +
        '<div class="crm-profile-section-body"><dl class="crm-profile-dl two-cols">' +
        '<dt>Factory locations</dt><dd>' + v(party.factory_locations) + '</dd>' +
        '<dt>Technical notes</dt><dd>' + v(party.technical_notes) + '</dd>' +
        '<dt>Credit limit</dt><dd>' + credit + '</dd>' +
        '<dt>Payment terms</dt><dd>' + terms + '</dd>' +
        '</dl></div></div></div>' +
        '</div>' +
        (party.general_notes ? '<div class="crm-profile-section mt-3"><div class="crm-profile-section-title"><i class="bi bi-journal-text"></i> General notes</div><div class="crm-profile-section-body"><p class="mb-0">' + escapeHtml(party.general_notes) + '</p></div></div>' : '') +
        visitSection;
}

function openProfileModal() {
    if (!party) return;
    document.getElementById('profileRegion').value = party.region || '';
    document.getElementById('profileIndustryType').value = party.industry_type || '';
    document.getElementById('profileTilesSubtypeWrap').style.display = (party.industry_type === 'tiles') ? 'block' : 'none';
    document.getElementById('profileTilesSubtype').value = party.tiles_subtype || '';
    document.getElementById('profileYearOfAssociation').value = party.year_of_association || '';
    document.getElementById('profileOrderFrequency').value = party.order_frequency || '';
    document.getElementById('profileNumberOfPlants').value = party.number_of_plants != null ? party.number_of_plants : '';
    document.getElementById('profileLastOrderDate').value = party.last_order_date || '';
    document.getElementById('profileLastVisitDate').value = party.last_visit_date || '';
    document.getElementById('profileNextFollowupDate').value = party.next_followup_date || '';
    document.getElementById('profileAssignedSalesOwner').value = party.assigned_sales_owner != null ? String(party.assigned_sales_owner) : '';
    document.getElementById('profilePaymentTrack').value = party.payment_track || '';
    document.getElementById('profileProductsIntroduced').value = party.products_introduced || '';
    document.getElementById('profileProductionCapacity').value = party.production_capacity || '';
    document.getElementById('profileMonthlyConsumptionTon').value = party.monthly_consumption_ton != null ? party.monthly_consumption_ton : '';
    document.getElementById('profileAvgPricePerTon').value = party.avg_price_per_ton != null ? party.avg_price_per_ton : '';
    updateFunnelValueDisplay();
    document.getElementById('profileTargetVolume').value = party.target_volume || '';
    document.getElementById('profileCurrentSupplierDetails').value = party.current_supplier_details || '';
    document.getElementById('profileFunnelStage').value = party.funnel_stage || '';
    document.getElementById('profileRelationPurchase').value = party.relation_with_purchase != null ? String(party.relation_with_purchase) : '';
    document.getElementById('profileRelationInternal').value = party.relation_with_internal_team != null ? String(party.relation_with_internal_team) : '';
    document.getElementById('profileProbabilityConversion').value = party.probability_of_conversion != null ? String(party.probability_of_conversion) : '';
    document.getElementById('profileVisitDescription').value = party.visit_description || '';
    document.getElementById('profileFollowupNotes').value = party.followup_notes || '';
    const samples = party.visit_samples_provided && Array.isArray(party.visit_samples_provided) ? party.visit_samples_provided : [];
    const container = document.getElementById('visitSamplesContainer');
    container.innerHTML = samples.length ? '' : getVisitSampleRowHtml();
    samples.forEach(function(s) {
        container.insertAdjacentHTML('beforeend', getVisitSampleRowHtml());
    });
    fillVisitSampleProductDropdowns();
    if (samples.length) {
        container.querySelectorAll('.visit-sample-row').forEach(function(row, i) {
            const s = samples[i];
            if (s) {
                const sel = row.querySelector('.profileVisitProduct');
                const price = row.querySelector('.profileVisitPrice');
                if (sel) sel.value = s.product || '';
                if (price) price.value = s.price != null ? s.price : '';
            }
        });
    }
    document.getElementById('profileFactoryLocations').value = party.factory_locations || '';
    document.getElementById('profileTechnicalNotes').value = party.technical_notes || '';
    document.getElementById('profileCreditLimit').value = party.credit_limit != null ? party.credit_limit : '';
    document.getElementById('profilePaymentTermsDays').value = party.payment_terms_days != null ? party.payment_terms_days : '';
    document.getElementById('profileGeneralNotes').value = party.general_notes || '';
    new bootstrap.Modal(document.getElementById('profileModal')).show();
}
document.getElementById('btnEditProfile').addEventListener('click', openProfileModal);
document.getElementById('btnEditProfile2').addEventListener('click', openProfileModal);

document.getElementById('btnSaveProfile').addEventListener('click', async function() {
    try {
        const visitSamples = [];
        document.querySelectorAll('.visit-sample-row').forEach(function(row) {
            const product = (row.querySelector('.profileVisitProduct') || {}).value;
            const priceEl = row.querySelector('.profileVisitPrice');
            const price = priceEl && priceEl.value !== '' ? parseFloat(priceEl.value) : null;
            if (product || price != null) visitSamples.push({ product: product || '', price: price });
        });
        const payload = {
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
        await apiCall('/api/parties/' + partyId, { method: 'PUT', body: JSON.stringify(payload) });
        const r = await apiCall('/api/parties/' + partyId);
        party = r.data;
        renderAtAGlance();
        renderCompanyProfile();
        bootstrap.Modal.getInstance(document.getElementById('profileModal')).hide();
    } catch (e) { showError(e.message); }
});

async function loadSamples() {
    try {
        const r = await apiCall('/api/crm/samples?party_id=' + partyId);
        const list = r.data || [];
        const el = document.getElementById('samplesList');
        if (list.length === 0) el.innerHTML = '<p class="text-muted mb-0">No samples yet. Click Add sample.</p>';
        else {
            const statusLabels = { sample_sent: 'Sample Sent', trial_scheduled: 'Trial Scheduled', trial_successful: 'Trial Successful', trial_failed: 'Trial Failed', trial_retesting: 'Trial Retesting' };
            el.innerHTML = '<table class="table table-sm mb-0"><thead><tr><th>Type</th><th>Qty</th><th>Dates</th><th>Status</th><th>Outcome</th></tr></thead><tbody>' +
                list.map(s => `<tr><td>${escapeHtml(s.sample_type || '–')}</td><td>${escapeHtml(s.quantity_sent || '–')}</td><td>${s.request_date || ''} / ${s.dispatch_date || ''} / ${s.trial_date || ''}</td><td><span class="badge bg-secondary">${statusLabels[s.status] || s.status}</span></td><td>${escapeHtml(s.outcome || '–')}</td></tr>`).join('') + '</tbody></table>';
        }
    } catch (e) {
        document.getElementById('samplesList').innerHTML = '<p class="text-muted mb-0">Samples not available.</p>';
    }
}

document.getElementById('btnAddSample').addEventListener('click', function() {
    document.getElementById('sampleType').value = '';
    document.getElementById('sampleQuantity').value = '';
    document.getElementById('sampleRequestDate').value = '';
    document.getElementById('sampleDispatchDate').value = '';
    document.getElementById('sampleTrialDate').value = '';
    document.getElementById('sampleStatus').value = 'sample_sent';
    document.getElementById('sampleOutcome').value = '';
    document.getElementById('sampleTechnicalFeedback').value = '';
    new bootstrap.Modal(document.getElementById('sampleModal')).show();
});
document.getElementById('btnSaveSample').addEventListener('click', async function() {
    try {
        await apiCall('/api/crm/samples', { method: 'POST', body: JSON.stringify({
            party_id: partyId,
            sample_type: document.getElementById('sampleType').value.trim(),
            quantity_sent: document.getElementById('sampleQuantity').value.trim(),
            request_date: document.getElementById('sampleRequestDate').value || null,
            dispatch_date: document.getElementById('sampleDispatchDate').value || null,
            trial_date: document.getElementById('sampleTrialDate').value || null,
            status: document.getElementById('sampleStatus').value,
            outcome: document.getElementById('sampleOutcome').value.trim(),
            technical_feedback: document.getElementById('sampleTechnicalFeedback').value.trim(),
        }) });
        loadSamples();
        bootstrap.Modal.getInstance(document.getElementById('sampleModal')).hide();
    } catch (e) { showError(e.message); }
});

async function loadReceivables() {
    try {
        const r = await apiCall('/api/crm/parties/' + partyId + '/receivables');
        const data = r.data || {};
        const entries = data.entries || [];
        const out = data.outstanding != null ? data.outstanding : 0;
        const limit = data.credit_limit;
        document.getElementById('receivableOutstanding').textContent = '₹' + Number(out).toLocaleString();
        document.getElementById('receivableCreditLimit').textContent = limit != null ? '₹' + Number(limit).toLocaleString() : '–';
        const alertEl = document.getElementById('receivableAlert');
        if (limit != null && out > limit) alertEl.textContent = 'Over credit limit';
        else alertEl.textContent = '';
        const el = document.getElementById('receivablesEntries');
        if (entries.length === 0) el.innerHTML = '<p class="text-muted mb-0">No entries. Add invoice or payment.</p>';
        else el.innerHTML = '<table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Reference</th></tr></thead><tbody>' +
            entries.map(e => `<tr><td>${e.entry_date}</td><td>${e.entry_type}</td><td>₹${Number(e.amount).toLocaleString()}</td><td>${escapeHtml(e.reference || '')}</td></tr>`).join('') + '</tbody></table>';
    } catch (e) {
        document.getElementById('receivableOutstanding').textContent = '–';
        document.getElementById('receivablesEntries').innerHTML = '<p class="text-muted mb-0">Receivables not available.</p>';
    }
}

document.getElementById('btnAddReceivable').addEventListener('click', function() {
    document.getElementById('receivableAmount').value = '';
    document.getElementById('receivableDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('receivableReference').value = '';
    document.getElementById('receivableDescription').value = '';
    new bootstrap.Modal(document.getElementById('receivableModal')).show();
});
document.getElementById('btnSaveReceivable').addEventListener('click', async function() {
    const amount = parseFloat(document.getElementById('receivableAmount').value);
    if (!amount || amount <= 0) { showError('Amount is required'); return; }
    try {
        await apiCall('/api/crm/receivables', { method: 'POST', body: JSON.stringify({
            party_id: partyId,
            entry_type: document.getElementById('receivableType').value,
            amount,
            entry_date: document.getElementById('receivableDate').value || new Date().toISOString().slice(0, 10),
            reference: document.getElementById('receivableReference').value.trim(),
            description: document.getElementById('receivableDescription').value.trim(),
        }) });
        loadReceivables();
        bootstrap.Modal.getInstance(document.getElementById('receivableModal')).hide();
    } catch (e) { showError(e.message); }
});

async function loadVisits() {
    const el = document.getElementById('visitsList');
    if (!el) return;
    try {
        const r = await apiCall('/api/crm/parties/' + partyId + '/visits');
        const list = r.data || [];
        if (list.length === 0) {
            el.innerHTML = '<p class="text-muted small mb-0">Not yet recorded. Log a visit after you leave the plant.</p>';
            return;
        }
        el.innerHTML = list.map(function (v) {
            const people = (v.contacts || []).map(function (c) { return escapeHtml(c.name); }).join(', ') || '—';
            const follow = v.no_followup_needed
                ? 'No follow-up · ' + escapeHtml(v.no_followup_reason || '')
                : ('Next touchpoint ' + escapeHtml(v.next_planned_touchpoint || '—'));
            return '<div class="crm-activity-item">' +
                '<div class="d-flex justify-content-between gap-2"><strong>' + escapeHtml(v.visit_date) + '</strong>' +
                '<span class="small text-muted">' + escapeHtml(v.visited_by_name || '') + '</span></div>' +
                '<div class="small">Met: ' + people + '</div>' +
                (v.outcome ? '<div class="small mt-1">' + escapeHtml(v.outcome) + '</div>' : '') +
                '<div class="small text-muted mt-1">' + follow + '</div></div>';
        }).join('');
    } catch (e) {
        el.innerHTML = '<p class="text-danger small mb-0">Failed to load visits.</p>';
    }
}

async function loadActivities() {
    try {
        const r = await apiCall('/api/crm/activities?party_id=' + partyId);
        const list = r.data || [];
        const el = document.getElementById('activitiesList');
        if (list.length === 0) el.innerHTML = '<p class="text-muted small mb-0">No activities yet. Click <strong>Log activity</strong> to add.</p>';
        else {
            el.innerHTML = list.map(a => `
                <div class="crm-activity-item">
                    <strong>${escapeHtml(a.type)}</strong> ${escapeHtml(a.subject || '')}
                    <div class="text-muted small mt-1">${a.activity_date} · ${escapeHtml(a.created_by_name || '')}</div>
                    ${a.description ? '<p class="mb-0 mt-1 small">' + escapeHtml(a.description) + '</p>' : ''}
                </div>
            `).join('');
        }
    } catch (e) {
        document.getElementById('activitiesList').innerHTML = 'Failed to load activities.';
    }
}

function triggerAddContact() {
    const btn = document.querySelector('#contactEditor [data-add-contact]');
    if (btn) btn.click();
    const editor = document.getElementById('contactEditor');
    if (editor) editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
document.getElementById('btnAddContact').addEventListener('click', triggerAddContact);
document.getElementById('btnAddContact2').addEventListener('click', triggerAddContact);

const btnSenior = document.getElementById('btnSeniorAttention');
if (btnSenior) {
    btnSenior.addEventListener('click', async function () {
        const note = prompt('Why does this need senior attention?');
        if (!note) return;
        try {
            await apiCall('/api/crm/escalations', {
                method: 'POST',
                body: JSON.stringify({ party_id: partyId, note: note })
            });
            showError('');
            alert('Flagged for the director inbox.');
        } catch (e) {
            showError(e.message);
        }
    });
}

document.getElementById('btnAddActivity').addEventListener('click', function() {
    document.getElementById('activitySubject').value = '';
    document.getElementById('activityDescription').value = '';
    document.getElementById('activityDate').value = new Date().toISOString().slice(0, 16);
    new bootstrap.Modal(document.getElementById('activityModal')).show();
});

document.getElementById('btnSaveActivity').addEventListener('click', async function() {
    const payload = {
        party_id: partyId,
        type: document.getElementById('activityType').value,
        subject: document.getElementById('activitySubject').value.trim(),
        description: document.getElementById('activityDescription').value.trim(),
        activity_date: document.getElementById('activityDate').value || new Date().toISOString().slice(0, 19).replace('T', ' '),
    };
    try {
        await apiCall('/api/crm/activities', { method: 'POST', body: JSON.stringify(payload) });
        bootstrap.Modal.getInstance(document.getElementById('activityModal')).hide();
        loadActivities();
    } catch (e) {
        showError(e.message);
    }
});

function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>
