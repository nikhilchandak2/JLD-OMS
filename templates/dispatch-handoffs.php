<?php include __DIR__ . '/partials/dispatch-nav.php'; ?>

<div class="page-header mb-3">
    <h1 class="page-title"><i class="bi bi-arrow-left-right me-2"></i>Handoff packets</h1>
    <p class="page-subtitle mb-0">
        Fields transferred at a seam are the source of truth.
        <strong>No packet field is a re-entry input for the receiving team.</strong>
        Dispatch acknowledges Sales packets as written. Accounts acknowledges Dispatch packets as written.
        Print the Dispatch→Accounts PDF for the person doing the Busy (offline) invoice entry.
    </p>
</div>

<div id="handoffError" class="alert alert-danger d-none" role="alert"></div>
<div id="handoffNotice" class="alert alert-success d-none" role="alert"></div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabSales" type="button">Sales → Dispatch</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabAccounts" type="button">Dispatch → Accounts</button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tabSales">
        <div class="alert alert-info py-2">
            Read-only. Acknowledge only. Do not re-type grade, quantity, packing, timeline, terms, or handling notes.
        </div>
        <div id="salesInbox" class="row g-3"></div>
    </div>
    <div class="tab-pane fade" id="tabAccounts">
        <?php if (!empty($can_create_accounts)): ?>
        <div class="card mb-3">
            <div class="card-header">Create Dispatch → Accounts packet</div>
            <div class="card-body">
                <p class="small text-muted">Captured here even though the invoice is raised in Busy. This packet is the defined source of truth for the manual bridge.</p>
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small" for="accOrderId">Order ID</label>
                        <input type="number" class="form-control" id="accOrderId" min="1">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="accDispatchId">Dispatch ID (optional)</label>
                        <input type="number" class="form-control" id="accDispatchId" min="1">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="accDeliveryDate">Dispatch / delivery date</label>
                        <input type="date" class="form-control" id="accDeliveryDate">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="accInvoice">Invoice reference</label>
                        <input type="text" class="form-control" id="accInvoice" placeholder="Busy invoice no.">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="accQuote">Linked quote</label>
                        <input type="text" class="form-control" id="accQuote">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="accTerms">Agreed terms</label>
                        <input type="text" class="form-control" id="accTerms">
                    </div>
                </div>
                <button class="btn btn-primary mt-3" type="button" id="btnCreateAccounts">Create packet</button>
            </div>
        </div>
        <?php endif; ?>
        <div id="accountsInbox" class="row g-3"></div>
    </div>
</div>

<script>
window.HANDOFF_PAGE = {
    canAckSales: <?= !empty($can_ack_sales) ? 'true' : 'false' ?>,
    canCreateAccounts: <?= !empty($can_create_accounts) ? 'true' : 'false' ?>,
    canAckAccounts: <?= !empty($can_ack_accounts) ? 'true' : 'false' ?>
};
</script>
