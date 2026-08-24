<style>
.new-order-section {
    border: none;
    border-radius: 0.75rem;
    box-shadow: 0 1px 3px rgba(43, 35, 94, 0.08);
    overflow: hidden;
}
.new-order-section .card-header {
    background: linear-gradient(135deg, rgba(43, 35, 94, 0.06), rgba(43, 35, 94, 0.02));
    border-bottom: 1px solid var(--jld-border);
    font-weight: 600;
    color: var(--jld-primary);
}
.new-order-summary {
    border: 1px solid var(--jld-border);
    border-radius: 0.75rem;
    box-shadow: 0 4px 16px rgba(43, 35, 94, 0.08);
}
.new-order-summary .summary-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.65rem 0;
    border-bottom: 1px solid var(--jld-border);
}
.new-order-summary .summary-row:last-child {
    border-bottom: none;
}
.new-order-summary .summary-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--jld-gray);
}
.new-order-summary .summary-value {
    font-weight: 600;
    color: var(--jld-primary);
    text-align: right;
}
.qty-preview-box {
    background: rgba(43, 35, 94, 0.04);
    border: 1px dashed var(--jld-border);
    border-radius: 0.5rem;
    padding: 1rem 1.25rem;
}
.qty-preview-box .preview-main {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--jld-primary);
}
.recurring-toggle-card {
    border: 1px solid var(--jld-border);
    border-radius: 0.5rem;
    padding: 1rem 1.25rem;
    background: #fafbfc;
    transition: border-color 0.2s, background 0.2s;
}
.recurring-toggle-card.active {
    border-color: var(--jld-primary);
    background: rgba(43, 35, 94, 0.03);
}
.field-with-action .btn-add {
    flex-shrink: 0;
    width: 42px;
    height: 42px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1 class="page-title mb-1">
                <i class="bi bi-plus-circle me-2"></i>Create New Order
            </h1>
            <p class="page-subtitle mb-0">
                <?php if (!empty($active_company['name'])): ?>
                Placing order for <span class="badge bg-primary"><?= htmlspecialchars($active_company['name']) ?></span>
                <span class="text-muted ms-1">· switch company from header</span>
                <?php else: ?>
                Select a company from the header before creating an order
                <?php endif; ?>
            </p>
        </div>
        <a href="/orders" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Orders
        </a>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<form id="newOrderForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" id="companyId" name="company_id" value="<?= (int)($active_company['id'] ?? 0) ?>">

    <div class="row g-4">
        <!-- Main form -->
        <div class="col-lg-8">
            <!-- Customer & Product -->
            <div class="card new-order-section mb-4">
                <div class="card-header py-3">
                    <i class="bi bi-people me-2"></i>Customer &amp; Product
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="partyId" class="form-label">Party <span class="text-danger">*</span></label>
                            <div class="d-flex gap-2 field-with-action">
                                <select class="form-select searchable-select" id="partyId" name="party_id" required>
                                    <option value="">Select party…</option>
                                </select>
                                <button type="button" class="btn btn-outline-primary btn-add" onclick="openQuickAddModal('party')" title="Add party">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                            <?php $feedKey = 'ledger'; $mode = 'group'; include __DIR__ . '/partials/data-as-of-banner.php'; ?>
                            <div id="creditStatusPanel" class="mt-2" style="display: none;"></div>
                        </div>
                        <div class="col-md-6">
                            <label for="productId" class="form-label">Product <span class="text-danger">*</span></label>
                            <div class="d-flex gap-2 field-with-action">
                                <select class="form-select searchable-select" id="productId" name="product_id" required>
                                    <option value="">Select product…</option>
                                </select>
                                <button type="button" class="btn btn-outline-primary btn-add" onclick="openQuickAddModal('product')" title="Add product">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-top">
                        <div class="recurring-toggle-card" id="billingToggleCard">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="billToOtherParty" name="bill_to_other_party">
                                <label class="form-check-label" for="billToOtherParty">
                                    <strong>Bill to another party</strong>
                                    <span class="text-muted d-block small mt-1">Invoice will be raised in a different party's name (delivery party stays the same)</span>
                                </label>
                            </div>
                        </div>
                        <div class="mt-3" id="billingPartyOptions" style="display: none;">
                            <label for="billingPartyId" class="form-label">Billing party <span class="text-danger">*</span></label>
                            <select class="form-select searchable-select" id="billingPartyId" name="billing_party_id">
                                <option value="">Select billing party…</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quantity -->
            <div class="card new-order-section mb-4">
                <div class="card-header py-3">
                    <i class="bi bi-box-seam me-2"></i>Order Quantity
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label d-block">Quantity mode <span class="text-danger">*</span></label>
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="order_qty_mode" id="qtyModeTrucks" value="trucks" checked>
                            <label class="btn btn-outline-primary" for="qtyModeTrucks"><i class="bi bi-truck me-1"></i> Trucks</label>
                            <input type="radio" class="btn-check" name="order_qty_mode" id="qtyModeWeight" value="weight">
                            <label class="btn btn-outline-primary" for="qtyModeWeight"><i class="bi bi-speedometer2 me-1"></i> Weight (MT)</label>
                        </div>
                    </div>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4" id="trucksQtyGroup">
                            <label for="orderQty" class="form-label">Number of Trucks <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-lg" id="orderQty" name="order_qty_trucks" min="1" placeholder="e.g. 2">
                        </div>
                        <div class="col-md-4" id="weightQtyGroup" style="display: none;">
                            <label for="orderWeight" class="form-label">Total Weight (MT) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-lg" id="orderWeight" name="order_weight_tons" step="0.001" min="0.001" placeholder="e.g. 80">
                        </div>
                        <div class="col-md-4">
                            <label for="tonsPerTruck" class="form-label">MT per Truck</label>
                            <input type="number" class="form-control" id="tonsPerTruck" name="tons_per_truck" value="40" step="0.01" min="1">
                        </div>
                        <div class="col-12">
                            <div class="qty-preview-box">
                                <div class="text-muted small mb-1">Preview</div>
                                <div class="preview-main" id="qtyPreview">Enter quantity to see preview</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card new-order-section mb-4">
                <div class="card-header py-3">
                    <i class="bi bi-shield-check me-2"></i>Credit gate
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="proposedOrderValue" class="form-label">Estimated value (₹)</label>
                            <input type="number" class="form-control" id="proposedOrderValue" name="proposed_order_value" min="0" step="0.01" placeholder="Last rate × tonnes">
                            <div class="form-text">Prefills from last dispatch rate. Confirmed only after submit.</div>
                        </div>
                        <div class="col-md-8">
                            <label for="linkedDealId" class="form-label">Link an open deal (optional)</label>
                            <select class="form-select" id="linkedDealId" name="deal_id">
                                <option value="">No deal — repeat order</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="repReason" class="form-label">Reason if over the limit</label>
                            <textarea class="form-control" id="repReason" name="rep_reason" rows="2" maxlength="500" placeholder="Required only when this order is over the group limit"></textarea>
                        </div>
                    </div>
                    <div class="mt-3" id="extraGrades"></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="btnAddGrade">
                        <i class="bi bi-plus-lg"></i> Add another grade
                    </button>
                </div>
            </div>

            <!-- Schedule & priority -->
            <div class="card new-order-section mb-4">
                <div class="card-header py-3">
                    <i class="bi bi-calendar3 me-2"></i>Schedule &amp; Priority
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="orderDate" class="form-label">Order Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="orderDate" name="order_date" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="priority" class="form-label">Priority <span class="text-danger">*</span></label>
                            <select class="form-select" id="priority" name="priority" required>
                                <option value="normal" selected>Normal</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delivery schedule -->
            <div class="card new-order-section mb-4">
                <div class="card-header py-3">
                    <i class="bi bi-calendar3 me-2"></i>Delivery Schedule
                </div>
                <div class="card-body">
                    <label class="form-label d-block mb-2">Delivery type</label>
                    <div class="btn-group flex-wrap mb-3" role="group" aria-label="Delivery type">
                        <input type="radio" class="btn-check" name="delivery_type" id="deliveryTypeNone" value="none" checked>
                        <label class="btn btn-outline-secondary" for="deliveryTypeNone">As needed</label>
                        <input type="radio" class="btn-check" name="delivery_type" id="deliveryTypeScheduled" value="scheduled">
                        <label class="btn btn-outline-primary" for="deliveryTypeScheduled"><i class="bi bi-calendar-event me-1"></i> Scheduled</label>
                        <input type="radio" class="btn-check" name="delivery_type" id="deliveryTypeRecurring" value="recurring">
                        <label class="btn btn-outline-primary" for="deliveryTypeRecurring"><i class="bi bi-arrow-repeat me-1"></i> Recurring</label>
                    </div>

                    <div id="scheduleDispatchOptions" style="display: none;">
                        <div class="recurring-toggle-card active mb-0">
                            <label for="scheduledDispatchDate" class="form-label">Scheduled dispatch date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="scheduledDispatchDate" name="scheduled_dispatch_date">
                            <div class="form-text">Planned date for the first dispatch</div>
                        </div>
                    </div>

                    <div id="recurringOptions" style="display: none;">
                        <div class="recurring-toggle-card active">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="trucksPerDelivery" class="form-label">Trucks per delivery</label>
                                    <input type="number" class="form-control" id="trucksPerDelivery" name="trucks_per_delivery" min="1" placeholder="e.g. 2">
                                </div>
                                <div class="col-md-4">
                                    <label for="deliveryFrequency" class="form-label">Frequency (days)</label>
                                    <input type="number" class="form-control" id="deliveryFrequency" name="delivery_frequency_days" min="1" placeholder="e.g. 7">
                                </div>
                                <div class="col-md-4">
                                    <label for="totalDeliveries" class="form-label">Total deliveries</label>
                                    <input type="number" class="form-control bg-light" id="totalDeliveries" name="total_deliveries" readonly>
                                </div>
                            </div>
                            <div class="alert alert-info mt-3 mb-0" id="deliveryPreview" style="display: none;">
                                <strong class="d-block mb-2"><i class="bi bi-calendar-week me-1"></i> Schedule preview</strong>
                                <div id="schedulePreview"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary sidebar -->
        <div class="col-lg-4">
            <div class="card new-order-summary sticky-lg-top" style="top: 1rem;">
                <div class="card-header py-3">
                    <h6 class="mb-0"><i class="bi bi-receipt me-2"></i>Order Summary</h6>
                </div>
                <div class="card-body">
                    <div class="summary-row">
                        <span class="summary-label">Company</span>
                        <span class="summary-value" id="summaryCompany"><?= htmlspecialchars($active_company['name'] ?? '—') ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Party</span>
                        <span class="summary-value" id="summaryParty">—</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Product</span>
                        <span class="summary-value" id="summaryProduct">—</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Quantity</span>
                        <span class="summary-value" id="summaryQty">—</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Order Date</span>
                        <span class="summary-value" id="summaryDate"><?= date('d/m/Y') ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Priority</span>
                        <span class="summary-value" id="summaryPriority">Normal</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Billing</span>
                        <span class="summary-value" id="summaryBilling">Same as party</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Delivery</span>
                        <span class="summary-value" id="summaryDeliveryType">As needed</span>
                    </div>
                    <div class="summary-row" id="summaryScheduledRow" style="display: none;">
                        <span class="summary-label">Scheduled dispatch</span>
                        <span class="summary-value" id="summaryScheduledDispatch">—</span>
                    </div>
                    <div class="summary-row" id="summaryRecurringRow" style="display: none;">
                        <span class="summary-label">Recurring</span>
                        <span class="summary-value" id="summaryRecurring">—</span>
                    </div>

                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                            <span class="spinner-border spinner-border-sm d-none" id="submitSpinner"></span>
                            <i class="bi bi-check-circle me-1"></i> Create Order
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='/orders'">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Quick Add Modals -->
<!-- Party Quick Add Modal -->
<div class="modal fade" id="quickAddPartyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quick Add Party</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="quickAddPartyForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="quickPartyName" class="form-label">Party Name *</label>
                        <input type="text" class="form-control" id="quickPartyName" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="quickContactPerson" class="form-label">Contact Person *</label>
                        <input type="text" class="form-control" id="quickContactPerson" name="contact_person" required>
                    </div>
                    <div class="mb-3">
                        <label for="quickGstNumber" class="form-label">GST No. *</label>
                        <input type="text" class="form-control text-uppercase" id="quickGstNumber" name="gst_number" required maxlength="15" placeholder="15-character GSTIN">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="quickPhone" class="form-label">Phone *</label>
                                <input type="tel" class="form-control" id="quickPhone" name="phone" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="quickEmail" class="form-label">Email *</label>
                                <input type="email" class="form-control" id="quickEmail" name="email" required>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="quickAddress" class="form-label">Address</label>
                        <textarea class="form-control" id="quickAddress" name="address" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Party</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Product Quick Add Modal -->
<div class="modal fade" id="quickAddProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quick Add Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="quickAddProductForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="quickProductCode" class="form-label">Product Code *</label>
                        <input type="text" class="form-control" id="quickProductCode" name="code" required placeholder="e.g., PROD-001">
                    </div>
                    <div class="mb-3">
                        <label for="quickProductName" class="form-label">Product Name *</label>
                        <input type="text" class="form-control" id="quickProductName" name="name" required placeholder="e.g., Portland Cement">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Raise Credit Request Modal -->
<div class="modal fade" id="creditRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Raise Credit Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="creditRequestForm">
                <div class="modal-body">
                    <div class="alert alert-warning" id="creditRequestInfo"></div>
                    <div class="mb-3">
                        <label for="requestedIncrease" class="form-label">Requested Credit Limit Increase</label>
                        <input type="number" class="form-control" id="requestedIncrease" name="requested_limit_increase" min="1" step="0.01" placeholder="e.g., 100000">
                        <div class="form-text">Suggested increase for the admin (admin decides the final amount)</div>
                    </div>
                    <div class="mb-3">
                        <label for="creditRequestReason" class="form-label">Reason</label>
                        <textarea class="form-control" id="creditRequestReason" name="reason" rows="2" maxlength="500" placeholder="Why should the credit limit be increased?"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-shield-exclamation"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const currentUserRole = <?= json_encode((string)($user['role'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let partyCreditStatus = null;

function clearNewOrderForm() {
    const form = document.getElementById('newOrderForm');
    form.reset();
    document.getElementById('orderDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('tonsPerTruck').value = '40';
    document.getElementById('deliveryTypeNone').checked = true;
    document.getElementById('creditStatusPanel').style.display = 'none';
    document.getElementById('creditStatusPanel').innerHTML = '';
    document.getElementById('billingPartyOptions').style.display = 'none';
    document.getElementById('billingToggleCard')?.classList.remove('active');
    document.getElementById('submitBtn').disabled = false;
    syncDeliveryTypeUI();
    if (typeof $.fn.select2 !== 'undefined') {
        $('#partyId').val('').trigger('change');
        $('#productId').val('').trigger('change');
        $('#billingPartyId').val('').trigger('change');
    }
    toggleQtyMode();
    updateQtyPreview();
    updateOrderSummary();
}

function updateOrderSummary() {
    const partySelect = document.getElementById('partyId');
    const productSelect = document.getElementById('productId');
    const partyText = partySelect.selectedOptions[0]?.textContent?.trim() || '—';
    const productText = productSelect.selectedOptions[0]?.textContent?.trim() || '—';
    const partyVal = partySelect.value;
    const productVal = productSelect.value;

    document.getElementById('summaryParty').textContent =
        partyVal && partyText !== 'Select party…' ? partyText : '—';
    document.getElementById('summaryProduct').textContent =
        productVal && productText !== 'Select product…' ? productText : '—';

    const previewEl = document.getElementById('qtyPreview');
    document.getElementById('summaryQty').textContent =
        previewEl && previewEl.textContent !== 'Enter quantity to see preview'
            ? previewEl.textContent
            : '—';

    const dateInput = document.getElementById('orderDate').value;
    if (dateInput) {
        document.getElementById('summaryDate').textContent = formatDate(dateInput);
    }

    const priority = document.getElementById('priority').value;
    document.getElementById('summaryPriority').innerHTML =
        priority === 'urgent'
            ? '<span class="badge bg-danger">Urgent</span>'
            : '<span class="badge bg-secondary">Normal</span>';

    const billToOther = document.getElementById('billToOtherParty').checked;
    const billingSelect = document.getElementById('billingPartyId');
    const billingText = billingSelect.selectedOptions[0]?.textContent?.trim() || '';
    document.getElementById('summaryBilling').textContent = billToOther && billingSelect.value
        ? billingText
        : 'Same as party';

    const deliveryType = document.querySelector('input[name="delivery_type"]:checked')?.value || 'none';
    const summaryDelivery = document.getElementById('summaryDeliveryType');
    const summaryScheduledRow = document.getElementById('summaryScheduledRow');
    const summaryRecurringRow = document.getElementById('summaryRecurringRow');

    summaryScheduledRow.style.display = 'none';
    summaryRecurringRow.style.display = 'none';

    if (deliveryType === 'scheduled') {
        summaryDelivery.textContent = 'Scheduled';
        summaryScheduledRow.style.display = '';
        const schedDate = document.getElementById('scheduledDispatchDate').value;
        document.getElementById('summaryScheduledDispatch').textContent = schedDate
            ? formatDate(schedDate)
            : '—';
    } else if (deliveryType === 'recurring') {
        summaryDelivery.textContent = 'Recurring';
        summaryRecurringRow.style.display = '';
        const totalDeliveries = document.getElementById('totalDeliveries').value;
        const trucksPerDelivery = document.getElementById('trucksPerDelivery').value;
        const frequency = document.getElementById('deliveryFrequency').value;
        document.getElementById('summaryRecurring').textContent = totalDeliveries
            ? `${totalDeliveries} deliveries · ${trucksPerDelivery || '?'} trucks every ${frequency || '?'} days`
            : '—';
    } else {
        summaryDelivery.textContent = 'As needed';
    }
}

function formatMoney(value) {
    return Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function checkPartyCredit(partyId) {
    const panel = document.getElementById('creditStatusPanel');
    const submitBtn = document.getElementById('submitBtn');
    partyCreditStatus = null;
    submitBtn.disabled = false;

    if (!partyId) {
        panel.style.display = 'none';
        panel.innerHTML = '';
        return;
    }

    try {
        const companyId = document.getElementById('companyId').value;
        const proposed = document.getElementById('proposedOrderValue')?.value || 0;
        const response = await apiCall(`/api/credit/evaluate?party_id=${partyId}&company_id=${companyId}&proposed_order_value=${encodeURIComponent(proposed)}`);
        const status = response.data;
        partyCreditStatus = status;

        const tier = Number(status.tier);
        const tone = tier === 1 ? 'success' : (tier === 2 ? 'warning' : 'danger');
        const label = tier === 1
            ? 'Within limit — will auto-clear on submit'
            : (tier === 2
                ? 'Up to 10% over — may proceed pending Director confirmation'
                : 'Over the 10% band, or no limit — nothing proceeds until the Director decides');
        const headroom = status.headroom == null ? '—' : formatMoney(status.headroom);
        const asOf = status.ledger_as_of || 'no contributing feed';
        const missing = (status.missing_entities || []).map(function (e) { return e.company_name || ('#' + e.company_id); }).join(', ');

        panel.innerHTML = `
            <div class="alert alert-${tone} py-2 mb-0">
                <small>
                    <strong>Tier ${tier}.</strong> ${label}<br>
                    Headroom: <strong>₹${headroom}</strong>
                    · as of ${escapeHtml(String(asOf))}${missing ? ' · missing: ' + escapeHtml(missing) : ''}
                    · not live
                </small>
            </div>`;
        panel.style.display = 'block';
        await loadPrefill(partyId);
    } catch (e) {
        panel.innerHTML = `<div class="alert alert-secondary py-2 mb-0"><small>${escapeHtml(e.message)}</small></div>`;
        panel.style.display = 'block';
    }
}

async function loadPrefill(partyId) {
    try {
        const res = await apiCall(`/api/credit/parties/${partyId}/prefill`);
        const grades = res.data.recent_grades || [];
        const deals = res.data.open_deals || [];
        const dealSel = document.getElementById('linkedDealId');
        if (dealSel) {
            dealSel.innerHTML = '<option value="">No deal — repeat order</option>' + deals.map(function (d) {
                return `<option value="${d.id}">#${d.id} stage ${d.stage}${d.grades ? ' · ' + escapeHtml(d.grades) : ''}</option>`;
            }).join('');
        }

        if (!grades.length) return;
        const first = grades[0];
        const productSel = document.getElementById('productId');
        if (productSel && !productSel.value && first.product_id) {
            if (typeof $.fn.select2 !== 'undefined') {
                $('#productId').val(String(first.product_id)).trigger('change');
            } else {
                productSel.value = String(first.product_id);
            }
            if (first.order_qty_mode === 'weight' && first.order_weight_tons) {
                document.getElementById('qtyModeWeight').checked = true;
                document.getElementById('orderWeight').value = first.order_weight_tons;
            } else if (first.order_qty_trucks) {
                document.getElementById('orderQty').value = first.order_qty_trucks;
            }
            if (first.tons_per_truck) document.getElementById('tonsPerTruck').value = first.tons_per_truck;
            toggleQtyMode();
            updateQtyPreview();
        }
        if (first.last_rate && (first.order_weight_tons || first.order_qty_trucks)) {
            const tonnes = first.order_weight_tons || (first.order_qty_trucks * (first.tons_per_truck || 40));
            const proposed = document.getElementById('proposedOrderValue');
            if (proposed && !proposed.value) proposed.value = (first.last_rate * tonnes).toFixed(2);
        }
    } catch (e) {
        /* prefill is optional */
    }
}

document.getElementById('btnAddGrade')?.addEventListener('click', function () {
    const wrap = document.getElementById('extraGrades');
    wrap.insertAdjacentHTML('beforeend', `<div class="extra-grade-row row g-2 align-items-end mb-2">
        <div class="col-md-5"><label class="form-label small">Extra grade</label>
            <select class="form-select extra-product">${document.getElementById('productId').innerHTML}</select></div>
        <div class="col-md-3"><label class="form-label small">Trucks</label>
            <input type="number" class="form-control extra-trucks" min="1"></div>
        <div class="col-md-3"><label class="form-label small">Weight MT</label>
            <input type="number" class="form-control extra-weight" step="0.001" min="0"></div>
        <div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.extra-grade-row').remove()">×</button></div>
    </div>`);
});

async function loadFormData() {
    try {
        const defaultCompanyId = <?= (int)($active_company['id'] ?? 0) ?>;
        if (defaultCompanyId <= 0) {
            showError('Please select a company using the header switcher before creating an order.');
            document.getElementById('submitBtn').disabled = true;
        }

        // Load parties
        const partiesResponse = await apiCall('/api/reports/parties');
        const partySelect = document.getElementById('partyId');
        
        partySelect.innerHTML = '<option value="">Select party…</option>';
        partiesResponse.data.forEach((party) => {
            const option = document.createElement('option');
            option.value = String(party.id ?? '');
            option.textContent = String(party.name ?? '');
            partySelect.appendChild(option);
        });

        const billingSelect = document.getElementById('billingPartyId');
        billingSelect.innerHTML = '<option value="">Select billing party…</option>';
        partiesResponse.data.forEach((party) => {
            const option = document.createElement('option');
            option.value = String(party.id ?? '');
            option.textContent = String(party.name ?? '');
            billingSelect.appendChild(option);
        });
        
        // Load products
        const productsResponse = await apiCall('/api/reports/products');
        const productSelect = document.getElementById('productId');
        
        productSelect.innerHTML = '<option value="">Select product…</option>';
        productsResponse.data.forEach((product) => {
            const option = document.createElement('option');
            option.value = String(product.id ?? '');
            option.textContent = String(product.name ?? '');
            productSelect.appendChild(option);
        });
        
        // Initialize Select2 for searchable dropdowns after a short delay
        setTimeout(() => {
            if (typeof $.fn.select2 !== 'undefined') {
                $('.searchable-select').select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    placeholder: function() {
                        return $(this).data('placeholder') || 'Select an option...';
                    },
                    allowClear: true,
                    dropdownParent: $('body')
                });
            } else {
                console.warn('Select2 not loaded, using regular dropdowns');
            }
        }, 100);
        
    } catch (error) {
        showError('Failed to load form data: ' + error.message);
    }
}

// Quick Add Modal Functions
function openQuickAddModal(type) {
    if (type === 'party') {
        new bootstrap.Modal(document.getElementById('quickAddPartyModal')).show();
    } else if (type === 'product') {
        new bootstrap.Modal(document.getElementById('quickAddProductModal')).show();
    }
}

// Quick Add Party Form Handler
document.getElementById('quickAddPartyForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = {
        name: formData.get('name'),
        contact_person: formData.get('contact_person'),
        gst_number: String(formData.get('gst_number') || '').trim().toUpperCase(),
        phone: formData.get('phone'),
        email: formData.get('email'),
        address: formData.get('address') || '',
        is_active: true
    };
    
    try {
        const response = await fetch('/api/parties', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Add new option to select
            const partySelect = document.getElementById('partyId');
            const newOption = new Option(result.data.name, result.data.id, true, true);
            partySelect.add(newOption);
            
            // Refresh Select2 if available
            if (typeof $.fn.select2 !== 'undefined') {
                $('#partyId').trigger('change');
            }
            
            // Close modal and reset form
            bootstrap.Modal.getInstance(document.getElementById('quickAddPartyModal')).hide();
            this.reset();
            
            showSuccess('Party added successfully!');
            updateOrderSummary();
        } else {
            showError(result.error || 'Error adding party');
        }
    } catch (error) {
        showError('Error adding party: ' + error.message);
    }
});

