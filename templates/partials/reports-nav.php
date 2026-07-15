<?php
$reportsUri = $_SERVER['REQUEST_URI'] ?? '';
$onPartywise = strpos($reportsUri, '/reports/daily-dispatch') === false && strpos($reportsUri, '/reports') === 0;
$onDailyDispatch = strpos($reportsUri, '/reports/daily-dispatch') === 0;
?>
<ul class="nav nav-pills mb-4">
    <?php if (in_array($user['role'] ?? '', ['admin', 'view'])): ?>
    <li class="nav-item">
        <a class="nav-link <?= $onPartywise ? 'active' : '' ?>" href="/reports">
            <i class="bi bi-people me-1"></i> Party-wise Report
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?= $onDailyDispatch ? 'active' : '' ?>" href="/reports/daily-dispatch">
            <i class="bi bi-calendar-day me-1"></i> Daily Dispatch Report
        </a>
    </li>
</ul>
