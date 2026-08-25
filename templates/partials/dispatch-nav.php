<?php
$dispatchUri = $_SERVER['REQUEST_URI'] ?? '';
$onHandoffs = strpos($dispatchUri, '/dispatch/handoffs') === 0;
$onDaily = strpos($dispatchUri, '/dispatch/daily') === 0;
$onUploads = strpos($dispatchUri, '/dispatch/uploads') === 0;
$onInvoices = strpos($dispatchUri, '/dispatch/invoices') === 0;
$onHistory = strpos($dispatchUri, '/dispatch/history') === 0;
$onRejectTransfers = strpos($dispatchUri, '/dispatch/reject-transfers') === 0;
$onQueue = strpos($dispatchUri, '/dispatch') === 0
    && !$onDaily
    && !$onUploads
    && !$onInvoices
    && !$onHistory
    && !$onRejectTransfers
    && !$onHandoffs;
?>
<ul class="nav nav-pills mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $onQueue ? 'active' : '' ?>" href="/dispatch">
            <i class="bi bi-list-check me-1"></i> Dispatch Queue
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onHandoffs ? 'active' : '' ?>" href="/dispatch/handoffs">
            <i class="bi bi-arrow-left-right me-1"></i> Handoffs
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onDaily ? 'active' : '' ?>" href="/dispatch/daily">
            <i class="bi bi-calendar3 me-1"></i> Daily Busy Dispatches
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onInvoices ? 'active' : '' ?>" href="/dispatch/invoices">
            <i class="bi bi-list-ul me-1"></i> All Invoices
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onUploads ? 'active' : '' ?>" href="/dispatch/uploads">
            <i class="bi bi-cloud-upload me-1"></i> Invoice Uploads
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onHistory ? 'active' : '' ?>" href="/dispatch/history">
            <i class="bi bi-clock-history me-1"></i> Dispatch History
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onRejectTransfers ? 'active' : '' ?>" href="/dispatch/reject-transfers">
            <i class="bi bi-arrow-left-right me-1"></i> Rejected / Transferred
        </a>
    </li>
</ul>
