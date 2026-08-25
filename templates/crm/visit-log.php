<?php
$party_id = (int)($party_id ?? 0);
$deal_id = (int)($deal_id ?? 0);
?>
<!-- Tap count with party pre-selected: 6 (open → person → purpose mic → outcome mic → date → save). Target under 60s one-handed. -->
<div class="visit-log-page">
    <div class="page-header mb-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
                <li class="breadcrumb-item active">Log visit</li>
            </ol>
        </nav>
        <h1 class="page-title mb-0">Log visit</h1>
        <p class="text-muted small mb-0">From the car: pick who you met, what happened, and when you will touch them next.</p>
    </div>

    <div id="error-container" class="error-message mb-3"></div>
    <div id="visitDraftBanner" class="alert alert-warning d-none" role="status">
        A draft is saved on this phone. Submission failed — tap Save to retry. It will not vanish.
        <button type="button" class="btn btn-sm btn-outline-dark ms-2" id="btnRetryDraft">Retry now</button>
    </div>
    <div id="visitNotice" class="alert alert-success d-none" role="status"></div>

    <form id="visitLogForm" class="visit-log-form" novalidate>
        <input type="hidden" name="deal_id" id="visitDealId" value="<?= $deal_id > 0 ? $deal_id : '' ?>">

        <div class="mb-3">
            <label class="form-label fw-semibold" for="visitPartySearch">Customer *</label>
            <input type="hidden" name="party_id" id="visitPartyId" value="<?= $party_id > 0 ? $party_id : '' ?>">
            <input type="search" class="form-control form-control-lg" id="visitPartySearch" placeholder="Search company…" autocomplete="off" <?= $party_id > 0 ? 'readonly' : '' ?>>
            <div class="list-group mt-1" id="visitPartyResults"></div>
            <div class="form-text" id="visitPartyName"></div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Person met</label>
            <div id="visitContactList" class="visit-contact-list"><p class="text-muted small mb-0">Pick a customer first.</p></div>
            <button type="button" class="btn btn-outline-primary w-100 mt-2 py-2" id="btnInlineContact" disabled>
                <i class="bi bi-person-plus me-1"></i>Add a new contact
            </button>
            <div id="inlineContactFields" class="border rounded p-2 mt-2 d-none">
                <input class="form-control form-control-lg mb-2" id="newContactName" placeholder="Name *">
                <input class="form-control mb-2" id="newContactRole" placeholder="Role">
                <input class="form-control mb-2" id="newContactPhone" placeholder="Phone" inputmode="tel">
                <button type="button" class="btn btn-primary" id="btnSaveInlineContact">Add to this visit</button>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold" for="visitDate">Visit date</label>
            <input type="date" class="form-control form-control-lg" id="visitDate" name="visit_date" required>
        </div>

        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <label class="form-label fw-semibold mb-0" for="visitPurpose">Purpose</label>
                <button type="button" class="btn btn-sm btn-outline-secondary visit-mic" data-target="visitPurpose" aria-label="Dictate purpose" hidden>
                    <i class="bi bi-mic"></i> Speak
                </button>
            </div>
            <textarea class="form-control" id="visitPurpose" name="purpose" rows="3" placeholder="Why you went"></textarea>
        </div>

        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <label class="form-label fw-semibold mb-0" for="visitOutcome">Outcome</label>
                <button type="button" class="btn btn-sm btn-outline-secondary visit-mic" data-target="visitOutcome" aria-label="Dictate outcome" hidden>
                    <i class="bi bi-mic"></i> Speak
                </button>
            </div>
            <textarea class="form-control" id="visitOutcome" name="outcome" rows="3" placeholder="What was agreed"></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold" for="visitTouchpoint">Next planned touchpoint *</label>
            <input type="date" class="form-control form-control-lg" id="visitTouchpoint" name="next_planned_touchpoint">
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="visitNoFollowup" name="no_followup_needed">
                <label class="form-check-label" for="visitNoFollowup">No follow-up needed</label>
            </div>
            <textarea class="form-control mt-2 d-none" id="visitNoFollowupReason" name="no_followup_reason" rows="2" placeholder="Why no follow-up? (required)"></textarea>
        </div>

        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <label class="form-label fw-semibold mb-0" for="visitNextAction">Next action</label>
                <button type="button" class="btn btn-sm btn-outline-secondary visit-mic" data-target="visitNextAction" aria-label="Dictate next action" hidden>
                    <i class="bi bi-mic"></i> Speak
                </button>
            </div>
            <textarea class="form-control" id="visitNextAction" name="next_action" rows="2" placeholder="Optional"></textarea>
        </div>

        <button type="submit" class="btn btn-success btn-lg w-100 py-3 visit-save" id="btnSaveVisit">Save visit</button>
    </form>
</div>