// Quick Add Product Form Handler
document.getElementById('quickAddProductForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = {
        code: formData.get('code'),
        name: formData.get('name'),
        is_active: true
    };
    
    try {
        const response = await fetch('/api/products', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Add new option to select
            const productSelect = document.getElementById('productId');
            const newOption = new Option(result.data.name, result.data.id, true, true);
            productSelect.add(newOption);
            
            // Refresh Select2 if available
            if (typeof $.fn.select2 !== 'undefined') {
                $('#productId').trigger('change');
            }
            
            // Close modal and reset form
            bootstrap.Modal.getInstance(document.getElementById('quickAddProductModal')).hide();
            this.reset();
            
            showSuccess('Product added successfully!');
            updateOrderSummary();
        } else {
            showError('Error adding product: ' + result.error);
        }
    } catch (error) {
        showError('Error adding product: ' + error.message);
    }
});

document.getElementById('newOrderForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const submitBtn = document.getElementById('submitBtn');
    const submitSpinner = document.getElementById('submitSpinner');
    const formData = new FormData(this);
    
    // Show loading state
    submitBtn.disabled = true;
    submitSpinner.classList.remove('d-none');
    
    try {
        const qtyMode = formData.get('order_qty_mode') || 'trucks';
        const deliveryType = formData.get('delivery_type') || 'none';
        const billToOtherParty = formData.has('bill_to_other_party');
        const extraLines = Array.from(document.querySelectorAll('.extra-grade-row')).map(function (row) {
            const pid = parseInt(row.querySelector('.extra-product')?.value || '0', 10);
            if (!pid) return null;
            const trucks = parseInt(row.querySelector('.extra-trucks')?.value || '0', 10);
            const weight = parseFloat(row.querySelector('.extra-weight')?.value || '0');
            return {
                product_id: pid,
                order_qty_mode: weight > 0 ? 'weight' : 'trucks',
                order_qty_trucks: trucks || null,
                order_weight_tons: weight || null,
                tons_per_truck: parseFloat(formData.get('tons_per_truck')) || 40
            };
        }).filter(Boolean);

        const orderData = {
            company_id: parseInt(formData.get('company_id')),
            order_date: formData.get('order_date'),
            product_id: parseInt(formData.get('product_id')),
            order_qty_mode: qtyMode,
            order_qty_trucks: qtyMode === 'trucks' ? parseInt(formData.get('order_qty_trucks')) : null,
            order_weight_tons: qtyMode === 'weight' ? parseFloat(formData.get('order_weight_tons')) : null,
            tons_per_truck: parseFloat(formData.get('tons_per_truck')) || 40,
            party_id: parseInt(formData.get('party_id')),
            bill_to_other_party: billToOtherParty,
            billing_party_id: billToOtherParty && formData.get('billing_party_id')
                ? parseInt(formData.get('billing_party_id'))
                : null,
            priority: formData.get('priority'),
            has_scheduled_dispatch: deliveryType === 'scheduled',
            scheduled_dispatch_date: deliveryType === 'scheduled' ? (formData.get('scheduled_dispatch_date') || null) : null,
            is_recurring: deliveryType === 'recurring',
            trucks_per_delivery: deliveryType === 'recurring' && formData.get('trucks_per_delivery')
                ? parseInt(formData.get('trucks_per_delivery'))
                : null,
            delivery_frequency_days: deliveryType === 'recurring' && formData.get('delivery_frequency_days')
                ? parseInt(formData.get('delivery_frequency_days'))
                : null,
            total_deliveries: deliveryType === 'recurring' && formData.get('total_deliveries')
                ? parseInt(formData.get('total_deliveries'))
                : null,
            proposed_order_value: formData.get('proposed_order_value') ? parseFloat(formData.get('proposed_order_value')) : 0,
            rep_reason: (formData.get('rep_reason') || '').trim(),
            deal_id: formData.get('deal_id') ? parseInt(formData.get('deal_id')) : null,
            lines: extraLines.length ? [{
                product_id: parseInt(formData.get('product_id')),
                order_qty_mode: qtyMode,
                order_qty_trucks: qtyMode === 'trucks' ? parseInt(formData.get('order_qty_trucks')) : null,
                order_weight_tons: qtyMode === 'weight' ? parseFloat(formData.get('order_weight_tons')) : null,
                tons_per_truck: parseFloat(formData.get('tons_per_truck')) || 40,
                scheduled_dispatch_date: deliveryType === 'scheduled' ? (formData.get('scheduled_dispatch_date') || null) : null
            }].concat(extraLines) : undefined
        };
        
        const response = await fetch('/api/credit/capture', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': formData.get('csrf_token')
            },
            body: JSON.stringify(orderData)
        });
        const result = await response.json();

        if (!response.ok) {
            if (result.evaluation) {
                partyCreditStatus = result.evaluation;
                await checkPartyCredit(orderData.party_id);
            }
            throw new Error(result.error || 'Failed to create order');
        }

        const first = (result.data && result.data.orders && result.data.orders[0]) || {};
        showSuccess((result.message || 'Order captured') + (first.order_no ? '! Order No: ' + first.order_no : ''));
        
        clearNewOrderForm();
        
        setTimeout(() => {
            window.location.href = '/orders';
        }, 2000);
        
    } catch (error) {
        showError(error.message);
    } finally {
        submitBtn.disabled = false;
        submitSpinner.classList.add('d-none');
    }
});

