<?php
/**
 * Shared DataAsOfBanner placeholder. JS hydrates it from /api/data-feeds/as-of.
 *
 * Amber = past the configured deadline. Red = more than one business day old
 * or a missing entity feed. Never implies live ledger/dispatch data.
 *
 * @var string $feedKey ledger|dispatch_day_file
 * @var string $mode group|company
 * @var int|null $companyId
 */
$feedKey = $feedKey ?? 'ledger';
$mode = $mode ?? 'group';
$companyId = $companyId ?? null;
?>
<div class="data-as-of-banner"
     data-feed-key="<?= htmlspecialchars($feedKey, ENT_QUOTES, 'UTF-8') ?>"
     data-mode="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>"
     <?php if ($companyId): ?>data-company-id="<?= (int)$companyId ?>"<?php endif; ?>
     role="status">
    Loading data as-of…
</div>
