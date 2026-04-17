<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
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
        
        /* Sidebar styling */
        .sidebar {
            background: linear-gradient(135deg, var(--jld-primary) 0%, #1e1a4a 100%);
            min-height: 100vh;
            box-shadow: var(--jld-shadow-lg);
        }
        
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            font-weight: 500;
            padding: 0.75rem 1.5rem;
            margin: 0.25rem 1rem;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }
        
        .sidebar .nav-link:hover {
            color: var(--jld-white);
            background-color: rgba(255, 255, 255, 0.1);
            transform: translateX(4px);
        }
        
        .sidebar .nav-link.active {
            color: var(--jld-white);
            background-color: var(--jld-secondary);
            box-shadow: 0 0.25rem 0.5rem rgba(237, 29, 37, 0.3);
        }
        
        .sidebar .nav-link i {
            width: 1.25rem;
            margin-right: 0.75rem;
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
        
        /* Mobile menu toggle button */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--jld-primary);
            font-size: 1.5rem;
            padding: 0.5rem;
            cursor: pointer;
            margin-right: 1rem;
        }
        
        .mobile-toggle-fixed {
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1050;
            background: var(--jld-white);
            border: 1px solid var(--jld-border);
            border-radius: 0.5rem;
            box-shadow: var(--jld-shadow);
            padding: 0.75rem;
            min-width: 44px;
            min-height: 44px;
        }
        
        .user-menu-fixed {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1050;
            background: var(--jld-white);
            border: 1px solid var(--jld-border);
            border-radius: 0.5rem;
            box-shadow: var(--jld-shadow);
            padding: 0.5rem 1rem;
        }
        
        .user-menu-fixed .nav-link {
            color: var(--jld-primary);
            padding: 0;
        }
        
        @media (min-width: 992px) {
            .mobile-toggle-fixed,
            .user-menu-fixed {
                display: none;
            }
        }
        
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
        
        .sidebar-overlay.show {
            display: block;
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
        @media (max-width: 991px) {
            .mobile-menu-toggle.mobile-toggle-fixed {
                display: block;
            }
            
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                width: 280px;
                max-width: 85vw;
                z-index: 1000;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                overflow-y: auto;
                height: 100vh;
                -webkit-overflow-scrolling: touch;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            body.sidebar-open {
                overflow: hidden;
                position: fixed;
                width: 100%;
            }
            
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0.75rem !important;
            }
            
            .container-fluid {
                padding-left: 0.75rem;
                padding-right: 0.75rem;
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
            }
            
            .table {
                font-size: 0.875rem;
            }
            
            .table th, .table td {
                padding: 0.75rem 0.5rem;
                white-space: nowrap;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 0.5rem !important;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start !important;
                padding: 1rem 0.5rem;
            }
            
            .page-title {
                font-size: 1.5rem;
                margin-bottom: 0.5rem;
            }
            
            .page-subtitle {
                font-size: 0.875rem;
                margin-bottom: 1rem;
            }
            
            .page-header .btn {
                margin-top: 0.5rem;
                width: 100%;
                min-height: 44px;
                font-size: 1rem;
            }
            
            .navbar {
                padding: 0.5rem 0.75rem;
            }
            
            .navbar-brand {
                font-size: 1.25rem;
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
                margin: 0 -0.75rem;
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
                margin-bottom: 1.5rem !important;
            }
            
            .mb-3 {
                margin-bottom: 1rem !important;
            }
            
            /* Modal optimizations */
            .modal-dialog {
                margin: 0.5rem;
            }
            
            .modal-content {
                border-radius: 0.5rem;
            }
            
            .modal-header, .modal-body, .modal-footer {
                padding: 1rem;
            }
        }
        
        @media (max-width: 576px) {
            .sidebar {
                width: 100%;
                max-width: 100%;
            }
            
            .navbar-brand img {
                height: 1.75rem;
                max-height: 1.75rem;
            }
            
            .page-title {
                font-size: 1.25rem;
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
                padding: 0.5rem 0.25rem;
                font-size: 0.75rem;
            }
            
            .btn {
                padding: 0.5rem 0.75rem;
                font-size: 0.85rem;
            }
            
            /* Stack columns on very small screens */
            .row > [class*="col-"] {
                margin-bottom: 1rem;
            }
            
            /* Better badge sizing */
            .badge {
                font-size: 0.75rem;
                padding: 0.375rem 0.5rem;
            }
        }
        
        /* Landscape mobile optimizations */
        @media (max-width: 991px) and (orientation: landscape) {
            .sidebar {
                width: 250px;
            }
            
            .navbar {
                padding: 0.25rem 0.5rem;
            }
            
            .main-content {
                padding: 0.5rem !important;
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
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const body = document.body;
            
            if (sidebar && overlay) {
                const isOpen = sidebar.classList.contains('show');
                
                if (isOpen) {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                    body.classList.remove('sidebar-open');
                } else {
                    sidebar.classList.add('show');
                    overlay.classList.add('show');
                    body.classList.add('sidebar-open');
                }
            }
        }
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.mobile-menu-toggle.mobile-toggle-fixed');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (window.innerWidth <= 991 && sidebar && toggle && overlay) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target) && sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                }
            }
        });
        
        // Close sidebar on window resize to desktop
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (window.innerWidth > 991 && sidebar && overlay) {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
                document.body.classList.remove('sidebar-open');
            }
        });
    </script>
