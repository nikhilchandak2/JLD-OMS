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
    <style>
        :root {
            --jld-primary: #2b235e;
            --jld-secondary: #ed1d25;
            --jld-white: #ffffff;
            --jld-light-gray: #f8f9fa;
            --jld-gray: #6c757d;
            --jld-dark-gray: #495057;
            --jld-border: #e9ecef;
            --jld-shadow: 0 0.125rem 0.25rem rgba(43, 35, 94, 0.075);
            --jld-shadow-lg: 0 0.5rem 1rem rgba(43, 35, 94, 0.15);
        }
        
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        body {
            background-color: var(--jld-light-gray);
            color: var(--jld-dark-gray);
            font-weight: 400;
            line-height: 1.6;
        }
        
        /* Custom Bootstrap overrides */
        .btn-primary {
            background-color: var(--jld-primary);
            border-color: var(--jld-primary);
            font-weight: 500;
        }
        
        .btn-primary:hover, .btn-primary:focus {
            background-color: #1e1a4a;
            border-color: #1e1a4a;
        }
        
        .btn-danger {
            background-color: var(--jld-secondary);
            border-color: var(--jld-secondary);
        }
        
        .btn-danger:hover, .btn-danger:focus {
            background-color: #c91621;
            border-color: #c91621;
        }
        
        .text-primary {
            color: var(--jld-primary) !important;
        }
        
        .text-danger {
            color: var(--jld-secondary) !important;
        }
        
        .bg-primary {
            background-color: var(--jld-primary) !important;
        }
        
        .bg-danger {
            background-color: var(--jld-secondary) !important;
        }
        
        .border-primary {
            border-color: var(--jld-primary) !important;
        }
        
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
            const toggle = document.querySelector('.mobile-menu-toggle');
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
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['REQUEST_URI']) === 'dashboard' ? 'active' : '' ?>" href="/dashboard">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    
                    <!-- Orders & Dispatches Section -->
                    <?php if (in_array($user['role'], ['entry', 'admin', 'view'])): ?>
                    <li class="nav-item mt-3">
                        <small class="text-white-50 text-uppercase px-3">Orders & Dispatches</small>
                    </li>
                    <?php if (in_array($user['role'], ['entry', 'admin'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/orders') === 0 && strpos($_SERVER['REQUEST_URI'], '/orders/analytics') === false && strpos($_SERVER['REQUEST_URI'], '/orders/new') === false ? 'active' : '' ?>" href="/orders">
                            <i class="bi bi-clipboard-check"></i> Orders
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array($user['role'], ['entry', 'admin', 'view'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/orders/analytics') === 0 ? 'active' : '' ?>" href="/orders/analytics">
                            <i class="bi bi-bar-chart"></i> Orders Analytics
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array($user['role'], ['view', 'admin'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['REQUEST_URI']) === 'reports' ? 'active' : '' ?>" href="/reports">
                            <i class="bi bi-graph-up"></i> Reports
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- Vehicle Tracking Section -->
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
                        <a class="nav-link <?= basename($_SERVER['REQUEST_URI']) === 'geofences' ? 'active' : '' ?>" href="/geofences">
                            <i class="bi bi-geo-fill"></i> Geofences
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['REQUEST_URI']) === 'fuel' ? 'active' : '' ?>" href="/fuel">
                            <i class="bi bi-fuel-pump"></i> Fuel Management
                        </a>
                    </li>
                    
                    <!-- Administration Section -->
                    <?php if (in_array($user['role'], ['entry', 'admin'])): ?>
                    <li class="nav-item mt-3">
                        <small class="text-white-50 text-uppercase px-3">Administration</small>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/parties') === 0 ? 'active' : '' ?>" href="/admin/parties">
                            <i class="bi bi-building"></i> Parties
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/products') === 0 ? 'active' : '' ?>" href="/admin/products">
                            <i class="bi bi-box"></i> Products
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($user['role'] === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/users') === 0 ? 'active' : '' ?>" href="/admin/users">
                            <i class="bi bi-people"></i> Users
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
        const csrfToken = '<?= $csrf_token ?? '' ?>';
        
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
                container.innerHTML = `
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `;
                container.style.display = 'block';
            }
        }
        
        function showSuccess(message, containerId = 'success-container') {
            const container = document.getElementById(containerId);
            if (container) {
                container.innerHTML = `
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        ${message}
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
            return statusMap[status] || status;
        }
        
        function formatPriority(priority) {
            const priorityMap = {
                'normal': '<span class="badge bg-secondary">Normal</span>',
                'urgent': '<span class="badge bg-danger">Urgent</span>'
            };
            return priorityMap[priority] || priority;
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

