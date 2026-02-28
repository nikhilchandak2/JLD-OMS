<!-- Export Documents (Nepal) – standalone module. Not linked to OMS orders, tracking, or admin. -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="page-title">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i>Nepal Export Documents
            </h1>
            <p class="page-subtitle">Generate Commercial Invoice, Tax Invoice, Packing List and related docs for Nepal export (LUT). Separate from Orders &amp; Vehicle Tracking.</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newExportOrderModal">
            <i class="bi bi-plus-circle me-1"></i> New Export Order
        </button>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="error-message"></div>

<div class="alert alert-info border-0 mb-4">
    <strong><i class="bi bi-info-circle me-2"></i>Separate system</strong><br>
    This section is only for <strong>Nepal export documentation</strong>. It does not use OMS Orders, Dispatches, or Vehicle Tracking. You maintain export orders and dispatch details here; one click generates all required documents in a single Excel file.
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Export orders</span>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="loadExportOrders()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">Export orders hold fixed data per Nepal order: consignee, LC details, terms, product description. Each dispatch (trucks, LR no., weight, amount) uses that order and produces one document pack.</p>
                <div id="export-orders-list">
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-inbox fs-1"></i>
                        <p class="mt-2 mb-0">Loading export orders…</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Generate dispatch pack</div>
            <div class="card-body">
                <p class="text-muted small">Select an export order and enter this dispatch’s details (trucks, LR no., weight, amount). One click generates Commercial Invoice and Packing List in one Excel file.</p>
                <button type="button" class="btn btn-primary w-100" id="btnGeneratePack" data-bs-toggle="modal" data-bs-target="#generatePackModal">
                    <i class="bi bi-file-earmark-zip me-1"></i> Generate documents
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: New Export Order -->
<div class="modal fade" id="newExportOrderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>New Export Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="newExportOrderForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Reference No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="reference_no" required placeholder="e.g. NEPAL/EXP-045">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Buyer PO No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="buyer_po_no" required placeholder="e.g. PO NO. HO/PO.19/80-81">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Buyer PO Date</label>
                                <input type="date" class="form-control" name="buyer_po_date">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Consignee</label>
                                <input type="text" class="form-control" name="consignee" placeholder="e.g. TO THE ORDER OF HIMALAYAN BANK LTD">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notify Applicant</label>
                        <input type="text" class="form-control" name="notify_applicant" placeholder="e.g. ARIHANT INFRASTRUCTURE LIMITED">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">PAN No.</label>
                                <input type="text" class="form-control" name="pan_no">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">EXIM Code</label>
                                <input type="text" class="form-control" name="exim_code">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">LC Number</label>
                                <input type="text" class="form-control" name="lc_number" placeholder="e.g. HBLIRIC05801794">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">LC Issue Date</label>
                                <input type="date" class="form-control" name="lc_issue_date">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Harmonic Code</label>
                                <input type="text" class="form-control" name="harmonic_code" placeholder="e.g. 2529.10.10">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Customs Entry</label>
                                <input type="text" class="form-control" name="customs_entry" placeholder="e.g. BIRATNAGAR CUSTOMS OFFICE, NEPAL">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Terms</label>
                        <input type="text" class="form-control" name="payment_terms" placeholder="e.g. 180 DAYS AT SIGHT">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Delivery Terms</label>
                        <input type="text" class="form-control" name="delivery_terms" placeholder="e.g. CPT JOGBANI LAND PORT, INDIA">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Product Description</label>
                        <input type="text" class="form-control" name="product_description" placeholder="e.g. INDUSTRIAL RAW MATERIAL FOR TILES INDUSTRY, FELDSPAR">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Packaging</label>
                        <input type="text" class="form-control" name="packaging" placeholder="e.g. Packed In Small Bags">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveExportOrder">
                    <span class="spinner-border spinner-border-sm d-none" id="saveOrderSpinner"></span>
                    <i class="bi bi-check-lg d-inline" id="saveOrderIcon"></i> Save Export Order
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Generate Dispatch Pack -->
<div class="modal fade" id="generatePackModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-zip me-2"></i>Generate Dispatch Pack</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="generatePackForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
                    <div class="mb-3">
                        <label class="form-label">Export Order <span class="text-danger">*</span></label>
                        <select class="form-select" id="packExportOrderId" name="export_order_id" required>
                            <option value="">Select export order...</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Invoice No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="invoice_no" required placeholder="e.g. NEPAL/EXP-045">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Invoice Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="invoice_date" required value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Rate per MT (INR)</label>
                                <input type="number" class="form-control" name="rate_per_mt" step="0.01" placeholder="e.g. 5200">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Amount (INR)</label>
                                <input type="number" class="form-control" name="amount" step="0.01" placeholder="Auto from trucks or enter">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Shipping Bill No.</label>
                                <input type="text" class="form-control" name="shipping_bill_no">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Shipping Bill Date</label>
                                <input type="date" class="form-control" name="shipping_bill_date">
                            </div>
                        </div>
                    </div>
                    <hr>
                    <h6 class="mb-3">Trucks <span class="text-danger">*</span></h6>
                    <div id="trucksContainer">
                        <div class="truck-row card mb-2 p-3">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <input type="text" class="form-control form-control-sm inp-truck-no" placeholder="Truck No.">
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control form-control-sm inp-lr-no" placeholder="LR No.">
                                </div>
                                <div class="col-md-2">
                                    <input type="date" class="form-control form-control-sm inp-truck-date" placeholder="Date">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" class="form-control form-control-sm inp-qty-mt" placeholder="Qty MT" step="0.001">
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control form-control-sm inp-bags" placeholder="Bags">
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-truck" title="Remove"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddTruck">
                        <i class="bi bi-plus"></i> Add truck
                    </button>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnGenerate">
                    <span class="spinner-border spinner-border-sm d-none" id="generateSpinner"></span>
                    <i class="bi bi-download d-inline" id="generateIcon"></i> Generate & Download
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const csrf = '<?= htmlspecialchars($csrf_token ?? '') ?>';

    function loadExportOrders() {
        const listEl = document.getElementById('export-orders-list');
        if (!listEl) return;
        listEl.innerHTML = '<div class="text-center py-3 text-muted"><span class="spinner-border spinner-border-sm"></span> Loading...</div>';
        fetch('/api/export/orders', { headers: { 'X-CSRF-Token': csrf } })
            .then(r => r.json())
            .then(data => {
                if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                    listEl.innerHTML = '<ul class="list-group list-group-flush">' + data.data.map(o =>
                        '<li class="list-group-item d-flex justify-content-between align-items-center">' +
                        '<span>' + (o.reference_no || o.buyer_po_no || 'Export #' + o.id) + '</span>' +
                        '<span class="badge bg-secondary">' + (o.consignee || '—').substring(0, 30) + (o.consignee && o.consignee.length > 30 ? '…' : '') + '</span></li>'
                    ).join('') + '</ul>';
                    populateExportOrderSelect(data.data);
                } else {
                    listEl.innerHTML = '<p class="text-muted mb-0">No export orders yet. Click "New Export Order" to create one.</p>';
                    document.getElementById('packExportOrderId').innerHTML = '<option value="">Select export order...</option>';
                }
            })
            .catch(() => {
                listEl.innerHTML = '<p class="text-muted mb-0">Could not load export orders.</p>';
            });
    }

    function populateExportOrderSelect(orders) {
        const sel = document.getElementById('packExportOrderId');
        sel.innerHTML = '<option value="">Select export order...</option>' + orders.map(o =>
            '<option value="' + o.id + '">' + (o.reference_no || o.buyer_po_no) + ' - ' + (o.consignee || '').substring(0, 40) + '</option>'
        ).join('');
    }

    document.getElementById('btnSaveExportOrder').addEventListener('click', function() {
        const btn = this;
        const form = document.getElementById('newExportOrderForm');
        const fd = new FormData(form);
        const data = {};
        fd.forEach((v, k) => { if (k !== 'csrf_token') data[k] = v || null; });
        if (!data.reference_no || !data.buyer_po_no) {
            showError('Reference No. and Buyer PO No. are required', 'error-container');
            return;
        }
        btn.disabled = true;
        document.getElementById('saveOrderSpinner').classList.remove('d-none');
        document.getElementById('saveOrderIcon').classList.add('d-none');
        fetch('/api/export/orders', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(data)
        })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('newExportOrderModal')).hide();
                    form.reset();
                    loadExportOrders();
                    showSuccess('Export order created successfully.', 'success-container');
                } else {
                    showError(res.error || 'Failed to create export order', 'error-container');
                }
            })
            .catch(() => showError('Request failed', 'error-container'))
            .finally(() => {
                btn.disabled = false;
                document.getElementById('saveOrderSpinner').classList.add('d-none');
                document.getElementById('saveOrderIcon').classList.remove('d-none');
            });
    });

    document.getElementById('btnAddTruck').addEventListener('click', function() {
        const tpl = '<div class="truck-row card mb-2 p-3">' +
            '<div class="row g-2">' +
            '<div class="col-md-3"><input type="text" class="form-control form-control-sm inp-truck-no" placeholder="Truck No."></div>' +
            '<div class="col-md-2"><input type="text" class="form-control form-control-sm inp-lr-no" placeholder="LR No."></div>' +
            '<div class="col-md-2"><input type="date" class="form-control form-control-sm inp-truck-date" placeholder="Date"></div>' +
            '<div class="col-md-2"><input type="number" class="form-control form-control-sm inp-qty-mt" placeholder="Qty MT" step="0.001"></div>' +
            '<div class="col-md-2"><input type="text" class="form-control form-control-sm inp-bags" placeholder="Bags"></div>' +
            '<div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger remove-truck" title="Remove"><i class="bi bi-trash"></i></button></div>' +
            '</div></div>';
        document.getElementById('trucksContainer').insertAdjacentHTML('beforeend', tpl);
    });

    document.getElementById('trucksContainer').addEventListener('click', function(e) {
        if (e.target.closest('.remove-truck')) {
            const row = e.target.closest('.truck-row');
            if (document.querySelectorAll('.truck-row').length > 1) row.remove();
        }
    });

    document.getElementById('btnGenerate').addEventListener('click', function() {
        const form = document.getElementById('generatePackForm');
        const exportOrderId = document.getElementById('packExportOrderId').value;
        const invoiceNo = form.querySelector('[name="invoice_no"]').value;
        const invoiceDate = form.querySelector('[name="invoice_date"]').value;
        const ratePerMt = form.querySelector('[name="rate_per_mt"]').value;
        const amount = form.querySelector('[name="amount"]').value;
        const shippingBillNo = form.querySelector('[name="shipping_bill_no"]').value;
        const shippingBillDate = form.querySelector('[name="shipping_bill_date"]').value;

        const trucks = [];
        document.querySelectorAll('.truck-row').forEach(row => {
            const no = row.querySelector('.inp-truck-no')?.value || '';
            const lr = row.querySelector('.inp-lr-no')?.value || '';
            const dt = row.querySelector('.inp-truck-date')?.value || '';
            const qty = row.querySelector('.inp-qty-mt')?.value || '';
            const bags = row.querySelector('.inp-bags')?.value || '';
            if (no || lr || qty) trucks.push({ truck_no: no, lr_no: lr, date: dt, qty_mt: qty, bags: bags });
        });

        if (!exportOrderId || !invoiceNo || !invoiceDate) {
            showError('Export order, Invoice No., and Invoice Date are required.', 'error-container');
            return;
        }
        if (trucks.length === 0) {
            showError('Add at least one truck with details.', 'error-container');
            return;
        }

        const totalWeight = trucks.reduce((s, t) => s + parseFloat(t.qty_mt || 0), 0);
        const amt = amount ? parseFloat(amount) : (ratePerMt ? totalWeight * parseFloat(ratePerMt) : null);

        const dispatch = {
            invoice_no: invoiceNo,
            invoice_date: invoiceDate,
            trucks: trucks,
            total_weight_mt: totalWeight,
            rate_per_mt: ratePerMt || null,
            amount: amt,
            shipping_bill_no: shippingBillNo || null,
            shipping_bill_date: shippingBillDate || null
        };

        const btn = this;
        btn.disabled = true;
        document.getElementById('generateSpinner').classList.remove('d-none');
        document.getElementById('generateIcon').classList.add('d-none');

        fetch('/api/export/dispatch-pack', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ export_order_id: parseInt(exportOrderId), dispatch: dispatch })
        })
            .then(r => r.json())
            .then(res => {
                if (res.success && res.download_url) {
                    window.location.href = res.download_url;
                    bootstrap.Modal.getInstance(document.getElementById('generatePackModal')).hide();
                    showSuccess('Documents generated. Download started.', 'success-container');
                } else {
                    showError(res.error || res.message || 'Failed to generate documents', 'error-container');
                }
            })
            .catch(() => showError('Request failed', 'error-container'))
            .finally(() => {
                btn.disabled = false;
                document.getElementById('generateSpinner').classList.add('d-none');
                document.getElementById('generateIcon').classList.remove('d-none');
            });
    });

    document.getElementById('newExportOrderModal').addEventListener('hidden.bs.modal', function() {
        document.getElementById('error-container').innerHTML = '';
        document.getElementById('success-container').innerHTML = '';
    });

    loadExportOrders();
})();
</script>
