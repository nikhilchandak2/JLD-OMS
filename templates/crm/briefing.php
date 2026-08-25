<?php
$partyId = (int)($party_id ?? 0);
$canWriteHandover = !empty($can_write_handover);
$reviewDate = (require dirname(__DIR__, 2) . '/config/briefing.php')['handover_notes_review_date'] ?? '';
?>
<style>
.briefing-page { max-width: 40rem; margin: 0 auto; }
.briefing-card { border: 1px solid var(--jld-border, #ddd); border-radius: 0.75rem; padding: 1rem; margin-bottom: 0.75rem; background: #fff; }
.briefing-card h2 { font-size: 0.95rem; margin: 0 0 0.5rem; color: var(--jld-primary, #2b235e); }
.briefing-empty { color: #6c5b2e; background: #fff8e1; border-radius: 0.5rem; padding: 0.65rem 0.75rem; font-size: 0.9rem; }
.briefing-empty a { font-weight: 600; }
.briefing-row { padding: 0.5rem 0; border-bottom: 1px solid #eee; }
.briefing-row:last-child { border-bottom: 0; }
.briefing-actions .btn { min-height: 44px; }
.briefing-transitional { font-size: 0.8rem; color: #7a5c00; background: #fff3cd; border-radius: 0.4rem; padding: 0.4rem 0.6rem; margin-bottom: 0.5rem; }
@media (min-width: 768px) {
    .briefing-page { max-width: 52rem; }
}
</style>

<div class="briefing-page">
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item"><a href="/crm/parties/<?= $partyId ?>">Account</a></li>
            <li class="breadcrumb-item active">Briefing</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h1 class="page-title mb-0" id="briefingTitle">Account briefing</h1>
            <p class="text-muted small mb-0">Read this before you walk in. Empty panels mean the fact has not been recorded yet.</p>
        </div>
        <div class="briefing-actions d-flex gap-2">
            <a class="btn btn-outline-secondary" id="briefingPdf" href="/api/crm/parties/<?= $partyId ?>/briefing/pdf">Print PDF</a>
        </div>
    </div>

    <div id="briefingError" class="alert alert-danger d-none"></div>
    <?php $feedKey = 'ledger'; $mode = 'group'; include dirname(__DIR__) . '/partials/data-as-of-banner.php'; ?>
    <?php $feedKey = 'dispatch_day_file'; $mode = 'group'; include dirname(__DIR__) . '/partials/data-as-of-banner.php'; ?>

    <div id="briefingRoot">Loading…</div>
</div>

<script>
window.BRIEFING = {
    partyId: <?= $partyId ?>,
    canWriteHandover: <?= $canWriteHandover ? 'true' : 'false' ?>,
    reviewDate: <?= json_encode($reviewDate) ?>
};
</script>
<script src="/js/briefing.js"></script>