function syncDeliveryTypeUI() {
    const deliveryType = document.querySelector('input[name="delivery_type"]:checked')?.value || 'none';
    const scheduleOptions = document.getElementById('scheduleDispatchOptions');
    const recurringOptions = document.getElementById('recurringOptions');
    const dateInput = document.getElementById('scheduledDispatchDate');
    const trucksPerDelivery = document.getElementById('trucksPerDelivery');
    const deliveryFrequency = document.getElementById('deliveryFrequency');
    const totalDeliveries = document.getElementById('totalDeliveries');
    const orderDate = document.getElementById('orderDate').value;

    scheduleOptions.style.display = deliveryType === 'scheduled' ? 'block' : 'none';
    recurringOptions.style.display = deliveryType === 'recurring' ? 'block' : 'none';

    dateInput.required = deliveryType === 'scheduled';
    trucksPerDelivery.required = deliveryType === 'recurring';
    deliveryFrequency.required = deliveryType === 'recurring';
    totalDeliveries.required = deliveryType === 'recurring';

    if (deliveryType === 'scheduled') {
        if (!dateInput.value && orderDate) {
            dateInput.value = orderDate;
        }
        dateInput.min = orderDate || '';
    } else {
        dateInput.value = '';
    }

    if (deliveryType !== 'recurring') {
        document.getElementById('deliveryPreview').style.display = 'none';
        totalDeliveries.value = '';
    } else {
        updateDeliveryPreview();
    }

    updateOrderSummary();
}

