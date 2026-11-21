<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// Adjust path to config based on where this file is included from
// If included from caterer/dashboard.php, config is in ../config.php
// We can use __DIR__ to be safe: __DIR__ is caterer/includes
require_once __DIR__ . '/../../config.php';
requireLogin();

if (!in_array(getUserRole(), ['store', 'caterer'])) {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch caterer details for the header/sidebar
// Using a different variable name to avoid conflict if the main page also fetches it
$header_store_query = "SELECT u.*, sd.business_name, sd.is_open, sd.business_open_time, sd.business_close_time, sd.auto_schedule_enabled, sd.subscription_status, sd.subscription_expiry, sd.free_trial_start, sd.free_trial_used, sd.free_trial_expiry, u.status as user_status 
                FROM users u 
                LEFT JOIN store_details sd ON u.id = sd.user_id 
                WHERE u.id = '$user_id'";
$header_store_result = mysqli_query($conn, $header_store_query);
$header_store = mysqli_fetch_assoc($header_store_result);
$header_is_closed = ($header_store['user_status'] === 'closed');
$today_date = date('Y-m-d');
$header_has_active_subscription = !empty($header_store['subscription_expiry'])
    && ($header_store['subscription_status'] ?? '') === 'active'
    && $header_store['subscription_expiry'] >= $today_date;
$header_has_active_trial = !empty($header_store['free_trial_start'])
    && empty($header_store['free_trial_used'])
    && !empty($header_store['free_trial_expiry'])
    && $header_store['free_trial_expiry'] >= $today_date;
$header_has_operational_access = $header_has_active_subscription || $header_has_active_trial;
$header_access_locked = !$header_is_closed && !$header_has_operational_access;

// Check if caterer is open based on business hours
function isStoreOpen($open_time, $close_time) {
    if (empty($open_time) || empty($close_time)) return true; // Default to open if not set
    $current_time = date('H:i:s');
    return ($current_time >= $open_time && $current_time <= $close_time);
}

$header_is_business_hours = isStoreOpen($header_store['business_open_time'], $header_store['business_close_time']);
$header_store['is_open'] = isStoreAvailableNow($header_store['is_open'] ?? 0, $header_store['business_open_time'] ?? null, $header_store['business_close_time'] ?? null, $header_store['auto_schedule_enabled'] ?? 0);

$current_page = basename($_SERVER['PHP_SELF']);
$needs_fullcalendar = in_array($current_page, ['dashboard.php', 'booking-capacity.php'], true);
$store_allowed_pages_when_locked = ['messages.php', 'subscription.php'];

if ($header_access_locked && !in_array($current_page, $store_allowed_pages_when_locked, true)) {
    header('Location: subscription.php?access=locked');
    exit();
}

$admin_impersonator = $_SESSION['admin_impersonator'] ?? null;
$store_back_targets = [
    'add-menu.php' => 'dashboard.php',
    'booking-capacity.php' => 'dashboard.php',
    'event_preparation.php' => 'dashboard.php',
    'reservations.php' => 'dashboard.php',
    'order-details.php' => 'reservations.php',
    'detailed_report.php' => 'reports.php',
    'service_mapping.php' => 'add-menu.php'
];
$store_back_href = $store_back_targets[$current_page] ?? '';
if ($current_page === 'order-details.php' && isset($order_back_href)) {
    $store_back_href = $order_back_href;
}

function renderSidebarAlertDot($count, $label = 'new updates') {
    $count = intval($count);
    if ($count <= 0) {
        return '';
    }

    return '<span class="nav-alert-dot" title="' . htmlspecialchars($count . ' ' . $label, ENT_QUOTES) . '"></span>';
}

$sidebar_message_unread_count = 0;
$sidebar_messages_has_reply_column = mysqli_query($conn, "SHOW COLUMNS FROM messages LIKE 'original_message_id'");
$sidebar_messages_support_threads = $sidebar_messages_has_reply_column && mysqli_num_rows($sidebar_messages_has_reply_column) > 0;

if ($sidebar_messages_support_threads) {
    $sidebar_messages_q = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS count
         FROM messages m
         WHERE (m.sender_id = '$user_id' OR m.receiver_id = '$user_id')
           AND COALESCE(m.is_deleted, 0) = 0
           AND m.original_message_id IS NULL
           AND EXISTS (
                SELECT 1
                FROM messages m3
                WHERE ((m3.original_message_id = m.id) OR (m3.original_message_id IS NULL AND m3.id = m.id))
                  AND m3.receiver_id = '$user_id'
                  AND COALESCE(m3.is_read, 0) = 0
                  AND COALESCE(m3.is_deleted, 0) = 0
           )"
    );
} else {
    $sidebar_messages_q = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS count
         FROM messages
         WHERE receiver_id = '$user_id'
           AND COALESCE(is_read, 0) = 0
           AND COALESCE(is_deleted, 0) = 0"
    );
}

if ($sidebar_messages_q && ($sidebar_messages_row = mysqli_fetch_assoc($sidebar_messages_q))) {
    $sidebar_message_unread_count = intval($sidebar_messages_row['count'] ?? 0);
}

require_once __DIR__ . '/../../includes/payment_notification_helper.php';
$sidebar_payment_groups = getPaymentNotificationGroups($conn, (int) $user_id);
$sidebar_payment_pending_count = count($sidebar_payment_groups);

require_once __DIR__ . '/../../includes/reservation_notification_helper.php';
$sidebar_reservation_groups = getReservationNotificationGroups($conn, (int) $user_id);
$sidebar_reservations_attention_count = array_sum(array_map('count', $sidebar_reservation_groups));

