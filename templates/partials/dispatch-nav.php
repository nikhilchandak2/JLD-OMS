<?php
$dispatchUri = $_SERVER['REQUEST_URI'] ?? '';
$onQueue = strpos($dispatchUri, '/dispatch/history') === false && strpos($dispatchUri, '/dispatch') === 0;
$onHistory = strpos($dispatchUri, '/dispatch/history') === 0;
?>
<ul class="nav nav-pills mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $onQueue ? 'active' : '' ?>" href="/dispatch">
            <i class="bi bi-list-check me-1"></i> Dispatch Queue
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onHistory ? 'active' : '' ?>" href="/dispatch/history">
            <i class="bi bi-clock-history me-1"></i> Dispatch History
        </a>
    </li>
</ul>