document.querySelectorAll('input[name="delivery_type"]').forEach(radio => {
    radio.addEventListener('change', syncDeliveryTypeUI);
});

document.getElementById('billToOtherParty').addEventListener('change', function() {
    const options = document.getElementById('billingPartyOptions');
    const card = document.getElementById('billingToggleCard');
    const billingSelect = document.getElementById('billingPartyId');

    if (this.checked) {
        options.style.display = 'block';
        card?.classList.add('active');
        billingSelect.required = true;
    } else {
        options.style.display = 'none';
        card?.classList.remove('active');
        billingSelect.required = false;
        billingSelect.value = '';
        if (typeof $.fn.select2 !== 'undefined') {
            $('#billingPartyId').trigger('change');
        }
    }
    updateOrderSummary();
});

document.getElementById('billingPartyId').addEventListener('change', updateOrderSummary);

document.getElementById('scheduledDispatchDate').addEventListener('change', updateOrderSummary);

document.getElementById('orderDate').addEventListener('change', function() {
    const sched = document.getElementById('scheduledDispatchDate');
    sched.min = this.value;
    if (sched.value && sched.value < this.value) {
        sched.value = this.value;
    }
    updateOrderSummary();
});

// Handle recurring delivery preview
function updateDeliveryPreview() {
    const orderDate = document.getElementById('orderDate').value;
    const trucksPerDelivery = parseInt(document.getElementById('trucksPerDelivery').value);
    const deliveryFrequency = parseInt(document.getElementById('deliveryFrequency').value);
    const totalTrucks = parseInt(document.getElementById('orderQty').value);
    
    if (!orderDate || !trucksPerDelivery || !deliveryFrequency || !totalTrucks) {
        document.getElementById('deliveryPreview').style.display = 'none';
        document.getElementById('totalDeliveries').value = '';
        return;
    }
    
    // Auto-calculate total deliveries
    const totalDeliveries = Math.ceil(totalTrucks / trucksPerDelivery);
    document.getElementById('totalDeliveries').value = totalDeliveries;
    
    // Generate preview schedule with proper truck distribution
    let currentDate = new Date(orderDate);
    let scheduleHtml = '<div class="row">';
    let remainingTrucks = totalTrucks;
    
    for (let i = 1; i <= Math.min(totalDeliveries, 5); i++) {
        let trucksForThisDelivery;
        
        if (i === totalDeliveries) {
            // Last delivery gets remaining trucks (handles odd figures)
            trucksForThisDelivery = remainingTrucks;
        } else {
            // Regular delivery gets standard quantity
            trucksForThisDelivery = Math.min(trucksPerDelivery, remainingTrucks);
            remainingTrucks -= trucksForThisDelivery;
        }
        
        scheduleHtml += `
            <div class="col-md-6 mb-2">
                <strong>Delivery ${i}:</strong> ${formatDate(currentDate.toISOString().slice(0, 10))} - ${trucksForThisDelivery} trucks
                ${i === totalDeliveries && trucksForThisDelivery !== trucksPerDelivery ? '<span class="text-info">(adjusted)</span>' : ''}
            </div>
        `;
        currentDate.setDate(currentDate.getDate() + deliveryFrequency);
    }
    
    if (totalDeliveries > 5) {
        scheduleHtml += `<div class="col-12"><em>... and ${totalDeliveries - 5} more deliveries</em></div>`;
    }
    
    scheduleHtml += '</div>';
    scheduleHtml += `<div class="mt-2 text-muted"><small>Total: ${totalTrucks} trucks across ${totalDeliveries} deliveries</small></div>`;
    
    document.getElementById('schedulePreview').innerHTML = scheduleHtml;
    document.getElementById('deliveryPreview').style.display = 'block';
}