</head>
<body>
    <?php if (isset($user)): ?>
    <!-- Top header: brand left, user right -->
    <nav class="navbar navbar-expand-lg border-bottom" style="background: var(--jld-white) !important;">
        <div class="container-fluid">
            <?php
                    $r = $user['role'] ?? '';
                    $brandHome = ($r === 'admin') ? '/dashboard' : (($r === 'crm') ? '/crm' : (($r === 'accounts') ? '/admin/parties' : (($r === 'operator') ? '/vehicles' : '/orders')));
                    ?>
            <a class="navbar-brand d-flex align-items-center" href="<?= htmlspecialchars($brandHome) ?>">JLD Minerals</a>
            <div class="d-flex align-items-center ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-primary fw-medium" href="#" role="button" data-bs-toggle="dropdown" id="headerUserMenu">
                        <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($user['name']) ?>
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
    <!-- Mobile menu toggle (floating button) -->
    <button class="mobile-menu-toggle mobile-toggle-fixed" onclick="toggleSidebar()" type="button" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
    </button>
    
    <!-- User menu (floating button) -->
    <div class="user-menu-fixed">
        <div class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars($user['name']) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text">Role: <?= ucfirst($user['role']) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a></li>
            </ul>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-3" id="sidebar">
                <ul class="nav nav-pills flex-column">
                    <?php
                    $r = $user['role'] ?? '';
                    $isAdmin = ($r === 'admin');
                    $navHome = $isAdmin ? '/dashboard' : (
                        $r === 'crm' ? '/crm' : ($r === 'accounts' ? '/admin/parties' : ($r === 'operator' ? '/vehicles' : '/orders'))
                    );
                    if ($isAdmin): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['REQUEST_URI']) === 'dashboard' ? 'active' : '' ?>" href="/dashboard">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php
                    $canOrders = in_array($r, ['admin', 'order_processing', 'entry', 'view']);
                    $canVehicles = in_array($r, ['admin', 'operator']);
                    $canExport = in_array($r, ['admin', 'accounts']);
                    $canCrm = in_array($r, ['admin', 'crm', 'entry']);
                    $canPartyMgmt = in_array($r, ['admin', 'accounts', 'entry', 'crm']);
                    $canProducts = in_array($r, ['admin', 'accounts', 'entry']);
                    ?>
                    <!-- Orders & Dispatches: admin, order_processing, entry, view -->
                    <?php if ($canOrders): ?>
                    <li class="nav-item mt-3">
                        <small class="text-white-50 text-uppercase px-3">Orders & Dispatches</small>
                    </li>
                    <?php if (in_array($r, ['admin', 'order_processing', 'entry'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/orders') === 0 && strpos($_SERVER['REQUEST_URI'], '/orders/analytics') === false && strpos($_SERVER['REQUEST_URI'], '/orders/new') === false ? 'active' : '' ?>" href="/orders">
                            <i class="bi bi-clipboard-check"></i> Orders
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/orders/analytics') === 0 ? 'active' : '' ?>" href="/orders/analytics">
                            <i class="bi bi-bar-chart"></i> Orders Analytics
                        </a>
                    </li>
                    <?php if (in_array($r, ['admin', 'order_processing', 'view'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['REQUEST_URI']) === 'reports' ? 'active' : '' ?>" href="/reports">
                            <i class="bi bi-graph-up"></i> Reports
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Vehicle Tracking: admin, operator -->
                    <?php if ($canVehicles): ?>
                    <li class="nav-item mt-3">
                        <small class="text-white-50 text-uppercase px-3">Vehicle Tracking</small>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['REQUEST_URI']) === 'vehicles' ? 'active' : '' ?>" href="/vehicles">
                            <i class="bi bi-truck"></i> Vehicles
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['REQUEST_URI']) === 'tracking' ? 'active' : '' ?>" href="/tracking">
                            <i class="bi bi-geo-alt"></i> Live Tracking
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['REQUEST_URI']) === 'trips' ? 'active' : '' ?>" href="/trips">
                            <i class="bi bi-arrow-left-right"></i> Trips
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/dumper-assignment') !== false ? 'active' : '' ?>" href="/dumper-assignment">
                            <i class="bi bi-truck"></i> Dumper Assignment
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['REQUEST_URI']) === 'geofences' ? 'active' : '' ?>" href="/geofences">
                            <i class="bi bi-geo-fill"></i> Geofences
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['REQUEST_URI']) === 'fuel' ? 'active' : '' ?>" href="/fuel">
                            <i class="bi bi-fuel-pump"></i> Fuel Management
                        </a>
                    </li>
                    <?php endif; ?>

                    <!-- Export Documents: admin, accounts -->
                    <?php if ($canExport): ?>
                    <li class="nav-item mt-3">
                        <small class="text-white-50 text-uppercase px-3">Export Documents</small>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/export') === 0 ? 'active' : '' ?>" href="/export">
                            <i class="bi bi-file-earmark-spreadsheet"></i> Nepal Export Docs
                        </a>
                    </li>
                    <?php endif; ?>

                    <!-- CRM: admin, crm, entry -->
                    <?php if ($canCrm): ?>
                    <li class="nav-item mt-3">
                        <small class="text-white-50 text-uppercase px-3">CRM</small>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= (($u = $_SERVER['REQUEST_URI']) === '/crm' || $u === '/crm/') ? 'active' : '' ?>" href="/crm">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/crm/funnel') === 0 ? 'active' : '' ?>" href="/crm/funnel">
                            <i class="bi bi-funnel"></i> Funnel
                        </a>
                    </li>
                    <?php endif; ?>

                    <!-- Administration: Parties & Products for admin, accounts, entry; Users & Busy for admin only -->
                    <?php if ($canPartyMgmt || $canProducts || $r === 'admin'): ?>
                    <li class="nav-item mt-3">
                        <small class="text-white-50 text-uppercase px-3">Administration</small>
                    </li>
                    <?php if ($canPartyMgmt): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/parties') === 0 ? 'active' : '' ?>" href="/admin/parties">
                            <i class="bi bi-person-circle"></i> Parties
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($canProducts): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/products') === 0 ? 'active' : '' ?>" href="/admin/products">
                            <i class="bi bi-box"></i> Products
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array($r, ['admin', 'accounts'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/reminders') === 0 ? 'active' : '' ?>" href="/admin/reminders">
                            <i class="bi bi-envelope-check"></i> Reminders
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/bills/import') === 0 ? 'active' : '' ?>" href="/admin/bills/import">
                            <i class="bi bi-upload"></i> Import Bills (Busy)
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($r === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/users') === 0 ? 'active' : '' ?>" href="/admin/users">
                            <i class="bi bi-people"></i> Users
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/credit-approvals') === 0 ? 'active' : '' ?>" href="/admin/credit-approvals">
                            <i class="bi bi-shield-check"></i> Credit Approvals
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/busy-integration') === 0 ? 'active' : '' ?>" href="/admin/busy-integration">
                            <i class="bi bi-link-45deg"></i> Busy Integration
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Main Content -->
            <div class="col-md-10 main-content">
                <?= $content ?>
            </div>
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
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || 'Request failed');
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
            return new Date(dateString).toLocaleDateString();
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
    </script>
</body>
</html>

