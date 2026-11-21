<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// Ensure config and login check
if (!isset($_SESSION['user_id'])) {
    // Ideally this should be handled by the parent page, but as a fallback:
    // header('Location: ../login.php');
    // exit();
}

$user_id = $_SESSION['user_id'] ?? 0;

// Fetch user details if not already available
if (!isset($customer)) {
    $header_user_query = "SELECT * FROM users WHERE id = '$user_id'";
    $header_user_result = mysqli_query($conn, $header_user_query);
    if ($header_user_result && mysqli_num_rows($header_user_result) > 0) {
        $customer = mysqli_fetch_assoc($header_user_result);
    }
}

// Get current page name for active state
$current_page = basename($_SERVER['PHP_SELF']);
$admin_impersonator = $_SESSION['admin_impersonator'] ?? null;
$needs_fullcalendar = in_array($current_page, ['dashboard.php', 'book-service.php'], true);

$unread_msg_count = 0;
if ($user_id > 0) {
    $unread_q = mysqli_query($conn, "SELECT COUNT(*) as count FROM messages WHERE receiver_id = '$user_id' AND is_read = 0 AND is_deleted = 0");
    if ($unread_q && $row = mysqli_fetch_assoc($unread_q)) {
        $unread_msg_count = (int) $row['count'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="soft-sage-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - Catering Portal' : 'Catering Portal'; ?></title>
    <?php
// Fetch site logo from system settings
$customer_header_logo = "../uploads/logo.png";
$_cust_logo_q = mysqli_query($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo'");
if ($_cust_logo_q && mysqli_num_rows($_cust_logo_q) > 0) {
    $_cust_logo_row = mysqli_fetch_assoc($_cust_logo_q);
    if (!empty($_cust_logo_row["setting_value"])) {
        $customer_header_logo = "../" . $_cust_logo_row["setting_value"];
    }
}
?>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($customer_header_logo); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars($customer_header_logo); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($customer_header_logo); ?>">

    <!-- Fonts and Icons -->
    <!-- Preconnect to external CDNs for faster font/icon loading -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <?php if ($needs_fullcalendar): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <?php endif; ?>

    <style>
        :root {
            --primary-bg: #101113;
            --secondary-bg: #17191d;
            --card-bg: #1f2328;
            --text-main: #f5f1e8;
            --text-muted: #c1b8aa;
            --accent-gold: #d6b35a;
            --accent-gold-hover: #bf983f;
            --border-color: rgba(255, 255, 255, 0.1);
            --input-bg: #23272d;
            --success-color: #4caf50;
            --error-color: #f44336;
            --info-color: #3498db;
            --warning-color: #ff9800;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background:
                radial-gradient(circle at top center, rgba(214, 179, 90, 0.08), transparent 22%),
                linear-gradient(180deg, #121315 0%, var(--primary-bg) 45%, #0d0e10 100%);
            color: var(--text-main);
            line-height: 1.6;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            animation: pageFadeIn 0.2s ease forwards;
        }

        @keyframes pageFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Navbar */
        /* Keep the document as the only page scroller on narrow screens. */
        @media (max-width: 768px) {
            html { overflow-y: auto !important; }
            body { overflow-x: clip; overflow-y: visible !important; }
        }

        .navbar {
            background-color: rgba(16, 17, 19, 0.9);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border-color);
            padding: 0 30px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
        }

        body.mobile-nav-open {
            overflow: hidden;
        }

        html:has(body.mobile-nav-open),
        body.mobile-nav-open {
            overflow: hidden !important;
        }

        .mobile-nav-toggle {
            display: none;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            align-items: center;
            justify-content: center;
            font-size: 18px;
            cursor: pointer;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text-main);
        }

        .logo img {
            height: 40px;
            border-radius: 8px;
            border: 1px solid var(--accent-gold);
        }

        .logo span {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -0.5px;
            line-height: 1.6;
            white-space: nowrap;
        }

        .dashboard-logo {
            position: relative;
        }

        .dashboard-logo .logo-mark {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            isolation: isolate;
            animation: dashboardLogoEntrance 0.8s ease-out both;
        }

        .dashboard-logo .logo-mark::before {
            content: '';
            position: absolute;
            inset: -6px;
            border-radius: 14px;
            background: radial-gradient(circle, rgba(214, 179, 90, 0.2) 0%, rgba(214, 179, 90, 0.08) 44%, rgba(214, 179, 90, 0) 72%);
            opacity: 0.5;
            transform: scale(0.96);
            animation: dashboardLogoHalo 4.8s ease-in-out infinite;
            z-index: -1;
            pointer-events: none;
        }

        .dashboard-logo .logo-mark::after {
            content: '';
            position: absolute;
            top: -12%;
            left: -42%;
            width: 36%;
            height: 124%;
            background: linear-gradient(115deg, transparent 0%, rgba(255, 255, 255, 0.08) 45%, rgba(255, 255, 255, 0.52) 50%, rgba(255, 255, 255, 0.08) 55%, transparent 100%);
            transform: skewX(-20deg);
            opacity: 0;
            pointer-events: none;
        }

        .dashboard-logo:hover .logo-mark::after {
            animation: dashboardLogoShine 1.05s ease;
        }

        .dashboard-logo img {
            box-shadow: 0 0 16px rgba(214, 179, 90, 0.2);
            animation: dashboardLogoFloat 4.8s ease-in-out infinite, dashboardLogoGlow 3.6s ease-in-out infinite;
            transform-origin: center;
            transition: transform 0.28s ease, box-shadow 0.28s ease, filter 0.28s ease;
            display: block;
        }

        .dashboard-logo:hover img {
            transform: translateY(-2px) scale(1.04);
            box-shadow:
                0 0 20px rgba(214, 179, 90, 0.34),
                0 0 30px rgba(214, 179, 90, 0.16);
            filter: brightness(1.05);
        }

        .dashboard-logo span {
            background: linear-gradient(135deg, #fff 0%, #f2e1b6 42%, var(--accent-gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            transition: filter 0.28s ease;
        }

        .dashboard-logo:hover span {
            filter: brightness(1.08);
        }

        @keyframes dashboardLogoFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }

        @keyframes dashboardLogoEntrance {
            0% {
                opacity: 0;
                transform: translateY(8px) scale(0.95);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes dashboardLogoGlow {
            0%, 100% {
                box-shadow:
                    0 0 12px rgba(214, 179, 90, 0.18),
                    0 0 20px rgba(214, 179, 90, 0.1);
            }
            50% {
                box-shadow:
                    0 0 16px rgba(214, 179, 90, 0.32),
                    0 0 26px rgba(214, 179, 90, 0.14);
            }
        }

        @keyframes dashboardLogoHalo {
            0%, 100% {
                opacity: 0.38;
                transform: scale(0.96);
            }
            50% {
                opacity: 0.62;
                transform: scale(1.02);
            }
        }

        @keyframes dashboardLogoShine {
            0% {
                left: -42%;
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

        .nav-links {
            display: flex;
            align-items: center;
            gap: 16px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .mobile-nav-logout {
            display: none;
        }

        .nav-links a {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.15px;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
            line-height: 1.6;
            white-space: nowrap;
            transition: all 0.5s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
        }

        .nav-links a:hover, .nav-links a.active {
            color: var(--accent-gold);
            background: rgba(214, 179, 90, 0.08);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info {
            text-align: right;
            display: none; /* Hidden on very small screens */
        }

        @media (min-width: 768px) {
            .user-info {
                display: block;
            }
        }

        .user-name {
            font-weight: 600;
            font-size: 14px;
            display: block;
        }

        .user-role {
            font-size: 12px;
            color: var(--text-muted);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid var(--accent-gold);
            object-fit: cover;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            text-decoration: none;
        }

        .logout-btn:hover {
            background: var(--error-color);
            color: white;
            border-color: var(--error-color);
        }

        .nav-overlay {
            position: fixed;
            inset: 70px 0 0;
            background: rgba(0, 0, 0, 0.55);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            z-index: 950;
        }

        .nav-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .impersonation-banner {
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid rgba(212, 175, 55, 0.35);
            color: var(--accent-gold);
            padding: 12px 16px;
            border-radius: 10px;
            margin: 90px 30px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-return-admin {
            background: var(--accent-gold);
            color: #000;
            padding: 8px 14px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
        }

        /* Main Container */
        .customer-page-shell {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-height: 100dvh;
            width: 100%;
            flex-shrink: 0;
        }

        .customer-page-shell > .container {
            flex: 1 0 auto;
            /* Keep one compact gap below the fixed navigation on every customer page. */
            margin-top: 80px !important;
        }

        body:not(.embedded-booking-modal) > .customer-page-shell > .container {
            box-sizing: border-box;
            min-height: calc(100dvh - 80px);
        }

        .customer-page-shell > .main-footer {
            flex-shrink: 0;
        }

        @media (max-width: 768px) {
            .main-footer {
                --customer-footer-padding: 12px;
                --customer-footer-link-gap: 4px;
                font-size: 11px;
                line-height: 1.4;
            }
        }

        .container {
            max-width: 1400px;
            margin: 100px auto 40px;
            padding: 0 30px;
            width: 100%;
            flex: 1;
        }

        @media (min-width: 993px) {
            .customer-page-shell > .container:has(.payments-center, .payment-details-container, .customer-messages-page, .profile-main-grid, .booking-view-tabs) {
                max-width: none;
                width: 100%;
                padding-inline: 12px;
                box-sizing: border-box;
            }

            .customer-page-shell > .container .payments-center {
                max-width: none;
                width: 100%;
            }
        }

        /* Common Components */
        .btn {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            letter-spacing: 0.15px;
            -webkit-font-smoothing: antialiased;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-primary {
            background: var(--accent-gold);
            color: #000;
        }

        .btn-primary:hover {
            background: var(--accent-gold-hover);
            transform: translateY(-2px);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-main);
        }

        .btn-outline:hover {
            border-color: var(--accent-gold);
            color: var(--accent-gold);
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.015));
            border: 1px solid var(--border-color);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 18px 36px rgba(0, 0, 0, 0.16);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-main);
        }

        .section-link {
            color: var(--accent-gold);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border: 1px solid var(--accent-gold);
            border-radius: 20px;
            transition: all 0.3s ease;
        }

        .section-link:hover {
            background: var(--accent-gold);
            color: #000;
            transform: translateY(-2px);
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-muted);
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 4px rgba(214, 179, 90, 0.08);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-main);
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 2px rgba(212, 175, 55, 0.1);
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            color: var(--text-muted);
            font-size: 12px;
            padding: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table td {
            padding: 15px 10px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }

        .price {
            color: var(--accent-gold);
            font-weight: 600;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending { background: rgba(212, 175, 55, 0.1); color: var(--accent-gold); }
        .status-completed { background: rgba(76, 175, 80, 0.1); color: var(--success-color); }
        .status-cancelled, .status-rejected { background: rgba(244, 67, 54, 0.1); color: var(--error-color); }
        .status-confirmed { background: rgba(33, 150, 243, 0.1); color: var(--info-color); }

        /* Payment Status Badges */
        .payment-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .payment-badge.paid { background: rgba(76, 175, 80, 0.15); color: #4caf50; border: 1px solid rgba(76, 175, 80, 0.3); }
        .payment-badge.partial { background: rgba(255, 152, 0, 0.15); color: #ff9800; border: 1px solid rgba(255, 152, 0, 0.3); }
        .payment-badge.unpaid { background: rgba(244, 67, 54, 0.15); color: #f44336; border: 1px solid rgba(244, 67, 54, 0.3); }

        /* Timeline Styles */
        .order-timeline {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            padding: 20px 0;
        }
        .order-timeline::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--border-color);
            z-index: 0;
        }
        .timeline-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
            flex: 1;
        }
        .timeline-step .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 8px;
        }
        .timeline-step.completed .step-icon {
            background: rgba(76, 175, 80, 0.2);
            border-color: #4caf50;
            color: #4caf50;
        }
        .timeline-step.current .step-icon {
            background: rgba(212, 175, 55, 0.2);
            border-color: var(--accent-gold);
            color: var(--accent-gold);
            animation: pulse 2s infinite;
        }
        .timeline-step .step-label {
            font-size: 11px;
            color: var(--text-muted);
            text-align: center;
        }
        .timeline-step.completed .step-label,
        .timeline-step.current .step-label {
            color: var(--text-main);
            font-weight: 500;
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(212, 175, 55, 0.4); }
            50% { box-shadow: 0 0 0 10px rgba(212, 175, 55, 0); }
        }

        /* Progress Bar */
        .progress-bar-container {
            width: 100%;
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-gold), #f5c542);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        .progress-bar-fill.complete {
            background: linear-gradient(90deg, #4caf50, #81c784);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 40px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 2000;
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--card-bg);
            width: 95%;
            max-width: 800px;
            max-height: 90vh;
            border-radius: 15px;
            border: 1px solid var(--accent-gold);
            overflow: hidden;
            animation: slideUp 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--secondary-bg);
        }

        .modal-header h2 {
            font-size: 18px;
            color: var(--accent-gold);
        }

        .close-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 24px;
            cursor: pointer;
        }

        .modal-body {
            padding: 20px;
            overflow-y: auto;
            max-height: calc(90vh - 80px);
        }

        /* Caterer Grid & Cards */
        .caterer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 16px;
        }

        .caterer-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s;
            text-decoration: none;
            color: var(--text-main);
            display: flex;
            flex-direction: column;
        }

        .caterer-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-gold);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .caterer-image {
            height: 130px;
            width: 100%;
            object-fit: cover;
            background: #2a2a2a;
        }

        .caterer-content {
            padding: 14px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .caterer-name {
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 5px;
            color: var(--accent-gold);
        }

        .caterer-meta {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .caterer-stats {
            margin-top: auto;
            padding-top: 10px;
            border-top: 1px solid var(--border-color);
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
        }

        /* Product Grid & Cards */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .product-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .product-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-gold);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .product-image-container {
            height: 150px;
            overflow: hidden;
            position: relative;
        }

        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .product-card:hover .product-image {
            transform: scale(1.05);
        }

        .product-content {
            padding: 14px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .product-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-main);
        }

        .product-description {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 12px;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .product-price {
            font-size: 16px;
            font-weight: 700;
            color: var(--accent-gold);
            margin-top: auto;
            margin-bottom: 12px;
        }

        .product-actions {
            display: flex;
            gap: 10px;
        }

        .btn-order {
            flex: 1;
            background: var(--accent-gold);
            color: #000;
            border: none;
            padding: 10px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .btn-order:hover {
            background: var(--accent-gold-hover);
        }

        /* Tabs */
        .section-tabs {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }

        .section-tab {
            padding: 10px 20px;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 2px solid transparent;
        }

        .section-tab:hover {
            color: var(--text-main);
        }

        .section-tab.active {
            color: var(--accent-gold);
            border-bottom-color: var(--accent-gold);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .navbar {
                padding: 0 16px;
                gap: 12px;
                /* Let the fixed drawer use the viewport as its containing block. */
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
            }

            .mobile-nav-toggle {
                display: inline-flex;
                flex-shrink: 0;
                width: 44px;
                height: 44px;
            }

            .notification-btn, .user-avatar, .logout-btn {
                min-width: 44px;
                min-height: 44px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .container {
                padding: 0 16px;
                margin-top: 90px;
            }

            .logo span {
                font-size: 16px;
            }

            .user-menu {
                gap: 10px;
                margin-left: auto;
            }

            .notification-wrapper {
                margin-right: 0 !important;
                position: static !important;
            }

            .notification-dropdown {
                position: fixed !important;
                top: 82px !important;
                left: 16px !important;
                right: 16px !important;
                width: auto !important;
                max-width: none !important;
            }

            .nav-links {
                position: fixed;
                top: 70px;
                left: 0;
                right: auto;
                bottom: auto;
                width: min(240px, 80vw);
                height: auto;
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 4px;
                padding: 12px;
                background: rgba(20, 20, 20, 0.98);
                border: 1px solid var(--border-color);
                border-radius: 0 18px 18px 0;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.45);
                transform: translateX(-100%);
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.25s ease, transform 0.25s ease;
                z-index: 980;
                max-height: calc(100dvh - 70px);
                padding-bottom: max(12px, env(safe-area-inset-bottom));
                box-sizing: border-box;
                overflow-y: auto;
                overscroll-behavior: contain;
                visibility: hidden;
            }

            .nav-links.active {
                visibility: visible;
                opacity: 1;
                pointer-events: auto;
                transform: translateX(0);
            }

            .nav-links a {
                width: 100%;
                box-sizing: border-box;
                min-height: 44px;
                justify-content: flex-start;
                padding: 10px 12px;
                height: auto;
                text-align: left;
                white-space: normal;
            }

            .nav-links > li {
                min-width: 0;
                flex-shrink: 0;
            }

            .nav-links .mobile-nav-logout {
                display: block;
                grid-column: 1 / -1;
                position: static;
                margin: 0;
                padding: 0;
                border-top: 0;
                background: transparent;
            }

            .nav-links .mobile-nav-logout a {
                color: var(--text-muted);
                background: transparent;
                border-radius: 8px;
            }

            .soft-sage-theme .navbar .nav-links .mobile-nav-logout a {
                background: transparent !important;
                border: 0 !important;
            }

            .user-menu > .logout-btn {
                display: none;
            }
        }

        @media (max-width: 576px) {
            .logo > span:not(.logo-mark) {
                display: none;
            }

            .user-avatar {
                width: 36px;
                height: 36px;
            }

            .logout-btn {
                width: 32px;
                height: 32px;
            }
        }

        /* Added from dashboard.php for global use */
        /* Hero Section */
        .hero-section {
            background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('../uploads/background/background.png');
            background-size: cover;
            background-position: center;
            border-radius: 20px;
            padding: 60px 40px;
            text-align: center;
            margin-bottom: 40px;
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        .hero-title {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 15px;
            background: linear-gradient(45deg, #ffffff, #d6b35a);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-subtitle {
            color: var(--text-muted);
            font-size: 18px;
            margin-bottom: 30px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Search Bar */
        .search-container {
            max-width: 600px;
            margin: 0 auto;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 18px 25px;
            padding-right: 60px;
            border-radius: 50px;
            border: 2px solid var(--border-color);
            background: rgba(30, 30, 30, 0.9);
            color: var(--text-main);
            font-size: 16px;
            transition: all 0.3s;
            backdrop-filter: blur(5px);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.2);
        }

        .search-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--accent-gold);
            border: none;
            color: #000;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .search-btn:hover {
            transform: translateY(-50%) scale(1.05);
            background: var(--accent-gold-hover);
        }

        /* Quick Categories */
        .quick-categories {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .category-chip {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            padding: 8px 20px;
            border-radius: 20px;
            color: var(--text-main);
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .category-chip:hover {
            background: rgba(212, 175, 55, 0.1);
            border-color: var(--accent-gold);
            color: var(--accent-gold);
            transform: translateY(-2px);
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }

        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Finance Card Styles */
        .finance-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .finance-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 600;
            border-bottom: 2px solid transparent;
        }

        .tab-btn.active {
            color: var(--accent-gold);
            border-bottom-color: var(--accent-gold);
        }

        .badge-verify {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Calendar Styles */
        .calendar-container {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 15px;
            border: 1px solid var(--border-color);
        }

        .fc-theme-standard .fc-scrollgrid {
            border-color: var(--border-color);
        }

        .fc-daygrid-day-number {
            color: var(--text-main);
            text-decoration: none;
        }

        .fc-col-header-cell-cushion {
            color: var(--accent-gold);
            text-decoration: none;
            font-weight: 600;
        }

        .fc-button-primary {
            background-color: var(--secondary-bg) !important;
            border-color: var(--border-color) !important;
            color: var(--text-main) !important;
        }

        .fc-button-active {
            background-color: var(--accent-gold) !important;
            border-color: var(--accent-gold) !important;
            color: #000 !important;
        }

        .fc-day-today {
            background: rgba(212, 175, 55, 0.1) !important;
        }

        /* Payment Card Styles */
        .payment-mobile-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            transition: all 0.3s ease;
        }

        .payment-mobile-card:hover {
            border-color: var(--accent-gold);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.1);
        }

        .payments-mobile-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
            padding: 0;
        }

        @media (max-width: 768px) {
            .payments-mobile-list {
                grid-template-columns: 1fr;
            }

            .payments-desktop-table {
                display: none !important;
            }
        }

        @media (min-width: 769px) {
            .payments-mobile-list {
                display: none !important;
            }

            .payments-desktop-table {
                display: block !important;
            }
        }

        .payment-mobile-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .payment-mobile-order {
            font-weight: 600;
            color: var(--accent-gold);
            font-size: 16px;
        }

        .payment-mobile-date {
            color: var(--text-muted);
            font-size: 12px;
            margin-top: 4px;
        }

        .payment-mobile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px;
        }

        .payment-mobile-label {
            display: block;
            color: var(--text-muted);
            font-size: 11px;
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .payment-mobile-value {
            font-size: 14px;
            color: var(--text-main);
            font-weight: 500;
        }

        /* Payments Summary Grid */
        .payments-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        /* Payments Tabs */
        .payments-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }

        /* Detail View Styles */
        .detail-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
        }

        @media (max-width: 992px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
        }

        .stat-card h3 {
            font-size: 16px;
            margin: 0 0 15px 0;
            color: var(--text-main);
        }

        .timeline-item {
            padding: 15px 0;
            border-bottom: 1px solid var(--border-color);
            position: relative;
            padding-left: 30px;
        }

        .timeline-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            position: absolute;
            left: 0;
            top: 20px;
        }

        .timeline-date {
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        .timeline-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
        }

        .timeline-desc {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .timeline-amount {
            font-size: 14px;
            font-weight: 600;
        }
    </style>
    <?php require_once __DIR__ . '/../../includes/auth_popups.php'; ?>

    <style>
        #mobileNavToggle {
            width:40px!important;height:40px!important;min-width:40px;
            border:1px solid rgba(212,175,55,.22)!important;
            border-radius:12px!important;background:#222016!important;
            color:#e3c773!important;font-size:18px!important;
            box-shadow:0 3px 10px rgba(0,0,0,.12)!important;
            align-items:center;justify-content:center;padding:0!important;
            cursor:pointer;transition:background .18s ease,border-color .18s ease;
        }
        #mobileNavToggle:hover,#mobileNavToggle[aria-expanded="true"] {background:#302a1b!important;border-color:rgba(212,175,55,.45)!important}
        #mobileNavToggle:focus-visible {outline:2px solid #e3c773;outline-offset:3px}
    </style>

    <link rel="stylesheet" href="../assets/css/soft-sage-theme.css?v=20261019-8">
    <style>
        @media (max-width: 768px) {
            .soft-sage-theme .navbar .nav-links .mobile-nav-logout a,
            .soft-sage-theme .navbar .nav-links .mobile-nav-logout a:hover,
            .soft-sage-theme .navbar .nav-links .mobile-nav-logout a:focus {
                background: transparent !important;
                border: 0 !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>
<body<?php echo (!empty($is_embedded_booking_modal) ? ' class="embedded-booking-modal"' : ''); ?>>
    <div class="customer-page-shell">

    <?php if ($admin_impersonator && ($admin_impersonator['role'] ?? '') === 'portal'): ?>
        <div class="impersonation-banner">
            <span><i class="fas fa-user-secret"></i> Admin view active. You are currently browsing as this customer.</span>
            <a href="../portal123/return_to_admin.php" class="btn-return-admin">Return to Admin</a>
        </div>
    <?php endif; ?>

    <!-- Navbar -->
    <nav class="navbar">
        <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Toggle navigation" aria-expanded="false" aria-controls="customerNavigation">
            <i class="fas fa-bars"></i>
        </button>

        <a href="dashboard.php" class="logo dashboard-logo">
            <span class="logo-mark">
                <img src="<?php echo htmlspecialchars($customer_header_logo); ?>" alt="Logo">
            </span>
            <span>Catering Portal</span>
        </a>

        <ul class="nav-links" id="customerNavigation">
            <?php if (isLoggedIn() && getUserRole() === 'customer'): ?>
                <li><a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-home"></i> Home</a></li>
                <li><a href="browse-caterer.php" class="<?php echo $current_page == 'browse-caterer.php' ? 'active' : ''; ?>"><i class="fas fa-caterer"></i> Browse Caterers</a></li>
                <li><a href="booking.php" class="<?php echo $current_page == 'booking.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> Bookings <span id="booking-reschedule-count" style="display:none;background:#ff4444;color:white;font-size:10px;padding:2px 6px;border-radius:10px;margin-left:5px;font-weight:bold;line-height:1;"></span></a></li>
                <li><a href="payments.php" class="<?php echo $current_page == 'payments.php' ? 'active' : ''; ?>"><i class="fas fa-wallet"></i> Payments</a></li>
                <li><a href="messages.php" class="<?php echo $current_page == 'messages.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i> Messages <?php if ($unread_msg_count > 0): ?><span style="background: #ff4444; color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: 5px; font-weight: bold; vertical-align: top; line-height: 1;"><?php echo $unread_msg_count; ?></span><?php endif; ?></a></li>
                <li><a href="profile.php" class="<?php echo $current_page == 'profile.php' ? 'active' : ''; ?>"><i class="fas fa-user"></i> Profile</a></li>
                <li class="mobile-nav-logout"><a href="../logout.php"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> Logout</a></li>
            <?php else: ?>
                <li><a href="../index.php"><i class="fas fa-home"></i> Home</a></li>
                <li><a href="browse-caterer.php" class="<?php echo $current_page == 'browse-caterer.php' ? 'active' : ''; ?>"><i class="fas fa-caterer"></i> Browse Caterers</a></li>
                <li><a href="../login.php"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                <li><a href="../register.php"><i class="fas fa-user-plus"></i> Register</a></li>
            <?php endif; ?>
        </ul>

        <?php if (isLoggedIn() && getUserRole() === 'customer'): ?>
        <div class="user-menu">
            <!-- Notification Bell -->
            <div class="notification-wrapper" style="position: relative; margin-right: 20px;">
                <button type="button" aria-label="Notifications" title="Notifications" class="notification-btn" onclick="toggleNotifications()" style="background: none; border: none; color: var(--text-main); font-size: 20px; cursor: pointer; position: relative;">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge" id="notif-count" style="display: none; position: absolute; top: -5px; right: -5px; background: #ff4444; color: white; font-size: 11px; padding: 2px 6px; border-radius: 10px; font-weight: 600;">0</span>
                </button>
                <div class="notification-dropdown" id="notif-dropdown" style="display: none; position: absolute; top: 45px; right: 0; width: 350px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); z-index: 1000; max-height: 450px; overflow: hidden;">
                    <div class="notif-header" style="padding: 15px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                        <h4 style="margin: 0; font-size: 16px; color: var(--text-main);">Notifications</h4>
                        <a href="#" onclick="markAllAsRead(); return false;" style="font-size: 12px; color: var(--accent-gold); text-decoration: none;">Mark all read</a>
                    </div>
                    <div class="notif-list" id="notif-list" style="max-height: 350px; overflow-y: auto;">
                        <!-- Notifications loaded here -->
                    </div>
                </div>
            </div>

            <?php 
            $profile_image = isset($customer['profile_image']) && !empty($customer['profile_image']) ? '../' . $customer['profile_image'] : '../uploads/logo.png';
            ?>
            <a href="profile.php" aria-label="View profile" title="View profile" style="display: inline-flex; border-radius: 50%;">
                <img src="<?php echo $profile_image; ?>" alt="Profile" class="user-avatar">
            </a>
            <a href="../logout.php" class="logout-btn" title="Logout" aria-label="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
        <?php endif; ?>
    </nav>

    <div class="nav-overlay" id="navOverlay"></div>

    <!-- Notification JavaScript -->
    <script>
        function setMobileNavState(isOpen) {
            const navLinks = document.querySelector('.nav-links');
            const navOverlay = document.getElementById('navOverlay');
            const navToggle = document.getElementById('mobileNavToggle');

            if (!navLinks || !navOverlay || !navToggle) {
                return;
            }

            navLinks.classList.toggle('active', isOpen);
            navOverlay.classList.toggle('active', isOpen);
            document.body.classList.toggle('mobile-nav-open', isOpen);
            navToggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
            navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelector('.nav-links');
            const navToggle = document.getElementById('mobileNavToggle');
            const navOverlay = document.getElementById('navOverlay');

            if (navLinks && navToggle && navOverlay) {
                navToggle.addEventListener('click', function() {
                    setMobileNavState(!navLinks.classList.contains('active'));
                });

                navOverlay.addEventListener('click', function() {
                    setMobileNavState(false);
                });

                navLinks.querySelectorAll('a').forEach(function(link) {
                    link.addEventListener('click', function() {
                        if (window.innerWidth <= 768) {
                            setMobileNavState(false);
                        }
                    });
                });

                window.addEventListener('resize', function() {
                    if (window.innerWidth > 768) {
                        setMobileNavState(false);
                    }
                });

                // Prevent reload for current active link
                navLinks.querySelectorAll('a').forEach(function(link) {
                    link.addEventListener('click', function(e) {
                        const targetHref = this.getAttribute('href');
                        const currentPath = window.location.pathname.split('/').pop();

                        // Prevent reload if clicking the current page's link
                        if (this.classList.contains('active') || targetHref === currentPath) {
                            e.preventDefault();
                            e.stopPropagation(); // Prevent click from bubbling to page elements (e.g. calendar modal)
                        }
                    });
                });
            }
        });

        // Toggle notification dropdown
        function toggleNotifications() {
            const dropdown = document.getElementById('notif-dropdown');
            if (!dropdown) {
                return;
            }
            if (dropdown.style.display === 'none') {
                dropdown.style.display = 'block';
                loadNotifications();
            } else {
                dropdown.style.display = 'none';
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const wrapper = document.querySelector('.notification-wrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                const dropdown = document.getElementById('notif-dropdown');
                if (dropdown) {
                    dropdown.style.display = 'none';
                }
            }

            if (window.innerWidth <= 768) {
                const navLinks = document.querySelector('.nav-links');
                const navToggle = document.getElementById('mobileNavToggle');

                if (navLinks && navToggle && !navLinks.contains(e.target) && !navToggle.contains(e.target)) {
                    setMobileNavState(false);
                }
            }
        });

        // Load notifications
        function loadNotifications() {
            fetch('../api/notifications.php?action=get')
                .then(r => r.json())
                .then(data => {
                    const list = document.getElementById('notif-list');
                    if (!list) {
                        return;
                    }
                    if (data.notifications && data.notifications.length > 0) {
                        list.innerHTML = data.notifications.map(n => `
                            <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}" onclick="handleNotifClick(${n.id}, '${n.link || ''}')" style="padding: 12px 20px; border-bottom: 1px solid var(--border-color); cursor: pointer; transition: background 0.2s; ${n.is_read == 0 ? 'background: rgba(212,175,55,0.05);' : ''}">
                                <div style="display: flex; align-items: flex-start; gap: 12px;">
                                    <div style="width: 36px; height: 36px; border-radius: 50%; background: ${getNotifColor(n.type)}; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i class="fas ${getNotifIcon(n.type)}" style="color: white; font-size: 14px;"></i>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-weight: 600; font-size: 13px; color: var(--text-main); margin-bottom: 3px;">${n.title}</div>
                                        <div style="font-size: 12px; color: var(--text-muted); line-height: 1.4;">${n.message}</div>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 5px;">${formatTime(n.created_at)}</div>
                                    </div>
                                    ${n.is_read == 0 ? '<div style="width: 8px; height: 8px; background: var(--accent-gold); border-radius: 50%; flex-shrink: 0; margin-top: 5px;"></div>' : ''}
                                </div>
                            </div>
                        `).join('');
                    } else {
                        list.innerHTML = '<div style="padding: 40px; text-align: center; color: var(--text-muted);"><i class="fas fa-bell-slash" style="font-size: 32px; margin-bottom: 10px; opacity: 0.5;"></i><br>No notifications</div>';
                    }
                });
        }

        // Follow each unread notice through navigation, tab, caterer and booking.
        function renderTargetBadges(targets) {
            document.querySelectorAll('.target-notification-badge').forEach(badge => badge.remove());
            const counts = new Map();
            function add(element, notices) {
                if (!element || !notices.length) return;
                if (!counts.has(element)) counts.set(element, new Set());
                notices.forEach(notice => counts.get(element).add(notice.notification_id));
            }
            document.querySelectorAll('a[href]').forEach(link => {
                const url = new URL(link.href, location.href);
                if (url.origin !== location.origin) return;
                const page = url.pathname.split('/').pop();
                const id = Number(url.searchParams.get('id') || url.searchParams.get('order_id') || url.searchParams.get('view') || 0);
                const notices = targets.filter(target => target.page === page && (!id || target.id === id));
                // Navigation shows the section count; detail links show only their booking.
                if (link.closest('nav, .nav-links') || link.classList.contains('payments-action-link')) add(link, notices);
                if (!link.classList.contains('payments-action-link')) return;
                const card = link.closest('.payments-list-card');
                add(card?.querySelector('.payment-group-toggle'), notices);
                const tab = link.closest('#pending-tab, #history-tab');
                if (tab) add(document.getElementById(tab.id === 'pending-tab' ? 'pending-tab-btn' : 'history-tab-btn'), notices);
            });
            counts.forEach((ids, element) => {
                const badge = document.createElement('span');
                badge.className = 'target-notification-badge';
                badge.textContent = ids.size > 99 ? '99+' : ids.size;
                badge.setAttribute('aria-label', ids.size + ' unread updates');
                badge.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;min-width:18px;height:18px;padding:0 5px;margin-left:6px;border-radius:12px;background:#b9231c;color:#fff;font-size:11px;font-weight:700;line-height:1;';
                element.append(badge);
            });
        }

        // Update unread count
        function updateNotifCount() {
            if (document.hidden) return;
            const params = new URLSearchParams(location.search);
            fetch('../api/notifications.php?action=sync_view', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                cache: 'no-store',
                body: JSON.stringify({page: location.pathname.split('/').pop(), id: params.get('id') || params.get('order_id') || params.get('view') || 0})
            })
                .then(r => r.json())
                .then(data => {
                    if (data.targets) renderTargetBadges(data.targets);
                    const badge = document.getElementById('notif-count');
                    const bookingBadge = document.getElementById('booking-reschedule-count');
                    if (bookingBadge && typeof data.booking_reschedule_count === 'number') {
                        bookingBadge.textContent = data.booking_reschedule_count > 99 ? '99+' : data.booking_reschedule_count;
                        bookingBadge.style.display = data.booking_reschedule_count > 0 ? 'inline-block' : 'none';
                    }
                    if (!badge) {
                        return;
                    }
                    if (data.count > 0) {
                        badge.textContent = data.count > 99 ? '99+' : data.count;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                });
        }

        // Mark as read and navigate
        function handleNotifClick(id, link) {
            fetch('../api/notifications.php?action=mark_read', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            }).then(() => {
                updateNotifCount();
                if (link) window.location.href = link;
            });
        }

        // Mark all as read
        function markAllAsRead() {
            fetch('../api/notifications.php?action=mark_all_read', {method: 'POST'})
                .then(() => {
                    loadNotifications();
                    updateNotifCount();
                });
        }

        // Helper functions
        function getNotifIcon(type) {
            const icons = {
                'new_order': 'fa-shopping-cart',
                'order_status': 'fa-sync-alt',
                'payment_received': 'fa-money-bill-wave',
                'payment_verified': 'fa-check-circle',
                'new_message': 'fa-comment-dots',
                'account_approved': 'fa-user-check',
                'account_rejected': 'fa-user-times',
                'subscription_expiring': 'fa-exclamation-triangle',
                'booking_reminder': 'fa-calendar-alt'
            };
            return icons[type] || 'fa-bell';
        }

        function getNotifColor(type) {
            const colors = {
                'new_order': '#4caf50',
                'order_status': '#2196f3',
                'payment_received': '#ff9800',
                'payment_verified': '#4caf50',
                'new_message': '#9c27b0',
                'account_approved': '#4caf50',
                'account_rejected': '#f44336',
                'subscription_expiring': '#ff9800',
                'booking_reminder': '#2196f3'
            };
            return colors[type] || 'var(--accent-gold)';
        }

        function formatTime(datetime) {
            const date = new Date(datetime);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000);

            if (diff < 60) return 'Just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
            return date.toLocaleDateString();
        }

        // Initial load and auto-refresh
        updateNotifCount();
        document.addEventListener('DOMContentLoaded', updateNotifCount);
        setInterval(updateNotifCount, 2000);
        window.addEventListener('focus', updateNotifCount);
        document.addEventListener('visibilitychange', function() { if (!document.hidden) updateNotifCount(); });
    </script>

    <div class="container">