// Add event listeners for preview updates
['trucksPerDelivery', 'deliveryFrequency', 'orderQty', 'orderWeight', 'tonsPerTruck', 'orderDate'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', () => { updateQtyPreview(); updateDeliveryPreview(); updateOrderSummary(); });
});

document.getElementById('orderDate').addEventListener('change', updateOrderSummary);
document.getElementById('priority').addEventListener('change', updateOrderSummary);

document.querySelectorAll('input[name="order_qty_mode"]').forEach(radio => {
    radio.addEventListener('change', toggleQtyMode);
});

function toggleQtyMode() {
    const mode = document.querySelector('input[name="order_qty_mode"]:checked')?.value || 'trucks';
    const trucksGroup = document.getElementById('trucksQtyGroup');
    const weightGroup = document.getElementById('weightQtyGroup');
    const orderQty = document.getElementById('orderQty');
    const orderWeight = document.getElementById('orderWeight');

    if (mode === 'weight') {
        trucksGroup.style.display = 'none';
        weightGroup.style.display = 'block';
        orderQty.removeAttribute('required');
        orderWeight.setAttribute('required', 'required');
    } else {
        trucksGroup.style.display = 'block';
        weightGroup.style.display = 'none';
        orderWeight.removeAttribute('required');
        orderQty.setAttribute('required', 'required');
    }
    updateQtyPreview();
    updateOrderSummary();
}

