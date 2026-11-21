<?php
require_once __DIR__ . '/logo_asset.php';

// Fetch site logo for favicon if not already fetched
$header_logo = portalLogoUrl('uploads/logo.png');
if (isset($conn)) {
    $logo_query = "SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo'";
    $logo_result = mysqli_query($conn, $logo_query);
    if ($logo_result && mysqli_num_rows($logo_result) > 0) {
        $logo_row = mysqli_fetch_assoc($logo_result);
        if (!empty($logo_row['setting_value'])) {
            $header_logo = portalLogoUrl($logo_row['setting_value']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="soft-sage-theme portal-sage-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : 'Portal Admin - Catering Portal'; ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($header_logo); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars($header_logo); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($header_logo); ?>">
    <!-- CSS Links -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Page Specific Head Content -->
    <?php if (isset($extra_head)) echo $extra_head; ?>

    <style>
        :root {
            --primary-bg: #0a0a0a;
            --secondary-bg: #141414;
            --card-bg: #1e1e1e;
            --text-main: #ffffff;
            --text-muted: #a0a0a0;
            --accent-gold: #d4af37;
            --accent-gold-hover: #b4942b;
            --border-color: #333333;
            --input-bg: #2a2a2a;
            --success-color: #4caf50;
            --error-color: #f44336;
            --warning-color: #ff9800;
            --info-color: #2196f3;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
        }

        body.portal-sidebar-open {
            overflow: hidden;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 260px;
            background: var(--secondary-bg);
            border-right: none;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1060;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.36);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            z-index: 1040;
        }

        .sidebar-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }

        .logo-img {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid var(--accent-gold);
            margin-bottom: 15px;
        }

        .sidebar-header h3 {
            color: var(--accent-gold);
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin: 0;
        }

        .sidebar-header p {
            color: var(--text-muted);
            font-size: 12px;
            margin-top: 5px;
        }

        .sidebar-header.dashboard-brand {
            position: relative;
            overflow: hidden;
        }

        .sidebar-header.dashboard-brand::after {
            content: '';
            position: absolute;
            top: -12%;
            left: -32%;
            width: 28%;
            height: 132%;
            background: linear-gradient(115deg, transparent 0%, rgba(255,255,255,0.08) 45%, rgba(255,255,255,0.42) 50%, rgba(255,255,255,0.08) 55%, transparent 100%);
            transform: skewX(-20deg);
            opacity: 0;
            pointer-events: none;
        }

        .sidebar-header.dashboard-brand:hover::after {
            animation: portalBrandShine 1.05s ease;
        }

        .dashboard-brand .logo-mark {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            isolation: isolate;
            animation: portalBrandEntrance 0.8s ease-out both;
        }

        .dashboard-brand .logo-mark::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 18px;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.18) 0%, rgba(212, 175, 55, 0.08) 44%, rgba(212, 175, 55, 0) 72%);
            opacity: 0.52;
            transform: scale(0.96);
            animation: portalBrandHalo 4.8s ease-in-out infinite;
            z-index: -1;
            pointer-events: none;
        }

        .dashboard-brand .logo-img {
            margin-bottom: 0;
            box-shadow: 0 0 18px rgba(212, 175, 55, 0.18);
            animation: portalBrandFloat 4.8s ease-in-out infinite, portalBrandGlow 3.6s ease-in-out infinite;
            transform-origin: center;
            transition: transform 0.28s ease, box-shadow 0.28s ease, filter 0.28s ease;
            display: block;
        }

        .dashboard-brand:hover .logo-img {
            transform: translateY(-2px) scale(1.04);
            box-shadow:
                0 0 22px rgba(212, 175, 55, 0.34),
                0 0 32px rgba(212, 175, 55, 0.14);
            filter: brightness(1.05);
        }

        .dashboard-brand h3 {
            margin-top: 14px;
            background: linear-gradient(135deg, #fff 0%, #f2deab 40%, var(--accent-gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        @keyframes portalBrandFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }

        @keyframes portalBrandEntrance {
            0% {
                opacity: 0;
                transform: translateY(8px) scale(0.95);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes portalBrandGlow {
            0%, 100% {
                box-shadow:
                    0 0 12px rgba(212, 175, 55, 0.18),
                    0 0 20px rgba(212, 175, 55, 0.1);
            }
            50% {
                box-shadow:
                    0 0 18px rgba(212, 175, 55, 0.32),
                    0 0 28px rgba(212, 175, 55, 0.14);
            }
        }

        @keyframes portalBrandHalo {
            0%, 100% {
                opacity: 0.4;
                transform: scale(0.96);
            }
            50% {
                opacity: 0.62;
                transform: scale(1.02);
            }
        }

        @keyframes portalBrandShine {
            0% {
                left: -32%;
                opacity: 0;
            }
            15% {
                opacity: 1;
            }
            100% {
                left: 118%;
                opacity: 0;
            }
        }

        .sidebar-menu {
            list-style: none;
            padding: 20px 15px;
            flex: 1;
            overflow-y: auto;
        }

        .sidebar-menu li {
            margin-bottom: 5px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.2s;
            font-weight: 500;
            font-size: 14px;
        }

        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: rgba(212, 175, 55, 0.1);
            color: var(--accent-gold);
        }

        .sidebar-menu a i {
            width: 25px;
            margin-right: 10px;
            font-size: 16px;
            text-align: center;
        }

        .sidebar-menu a {
            position: relative;
        }

        .sidebar-menu a span:not(.nav-alert-dot) {
            flex: 1;
        }

        .nav-alert-dot {
            display: none;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ef4444;
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.25);
            flex-shrink: 0;
            margin-left: auto;
        }

        .nav-alert-dot.is-on {
            display: inline-block;
        }

        .portal-bell-wrap {
            position: relative;
            width: 22px;
            height: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .portal-bell-wrap .nav-alert-dot {
            position: absolute;
            top: -2px;
            right: -2px;
            margin-left: 0;
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            flex: 1;
            padding: 30px;
            background: var(--primary-bg);
            min-height: 100vh;
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .content-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-main);
        }

        .content-header p {
            color: var(--text-muted);
            margin-top: 5px;
        }

        /* Common Components */
        .content-body {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }

        .content-body h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .portal-utility {
            padding: 16px 15px 0;
        }

        .portal-utility-card {
            position: relative;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: visible;
        }

        .portal-notification-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: transparent;
            border: none;
            color: var(--text-main);
            cursor: pointer;
            text-align: left;
        }

        .portal-notification-btn:hover {
            background: rgba(212, 175, 55, 0.08);
        }

        .portal-notification-copy {
            display: flex;
            flex-direction: column;
            min-width: 0;
            gap: 2px;
        }

        .portal-notification-copy strong {
            font-size: 13px;
        }

        .portal-notification-copy span {
            font-size: 11px;
            color: var(--text-muted);
        }

        .portal-notification-badge {
            margin-left: auto;
            background: #f44336;
            color: #fff;
            min-width: 24px;
            padding: 4px 7px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
        }

        .portal-notification-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 10px);
            left: 0;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
            z-index: 1200;
            overflow: hidden;
        }

        .portal-notification-dropdown.active {
            display: block;
        }

        .portal-notification-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .portal-notification-header h4 {
            margin: 0;
            font-size: 14px;
            color: #173d32;
        }

        .portal-notification-header a {
            color: #173d32;
            font-size: 11px;
            text-decoration: none;
        }

        .portal-notification-list {
            max-height: 340px;
            overflow-y: auto;
        }

        .portal-notification-item {
            display: block;
            padding: 12px 15px;
            color: inherit;
            text-decoration: none;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
        }

        .portal-notification-item:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        .portal-notification-item.unread {
            background: rgba(212, 175, 55, 0.06);
        }

        .portal-notification-empty {
            padding: 28px 18px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
        }

        /* Form Styles */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--text-muted); font-size: 14px; font-weight: 500; }
        .form-control { width: 100%; padding: 12px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-main); font-family: 'Inter', sans-serif; transition: all 0.3s ease; }
        .form-control:focus { border-color: var(--accent-gold); outline: none; box-shadow: 0 0 0 2px rgba(212, 175, 55, 0.2); }
        
        /* Buttons */
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; border: none; }
        .btn-primary { background: var(--accent-gold); color: #000; }
        .btn-primary:hover { background: var(--accent-gold-hover); }
        .btn-info { background: var(--info-color); color: white; }
        .btn-info:hover { filter: brightness(1.1); }
        .btn-danger { background: var(--error-color); color: white; }
        .btn-danger:hover { filter: brightness(1.1); }
        .btn-success { background: var(--success-color); color: white; }
        .btn-success:hover { filter: brightness(1.1); }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--card-bg); padding: 25px; border-radius: 12px; border: 1px solid var(--border-color); transition: transform 0.3s ease, box-shadow 0.3s ease; position: relative; overflow: hidden; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3); border-color: var(--accent-gold); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; }
        .stat-card.gold::before { background: var(--accent-gold); }
        .stat-card.green::before { background: var(--success-color); }
        .stat-card.blue::before { background: var(--info-color); }
        .stat-card.orange::before { background: var(--warning-color); }
        .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .stat-header h3 { font-size: 14px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
        .gold .stat-icon { background: rgba(212, 175, 55, 0.1); color: var(--accent-gold); }
        .green .stat-icon { background: rgba(76, 175, 80, 0.1); color: var(--success-color); }
        .blue .stat-icon { background: rgba(33, 150, 243, 0.1); color: var(--info-color); }
        .orange .stat-icon { background: rgba(255, 152, 0, 0.1); color: var(--warning-color); }
        .stat-value { font-size: 32px; font-weight: 700; color: var(--text-main); margin-bottom: 5px; }
        .stat-desc { font-size: 12px; color: var(--text-muted); }

        /* Tables */
        .table-container { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { text-align: left; padding: 15px; color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border-color); font-size: 13px; text-transform: uppercase; }
        .data-table td { padding: 15px; border-bottom: 1px solid var(--border-color); color: var(--text-main); font-size: 14px; }
        .data-table tr:hover { background-color: rgba(255, 255, 255, 0.02); }

        /* Status Badges */
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block; }
        .status-active { background: rgba(76, 175, 80, 0.15); color: #4caf50; border: 1px solid rgba(76, 175, 80, 0.3); }
        .status-pending { background: rgba(255, 152, 0, 0.15); color: #ff9800; border: 1px solid rgba(255, 152, 0, 0.3); }
        .status-closed, .status-rejected { background: rgba(244, 67, 54, 0.15); color: #f44336; border: 1px solid rgba(244, 67, 54, 0.3); }

        /* Modal Styles */
        .portal-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(5px); }
        .portal-modal.active { display: flex; animation: fadeIn 0.3s ease; }
        .portal-modal-content { background: var(--card-bg); border-radius: 12px; width: 90%; max-width: 900px; max-height: 85vh; display: flex; flex-direction: column; border: 1px solid var(--border-color); box-shadow: 0 20px 50px rgba(0,0,0,0.5); animation: slideUp 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .portal-modal-header { padding: 20px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .portal-modal-header h2 { font-size: 20px; color: var(--text-main); margin: 0; }
        .portal-close-btn { background: none; border: none; color: var(--text-muted); font-size: 24px; cursor: pointer; transition: color 0.2s; }
        .portal-close-btn:hover { color: var(--error-color); }
        .portal-modal-body { padding: 25px; overflow-y: auto; color: var(--text-muted); }
        .portal-modal-empty { text-align: center; padding: 40px; }
        .portal-modal-empty i { font-size: 48px; color: var(--border-color); margin-bottom: 15px; }

        /* Mobile Sidebar Toggle */
        .sidebar-toggle { display: none; position: fixed; top: 16px; left: 16px; z-index: 1100; background: var(--accent-gold); color: #000; border: none; width: 44px; height: 44px; border-radius: 10px; cursor: pointer; box-shadow: 0 12px 24px rgba(0,0,0,0.3); }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: min(280px, calc(100vw - 32px));
                box-shadow: 0 24px 48px rgba(0,0,0,0.35);
            }
            .sidebar.active { transform: translateX(0); }
            .sidebar-header h3, .sidebar-header p, .sidebar-menu a span:not(.nav-alert-dot) { display: block; }
            .sidebar-menu a { justify-content: flex-start; padding: 12px 15px; }
            .sidebar-menu a i { margin-right: 10px; font-size: 16px; }
            .logo-img { width: 50px; height: 50px; }
            .main-content { margin-left: 0; padding: 76px 24px 24px; }
            .content-header { gap: 16px; align-items: flex-start; flex-wrap: wrap; }
            .sidebar-toggle { display: inline-flex; align-items: center; justify-content: center; font-size: 22px; }
        }

        @media (max-width: 768px) {
            .main-content { padding: 76px 16px 20px; }
            .content-body,
            .portal-modal-content { width: 100%; }
            .content-body { padding: 18px; }
            .portal-modal-content { max-height: calc(100vh - 32px); }
        }

        @media (max-width: 576px) {
            .main-content {
                padding-left: 12px;
                padding-right: 12px;
            }

            .content-body,
            .stat-card {
                padding: 16px;
            }

            .portal-modal-header,
            .portal-modal-body {
                padding-left: 16px;
                padding-right: 16px;
            }
        }
    </style>
    <?php require_once __DIR__ . '/../../includes/auth_popups.php'; ?>

    <style>
        #sidebarToggle {
            width:40px!important;height:40px!important;min-width:40px;
            border:1px solid rgba(212,175,55,.22)!important;
            border-radius:12px!important;background:#222016!important;
            color:#e3c773!important;font-size:18px!important;
            box-shadow:0 3px 10px rgba(0,0,0,.12)!important;
            align-items:center;justify-content:center;padding:0!important;
            cursor:pointer;transition:background .18s ease,border-color .18s ease;
        }
        #sidebarToggle:hover,#sidebarToggle[aria-expanded="true"] {background:#302a1b!important;border-color:rgba(212,175,55,.45)!important}
        #sidebarToggle:focus-visible {outline:2px solid #e3c773;outline-offset:3px}
    </style>

    <?php $premium_admin_page = in_array(basename($_SERVER['PHP_SELF']), ['users.php', 'payouts.php', 'manage-users.php', 'user-reports.php'], true); ?>
    <?php if ($premium_admin_page): ?>
        <link rel="stylesheet" href="admin-workspace.css?v=20261016">
        <script src="admin-workspace.js?v=20261016-2" defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="portal-layout.css?v=20261016-1">
    <link rel="stylesheet" href="../assets/css/soft-sage-theme.css?v=20261019-8">
    <link rel="stylesheet" href="../assets/css/portal-sage-theme.css?v=20261019-4">
    <link rel="stylesheet" href="portal-compact.css?v=<?php echo filemtime(__DIR__ . '/../portal-compact.css'); ?>">
    <link rel="stylesheet" href="portal-refinements.css?v=<?php echo filemtime(__DIR__ . '/../portal-refinements.css'); ?>">
    <link rel="stylesheet" href="portal-unified.css?v=<?php echo filemtime(__DIR__ . '/../portal-unified.css'); ?>">
    <script src="portal-feedback.js?v=<?php echo filemtime(__DIR__ . '/../portal-feedback.js'); ?>" data-user="<?php echo (int) ($_SESSION['user_id'] ?? 0); ?>"></script>
    <?php if (!empty($_SESSION['portal_feedback'])): ?>
    <script>window.addEventListener('DOMContentLoaded', function () { alert(<?php echo json_encode($_SESSION['portal_feedback'], JSON_HEX_TAG | JSON_HEX_AMP); ?>); });</script>
    <?php unset($_SESSION['portal_feedback']); endif; ?>
    <script src="portal-view-state.js?v=<?php echo filemtime(__DIR__ . '/../portal-view-state.js'); ?>" defer data-user="<?php echo (int) ($_SESSION['user_id'] ?? 0); ?>"></script>
</head>
<body class="portal-layout <?php echo $premium_admin_page ? 'admin-workspace' : ''; ?>">
<?php
$portal_current_page = basename($_SERVER['PHP_SELF']);
$portal_back_targets = [
    'users.php' => 'dashboard.php',

    'reports.php' => 'dashboard.php',
    'payouts.php' => 'dashboard.php',
    'manage-users.php' => 'dashboard.php',
    'subscription.php' => 'dashboard.php',
    'system-settings.php' => 'dashboard.php',
    'login_as_user.php' => 'users.php',
    'return_to_admin.php' => 'dashboard.php'
];
$portal_back_href = $portal_back_targets[$portal_current_page] ?? '';
?>
    <!-- Shared mobile header for all admin pages -->
    <header class="portal-mobile-topbar" aria-label="Admin portal header">
        <button type="button" id="sidebarToggle" class="sidebar-toggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation menu">
        ☰
    </button>
        <span class="portal-mobile-title">Portal Admin</span>
    </header>

    <div class="sidebar-overlay" id="portalSidebarOverlay"></div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggle = document.getElementById('sidebarToggle');
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('portalSidebarOverlay');
            
            if(toggle && sidebar) {
                toggle.addEventListener('click', function(event) {
                    // The icon is replaced below; do not treat its tap as an outside click.
                    event.stopPropagation();
                    setPortalSidebarState(!sidebar.classList.contains('active'));
                });

                if (overlay) {
                    overlay.addEventListener('click', function() {
                        setPortalSidebarState(false);
                    });
                }

                document.addEventListener('click', function(e) {
                    if (window.innerWidth <= 992) {
                        if (!sidebar.contains(e.target) && !toggle.contains(e.target) && sidebar.classList.contains('active')) {
                            setPortalSidebarState(false);
                        }
                    }
                });

                sidebar.querySelectorAll('a').forEach(function(link) {
                    link.addEventListener('click', function() {
                        if (window.innerWidth <= 992) {
                            setPortalSidebarState(false);
                        }
                    });
                });

                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape' && sidebar.classList.contains('active')) {
                        setPortalSidebarState(false);
                        toggle.focus();
                    }
                });

                window.addEventListener('resize', function() {
                    if (window.innerWidth > 992) {
                        setPortalSidebarState(false);
                    }
                });
            }
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggle = document.getElementById('sidebarToggle');
            if (toggle) {
                toggle.innerHTML = '<i class="fas fa-bars"></i>';
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        function setPortalSidebarState(isOpen) {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('portalSidebarOverlay');
            const toggle = document.getElementById('sidebarToggle');

            if (!sidebar || !overlay || !toggle) {
                return;
            }

            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.classList.remove('portal-sidebar-open');
                toggle.innerHTML = '<i class="fas fa-bars"></i>';
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-label', 'Open navigation menu');
                return;
            }

            sidebar.classList.toggle('active', isOpen);
            overlay.classList.toggle('active', isOpen);
            document.body.classList.toggle('portal-sidebar-open', isOpen);
            toggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
        }
    </script>
    <script>
        let lastPortalNotifCount = -1;

        function togglePortalNotifications() {
            openPortalNotifications();
        }

        function loadPortalNotifications() {
            return fetch('../api/notifications.php?action=get', {cache: 'no-store'})
                .then(response => response.json())
                .then(data => {
                    const list = document.getElementById('portal-notif-list');
                    if (!list) {
                        return;
                    }

                    if (data.notifications && data.notifications.length > 0) {
                        list.innerHTML = data.notifications.map(function(notification) {
                            const link = normalizePortalNotifLink(notification.link || '');
                            return `
                                <a class="portal-notification-item ${notification.is_read == 0 ? 'unread' : ''}" onclick="handlePortalNotifClick(${notification.id}, '${link}'); return false;" href="${link || '#'}">
                                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                                        <div style="width: 34px; height: 34px; border-radius: 50%; background: ${getPortalNotifColor(notification.type)}; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                            <i class="fas ${getPortalNotifIcon(notification.type)}" style="color: white; font-size: 12px;"></i>
                                        </div>
                                        <div style="flex: 1; min-width: 0;">
                                            <div style="font-weight: 600; font-size: 12px; color: var(--text-main); margin-bottom: 3px;">${escapePortalHtml(notification.title)}</div>
                                            <div style="font-size: 11px; line-height: 1.35; color: var(--text-muted);">${escapePortalHtml(notification.message)}</div>
                                            <div style="font-size: 10px; color: var(--text-muted); margin-top: 5px;">${formatPortalNotifTime(notification.created_at)}</div>
                                        </div>
                                        ${notification.is_read == 0 ? '<div style="width: 7px; height: 7px; background: var(--accent-gold); border-radius: 50%; margin-top: 5px;"></div>' : ''}
                                    </div>
                                </a>
                            `;
                        }).join('');
                    } else {
                        list.innerHTML = '<div class="portal-notification-empty"><i class="fas fa-bell-slash" style="font-size: 22px; margin-bottom: 8px; opacity: 0.5;"></i><br>No notifications yet.</div>';
                    }
                });
        }

        let portalNotifRequest = 0;

        function updatePortalNotifCount() {
            const request = ++portalNotifRequest;
            return fetch('../api/notifications.php?action=count', {cache: 'no-store'})
                .then(response => response.json())
                .then(data => {
                    if (!data.success || request !== portalNotifRequest) return;
                    const badge = document.getElementById('portal-notif-count');
                    if (!badge) {
                        return;
                    }

                    const newCount = parseInt(data.count || 0, 10);
                    badge.setAttribute('aria-label', newCount + ' unread notifications');
                    if (newCount > 0) {
                        badge.textContent = newCount > 99 ? '99+' : String(newCount);
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }

                    const dots = data.dots || {};
                    const notifDot = document.getElementById('portal-notif-dot');
                    if (notifDot) {
                        notifDot.classList.toggle('is-on', newCount > 0);
                    }
                    document.querySelectorAll('[data-nav-dot]').forEach(function(dot) {
                        const key = dot.getAttribute('data-nav-dot');
                        dot.classList.toggle('is-on', Boolean(dots[key]));
                    });

                    if (lastPortalNotifCount !== -1 && newCount !== lastPortalNotifCount) {
                        loadPortalNotifications();
                    }

                    lastPortalNotifCount = newCount;
                }).catch(function() { /* Retry after a network failure on the next refresh. */ });
        }

        function handlePortalNotifClick(id, link) {
            ++portalNotifRequest;
            return fetch('../api/notifications.php?action=mark_read', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            }).then(response => response.json()).then(function(data) {
                if (!data.success) throw new Error('Unable to mark notification as read');
                ++portalNotifRequest;
                const targetLink = normalizePortalNotifLink(link || '');
                if (targetLink) {
                    window.location.href = targetLink;
                } else {
                    return Promise.all([loadPortalNotifications(), updatePortalNotifCount()]);
                }
            }).catch(function() { updatePortalNotifCount(); });
        }

        function markAllPortalAsRead() {
            return fetch('../api/notifications.php?action=mark_all_read', {method: 'POST'})
                .then(response => response.json())
                .then(function(data) {
                    if (!data.success) throw new Error('Unable to mark notifications as read');
                    // Ignore counts requested before the read was saved.
                    ++portalNotifRequest;
                    lastPortalNotifCount = 0;
                    const badge = document.getElementById('portal-notif-count');
                    if (badge) {
                        badge.textContent = '0';
                        badge.style.display = 'none';
                        badge.setAttribute('aria-label', '0 unread notifications');
                    }
                    const dot = document.getElementById('portal-notif-dot');
                    if (dot) dot.classList.remove('is-on');
                    return Promise.all([loadPortalNotifications(), updatePortalNotifCount()]);
                }).catch(function() {
                    // Keep the unread badge when saving fails; allow another attempt.
                    loadPortalNotifications();
                    updatePortalNotifCount();
                });
        }

        function normalizePortalNotifLink(link) {
            if (!link) {
                return '';
            }

            if (link.indexOf('portal/') === 0) {
                return link.substring(7);
            }

            return link;
        }

        function getPortalNotifIcon(type) {
            const icons = {
                'new_order': 'fa-shopping-cart',
                'order_status': 'fa-sync-alt',
                'payment_received': 'fa-money-bill-wave',
                'payment_confirmed': 'fa-check-double',
                'payment_verified': 'fa-check-circle',
                'new_message': 'fa-comment-dots',
                'account_approved': 'fa-user-check',
                'account_rejected': 'fa-user-times',
                'store_registration': 'fa-caterer',
                'customer_registration': 'fa-user-plus',
                'subscription_expiring': 'fa-exclamation-triangle',
                'reschedule_request': 'fa-calendar-day',
                'reschedule_approved': 'fa-calendar-check',
                'reschedule_rejected': 'fa-calendar-xmark',
                'menu_change_request': 'fa-utensils',
                'menu_change_approved': 'fa-thumbs-up',
                'menu_change_rejected': 'fa-thumbs-down',
                'booking_reminder': 'fa-calendar-alt'
            };

            return icons[type] || 'fa-bell';
        }

        function getPortalNotifColor(type) {
            return '#426052';
        }

        function formatPortalNotifTime(value) {
            if (!value) {
                return 'Just now';
            }

            const parsed = new Date(value.replace(' ', 'T'));
            if (Number.isNaN(parsed.getTime())) {
                return value;
            }

            return parsed.toLocaleString();
        }

        function escapePortalHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        document.addEventListener('DOMContentLoaded', function() {
            fetch('../api/notifications.php?action=sync_view', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({page: location.pathname.split('/').pop()})
            }).then(response => response.json()).then(function() {
                ++portalNotifRequest;
                return updatePortalNotifCount();
            }).catch(updatePortalNotifCount);
            setInterval(function() {
                if (!document.hidden) updatePortalNotifCount();
            }, 2000);
            window.addEventListener('focus', updatePortalNotifCount);
            window.addEventListener('pageshow', updatePortalNotifCount);
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) updatePortalNotifCount();
            });

            document.addEventListener('click', function(event) {
                const wrapper = document.getElementById('portalNotifWrapper');
                const dropdown = document.getElementById('portalNotifDropdown');

                if (wrapper && dropdown && !dropdown.closest("dialog") && !wrapper.contains(event.target)) {
                    dropdown.classList.remove('active');
                }
            });
        });
    </script>
</toolcall_result>