?>
<?php
// Fetch site logo from system settings
$caterer_header_logo = '../uploads/logo.png';
$_logo_q = mysqli_query($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo'");
if ($_logo_q && mysqli_num_rows($_logo_q) > 0) {
    $_logo_row = mysqli_fetch_assoc($_logo_q);
    if (!empty($_logo_row['setting_value'])) {
        $caterer_header_logo = '../' . $_logo_row['setting_value'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="soft-sage-theme caterer-sage-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caterer - Catering Services</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($caterer_header_logo); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars($caterer_header_logo); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($caterer_header_logo); ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>">
    <!-- Preconnect to external CDNs for faster font/icon loading -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php if ($needs_fullcalendar): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #121212;
            --card-bg: #1e1e1e;
            --sidebar-bg: #070707;
            --sidebar-panel: #121212;
            --text-main: #e0e0e0;
            --text-muted: #a0a0a0;
            --accent-gold: #d4af37;
            --accent-blue: #3498db;
            --border-color: #333333;
            --success: #2ecc71;
            --danger: #e74c3c;
            --warning: #f1c40f;
            --sidebar-width: 300px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            
        }

        body.store-sidebar-open {
            overflow: hidden;
        }

        html {
            overflow-y: scroll;
            overflow-x: hidden;
            scrollbar-color: rgba(212, 175, 55, 0.78) #111111;
        }

        html::-webkit-scrollbar,
        .sidebar-menu::-webkit-scrollbar,
        .main-content::-webkit-scrollbar,
        body::-webkit-scrollbar,
        .notif-list::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }

        html::-webkit-scrollbar-track,
                .main-content::-webkit-scrollbar-track,
        body::-webkit-scrollbar,
        .notif-list::-webkit-scrollbar-track {
            background: #111111;
        }

        html::-webkit-scrollbar-thumb,
        .sidebar-menu::-webkit-scrollbar-thumb,
        .main-content::-webkit-scrollbar-thumb,
        body::-webkit-scrollbar,
        .notif-list::-webkit-scrollbar-thumb {
            background: rgba(212, 175, 55, 0.78);
            border-radius: 999px;
            border: 2px solid #111111;
        }

        html::-webkit-scrollbar-thumb:hover,
        .sidebar-menu::-webkit-scrollbar-thumb:hover,
        .main-content::-webkit-scrollbar-thumb:hover,
        body::-webkit-scrollbar,
        .notif-list::-webkit-scrollbar-thumb:hover {
            background: var(--accent-gold);
        }

        /* Keep the full sidebar scrollbar strip continuous with its black base. */
        .sidebar-menu {
            scrollbar-color: rgba(212, 175, 55, 0.78) #050505;
        }

        .sidebar-menu::-webkit-scrollbar,
        .sidebar-menu::-webkit-scrollbar-track,
        .sidebar-menu::-webkit-scrollbar-track-piece,
        .sidebar-menu::-webkit-scrollbar-button,
        .sidebar-menu::-webkit-scrollbar-corner {
            background-color: #050505;
        }

        .sidebar-menu::-webkit-scrollbar-thumb {
            border-color: #050505;
        }

          /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background:
                radial-gradient(circle at top center, rgba(212, 175, 55, 0.12), transparent 28%),
                linear-gradient(180deg, #080808 0%, #050505 100%);
            color: white;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1100;
            border-right: none;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.58);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            z-index: 1090;
        }

        .sidebar-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .quick-action-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(18, 18, 18, 0.92);
            color: var(--text-main);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 16px 32px rgba(0,0,0,0.32);
            font-size: 20px;
            backdrop-filter: blur(10px);
        }

        .sidebar-header {
            padding: 14px 14px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .sidebar-brand-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 18px;
            padding: 12px 12px;
            box-shadow: 0 14px 30px rgba(0,0,0,0.25);
        }

        .sidebar-brand-main {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-brand-copy {
            min-width: 0;
            flex: 1;
        }

        .sidebar-brand-eyebrow {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: rgba(255,255,255,0.45);
            margin-bottom: 6px;
        }

        .logo-img {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent-gold);
            background: #fff;
            box-shadow: 0 10px 24px rgba(0,0,0,0.28);
            flex-shrink: 0;
        }

        .sidebar-caterer-name {
            margin: 0;
            font-size: 17px;
            color: var(--accent-gold);
            font-weight: 700;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }

        .sidebar-caterer-meta {
            margin-top: 4px;
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.35;
        }

        .dashboard-brand-card {
            position: relative;
            overflow: hidden;
        }

        .dashboard-brand-card::after {
            content: '';
            position: absolute;
            top: -16%;
            left: -34%;
            width: 30%;
            height: 138%;
            background: linear-gradient(115deg, transparent 0%, rgba(255,255,255,0.08) 45%, rgba(255,255,255,0.4) 50%, rgba(255,255,255,0.08) 55%, transparent 100%);
            transform: skewX(-20deg);
            opacity: 0;
            pointer-events: none;
        }

        .dashboard-brand-card:hover::after {
            animation: dashboardBrandShine 1.05s ease;
        }

        .dashboard-brand-card .logo-mark {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            isolation: isolate;
            animation: dashboardBrandEntrance 0.8s ease-out both;
        }

        .dashboard-brand-card .logo-mark::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.18) 0%, rgba(212, 175, 55, 0.08) 45%, rgba(212, 175, 55, 0) 72%);
            opacity: 0.52;
            transform: scale(0.96);
            animation: dashboardBrandHalo 4.8s ease-in-out infinite;
            z-index: -1;
            pointer-events: none;
        }

        .dashboard-brand-card .logo-img {
            animation: dashboardBrandFloat 4.8s ease-in-out infinite, dashboardBrandGlow 3.6s ease-in-out infinite;
            transform-origin: center;
            transition: transform 0.28s ease, box-shadow 0.28s ease, filter 0.28s ease;
        }

        .dashboard-brand-card:hover .logo-img {
            transform: translateY(-2px) scale(1.04);
            box-shadow:
                0 0 22px rgba(212, 175, 55, 0.32),
                0 0 34px rgba(212, 175, 55, 0.14);
            filter: brightness(1.05);
        }

        .dashboard-brand-card .sidebar-caterer-name {
            background: linear-gradient(135deg, #fff 0%, #f2deab 40%, var(--accent-gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        @keyframes dashboardBrandFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }

        @keyframes dashboardBrandEntrance {
            0% {
                opacity: 0;
                transform: translateY(8px) scale(0.95);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes dashboardBrandGlow {
            0%, 100% {
                box-shadow:
                    0 10px 24px rgba(0,0,0,0.28),
                    0 0 10px rgba(212, 175, 55, 0.12);
            }
            50% {
                box-shadow:
                    0 10px 24px rgba(0,0,0,0.28),
                    0 0 18px rgba(212, 175, 55, 0.24);
            }
        }

        @keyframes dashboardBrandHalo {
            0%, 100% {
                opacity: 0.4;
                transform: scale(0.96);
            }
            50% {
                opacity: 0.64;
                transform: scale(1.02);
            }
        }

        @keyframes dashboardBrandShine {
            0% {
                left: -34%;
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

        .sidebar-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.04);
        }

        .sidebar-status-pill.status-open {
            color: var(--success);
        }

        .sidebar-status-pill.status-closed,
        .sidebar-status-pill.status-locked {
            color: var(--danger);
        }

        .sidebar-status-hours {
            margin-top: 6px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .sidebar-menu {
            list-style: none;
            padding: 10px 12px 18px;
            margin: 0;
            flex-grow: 1;
            overflow-y: scroll;
        }

        .nav-section {
            margin-bottom: 18px;
        }

        .nav-section-title {
            padding: 8px 12px 6px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: rgba(255,255,255,0.38);
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: none;
            font-size: 14px;
            border-radius: 12px;
            margin-bottom: 3px;
            position: relative;
        }

        .sidebar-menu li a:hover, .sidebar-menu li a.active {
            background: rgba(212, 175, 55, 0.12);
            color: #fff8dd;
        }

        .sidebar-menu li a.active {
            box-shadow: inset 0 0 0 1px rgba(212, 175, 55, 0.28);
        }

        .sidebar-menu li a.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 10px;
            bottom: 10px;
            width: 4px;
            border-radius: 999px;
            background: var(--accent-gold);
        }

        .sidebar-menu li a i {
            width: 20px;
            text-align: center;
            font-size: 16px;
            color: rgba(255,255,255,0.72);
            flex-shrink: 0;
        }

        .nav-item-text {
            min-width: 0;
        }

        .sidebar .section-notification-badge {
            margin-left: auto; min-width: 19px; height: 19px; padding: 0 5px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 999px; background: #b83d3d !important;
            color: #fff !important; font-size: 10px; font-weight: 700;
            line-height: 1; flex-shrink: 0;
        }
        .sidebar .section-notification-badge[hidden] { display: none !important; }
        .nav-alert-dot {
            margin-left: auto;
            width: 9px;
            height: 9px;
            border-radius: 999px;
            background: #ff4d5e;
            box-shadow: 0 0 0 3px rgba(255, 77, 94, 0.14), 0 0 12px rgba(255, 77, 94, 0.42);
            flex-shrink: 0;
        }

        .sidebar-menu li a.active i,
        .sidebar-menu li a:hover i {
            color: var(--accent-gold);
        }

        .sidebar-menu li a.nav-locked,
        .sidebar-menu li a.nav-locked:hover,
        .sidebar-menu li a.nav-locked.active {
            background: rgba(231, 76, 60, 0.08);
            color: #ff9d9d;
            cursor: not-allowed;
            pointer-events: none;
            box-shadow: inset 0 0 0 1px rgba(231, 76, 60, 0.24);
        }

        .sidebar-menu li a.nav-locked i,
        .sidebar-menu li a.nav-locked:hover i,
        .sidebar-menu li a.nav-locked.active i {
            color: var(--danger);
        }

        .sidebar-menu li a.nav-locked::before {
            display: none;
        }

        .sidebar-utility {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .sidebar-utility-card {
            position: relative;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            overflow: visible;
        }

        .notification-btn {
            background: none;
            border: none;
            color: var(--text-main);
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 13px 15px;
        }

        .notification-label {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .notification-label strong {
            font-size: 14px;
            color: var(--text-main);
        }

        .notification-label span {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Main Content */
        .main-content {
            min-width: 0;
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 30px;
            transition: margin-left 0.3s ease;
        }

        .mobile-topbar {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 14px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            background: #0a0a0a;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 995;
            height: 58px;
        }

        .mobile-topbar h2 {
            margin: 0;
            color: inherit;
            font-size: 18px;
        }

        .content-header {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .impersonation-banner {
            background: #c9d2c1;
            border: 1px solid #96a38e;
            color: #20372b;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        .impersonation-banner > span { display:flex; align-items:center; gap:10px; font-size:14px; line-height:1.6; }
        .impersonation-banner > span i { color:#245548; flex-shrink:0; }
        .impersonation-banner .btn-return-admin {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:44px;
            box-sizing:border-box;
            background:#173d32;
            border:1px solid #173d32;
            color:#edf1e7;
            padding:10px 16px;
            border-radius:9px;
            font-size:13px;
            font-weight:600;
            text-decoration:none;
            white-space:nowrap;
        }
        .impersonation-banner .btn-return-admin:hover { background:#245548; border-color:#245548; }
        .impersonation-banner .btn-return-admin:focus-visible { outline:2px solid #245548; outline-offset:3px; }
        @media(max-width:768px) {
            .impersonation-banner-wrap { margin-left:0!important; padding:72px 12px 0!important; }
            .impersonation-banner { padding:14px; gap:12px; }
            .impersonation-banner .btn-return-admin { width:100%; }
        }

        .page-title h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            color: var(--accent-gold);
        }

        .page-title p {
            margin: 5px 0 0;
            color: var(--text-muted);
            font-size: 14px;
        }

        /* Card Styles */
        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .card-header h3 {
            margin: 0;
            font-size: 18px;
            color: var(--text-main);
            font-weight: 600;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
            color: var(--text-muted);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            background: var(--primary-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            color: var(--text-main);
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-gold);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
            text-decoration: none;
            font-size: 14px;
            gap: 8px;
            background: transparent;
        }

        /* Filled primary button – can still be used for key actions */
        .btn-primary {
            background: var(--accent-gold);
            color: #000; /* Black text on gold for contrast */
            border-color: var(--accent-gold);
        }
        
        .btn-primary:hover {
            background: #b5952f;
            border-color: #b5952f;
            transform: translateY(-1px);
        }

        /* Neutral / secondary button */
        .btn-secondary {
            background: transparent;
            color: var(--text-muted);
            border-color: var(--border-color);
        }
        
        .btn-secondary:hover {
            color: var(--text-main);
            border-color: var(--accent-gold);
        }

        /* Outline buttons for actions (View / Update, etc.) */
        .btn-outline-blue {
            background: transparent;
            color: var(--accent-blue);
            border-color: var(--accent-blue);
        }

        .btn-outline-blue:hover {
            background: rgba(52, 152, 219, 0.08);
        }

        .btn-outline-gold {
            background: transparent;
            color: var(--accent-gold);
            border-color: var(--accent-gold);
        }

        .btn-outline-gold:hover {
            background: rgba(212, 175, 55, 0.08);
        }

        .btn-danger {
            background: transparent;
            color: var(--danger);
            border-color: var(--danger);
        }
        
        .btn-success {
            background: transparent;
            color: var(--success);
            border-color: var(--success);
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: left;
            padding: 15px;
            color: var(--accent-gold);
            font-size: 13px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border-color);
        }

        .table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
        }

        .table tr:hover td {
            background: rgba(255,255,255,0.02);
        }

        /* Status Badges - transparent with colored borders for clean look on dark bg */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            background: transparent;
            border: 1px solid var(--border-color);
        }
        
        .status-active,
        .status-confirmed,
        .status-open {
            background: transparent;
            color: var(--success);
            border-color: rgba(46, 204, 113, 0.7);
        }
        
        .status-pending {
            background: transparent;
            color: var(--warning);
            border-color: rgba(241, 196, 15, 0.7);
        }
        
        .status-closed,
        .status-cancelled {
            background: transparent;
            color: var(--danger);
            border-color: rgba(231, 76, 60, 0.7);
        }

        /* Alert Styles */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid transparent;
        }
        
        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            border-color: rgba(46, 204, 113, 0.2);
            color: var(--success);
        }
        
        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            border-color: rgba(231, 76, 60, 0.2);
            color: var(--danger);
        }

        /* Mobile Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                height: 100dvh;
                width: min(300px, calc(100vw - 28px));
                box-shadow: 0 24px 48px rgba(0,0,0,0.35);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 72px 18px 24px;
            }

            .mobile-menu-toggle,
            .mobile-topbar {
                display: flex;
            }

            .notification-dropdown {
                position: fixed !important;
                top: 82px !important;
                left: 16px !important;
                right: 16px !important;
                width: auto !important;
                max-width: none !important;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 68px 16px 24px;
            }
        }

        @media (max-width: 576px) {
            .quick-action-btn {
                width: 42px;
                height: 42px;
            }

            .sidebar {
                width: min(300px, calc(100vw - 20px));
            }

            .main-content {
                padding-left: 12px;
                padding-right: 12px;
                padding-top: 66px;
            }

            .content-header,
            .content-body,
            .modal-header,
            .modal-body {
                padding-left: 16px;
                padding-right: 16px;
            }

            .content-header {
                flex-wrap: wrap;
                gap: 12px;
            }
        }

        .mobile-menu-toggle {
            display: none;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            color: var(--text-main);
            font-size: 20px;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

        @media (max-width: 992px) {
            .mobile-menu-toggle {
                display: inline-flex;
            }
        }
        
        .locked-badge {
            font-size: 10px; 
            background: #e74c3c; 
            color: white; 
            padding: 2px 6px; 
            border-radius: 4px; 
            margin-left: 5px;
        }

        /* Modal Styles - Generic */
        .caterer-modal, .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0);
            z-index: 1050;
            justify-content: center;
            align-items: center;
        }
        
        .modal-dialog {
            width: 90%;
            max-width: 800px;
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            position: relative;
            z-index: 1051;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            border: 1px solid var(--border-color);
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 20px;
            color: var(--accent-gold);
        }
        
        .modal-body {
            padding: 20px;
            overflow-y: auto;
            color: var(--text-main);
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            text-align: right;
            background: var(--primary-bg);
            border-radius: 0 0 12px 12px;
        }

        .close-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 24px;
            cursor: pointer;
            transition: color 0.2s;
        }
        .close-btn:hover { color: var(--danger); }
        
        /* FullCalendar Overrides for Dark Mode */
        .fc-theme-standard td, .fc-theme-standard th {
            border-color: var(--border-color);
        }
        .fc-col-header-cell-cushion, .fc-daygrid-day-number {
            color: var(--text-main);
            text-decoration: none;
        }
        .fc-daygrid-day:hover {
            background-color: rgba(255,255,255,0.05);
        }
        .fc-button-primary {
            background-color: var(--accent-blue) !important;
            border-color: var(--accent-blue) !important;
        }

        /* Page Transition Overlay */
        #page-transition-overlay {
            position: fixed;
            inset: 0;
            background: #0a0a0a;
            z-index: 9999;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.22s ease;
        }
        #page-transition-overlay.fading-out {
            opacity: 1;
            pointer-events: auto;
        }
    </style>
    <?php require_once __DIR__ . '/../../includes/auth_popups.php'; ?>

    <style>
        .mobile-menu-toggle {
            width:40px!important;height:40px!important;min-width:40px;
            border:1px solid rgba(212,175,55,.22)!important;
            border-radius:12px!important;background:#222016!important;
            color:#e3c773!important;font-size:18px!important;
            box-shadow:0 3px 10px rgba(0,0,0,.12)!important;
            align-items:center;justify-content:center;padding:0!important;
            cursor:pointer;transition:background .18s ease,border-color .18s ease;
        }
        .mobile-menu-toggle:hover,.mobile-menu-toggle[aria-expanded="true"] {background:#302a1b!important;border-color:rgba(212,175,55,.45)!important}
        .mobile-menu-toggle:focus-visible {outline:2px solid #e3c773;outline-offset:3px}
    </style>

    <link rel="stylesheet" href="../assets/css/soft-sage-theme.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/soft-sage-theme.css'); ?>">
    <link rel="stylesheet" href="../assets/css/caterer-sage-theme.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/caterer-sage-theme.css'); ?>">
    <link rel="stylesheet" href="../assets/css/caterer-page-spacing.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/caterer-page-spacing.css'); ?>">
</head>
<?php $compact_workspace_enabled = in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['add-menu.php', 'inventory.php', 'service_mapping.php', 'payments.php', 'reviews.php', 'reports.php', 'subscription.php', 'profile.php', 'order-details.php'], true); ?>
<body class="caterer-page<?php echo $compact_workspace_enabled ? ' bounded-caterer-page' : ''; ?>">
    <!-- Page Transition Overlay -->
    <div id="page-transition-overlay"></div>

    <?php if ($admin_impersonator && ($admin_impersonator['role'] ?? '') === 'portal'): ?>
        <div class="impersonation-banner-wrap" style="margin-left: var(--sidebar-width); padding: 20px 30px 0;">
            <div class="impersonation-banner">
                <span><i class="fas fa-user-secret"></i> Admin view active. You are currently browsing as this caterer.</span>
                <a href="../portal123/return_to_admin.php" class="btn-return-admin">Return to Admin</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <?php 
            $profile_image = !empty($header_store['profile_image']) ? $header_store['profile_image'] : 'uploads/logo.png';
            // Handle relative path if needed
            if (strpos($profile_image, 'uploads') === 0) {
                $profile_image = '../' . $profile_image;
            }
            ?>
            <div class="sidebar-brand-card <?php echo $current_page === 'dashboard.php' ? 'dashboard-brand-card' : ''; ?>">
                <div class="sidebar-brand-main">
                    <span class="logo-mark">
                        <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="Logo" class="logo-img">
                    </span>
                    <div class="sidebar-brand-copy">
                        <div class="sidebar-brand-eyebrow">Caterer</div>
                        <h3 class="sidebar-caterer-name"><?php echo htmlspecialchars($header_store['business_name']); ?></h3>
                    </div>
                </div>
                <?php if ($header_is_closed): ?>
                    <div class="sidebar-status-pill status-locked">
                        <i class="fas fa-lock"></i>
                        <span>Account Closed</span>
                    </div>
                <?php elseif ($header_access_locked): ?>
                    <div class="sidebar-status-pill status-locked">
                        <i class="fas fa-credit-card"></i>
                        <span>Subscription Locked</span>
                    </div>
                    <div class="sidebar-status-hours">
                        Renew your subscription to unlock caterer operations.
                    </div>
                <?php else: ?>
                    <div class="sidebar-status-pill <?php echo $header_store['is_open'] ? 'status-open' : 'status-closed'; ?>">
                        <i class="fas fa-clock"></i>
                        <span><?php echo $header_store['is_open'] ? 'Open Now' : 'Closed'; ?></span>
                    </div>
                    <div class="sidebar-status-hours">
                        <?php echo date('g:i A', strtotime($header_store['business_open_time'] ?? '08:00:00')); ?> - <?php echo date('g:i A', strtotime($header_store['business_close_time'] ?? '17:00:00')); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Notification Bell for Caterer -->
        <div class="sidebar-utility">
            <div class="sidebar-utility-card notification-wrapper">
            <button class="notification-btn" onclick="toggleNotifications()" style="position: relative;">
                <i class="fas fa-bell"></i>
                <span class="notification-label">
                    <strong>Notifications</strong>
                    <span>Updates and reminders</span>
                </span>
                <span class="notification-badge" id="notif-count" style="display: none; margin-left: auto; background: #ff4444; color: white; font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 600;">0</span>
            </button>
            <div class="notification-dropdown" id="notif-dropdown" style="display: none; position: absolute; top: calc(100% + 10px); left: 0; right: 0; width: 320px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); z-index: 1000; max-height: 400px; overflow: hidden;">
                <div class="notif-header" style="padding: 12px 15px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="margin: 0; font-size: 14px; color: var(--text-main);">Notifications</h4>
                    <a href="#" onclick="markAllAsRead(); return false;" style="font-size: 11px; color: var(--accent-gold); text-decoration: none;">Mark all read</a>
                </div>
                <div class="notif-list" id="notif-list" style="max-height: 320px; overflow-y: auto;">
                    <!-- Notifications loaded here -->
                </div>
            </div>
            </div>
        </div>
        
        <ul class="sidebar-menu">
            <?php if ($header_is_closed): ?>
                <!-- Restricted Menu -->
                <li class="nav-section-title">Access</li>
                <li><a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard <span class="locked-badge">Locked</span></a></li>
                <li><a href="messages.php" class="<?php echo $current_page == 'messages.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i><span class="nav-item-text">Messages</span></a></li>
                <li class="nav-section-title">Locked</li>
                <li><a href="#" style="opacity: 0.5; cursor: not-allowed;"><i class="fas fa-utensils"></i> Menu <span class="locked-badge">Locked</span></a></li>
                <li><a href="#" style="opacity: 0.5; cursor: not-allowed;"><i class="fas fa-calendar-check"></i> Reservations <span class="locked-badge">Locked</span></a></li>
                
                <li><a href="#" style="opacity: 0.5; cursor: not-allowed;"><i class="fas fa-chart-bar"></i> Reports <span class="locked-badge">Locked</span></a></li>
                <li><a href="#" style="opacity: 0.5; cursor: not-allowed;"><i class="fas fa-credit-card"></i> Subscription <span class="locked-badge">Locked</span></a></li>
                <li><a href="#" style="opacity: 0.5; cursor: not-allowed;"><i class="fas fa-user"></i> Profile <span class="locked-badge">Locked</span></a></li>
            <?php elseif ($header_access_locked): ?>
                <li class="nav-section-title">Access</li>
                <li><a href="#" class="nav-locked"><i class="fas fa-tachometer-alt"></i> Dashboard <span class="locked-badge">Locked</span></a></li>
                <li><a href="#" class="nav-locked"><i class="fas fa-calendar-check"></i> Reservations <span class="locked-badge">Locked</span></a></li>
                <li><a href="#" class="nav-locked"><i class="fas fa-calendar-days"></i> Booking Capacity <span class="locked-badge">Locked</span></a></li>
                <li><a href="messages.php" class="<?php echo $current_page == 'messages.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i><span class="nav-item-text">Messages</span></a></li>
                <li><a href="#" class="nav-locked"><i class="fas fa-tasks"></i> Event Preparation <span class="locked-badge">Locked</span></a></li>

                <li class="nav-section-title">Catalog</li>
                <li><a href="#" class="nav-locked"><i class="fas fa-utensils"></i> Menu Management <span class="locked-badge">Locked</span></a></li>
                <li><a href="#" class="nav-locked"><i class="fas fa-boxes"></i> Inventory <span class="locked-badge">Locked</span></a></li>
                <li><a href="#" class="nav-locked"><i class="fas fa-link"></i> Service Packages <span class="locked-badge">Locked</span></a></li>

                <li class="nav-section-title">Business</li>
                <li><a href="#" class="nav-locked"><i class="fas fa-money-bill-wave"></i> Payments <span class="locked-badge">Locked</span></a></li>
                <li><a href="#" class="nav-locked"><i class="fas fa-star"></i> Reviews <span class="locked-badge">Locked</span></a></li>
                <li><a href="#" class="nav-locked"><i class="fas fa-chart-bar"></i> Reports <span class="locked-badge">Locked</span></a></li>

                <li class="nav-section-title">Account</li>
                <li><a href="subscription.php" class="<?php echo $current_page == 'subscription.php' ? 'active' : ''; ?>"><i class="fas fa-credit-card"></i><span class="nav-item-text">Subscription</span></a></li>
                <li><a href="#" class="nav-locked"><i class="fas fa-user"></i> Profile <span class="locked-badge">Locked</span></a></li>
            <?php else: ?>
                <!-- Full Menu -->
                <li class="nav-section-title">Operations</li>
                <li><a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span class="nav-item-text">Dashboard</span></a></li>
                <li><a href="reservations.php" class="<?php echo $current_page == 'reservations.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i><span class="nav-item-text">Reservations</span><?php echo renderSidebarAlertDot($sidebar_reservations_attention_count, 'reservation updates'); ?></a></li>
                <li><a href="booking-capacity.php" class="<?php echo $current_page == 'booking-capacity.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-days"></i><span class="nav-item-text">Booking Capacity</span></a></li>
                <li><a href="messages.php" class="<?php echo $current_page == 'messages.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i><span class="nav-item-text">Messages</span></a></li>
                <li><a href="event_preparation.php" class="<?php echo $current_page == 'event_preparation.php' ? 'active' : ''; ?>"><i class="fas fa-tasks"></i><span class="nav-item-text">Event Preparation</span></a></li>

                <li class="nav-section-title">Catalog</li>
                <li><a href="add-menu.php" class="<?php echo $current_page == 'add-menu.php' ? 'active' : ''; ?>"><i class="fas fa-utensils"></i><span class="nav-item-text">Menu Management</span></a></li>
                <li><a href="inventory.php" class="<?php echo $current_page == 'inventory.php' ? 'active' : ''; ?>"><i class="fas fa-boxes"></i><span class="nav-item-text">Inventory</span></a></li>
                <li><a href="service_mapping.php" class="<?php echo $current_page == 'service_mapping.php' ? 'active' : ''; ?>"><i class="fas fa-link"></i><span class="nav-item-text">Service Packages</span></a></li>

                <li class="nav-section-title">Business</li>
                <li><a href="payments.php" class="<?php echo $current_page == 'payments.php' ? 'active' : ''; ?>"><i class="fas fa-money-bill-wave"></i><span class="nav-item-text">Payments</span><?php echo renderSidebarAlertDot($sidebar_payment_pending_count, 'payment reviews'); ?></a></li>
                <li><a href="reviews.php" class="<?php echo $current_page == 'reviews.php' ? 'active' : ''; ?>"><i class="fas fa-star"></i><span class="nav-item-text">Reviews</span></a></li>
                <li><a href="reports.php" class="<?php echo $current_page == 'reports.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i><span class="nav-item-text">Reports</span></a></li>

                <li class="nav-section-title">Account</li>
                <li><a href="subscription.php" class="<?php echo $current_page == 'subscription.php' ? 'active' : ''; ?>"><i class="fas fa-credit-card"></i><span class="nav-item-text">Subscription</span></a></li>
                <li><a href="profile.php" class="<?php echo $current_page == 'profile.php' ? 'active' : ''; ?>"><i class="fas fa-user"></i><span class="nav-item-text">Profile</span></a></li>
            <?php endif; ?>
            <li class="nav-section-title">Session</li>
            <li><a href="#" data-no-notification-badge onclick="showLogoutConfirm(event)"><i class="fas fa-sign-out-alt"></i><span class="nav-item-text">Logout</span></a></li>
        </ul>
    </div>

    <div class="sidebar-overlay" id="storeSidebarOverlay"></div>

    <!-- Main Content Wrapper -->
    <div class="main-content">
        <!-- Top Mobile Header -->
        <div class="mobile-topbar">
            <button class="mobile-menu-toggle" type="button" onclick="toggleStoreSidebar()" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">☰</button>
            <h2>Caterer</h2>
        </div>

        <?php if ($compact_workspace_enabled): ?><div class="caterer-workspace"><?php endif; ?>
        <?php if ($header_access_locked): ?>
            <?php require __DIR__ . '/subscription-access-notice.php'; ?>
        <?php endif; ?>

    <!-- Notification JavaScript -->
    <script>
        // Fade in on page load (completes the fade-out → navigate → fade-in cycle)
        (function() {
            var overlay = document.getElementById('page-transition-overlay');
            if (overlay) {
                // Start fully opaque on entry so we can fade in
                overlay.style.transition = 'none';
                overlay.style.opacity = '1';
                overlay.style.pointerEvents = 'none';
                requestAnimationFrame(function() {
                    requestAnimationFrame(function() {
                        overlay.style.transition = 'opacity 0.28s ease';
                        overlay.style.opacity = '0';
                    });
                });
            }
        }());

        function setStoreSidebarState(isOpen) {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('storeSidebarOverlay');
            const toggles = document.querySelectorAll('.mobile-menu-toggle');

            if (!sidebar || !overlay) {
                return;
            }

            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.classList.remove('store-sidebar-open');
                toggles.forEach(function(toggle) {
                    toggle.textContent = '☰';
                    toggle.setAttribute('aria-expanded', 'false');
                });
                return;
            }

            sidebar.classList.toggle('active', isOpen);
            overlay.classList.toggle('active', isOpen);
            document.body.classList.toggle('store-sidebar-open', isOpen);
            toggles.forEach(function(toggle) {
                toggle.textContent = isOpen ? '✕' : '☰';
                toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        }

        function toggleStoreSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                setStoreSidebarState(!sidebar.classList.contains('active'));
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('storeSidebarOverlay');

            if (overlay) {
                overlay.addEventListener('click', function() {
                    setStoreSidebarState(false);
                });
            }

            if (sidebar) {
                sidebar.querySelectorAll('a').forEach(function(link) {
                    link.addEventListener('click', function(e) {
                        const href = link.getAttribute('href');

                        // Prevent scroll-jump for placeholder / locked links
                        if (!href || href === '#' || href === '' || link.classList.contains('nav-locked')) {
                            e.preventDefault();
                            return;
                        }

                        // Close mobile sidebar first
                        if (window.innerWidth <= 992) {
                            setStoreSidebarState(false);
                        }

                        // Save sidebar scroll position before navigating
                        const sidebarMenu = document.querySelector('.sidebar-menu');
                        if (sidebarMenu) {
                            sessionStorage.setItem('sidebarScrollTop', sidebarMenu.scrollTop);
                        }

                        // Smooth fade-out transition before navigating
                        e.preventDefault();
                        const overlay = document.getElementById('page-transition-overlay');
                        if (overlay) {
                            overlay.classList.add('fading-out');
                            setTimeout(function() {
                                window.location.href = href;
                            }, 200);
                        } else {
                            window.location.href = href;
                        }
                    });
                });
            }

            // Restore sidebar scroll position after page loads
            const sidebarMenu = document.querySelector('.sidebar-menu');
            const savedScrollTop = sessionStorage.getItem('sidebarScrollTop');
            if (sidebarMenu && savedScrollTop !== null) {
                sidebarMenu.scrollTop = parseInt(savedScrollTop, 10);
            }

            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    setStoreSidebarState(false);
                }
            });
        });

        // Toast Notification System
        function showToast(title, message, type = 'info') {
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                container.style.cssText = 'position: fixed; bottom: 30px; right: 30px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;';
                document.body.appendChild(container);
            }
            
            const toast = document.createElement('div');
            toast.style.cssText = `
                background: var(--card-bg);
                border-left: 4px solid ${type === 'success' ? '#2ecc71' : (type === 'error' ? '#e74c3c' : '#3498db')};
                padding: 15px 20px;
                border-radius: 4px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.3);
                display: flex;
                align-items: center;
                gap: 15px;
                min-width: 300px;
                transform: translateX(100%);
                transition: transform 0.3s ease;
                color: var(--text-main);
            `;
            
            const icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle');
            const iconColor = type === 'success' ? '#2ecc71' : (type === 'error' ? '#e74c3c' : '#3498db');
            
            toast.innerHTML = `
                <i class="fas ${icon}" style="color: ${iconColor}; font-size: 20px;"></i>
                <div>
                    <div style="font-weight: bold; margin-bottom: 4px;">${title}</div>
                    <div style="font-size: 13px; color: var(--text-muted);">${message}</div>
                </div>
            `;
            
            container.appendChild(toast);
            
            // Trigger animation
            requestAnimationFrame(() => {
                toast.style.transform = 'translateX(0)';
            });
            
            // Play sound (if available)
            // const audio = new Audio('../uploads/notification.mp3'); 
            // audio.play().catch(e => console.log('Audio play failed', e));
            
            // Remove after 5 seconds
            setTimeout(() => {
                toast.style.transform = 'translateX(120%)';
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 5000);
        }

        // Toggle notification dropdown
        function toggleNotifications() {
            const dropdown = document.getElementById('notif-dropdown');
            if (dropdown.style.display === 'none') {
                dropdown.style.display = 'block';
                markAllAsRead();
            } else {
                dropdown.style.display = 'none';
            }
        }
        
        // Dismiss on an outside tap, including surfaces that stop click propagation.
        document.addEventListener('pointerdown', function(e) {
            const wrapper = document.querySelector('.notification-wrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                const notifDropdown = document.getElementById('notif-dropdown');
                if (notifDropdown) notifDropdown.style.display = 'none';
            }
        }, true);

        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 992) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('storeSidebarOverlay');
                // If the click came from the toggle button (or its children), skip – the onclick already handled it
                const toggle = e.target.closest('.mobile-menu-toggle');
                if (toggle) return;

                if (sidebar && overlay && !sidebar.contains(e.target) && !overlay.contains(e.target)) {
                    setStoreSidebarState(false);
                }
            }
        });
        
        // Load notifications
        function loadNotifications() {
            fetch('../api/notifications.php?action=get')
                .then(r => r.json())
                .then(data => {
                    const list = document.getElementById('notif-list');
                    if (data.notifications && data.notifications.length > 0) {
                        list.innerHTML = data.notifications.map(n => `
                            <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}" onclick="handleNotifClick(${n.id}, '${n.link || ''}')" style="padding: 10px 15px; border-bottom: 1px solid var(--border-color); cursor: pointer; transition: background 0.2s; ${n.is_read == 0 ? 'background: rgba(212,175,55,0.05);' : ''}">
                                <div style="display: flex; align-items: flex-start; gap: 10px;">
                                    <div style="width: 32px; height: 32px; border-radius: 50%; background: ${getNotifColor(n.type)}; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i class="fas ${getNotifIcon(n.type)}" style="color: white; font-size: 12px;"></i>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-weight: 600; font-size: 12px; color: var(--text-main);">${n.title}</div>
                                    </div>
                                    ${n.is_read == 0 ? '<div style="width: 6px; height: 6px; background: var(--accent-gold); border-radius: 50%; flex-shrink: 0; margin-top: 4px;"></div>' : ''}
                                </div>
                            </div>
                        `).join('');
                    } else {
                        list.innerHTML = '<div style="padding: 30px; text-align: center; color: var(--text-muted);"><i class="fas fa-bell-slash" style="font-size: 24px; margin-bottom: 8px; opacity: 0.5;"></i><br>No notifications</div>';
                    }
                });
        }
        
        // Update unread count with Toast Trigger
        let lastNotifCount = -1;
        let notifRequestVersion = 0;
        
        function updateNotifCount() {
            const version = ++notifRequestVersion;
            return fetch('../api/notifications.php?action=count', {cache: 'no-store'})
                .then(r => r.json())
                .then(data => {
                    if (!data.success || version !== notifRequestVersion) return;
                    const badge = document.getElementById('notif-count');
                    const newCount = parseInt(data.count);
                    
                    if (newCount > 0) {
                        badge.textContent = newCount > 99 ? '99+' : newCount;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                    
                    // Check if count increased (New Notification!)
                    if (lastNotifCount !== -1 && newCount > lastNotifCount) {
                        // Fetch the latest notification to show in toast
                        fetch('../api/notifications.php?action=get&limit=1')
                            .then(r => r.json())
                            .then(notifData => {
                                if (notifData.notifications && notifData.notifications.length > 0) {
                                    const latest = notifData.notifications[0];
                                    showToast(latest.title, latest.message, 'info');
                                } else {
                                    showToast('New Notification', 'You have new unread messages.', 'info');
                                }
                            });
                    }
                    
                    badge.setAttribute('aria-label', newCount + ' unread notifications');
                    syncSectionNotificationBadges(data.sections || {});
                    if (lastNotifCount !== newCount && document.getElementById('notif-dropdown').style.display !== 'none') loadNotifications();
                    lastNotifCount = newCount;
                }).catch(() => {});
        }
        
        // Mark as read and navigate
        function syncSectionNotificationBadges(sections) {
            document.querySelectorAll('.sidebar a[href]').forEach(link => {
                const href = link.getAttribute('href').trim();
                // Action links such as Logout must never inherit this page's count.
                if (!href || href.startsWith('#') || href.startsWith('javascript:') || link.hasAttribute('data-no-notification-badge')) {
                    link.querySelectorAll('.section-notification-badge').forEach(badge => badge.remove());
                    return;
                }
                const url = new URL(href, location.href);
                if (url.origin !== location.origin || !url.pathname.includes('/caterer/')) return;
                const page = url.pathname.split('/').pop();
                const count = Number(sections[page] || 0);
                let badge = link.querySelector('.section-notification-badge');
                if (!badge && count > 0) {
                    badge = document.createElement('span');
                    badge.className = 'section-notification-badge';
                    badge.setAttribute('role', 'status');
                    link.appendChild(badge);
                }
                if (badge) {
                    badge.hidden = count === 0;
                    badge.textContent = count > 99 ? '99+' : String(count);
                    badge.setAttribute('aria-label', count + ' unread updates');
                }
            });
        }

        function handleNotifClick(id, link) {
            fetch('../api/notifications.php?action=mark_read', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            }).then(response => response.json()).then(data => {
                if (!data.success) return;
                ++notifRequestVersion;
                updateNotifCount();
                if (link) window.location.href = link;
            }).catch(() => updateNotifCount());
        }
        
        // Mark all as read
        function markAllAsRead() {
            ++notifRequestVersion;
            return fetch('../api/notifications.php?action=mark_all_read', {method: 'POST'})
                .then(response => response.json()).then(data => {
                    if (!data.success) throw new Error('Read update failed');
                    ++notifRequestVersion;
                    const badge = document.getElementById('notif-count');
                    badge.style.display = 'none';
                    badge.textContent = '';
                    badge.setAttribute('aria-label', '0 unread notifications');
                    lastNotifCount = 0;
                    syncSectionNotificationBadges({});
                    loadNotifications();
                    return updateNotifCount();
                }).catch(() => { loadNotifications(); updateNotifCount(); });
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
        
        // Sync the viewed section, then poll while this tab is visible.
        const notificationPage = new URL(location.href);
        fetch('../api/notifications.php?action=sync_view', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({page: notificationPage.pathname.split('/').pop(), id: Number(notificationPage.searchParams.get('view') || notificationPage.searchParams.get('id') || notificationPage.searchParams.get('order_id') || 0)})
        }).then(() => updateNotifCount()).catch(() => updateNotifCount());
        setInterval(() => { if (!document.hidden) updateNotifCount(); }, 2000);
        window.addEventListener('focus', updateNotifCount);
        window.addEventListener('pageshow', updateNotifCount);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) updateNotifCount(); });

        // Logout confirmation
        function showLogoutConfirm(e) {
            e.preventDefault();
            document.getElementById('logoutConfirmModal').style.display = 'flex';
        }
        function hideLogoutConfirm() {
            document.getElementById('logoutConfirmModal').style.display = 'none';
        }
        function confirmLogout() {
            window.location.href = '../logout.php';
        }

        // Close modal on backdrop click
        document.addEventListener('DOMContentLoaded', function () {
            var modal = document.getElementById('logoutConfirmModal');
            if (modal) {
                modal.addEventListener('click', function (e) {
                    if (e.target === modal) hideLogoutConfirm();
                });
            }
        });
    </script>

    <!-- Logout Confirmation Modal -->
    <div id="logoutConfirmModal" style="display:none; position:fixed; inset:0; z-index:99999; align-items:center; justify-content:center; background:rgba(0,0,0,0.65); backdrop-filter:blur(4px);">
        <div style="background:#1e1e1e; border:1px solid #333; border-radius:18px; padding:36px 32px 28px; max-width:380px; width:90%; text-align:center; box-shadow:0 24px 60px rgba(0,0,0,0.5); animation:logoutModalIn 0.22s ease;">
            <div style="width:58px;height:58px;border-radius:50%;background:rgba(241,196,15,0.12);border:1.5px solid rgba(241,196,15,0.3);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
                <i class="fas fa-sign-out-alt" style="font-size:24px;color:#f1c40f;"></i>
            </div>
            <h3 style="margin:0 0 8px;color:#fff;font-size:19px;font-weight:700;">Logout</h3>
            <p style="margin:0 0 26px;color:#a0a0a0;font-size:14px;line-height:1.5;">Are you sure you want to logout?</p>
            <div style="display:flex;gap:12px;justify-content:center;">
                <button onclick="hideLogoutConfirm()" style="flex:1;padding:11px 0;border-radius:10px;border:1px solid #444;background:rgba(255,255,255,0.05);color:#e0e0e0;font-size:14px;font-weight:600;cursor:pointer;transition:background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'">Cancel</button>
                <button onclick="confirmLogout()" style="flex:1;padding:11px 0;border-radius:10px;border:none;background:linear-gradient(135deg,#f1c40f,#f39c12);color:#121212;font-size:14px;font-weight:700;cursor:pointer;transition:opacity 0.2s;" onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'"><i class="fas fa-sign-out-alt" style="margin-right:6px;"></i>Yes, Logout</button>
            </div>
        </div>
    </div>
    <style>
        @keyframes logoutModalIn {
            from { opacity: 0; transform: scale(0.93) translateY(12px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
    </style>

<script>
function syncReservationSidebarBadge(count) {
    document.querySelectorAll('a[href="reservations.php"]').forEach(link => {
        let dot = link.querySelector('.nav-alert-dot');
        if (!count) { if (dot) dot.remove(); return; }
        if (!dot) { dot = document.createElement('span'); dot.className = 'nav-alert-dot'; link.appendChild(dot); }
        dot.title = count + ' unread reservation updates';
        dot.setAttribute('aria-label', dot.title);
    });
}
// Other caterer pages also follow the saved read state without reloading.
if (!window.location.pathname.endsWith('/reservations.php')) {
    let reservationSidebarBusy = false;
    async function refreshReservationSidebarBadge() {
        if (document.hidden || reservationSidebarBusy) return;
        reservationSidebarBusy = true;
        try {
            const response = await fetch('../api/reservation-notifications.php', {cache:'no-store'});
            const data = await response.json();
            if (data.success) syncReservationSidebarBadge(Object.values(data.groups).reduce((sum, ids) => sum + ids.length, 0));
        } catch (error) { console.error('Unable to refresh reservation indicator', error); }
        finally { reservationSidebarBusy = false; }
    }
    setInterval(refreshReservationSidebarBadge, 3000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshReservationSidebarBadge(); });
}
</script>
<script src="../assets/js/payment-notifications.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/payment-notifications.js'); ?>"></script>