function updateQtyPreview() {
    const mode = document.querySelector('input[name="order_qty_mode"]:checked')?.value || 'trucks';
    const tonsPerTruck = parseFloat(document.getElementById('tonsPerTruck').value) || 40;
    const preview = document.getElementById('qtyPreview');
    if (!preview) return;

    if (mode === 'weight') {
        const weight = parseFloat(document.getElementById('orderWeight').value) || 0;
        const trucks = weight > 0 ? Math.max(1, Math.ceil(weight / tonsPerTruck)) : 0;
        preview.textContent = weight > 0
            ? `${weight.toLocaleString('en-IN')} MT ≈ ${trucks} truck(s) @ ${tonsPerTruck} MT/truck`
            : 'Enter weight in metric tons';
    } else {
        const trucks = parseInt(document.getElementById('orderQty').value) || 0;
        const weight = trucks > 0 ? (trucks * tonsPerTruck).toLocaleString('en-IN') : '0';
        preview.textContent = trucks > 0
            ? `${trucks} truck(s) ≈ ${weight} MT @ ${tonsPerTruck} MT/truck`
            : 'Enter number of trucks';
    }
    updateOrderSummary();
}

// Load form data on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleQtyMode();
    updateQtyPreview();
    syncDeliveryTypeUI();
    loadFormData();

    // Credit gate check on party selection (jQuery event covers Select2 too)
    $('#partyId').on('change', function() {
        checkPartyCredit(this.value);
        updateOrderSummary();
    });
    $('#productId').on('change', updateOrderSummary);
    const proposedEl = document.getElementById('proposedOrderValue');
    if (proposedEl) {
        proposedEl.addEventListener('change', function () {
            const partyId = document.getElementById('partyId').value;
            if (partyId) checkPartyCredit(partyId);
        });
    }
    updateOrderSummary();
});
</script>
