<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?= htmlspecialchars($title ?? 'JLD Minerals - Operations Management System') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="/css/design-system.css" rel="stylesheet">
    <style>
        /* Layout & components – design tokens live in /css/design-system.css */
        
        /* Header nav menu panel (replaces left sidebar) */
        .nav-menu-dropdown {
            position: relative;
        }

        .nav-menu-toggle {
            color: var(--jld-primary);
            border: 1px solid var(--jld-border);
            background: var(--jld-white);
            padding: 0.4rem 0.75rem;
            min-width: 44px;
            min-height: 44px;
            line-height: 1;
            flex-shrink: 0;
            border-radius: 0.5rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }
        .nav-menu-toggle:hover,
        .nav-menu-toggle:focus,
        .nav-menu-toggle.show {
            color: var(--jld-primary);
            background: rgba(43, 35, 94, 0.06);
            border-color: rgba(43, 35, 94, 0.25);
        }
        .nav-menu-toggle .menu-label {
            font-size: 0.9rem;
        }

        .nav-menu-panel {
            --nav-menu-width: min(720px, calc(100vw - 1.5rem));
            width: var(--nav-menu-width);
            max-width: var(--nav-menu-width);
            max-height: min(78vh, 640px);
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 0;
            margin-top: 0.5rem !important;
            border: 1px solid var(--jld-border);
            border-radius: 0.75rem;
            box-shadow: var(--jld-shadow-lg);
            background: var(--jld-white);
            z-index: 1040;
        }

        .nav-menu-panel-inner {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.25rem 1.25rem;
            padding: 1rem 1.1rem 1.15rem;
        }

        .nav-menu-section {
            min-width: 0;
            padding-bottom: 0.5rem;
        }

        .nav-menu-section-title {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--jld-gray);
            padding: 0.35rem 0.65rem 0.5rem;
            margin-bottom: 0.15rem;
        }

        .nav-menu-link {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.55rem 0.65rem;
            margin: 0.1rem 0;
            border-radius: 0.5rem;
            color: var(--jld-dark-gray);
            font-weight: 500;
            font-size: 0.9rem;
            text-decoration: none;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .nav-menu-link i {
            width: 1.15rem;
            text-align: center;
            color: var(--jld-primary);
            opacity: 0.85;
            flex-shrink: 0;
        }
        .nav-menu-link:hover {
            background: rgba(43, 35, 94, 0.06);
            color: var(--jld-primary);
        }
        .nav-menu-link.active {
            background: rgba(237, 29, 37, 0.1);
            color: var(--jld-secondary);
        }
        .nav-menu-link.active i {
            color: var(--jld-secondary);
            opacity: 1;
        }

        .nav-menu-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.35);
            z-index: 1035;
        }
        .nav-menu-overlay.show {
            display: block;
        }
        body.nav-menu-open {
            overflow: hidden;
        }
        
        /* Header styling */
        .navbar {
            background: var(--jld-white) !important;
            box-shadow: var(--jld-shadow);
            border-bottom: 1px solid var(--jld-border);
        }
        
        .navbar-brand {
            font-weight: 700;
            color: var(--jld-primary) !important;
            font-size: 1.5rem;
        }
        
        .navbar-brand img {
            height: 3.5rem;
            max-height: 3.5rem;
            width: auto;
        }
        
        .navbar-dark .navbar-nav .nav-link {
            color: var(--jld-primary) !important;
            font-weight: 500;
        }
        
        .navbar-dark .navbar-nav .nav-link:hover {
            color: var(--jld-secondary) !important;
        }
        
        /* Card styling */
        .card {
            border: none;
            border-radius: 0.75rem;
            box-shadow: var(--jld-shadow);
            background: var(--jld-white);
        }
        
        .card-header {
            background: var(--jld-white);
            border-bottom: 1px solid var(--jld-border);
            font-weight: 600;
            color: var(--jld-primary);
            padding: 1.25rem 1.5rem;
            border-radius: 0.75rem 0.75rem 0 0 !important;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Table styling */
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background-color: var(--jld-light-gray);
            color: var(--jld-primary);
            font-weight: 600;
            border-bottom: 2px solid var(--jld-border);
            padding: 1rem 0.75rem;
            border-top: none;
        }
        
        .table tbody td {
            padding: 1rem 0.75rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--jld-border);
        }
        
        .table tbody tr:hover {
            background-color: rgba(43, 35, 94, 0.02);
        }
        
        /* Form styling */
        .form-control, .form-select {
            border: 1px solid var(--jld-border);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            font-weight: 400;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--jld-primary);
            box-shadow: 0 0 0 0.2rem rgba(43, 35, 94, 0.25);
        }
        
        .form-label {
            font-weight: 500;
            color: var(--jld-primary);
            margin-bottom: 0.5rem;
        }
        
        /* Badge styling */
        .badge {
            font-weight: 500;
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
        }
        
        .badge.bg-warning {
            background-color: #ffc107 !important;
            color: #000;
        }
        
        .badge.bg-success {
            background-color: #198754 !important;
        }
        
        .badge.bg-info {
            background-color: #0dcaf0 !important;
            color: #000;
        }
        
        .badge.bg-secondary {
            background-color: var(--jld-gray) !important;
        }
        
        .badge.bg-danger {
            background-color: var(--jld-secondary) !important;
        }
        
        /* Alert styling */
        .alert {
            border: none;
            border-radius: 0.5rem;
            font-weight: 500;
        }
        
        .alert-success {
            background-color: rgba(25, 135, 84, 0.1);
            color: #0f5132;
            border-left: 4px solid #198754;
        }
        
        .alert-danger {
            background-color: rgba(237, 29, 37, 0.1);
            color: #721c24;
            border-left: 4px solid var(--jld-secondary);
        }
        
        .alert-info {
            background-color: rgba(43, 35, 94, 0.1);
            color: var(--jld-primary);
            border-left: 4px solid var(--jld-primary);
        }
        
        /* Button styling */
        .btn {
            font-weight: 500;
            border-radius: 0.5rem;
            padding: 0.625rem 1.25rem;
            transition: all 0.2s ease;
        }
        
        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }
        
        .btn-outline-primary {
            color: var(--jld-primary);
            border-color: var(--jld-primary);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--jld-primary);
            border-color: var(--jld-primary);
        }
        
        .btn-outline-danger {
            color: var(--jld-secondary);
            border-color: var(--jld-secondary);
        }
        
        .btn-outline-danger:hover {
            background-color: var(--jld-secondary);
            border-color: var(--jld-secondary);
        }
        
        .btn-outline-warning {
            color: #fd7e14;
            border-color: #fd7e14;
        }
        
        .btn-outline-warning:hover {
            background-color: #fd7e14;
            border-color: #fd7e14;
        }
        
        /* Main content area */
        .main-content {
            padding: 2rem;
        }
        
        /* Page header */
        .page-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--jld-border);
        }
        
        .page-title {
            color: var(--jld-primary);
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: var(--jld-gray);
            font-weight: 400;
            margin-bottom: 0;
        }
        
        /* CRM – professional dashboard & funnel */
        .crm-kpi-card {
            border-radius: 0.75rem;
            border: none;
            box-shadow: 0 1px 3px rgba(43, 35, 94, 0.08);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .crm-kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(43, 35, 94, 0.12);
        }
        .crm-kpi-card .kpi-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--jld-primary);
            letter-spacing: -0.02em;
        }
        .crm-kpi-card .kpi-label {
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--jld-gray);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .crm-nav-tile {
            display: block;
            padding: 1.5rem;
            border-radius: 0.75rem;
            border: 1px solid var(--jld-border);
            background: var(--jld-white);
            color: inherit;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(43, 35, 94, 0.06);
        }
        .crm-nav-tile:hover {
            border-color: var(--jld-primary);
            background: rgba(43, 35, 94, 0.03);
            color: var(--jld-primary);
            box-shadow: 0 4px 12px rgba(43, 35, 94, 0.1);
        }
        .crm-nav-tile .tile-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
        }
        .crm-nav-tile .tile-title { font-weight: 600; font-size: 1rem; }
        .crm-nav-tile .tile-desc { font-size: 0.8125rem; color: var(--jld-gray); margin-top: 0.25rem; }
        /* Funnel board – 5 equal columns (Sampling, Technical Support, Re-Sampling, Trial Order, Closed) */
        .crm-funnel-board {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 1rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            min-height: 420px;
        }
        .crm-funnel-column {
            border-radius: 0.75rem;
            background: var(--jld-light-gray);
            border: 1px solid var(--jld-border);
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 220px);
            min-width: 0;
        }
        .crm-funnel-column-header {
            padding: 1rem 1.25rem;
            border-radius: 0.75rem 0.75rem 0 0;
            font-weight: 600;
            font-size: 0.875rem;
            color: #fff;
            flex-shrink: 0;
        }
        .crm-funnel-column-meta {
            padding: 0.5rem 1.25rem 0.75rem;
            font-size: 0.75rem;
            color: var(--jld-gray);
            border-top: 1px solid var(--jld-border);
            flex-shrink: 0;
            margin-top: auto;
        }
        .crm-funnel-column-cards {
            flex: 1;
            overflow-y: auto;
            padding: 0.5rem;
            min-height: 0;
        }
        .crm-company-card {
            background: var(--jld-white);
            border: 1px solid var(--jld-border);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .crm-company-card:hover {
            border-color: var(--jld-primary);
            box-shadow: 0 2px 8px rgba(43, 35, 94, 0.12);
        }
        .crm-company-card.dragging {
            opacity: 0.6;
            transform: scale(0.99);
        }
        .crm-funnel-column-cards.drag-over {
            outline: 2px dashed rgba(43, 35, 94, 0.55);
            background: rgba(43, 35, 94, 0.02);
        }
        .crm-company-card .company-name { font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; }
        .crm-company-card .company-meta { font-size: 0.75rem; color: var(--jld-gray); }
        .crm-company-card .company-value { font-size: 0.8125rem; font-weight: 600; color: var(--jld-primary); margin-top: 0.35rem; }
        
        /* Company profile page */
        .crm-profile-hero {
            background: linear-gradient(135deg, var(--jld-primary) 0%, #1e1a4a 100%);
            border-radius: 0.75rem;
            padding: 1.5rem 1.75rem;
            color: #fff;
            margin-bottom: 1.5rem;
        }
        .crm-profile-hero .profile-name { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; letter-spacing: -0.02em; }
        .crm-profile-hero .profile-meta { opacity: 0.9; font-size: 0.9375rem; }
        .crm-profile-hero .profile-meta i { opacity: 0.85; margin-right: 0.35rem; }
        .crm-profile-hero .btn-light { font-weight: 500; border-radius: 0.5rem; }
        .crm-glance-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .crm-glance-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.4rem 0.75rem;
            background: var(--jld-light-gray);
            border: 1px solid var(--jld-border);
            border-radius: 2rem;
            font-size: 0.8125rem;
            color: var(--jld-dark-gray);
        }
        .crm-glance-pill .pill-label { color: var(--jld-gray); font-weight: 500; }
        .crm-profile-section {
            border-radius: 0.75rem;
            border: 1px solid var(--jld-border);
            background: var(--jld-white);
            overflow: hidden;
            margin-bottom: 1rem;
        }
        .crm-profile-section-title {
            padding: 0.75rem 1.25rem;
            background: var(--jld-light-gray);
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--jld-primary);
            border-bottom: 1px solid var(--jld-border);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .crm-profile-section-title i { opacity: 0.9; }
        .crm-profile-section-body { padding: 1rem 1.25rem; }
        .crm-profile-dl { display: grid; gap: 0.5rem 1rem; margin: 0; font-size: 0.875rem; }
        .crm-profile-dl dt { color: var(--jld-gray); font-weight: 500; grid-column: 1; }
        .crm-profile-dl dd { margin: 0; grid-column: 2; }
        .crm-profile-dl.two-cols { grid-template-columns: auto 1fr; }
        .crm-section-card .card-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            font-size: 0.9375rem;
        }
        .crm-section-card .card-header i { color: var(--jld-primary); opacity: 0.9; }
        .crm-contact-item, .crm-activity-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--jld-border);
            font-size: 0.875rem;
        }
        .crm-contact-item:last-child, .crm-activity-item:last-child { border-bottom: none; }
        .crm-receivable-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem 1.5rem;
            padding: 0.75rem 0;
            margin-bottom: 0.75rem;
            background: var(--jld-light-gray);
            border-radius: 0.5rem;
            padding: 1rem 1.25rem;
        }
        .crm-receivable-summary .item { font-size: 0.875rem; }
        .crm-receivable-summary .item strong { color: var(--jld-primary); margin-right: 0.35rem; }
        
        /* Select2 customization */
        .select2-container--bootstrap-5 .select2-selection {
            border: 1px solid var(--jld-border);
            border-radius: 0.5rem;
        }
        
        .select2-container--bootstrap-5 .select2-selection--single {
            height: calc(2.25rem + 2px);
        }
        
        .select2-container--bootstrap-5.select2-container--focus .select2-selection {
            border-color: var(--jld-primary);
            box-shadow: 0 0 0 0.2rem rgba(43, 35, 94, 0.25);
        }
        
        /* Status colors */
        .status-pending { color: #ffc107; }
        .status-partial { color: #fd7e14; }
        .status-completed { color: #198754; }
        
        /* Loading and error states */
        .loading {
            display: none;
            text-align: center;
            padding: 2rem;
        }
        
        .error-message {
            display: none;
            margin-top: 1rem;
        }
        
        /* Loading spinner */
        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
        }
        
        /* Clickable cards */
        .clickable-card {
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        
        .clickable-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
        }
        
        .border-left-primary {
            border-left: 4px solid var(--jld-primary) !important;
        }
        
        .delivery-schedule-card {
            transition: all 0.2s ease;
        }
        
        .delivery-schedule-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .clickable-card:active {
            transform: translateY(0);
        }
        
        .clickable-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.1);
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        
        .clickable-card:hover::after {
            opacity: 1;
        }
        
        .mobile-menu-toggle {
            display: none;
        }
        
        .mobile-toggle-fixed {
            display: none;
        }
        
        .user-menu-fixed {
            display: none !important;
        }

        .app-topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            overflow: visible;
        }

        .app-topbar .container-fluid {
            overflow: visible;
        }

        @media (min-width: 992px) {
            .main-content {
                padding: 1.75rem 2rem 2.25rem;
            }

            .nav-menu-panel {
                min-width: 480px;
            }
        }

        .app-topbar .navbar-brand {
            font-size: 1.1rem;
            max-width: min(48vw, 14rem);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Mobile-first optimizations */
        * {
            -webkit-tap-highlight-color: rgba(0, 0, 0, 0.1);
        }
        
        /* Touch-friendly targets */
        .btn, .nav-link, .form-control, .form-select, .card {
            touch-action: manipulation;
        }
        
        /* Better mobile scrolling */
        body {
            -webkit-overflow-scrolling: touch;
            overflow-x: hidden;
        }
        
        /* Responsive adjustments */
        @media (max-width: 991.98px) {
            .nav-menu-toggle .menu-label {
                display: none;
            }

            .nav-menu-dropdown {
                position: static;
            }

            .nav-menu-panel {
                position: fixed !important;
                top: var(--app-topbar-offset, 56px);
                left: 0 !important;
                right: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                max-height: calc(100dvh - var(--app-topbar-offset, 56px));
                margin: 0 !important;
                border-radius: 0 0 0.75rem 0.75rem;
                border-left: none;
                border-right: none;
                transform: none !important;
                inset: auto 0 auto 0 !important;
            }

            .nav-menu-panel-inner {
                grid-template-columns: 1fr;
                padding: 0.75rem 0.85rem 1rem;
                gap: 0.15rem;
            }

            .nav-menu-link {
                min-height: 44px;
                padding: 0.7rem 0.75rem;
                font-size: 0.95rem;
            }

            .nav-menu-section-title {
                padding-top: 0.65rem;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 0.75rem !important;
                padding-top: 0.5rem !important;
            }
            
            .container-fluid {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }

            .app-topbar {
                padding-top: env(safe-area-inset-top, 0);
            }
            
            .navbar-brand img {
                height: 2.5rem;
                max-height: 2.5rem;
            }
            
            /* Better card spacing on mobile */
            .card {
                margin-bottom: 1rem;
            }
            
            .card-header {
                padding: 1rem;
                font-size: 0.95rem;
            }
            
            /* Better table handling */
            .table-responsive {
                -webkit-overflow-scrolling: touch;
                border-radius: 0.5rem;
                margin-bottom: 0;
            }
            
            .table {
                font-size: 0.875rem;
            }
            
            .table th, .table td {
                padding: 0.75rem 0.5rem;
                white-space: normal;
                vertical-align: top;
            }

            .table th.text-end,
            .table td.text-end {
                white-space: nowrap;
            }

            .nav-pills {
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                gap: 0.25rem;
                padding-bottom: 0.25rem;
            }
            .nav-pills::-webkit-scrollbar {
                display: none;
            }
            .nav-pills .nav-link {
                white-space: nowrap;
                flex-shrink: 0;
            }
        }
        
        @media (max-width: 767.98px) {
            .main-content {
                padding: 0.5rem !important;
            }
            
            .page-header {
                margin-bottom: 1.25rem;
                padding-bottom: 0.75rem;
            }

            .page-header > .d-flex,
            .page-header .d-flex.justify-content-between {
                flex-direction: column;
                align-items: stretch !important;
                gap: 0.75rem;
            }
            
            .page-title {
                font-size: 1.35rem;
                margin-bottom: 0.35rem;
                line-height: 1.3;
            }
            
            .page-subtitle {
                font-size: 0.875rem;
                margin-bottom: 0.5rem;
            }
            
            .page-header .d-flex.flex-wrap.gap-2,
            .page-header .d-flex.gap-2 {
                width: 100%;
            }

            .page-header .d-flex.flex-wrap.gap-2 .btn,
            .page-header .d-flex.gap-2 .btn {
                flex: 1 1 calc(50% - 0.35rem);
                width: auto;
                min-height: 44px;
                font-size: 0.9rem;
                margin-top: 0;
            }

            .page-header .d-flex.flex-wrap.gap-2 .btn:only-child,
            .page-header .d-flex.gap-2 .btn:only-child {
                flex: 1 1 100%;
            }
            
            .navbar {
                padding: 0.35rem 0.5rem;
            }
            
            .navbar-brand {
                font-size: 1rem;
            }
            
            .navbar-brand img {
                height: 2rem;
                max-height: 2rem;
            }
            
            .card-body {
                padding: 1rem 0.75rem;
            }
            
            .card-header {
                padding: 0.875rem;
                font-size: 0.9rem;
            }
            
            .table-responsive {
                font-size: 0.8rem;
            }
            
            .table th, .table td {
                padding: 0.625rem 0.375rem;
                font-size: 0.8rem;
            }
            
            .btn {
                padding: 0.625rem 1rem;
                font-size: 0.9rem;
                min-height: 44px;
            }
            
            .btn-sm {
                min-height: 36px;
                padding: 0.5rem 0.75rem;
            }
            
            /* Form optimizations */
            .form-control, .form-select {
                font-size: 16px; /* Prevents zoom on iOS */
                padding: 0.75rem;
                min-height: 44px;
            }
            
            .form-label {
                font-size: 0.9rem;
                margin-bottom: 0.5rem;
            }
            
            /* Better spacing */
            .mb-4 {
                margin-bottom: 1.25rem !important;
            }
            
            .mb-3 {
                margin-bottom: 0.875rem !important;
            }
            
            /* Modal optimizations */
            .modal-dialog {
                margin: 0.5rem;
            }

            .modal-fullscreen-sm-down {
                margin: 0;
            }
            
            .modal-content {
                border-radius: 0.5rem;
            }
            
            .modal-header, .modal-body, .modal-footer {
                padding: 1rem;
            }

            .modal-footer {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .modal-footer .btn {
                flex: 1 1 auto;
                min-width: calc(50% - 0.25rem);
            }
        }
        
        @media (max-width: 575.98px) {
            .app-topbar .navbar-brand {
                max-width: min(42vw, 10rem);
                font-size: 0.95rem;
            }

            .nav-menu-toggle {
                padding: 0.35rem 0.55rem;
            }
            
            .page-title {
                font-size: 1.2rem;
            }
            
            .page-subtitle {
                font-size: 0.8rem;
            }
            
            .main-content {
                padding: 0.5rem !important;
            }
            
            .card-body {
                padding: 0.75rem 0.5rem;
            }
            
            .card-header {
                padding: 0.75rem;
                font-size: 0.85rem;
            }
            
            .table th, .table td {
                padding: 0.5rem 0.3rem;
                font-size: 0.75rem;
            }
            
            .btn {
                padding: 0.5rem 0.75rem;
                font-size: 0.85rem;
            }
            
            /* Better badge sizing */
            .badge {
                font-size: 0.75rem;
                padding: 0.375rem 0.5rem;
            }

            #summaryCards .col-6 {
                padding-left: 0.35rem;
                padding-right: 0.35rem;
            }
        }
        
        /* Landscape mobile optimizations */
        @media (max-width: 991px) and (orientation: landscape) {
            .nav-menu-panel {
                max-height: calc(100dvh - var(--app-topbar-offset, 48px));
            }

            .nav-menu-panel-inner {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            
            .navbar {
                padding: 0.25rem 0.5rem;
            }
            
            .main-content {
                padding: 0.5rem !important;
            }
        }

        @media (min-width: 1200px) {
            .nav-menu-panel {
                --nav-menu-width: min(840px, calc(100vw - 2rem));
            }
            .nav-menu-panel-inner {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        
        /* Prevent text size adjustment on iOS */
        @media screen and (max-width: 768px) {
            html {
                -webkit-text-size-adjust: 100%;
                text-size-adjust: 100%;
            }
        }
    </style>
</head>
<body>
    <?php if (isset($user)): ?>
    <?php
    $r = $user['role'] ?? '';
    $isAdmin = ($r === 'admin');
    $roleHomes = [
        'admin' => '/dashboard',
        'order_processing' => '/orders',
        'crm' => '/crm',
        'marketing' => '/crm',
        'accounts' => '/admin/parties',
        'operator' => '/vehicles',
        'dispatch' => '/dispatch',
        'technical' => '/visit-requests',
    ];
    $brandHome = $roleHomes[$r] ?? '/orders';
    $canOrders = in_array($r, ['admin', 'order_processing', 'entry', 'view', 'sales', 'dispatch']);
    $canOrdersAnalytics = in_array($r, ['admin', 'entry', 'view']);
    $canDispatchDash = in_array($r, ['admin', 'dispatch', 'order_processing']);
    $canDispatchHistory = in_array($r, ['admin', 'dispatch', 'order_processing', 'entry']);
    $canVisitRequests = in_array($r, ['admin', 'marketing', 'technical', 'crm']);
    $canVehicles = in_array($r, ['admin', 'operator']);
    $canExport = in_array($r, ['admin', 'accounts']);
    $canCrm = in_array($r, ['admin', 'crm', 'entry', 'sales', 'marketing']);
    $canPartyMgmt = in_array($r, ['admin', 'accounts', 'entry', 'crm', 'sales', 'marketing']);
    $canProducts = in_array($r, ['admin', 'accounts', 'entry']);
    $reqUri = $_SERVER['REQUEST_URI'] ?? '';
    ?>
    <!-- Top header: menu dropdown + brand + user -->
    <nav class="navbar navbar-expand-lg border-bottom app-topbar" style="background: var(--jld-white) !important;">
        <div class="container-fluid px-2 px-md-3">
            <div class="dropdown nav-menu-dropdown">
                <button class="nav-menu-toggle" type="button" id="navMenuToggle"
                        data-bs-toggle="dropdown" data-bs-auto-close="outside" data-bs-display="static"
                        aria-expanded="false" aria-label="Open navigation menu">
                    <i class="bi bi-list fs-4"></i>
                    <span class="menu-label">Menu</span>
                </button>
                <div class="dropdown-menu nav-menu-panel" aria-labelledby="navMenuToggle">
                    <div class="nav-menu-panel-inner">
                        <?php if ($isAdmin): ?>
                        <div class="nav-menu-section">
                            <div class="nav-menu-section-title">Overview</div>
                            <a class="nav-menu-link <?= basename($reqUri) === 'dashboard' ? 'active' : '' ?>" href="/dashboard">
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if ($canOrders): ?>
                        <div class="nav-menu-section">
                            <div class="nav-menu-section-title">Orders & Dispatches</div>
                            <?php if (in_array($r, ['admin', 'order_processing', 'entry', 'sales', 'dispatch'])): ?>
                            <a class="nav-menu-link <?= strpos($reqUri, '/orders') === 0 && strpos($reqUri, '/orders/analytics') === false && strpos($reqUri, '/orders/new') === false ? 'active' : '' ?>" href="/orders">
                                <i class="bi bi-clipboard-check"></i> Orders
                            </a>
                            <?php if (in_array($r, ['admin', 'order_processing', 'entry', 'sales'])): ?>
                            <a class="nav-menu-link <?= strpos($reqUri, '/orders/new') === 0 ? 'active' : '' ?>" href="/orders/new">
                                <i class="bi bi-plus-circle"></i> New Order
                            </a>
                            <?php endif; ?>
                            <?php if ($canDispatchDash): ?>
                            <a class="nav-menu-link <?= strpos($reqUri, '/dispatch') === 0 && strpos($reqUri, '/dispatch/history') === false && strpos($reqUri, '/dispatch/daily') === false && strpos($reqUri, '/dispatch/reject-transfers') === false && strpos($reqUri, '/dispatches') !== 0 ? 'active' : '' ?>" href="/dispatch">
                                <i class="bi bi-truck-flatbed"></i> Dispatch Dashboard
                            </a>
                            <?php endif; ?>
                            <?php if ($canDispatchHistory): ?>
                            <a class="nav-menu-link <?= strpos($reqUri, '/dispatch/daily') === 0 ? 'active' : '' ?>" href="/dispatch/daily">
                                <i class="bi bi-calendar3"></i> Daily Busy Dispatches
                            </a>
                            <a class="nav-menu-link <?= strpos($reqUri, '/dispatch/history') === 0 ? 'active' : '' ?>" href="/dispatch/history">
                                <i class="bi bi-clock-history"></i> Dispatch History
                            </a>
                            <?php endif; ?>
                            <?php if ($canOrdersAnalytics): ?>
                            <a class="nav-menu-link <?= strpos($reqUri, '/orders/analytics') === 0 ? 'active' : '' ?>" href="/orders/analytics">
                                <i class="bi bi-bar-chart"></i> Orders Analytics
                            </a>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (in_array($r, ['admin', 'view'])): ?>
                            <a class="nav-menu-link <?= basename($reqUri) === 'reports' ? 'active' : '' ?>" href="/reports">
                                <i class="bi bi-graph-up"></i> Reports
                            </a>
                            <?php endif; ?>
                            <?php if (in_array($r, ['admin', 'view', 'order_processing', 'dispatch', 'entry'])): ?>
                            <a class="nav-menu-link <?= strpos($reqUri, '/reports/daily-dispatch') === 0 ? 'active' : '' ?>" href="/reports/daily-dispatch">
                                <i class="bi bi-calendar-day"></i> Daily Dispatch Report
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($canVehicles): ?>
                        <div class="nav-menu-section">
                            <div class="nav-menu-section-title">Vehicle Tracking</div>
                            <a class="nav-menu-link <?= basename($reqUri) === 'vehicles' ? 'active' : '' ?>" href="/vehicles">
                                <i class="bi bi-truck"></i> Vehicles
                            </a>
                            <a class="nav-menu-link <?= basename($reqUri) === 'tracking' ? 'active' : '' ?>" href="/tracking">
                                <i class="bi bi-geo-alt"></i> Live Tracking
                            </a>
                            <a class="nav-menu-link <?= basename($reqUri) === 'trips' ? 'active' : '' ?>" href="/trips">
                                <i class="bi bi-arrow-left-right"></i> Trips
                            </a>
                            <a class="nav-menu-link <?= strpos($reqUri, '/dumper-assignment') !== false ? 'active' : '' ?>" href="/dumper-assignment">
                                <i class="bi bi-truck"></i> Dumper Assignment
                            </a>
                            <a class="nav-menu-link <?= basename($reqUri) === 'geofences' ? 'active' : '' ?>" href="/geofences">
                                <i class="bi bi-geo-fill"></i> Geofences
                            </a>
                            <a class="nav-menu-link <?= basename($reqUri) === 'fuel' ? 'active' : '' ?>" href="/fuel">
                                <i class="bi bi-fuel-pump"></i> Fuel Management
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if ($canExport): ?>
                        <div class="nav-menu-section">
                            <div class="nav-menu-section-title">Export Documents</div>
                            <a class="nav-menu-link <?= strpos($reqUri, '/export') === 0 ? 'active' : '' ?>" href="/export">
                                <i class="bi bi-file-earmark-spreadsheet"></i> Nepal Export Docs
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if ($canCrm): ?>
                        <div class="nav-menu-section">
                            <div class="nav-menu-section-title">CRM</div>
                            <a class="nav-menu-link <?= ($reqUri === '/crm' || $reqUri === '/crm/') ? 'active' : '' ?>" href="/crm">
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a>
                            <a class="nav-menu-link <?= strpos($reqUri, '/crm/funnel') === 0 ? 'active' : '' ?>" href="/crm/funnel">
                                <i class="bi bi-funnel"></i> Funnel
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if ($canVisitRequests): ?>
                        <div class="nav-menu-section">
                            <div class="nav-menu-section-title">Client Visits</div>
                            <a class="nav-menu-link <?= strpos($reqUri, '/visit-requests') === 0 ? 'active' : '' ?>" href="/visit-requests">
                                <i class="bi bi-geo-alt-fill"></i> Visit Requests
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if ($canPartyMgmt || $canProducts || $r === 'admin'): ?>
                        <div class="nav-menu-section">
                            <div class="nav-menu-section-title">Administration</div>
                            <?php if ($canPartyMgmt): ?>
                            <a class="nav-menu-link <?= strpos($reqUri, '/admin/parties') === 0 ? 'active' : '' ?>" href="/admin/parties">
                                <i class="bi bi-person-circle"></i> Parties
                            </a>
                            <?php endif; ?>
                            <?php if ($canProducts): ?>
                            <a class="nav-menu-link <?= strpos($reqUri, '/admin/products') === 0 ? 'active' : '' ?>" href="/admin/products">
                                <i class="bi bi-box"></i> Products
                            </a>
                            <?php endif; ?>
                            <?php if (in_array($r, ['admin', 'accounts'])): ?>
                            <a class="nav-menu-link <?= strpos($reqUri, '/admin/reminders') === 0 ? 'active' : '' ?>" href="/admin/reminders">
                                <i class="bi bi-envelope-check"></i> Reminders
                            </a>
                            <a class="nav-menu-link <?= strpos($reqUri, '/admin/bills/import') === 0 ? 'active' : '' ?>" href="/admin/bills/import">
                                <i class="bi bi-upload"></i> Import Bills (Busy)
                            </a>
                            <?php endif; ?>
                            <?php if ($r === 'admin'): ?>
                            <a class="nav-menu-link <?= strpos($reqUri, '/admin/users') === 0 ? 'active' : '' ?>" href="/admin/users">
                                <i class="bi bi-people"></i> Users
                            </a>
                            <a class="nav-menu-link <?= strpos($reqUri, '/admin/credit-approvals') === 0 ? 'active' : '' ?>" href="/admin/credit-approvals">
                                <i class="bi bi-shield-check"></i> Credit Approvals
                            </a>
                            <a class="nav-menu-link <?= strpos($reqUri, '/admin/busy-integration') === 0 ? 'active' : '' ?>" href="/admin/busy-integration">
                                <i class="bi bi-link-45deg"></i> Busy Integration
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <a class="navbar-brand d-flex align-items-center me-auto text-truncate ms-2" href="<?= htmlspecialchars($brandHome) ?>"><?= htmlspecialchars($active_company['name'] ?? 'JLD Minerals') ?></a>
            <div class="d-flex align-items-center flex-shrink-0 gap-1">
                <?php if (!empty($companies_list)): ?>
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-primary fw-medium py-1 px-2" href="#" role="button" data-bs-toggle="dropdown" id="companySwitcherBtn" title="Switch company">
                        <i class="bi bi-building"></i><span class="d-none d-md-inline ms-1"><?= htmlspecialchars($active_company['name'] ?? 'Select company') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" id="companySwitcherMenu">
                        <?php foreach ($companies_list as $co): ?>
                        <li>
                            <a class="dropdown-item company-switch-item <?= (int)($active_company['id'] ?? 0) === (int)$co['id'] ? 'active' : '' ?>"
                               href="#"
                               data-company-id="<?= (int)$co['id'] ?>"
                               onclick="switchCompany(<?= (int)$co['id'] ?>); return false;">
                                <?= htmlspecialchars($co['name'] ?? '') ?>
                                <?php if ((int)($active_company['id'] ?? 0) === (int)$co['id']): ?>
                                <i class="bi bi-check-lg float-end"></i>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-primary fw-medium py-1 px-2" href="#" role="button" data-bs-toggle="dropdown" id="headerUserMenu" aria-label="User menu">
                        <i class="bi bi-person-circle"></i><span class="d-none d-sm-inline ms-1"><?= htmlspecialchars($user['name']) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small">Role: <?= ucfirst($user['role']) ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="#" onclick="logout(); return false;"><i class="bi bi-box-arrow-right me-1"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="nav-menu-overlay" id="navMenuOverlay" aria-hidden="true"></div>
    <div class="container-fluid">
        <div class="main-content">
            <?= $content ?>
        </div>
    </div>
    <?php else: ?>
    <!-- Login page layout -->
    <div class="container-fluid h-100">
        <?= $content ?>
    </div>
    <?php endif; ?>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Global CSRF token
        const csrfToken = <?= json_encode((string)($csrf_token ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        
        function escapeHtml(value) {
            if (value === null || value === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(value);
            return div.innerHTML;
        }
        
        // Global API helper functions
        async function apiCall(url, options = {}) {
            const defaultOptions = {
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                }
            };
            
            const response = await fetch(url, { ...defaultOptions, ...options });
            let data;
            try {
                data = await response.json();
            } catch (e) {
                throw new Error('Server error — check that database migrations are up to date (php scripts/migrate.php).');
            }
            
            if (!response.ok) {
                throw new Error(data.error || data.message || 'Request failed');
            }
            
            return data;
        }
        
        function showError(message, containerId = 'error-container') {
            const container = document.getElementById(containerId);
            if (container) {
                if (!message || String(message).trim() === '') {
                    container.innerHTML = '';
                    container.style.display = 'none';
                    return;
                }
                const safeMessage = escapeHtml(message);
                container.innerHTML = `
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        ${safeMessage}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `;
                container.style.display = 'block';
            }
        }
        
        function showSuccess(message, containerId = 'success-container') {
            const container = document.getElementById(containerId);
            if (container) {
                const safeMessage = escapeHtml(message);
                container.innerHTML = `
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        ${safeMessage}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `;
                container.style.display = 'block';
            }
        }
        
        function formatDate(dateString) {
            if (!dateString) return '—';
            // Prefer YYYY-MM-DD from API/DB without timezone shift
            const iso = String(dateString).match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (iso) {
                return `${iso[3]}/${iso[2]}/${iso[1]}`;
            }
            // Already Indian DD/MM/YYYY
            const indian = String(dateString).match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/);
            if (indian) {
                const d = indian[1].padStart(2, '0');
                const m = indian[2].padStart(2, '0');
                return `${d}/${m}/${indian[3]}`;
            }
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return '—';
            const dd = String(date.getDate()).padStart(2, '0');
            const mm = String(date.getMonth() + 1).padStart(2, '0');
            const yyyy = date.getFullYear();
            return `${dd}/${mm}/${yyyy}`;
        }

        function formatDateTime(dateString) {
            if (!dateString) return '—';
            const m = String(dateString).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
            if (m) {
                return `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}`;
            }
            return formatDate(dateString);
        }
        
        function formatStatus(status) {
            const statusMap = {
                'pending': '<span class="badge bg-warning">Pending</span>',
                'partial': '<span class="badge bg-info">Partial</span>',
                'completed': '<span class="badge bg-success">Completed</span>'
            };
            return statusMap[status] || escapeHtml(status);
        }
        
        function formatPriority(priority) {
            const priorityMap = {
                'normal': '<span class="badge bg-secondary">Normal</span>',
                'urgent': '<span class="badge bg-danger">Urgent</span>'
            };
            return priorityMap[priority] || escapeHtml(priority);
        }
        
        async function logout() {
            try {
                await apiCall('/api/logout', { method: 'POST' });
                window.location.href = '/login';
            } catch (error) {
                console.error('Logout failed:', error);
                window.location.href = '/login';
            }
        }

        async function switchCompany(companyId) {
            try {
                await apiCall('/api/companies/active', {
                    method: 'POST',
                    body: JSON.stringify({ company_id: companyId })
                });
                window.location.reload();
            } catch (error) {
                showError(error.message || 'Failed to switch company');
            }
        }

        function syncAppTopbarOffset() {
            const topbar = document.querySelector('.app-topbar');
            if (topbar) {
                document.documentElement.style.setProperty('--app-topbar-offset', topbar.offsetHeight + 'px');
            }
        }

        function setNavMenuOpenState(isOpen) {
            const overlay = document.getElementById('navMenuOverlay');
            document.body.classList.toggle('nav-menu-open', isOpen && window.innerWidth <= 991);
            if (overlay) {
                overlay.classList.toggle('show', isOpen && window.innerWidth <= 991);
            }
        }

        function closeNavMenu() {
            const toggle = document.getElementById('navMenuToggle');
            if (!toggle || typeof bootstrap === 'undefined') return;
            const dropdown = bootstrap.Dropdown.getInstance(toggle) || bootstrap.Dropdown.getOrCreateInstance(toggle);
            if (dropdown) dropdown.hide();
        }

        syncAppTopbarOffset();
        (function initNavMenuPanel() {
            const toggle = document.getElementById('navMenuToggle');
            const overlay = document.getElementById('navMenuOverlay');

            if (toggle) {
                toggle.addEventListener('show.bs.dropdown', function() {
                    syncAppTopbarOffset();
                    setNavMenuOpenState(true);
                });
                toggle.addEventListener('hide.bs.dropdown', function() {
                    setNavMenuOpenState(false);
                });
            }

            if (overlay) {
                overlay.addEventListener('click', closeNavMenu);
            }

            window.addEventListener('resize', function() {
                syncAppTopbarOffset();
                if (window.innerWidth > 991) {
                    document.body.classList.remove('nav-menu-open');
                    if (overlay) overlay.classList.remove('show');
                }
            });
        })();
    </script>
</body>
</html>

