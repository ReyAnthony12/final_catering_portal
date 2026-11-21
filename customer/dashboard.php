<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/payment_functions.php';
require_once __DIR__ . '/../includes/order_reference_helper.php';
requireLogin();

if (getUserRole() !== 'customer') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$store_available_sql = getStoreAvailabilitySql('sd.is_open', 'sd.business_open_time', 'sd.business_close_time', 'sd.auto_schedule_enabled');

// Get customer details
$customer_query = "SELECT * FROM users WHERE id = '$user_id'";
$customer_result = mysqli_query($conn, $customer_query);
$customer = mysqli_fetch_assoc($customer_result);

// Get payment statistics
$payment_stats = getCustomerPaymentStats($user_id);

// Get upcoming events
$upcoming_events = getUpcomingEvents($user_id, 5);

// Initialize variables
$events_json = '[]';
$search = '';
$event_name_suggestions = [];

$event_suggestions_query = "
    SELECT DISTINCT p.name
    FROM products p
    INNER JOIN users u ON p.store_id = u.id
    INNER JOIN store_details sd ON u.id = sd.user_id
    WHERE p.is_available = 1
      AND p.category = 'catering_service'
      AND u.role IN ('caterer','store')
      AND u.status = 'active'
      AND sd.approval_status = 'approved'
      AND $store_available_sql
    ORDER BY p.name ASC
    LIMIT 30
";
$event_suggestions_result = mysqli_query($conn, $event_suggestions_query);
if ($event_suggestions_result) {
    while ($event_row = mysqli_fetch_assoc($event_suggestions_result)) {
        if (!empty($event_row['name'])) {
            $event_name_suggestions[] = $event_row['name'];
        }
    }
}

// Handle AJAX request for getting orders by date
if (isset($_GET['ajax_date'])) {
    $date = mysqli_real_escape_string($conn, $_GET['ajax_date']);
    
    $orders_query = "
        SELECT o.*, sd.business_name 
        FROM bookings o 
        LEFT JOIN store_details sd ON o.store_id = sd.user_id 
        WHERE o.customer_id = '$user_id' 
        AND DATE(o.order_date) = '$date'
        ORDER BY o.order_date DESC
    ";
    $orders_result = mysqli_query($conn, $orders_query);
    
    if (mysqli_num_rows($orders_result) > 0): ?>
        <div class="modal-header">
            <h2>Orders for <?php echo date('F d, Y', strtotime($date)); ?></h2>
            <button type="button" class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body custom-scrollbar">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Booking #</th>
                        <th>Caterer</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($order = mysqli_fetch_assoc($orders_result)): ?>
                        <tr>
                            <td><span class="order-id">#<?php echo htmlspecialchars(formatOrderReference($order['id'])); ?></span></td>
                            <td><?php echo htmlspecialchars($order['business_name'] ?: 'N/A'); ?></td>
                            <td class="price">₱<?php echo number_format($order['total_amount'] ?? 0, 2); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($order['order_status'] ?? 'pending'); ?>">
                                    <?php echo ucfirst($order['order_status'] ?? 'pending'); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="modal-header">
            <h2>No Orders</h2>
            <button type="button" class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <div class="empty-state">
            <i class="fas fa-calendar-day"></i>
            <p>No orders scheduled for this date.</p>
        </div>
    <?php endif;
    exit();
}

// Get all orders for the calendar
$all_orders_query = "
    SELECT o.id, o.order_number, o.order_date, o.order_status, o.total_amount, 
           sd.business_name 
    FROM bookings o 
    LEFT JOIN store_details sd ON o.store_id = sd.user_id 
    WHERE o.customer_id = '$user_id' 
    AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
    AND o.order_date IS NOT NULL
    ORDER BY o.order_date DESC
";
$all_orders_result = mysqli_query($conn, $all_orders_query);

$calendar_events = [];
$calendar_events_by_date = [];
if ($all_orders_result) {
    while ($order = mysqli_fetch_assoc($all_orders_result)) {
        if (!empty($order['order_date'])) {
            $order_date = date('Y-m-d', strtotime($order['order_date']));
            $calendar_events_by_date[$order_date] = ($calendar_events_by_date[$order_date] ?? 0) + 1;
            if ($calendar_events_by_date[$order_date] > 3) {
                continue;
            }
            
            // Status colors matching the theme
            $color = '#d4af37'; // Default gold for pending
            if ($order['order_status'] === 'completed') $color = '#4caf50'; // Green
            if ($order['order_status'] === 'cancelled' || $order['order_status'] === 'rejected') $color = '#f44336'; // Red
            if ($order['order_status'] === 'confirmed') $color = '#2196f3'; // Blue
            
            $calendar_events[] = [
                'id' => $order['id'],
                'title' => ($order['business_name'] ? substr($order['business_name'], 0, 15) . '...' : 'Booking #' . formatOrderReference($order['id'])),
                'start' => $order_date,
                'color' => $color,
                'classNames' => ['fc-event-custom']
            ];
        }
    }
}
$events_json = json_encode($calendar_events);

// Get Featured Stores (Active & Approved) with real ratings
$featured_stores_query = "
    SELECT u.id, u.profile_image, u.address, sd.business_name, COALESCE(NULLIF(sd.location, ''), u.address) as location, sd.description, sd.is_open, sd.business_open_time, sd.business_close_time, sd.auto_schedule_enabled, sd.cover_photo, sd.cover_position,
           $store_available_sql AS is_available_now,
           (SELECT COUNT(*) FROM products WHERE store_id = u.id AND is_available = 1) as product_count,
           (SELECT AVG(overall_rating) FROM reviews WHERE store_id = u.id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE store_id = u.id) as review_count
    FROM users u
    JOIN store_details sd ON u.id = sd.user_id
    WHERE u.role IN ('caterer','store') AND u.status = 'active' AND sd.approval_status = 'approved'
    ORDER BY sd.business_name ASC, u.id ASC LIMIT 6
";
$featured_stores_result = mysqli_query($conn, $featured_stores_query);

$page_title = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .container {
        max-width: none;
        padding: 0 8px;
        box-sizing: border-box;
    }

    .customer-page-shell > .container {
        margin-bottom: 6px;
    }

    .dashboard-overview {
        border: 1px solid var(--border-color);
        border-radius: 12px;
        background: var(--card-bg);
        margin-bottom: 16px;
        overflow: hidden;
    }

    .dashboard-overview > .hero-section {
        max-width: none;
        margin: 0;
        border: 0;
        border-radius: 0;
        background: transparent !important;
        box-shadow: none !important;
    }

    .dashboard-overview > .dashboard-stats-row {
        max-width: none;
        margin: 0;
        border: 0;
        border-radius: 0;
        background: transparent !important;
        box-shadow: none !important;
    }

    /* Only the overview draws an outline; nested sections share its surface. */
    .dashboard-overview > .hero-section,
    .dashboard-overview > .content-card.dashboard-stats-row,
    .dashboard-overview .dashboard-stats-row > .dashboard-summary-card {
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: transparent !important;
    }

    .dashboard-overview > .hero-section::after,
    .dashboard-overview > .dashboard-stats-row::before,
    .dashboard-overview > .dashboard-stats-row::after {
        display: none;
    }

    .hero-section {
        max-width: 1060px;
        margin: 0 auto 10px;
        padding: 8px 14px;
        border-radius: 8px;
        min-height: 44px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        text-align: left;
        background: #191c21;
        border: 1px solid rgba(255, 255, 255, 0.1);
        box-shadow: none;
        position: relative;
        overflow: hidden;
    }

    .hero-section::before {
        display: none;
    }

    .hero-title {
        font-size: 17px;
        font-weight: 700;
        margin: 0;
        white-space: nowrap;
        letter-spacing: 0;
        color: var(--text-main);
        background: none;
        -webkit-text-fill-color: currentColor;
        position: relative;
        padding-left: 0;
    }

    .hero-section .search-container {
        max-width: 360px;
        flex: 1;
        margin: 0;
        position: relative;
    }

    .hero-section .search-input {
        padding: 8px 38px 8px 12px;
        font-size: 11px;
        border-width: 1px;
        background: #111317;
        border-color: rgba(255, 255, 255, 0.12);
        box-shadow: none;
    }

    .hero-section .search-btn {
        width: 27px;
        height: 27px;
        right: 4px;
        font-size: 12px;
    }

    .content-card.dashboard-stats-row {
        display: grid;
        grid-template-columns: 0.9fr 0.85fr 1.15fr;
        gap: 0;
        padding: 10px;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        background: var(--card-bg);
        max-width: 1060px;
        margin: 0 auto 16px;
        align-items: stretch;
    }

    .dashboard-summary-card {
        background: #191c21;
        border-radius: 8px;
        padding: 8px 10px;
        border: 1px solid rgba(255, 255, 255, 0.09);
        min-height: 148px;
        height: auto;
        box-shadow: none;
        position: relative;
        overflow: hidden;
    }

    .dashboard-summary-card::before {
        display: none;
    }

    .dashboard-stats-row > .dashboard-summary-card {
        min-width: 0;
        background: transparent !important;
        border: 0;
        border-radius: 0;
        box-shadow: none !important;
        margin: 0;
    }

    .dashboard-stats-row > .dashboard-summary-card + .dashboard-summary-card {
        border-left: 1px solid var(--border-color);
    }

    .dashboard-summary-card h3 {
        color: var(--text-main);
        margin-bottom: 6px;
        font-size: 12px;
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .dashboard-summary-card h3 i {
        color: var(--accent-gold);
        margin-right: 0;
    }

    .summary-stat-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 6px;
        margin-bottom: 7px;
    }

    .summary-stat-box {
        display: block;
        text-align: center;
        padding: 4px 6px;
        border-radius: 6px;
        border: 1px solid rgba(255, 255, 255, 0.06);
        color: inherit;
        text-decoration: none;
        cursor: pointer;
        transition: border-color 0.2s ease, transform 0.2s ease, background 0.2s ease;
    }

    .summary-stat-box:hover {
        border-color: rgba(214, 179, 90, 0.45);
        transform: translateY(-1px);
    }

    .summary-stat-number {
        font-size: 15px;
        font-weight: 700;
        line-height: 1.1;
    }

    .summary-stat-label {
        font-size: 8px;
        color: var(--text-muted);
        margin-top: 2px;
    }

    .payment-totals {
        border-top: 1px solid var(--border-color);
        padding-top: 6px;
        display: grid;
        gap: 3px;
        font-size: 11px;
    }

    .payment-total-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .events-scroll {
        max-height: 96px;
        overflow-y: auto;
    }

    .upcoming-event-row {
        display: flex;
        align-items: center;
        padding: 4px 7px;
        margin-bottom: 4px;
        border-radius: 6px;
        border-left: 2px solid var(--accent-gold);
    }

    .upcoming-empty {
        text-align: center;
        padding: 24px 10px;
        color: var(--text-muted);
        border: 0;
        border-radius: 6px;
        background: rgba(255, 255, 255, 0.02);
    }

    .upcoming-empty i {
        font-size: 16px;
        margin-bottom: 3px;
        opacity: 0.5;
    }

    .dashboard-grid {
        max-width: none;
        width: 100%;
        padding: 6px;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        box-sizing: border-box;
        margin: 0 auto;
        display: grid;
        grid-template-columns: 1fr;
        gap: 16px;
        align-items: start;
    }

    .main-column,
    .side-column {
        min-width: 0;
    }

    .section-header {
        margin-bottom: 12px;
        padding: 0 2px;
    }

    .section-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 16px;
        line-height: 1.2;
    }

    .section-link {
        border-radius: 8px;
        padding: 7px 11px;
        font-size: 12px;
    }

    .caterer-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .caterer-card {
        scroll-margin-top: 100px;
        display: grid;
        grid-template-columns: 112px minmax(0, 1fr);
        min-height: 126px;
        border-radius: 10px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.03), rgba(255, 255, 255, 0.012));
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.16);
    }

    .caterer-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 14px 28px rgba(0, 0, 0, 0.22);
    }

    .caterer-image {
        width: 112px;
        height: 100%;
        min-height: 126px;
        object-fit: cover;
    }

    .caterer-content {
        padding: 12px;
        min-width: 0;
    }

    .caterer-name {
        font-size: 14px;
        color: var(--text-main);
        margin-bottom: 6px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .caterer-meta {
        font-size: 11px;
        margin-bottom: 6px;
        line-height: 1.35;
    }

    .caterer-stats {
        gap: 8px;
        font-size: 11px;
        line-height: 1.3;
        flex-wrap: wrap;
    }

    .calendar-container {
        border-radius: 10px;
        padding: 14px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.035), rgba(255, 255, 255, 0.015));
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
    }

    .dashboard-calendar-card {
        padding: 8px;
        cursor: pointer;
    }

    .dashboard-calendar-card h3::after {
        content: none;
        margin-left: auto;
        color: var(--text-muted);
        font-size: 8px;
        font-weight: 500;
    }

    .dashboard-calendar-card .calendar-container {
        padding: 4px;
        border-radius: 7px;
        background: rgba(255, 255, 255, 0.015);
        box-shadow: none;
        height: 112px;
        overflow: hidden;
    }

    .dashboard-calendar-card .fc .fc-toolbar {
        margin-bottom: 3px !important;
    }

    .dashboard-calendar-card .fc .fc-toolbar-title {
        font-size: 9px !important;
    }

    .dashboard-calendar-card .fc .fc-button {
        padding: 0 4px !important;
        font-size: 8px !important;
        border-radius: 5px !important;
    }

    .dashboard-calendar-card .fc .fc-col-header-cell-cushion,
    .dashboard-calendar-card .fc .fc-daygrid-day-number {
        font-size: 6px !important;
    }

    .dashboard-calendar-card .fc .fc-daygrid-day-frame {
        min-height: 11px !important;
    }

    .dashboard-calendar-card .fc .fc-daygrid-event {
        font-size: 6px !important;
        padding: 0 2px;
        line-height: 1.2;
    }

    .dashboard-calendar-card .fc,
    .dashboard-calendar-card .fc-view-harness,
    .dashboard-calendar-card .fc-scrollgrid,
    .dashboard-calendar-card .fc-scroller,
    .dashboard-calendar-card .fc-scroller-liquid-absolute {
        height: auto !important;
        overflow: hidden !important;
    }

    .dashboard-calendar-card .fc-daygrid-day {
        height: 13px !important;
    }

    .dashboard-calendar-card .fc-daygrid-body,
    .dashboard-calendar-card .fc-daygrid-body table,
    .dashboard-calendar-card .fc-scrollgrid-sync-table {
        height: auto !important;
    }

    .calendar-modal-content {
        max-width: 940px;
        width: 92%;
    }

    .large-calendar-wrap {
        padding: 14px;
    }

    .large-calendar-wrap .calendar-container {
        padding: 14px;
    }

    .large-calendar-wrap .fc .fc-toolbar-title {
        font-size: 18px;
    }

    .large-calendar-wrap .fc .fc-daygrid-day-frame {
        min-height: 72px;
    }

    .fc .fc-toolbar {
        margin-bottom: 10px;
    }

    .fc .fc-toolbar-title {
        font-size: 15px;
        font-weight: 700;
    }

    .fc .fc-button {
        padding: 4px 8px !important;
        border-radius: 7px !important;
        font-size: 12px !important;
    }

    .fc .fc-col-header-cell-cushion,
    .fc .fc-daygrid-day-number {
        font-size: 11px;
    }

    .fc .fc-daygrid-day-frame {
        min-height: 58px;
    }

    .fc .fc-daygrid-event {
        border-radius: 5px;
        padding: 1px 4px;
        font-size: 10px;
    }

    @media (max-width: 992px) {
        .content-card.dashboard-stats-row {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .dashboard-stats-row > .dashboard-calendar-card {
            border-left: 0 !important;
            border-top: 1px solid var(--border-color);
        }

        .dashboard-summary-card {
            height: auto;
            min-width: 0;
            padding: 16px;
            border-radius: 14px;
        }

        .dashboard-summary-card h3 { font-size: 16px; margin-bottom: 14px; }
        .summary-stat-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px; }
        .summary-stat-box { padding: 12px 4px; border-radius: 10px; }
        .summary-stat-number { font-size: 24px; line-height: 1.2; }
        .summary-stat-label { font-size: 12px; margin-top: 5px; }
        .payment-totals { font-size: 14px; gap: 8px; padding-top: 12px; }
        .payment-total-row { gap: 12px; flex-wrap: wrap; }
        .payment-total-row span { overflow-wrap: anywhere; }
        .events-scroll { max-height: none; overflow: visible; }
        .upcoming-event-row { padding: 12px; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
        .upcoming-event-row > div:first-child { min-width: 140px; overflow-wrap: anywhere; }
        .upcoming-event-row > div:first-child > div:first-child { font-size: 15px !important; }
        .upcoming-event-row > div:first-child > div:last-child { font-size: 12px !important; }

        .dashboard-calendar-card { grid-column: 1 / -1; padding: 10px; }
        .dashboard-calendar-card h3 { font-size: 14px; margin-bottom: 8px; }
        .dashboard-calendar-card h3::after { content: none; font-size: 10px; }
        .dashboard-calendar-card .calendar-container { height: auto; padding: 0; }
        .dashboard-calendar-card .fc .fc-toolbar { margin-bottom: 6px !important; }
        .dashboard-calendar-card .fc .fc-toolbar-title { font-size: 14px !important; }
        .dashboard-calendar-card .fc .fc-button { min-width: 36px; min-height: 36px; font-size: 14px !important; }
        .dashboard-calendar-card .fc .fc-col-header-cell-cushion,
        .dashboard-calendar-card .fc .fc-daygrid-day-number { font-size: 10px !important; }
        .dashboard-calendar-card .fc .fc-daygrid-day-frame { min-height: 28px !important; }
        .dashboard-calendar-card .fc-daygrid-day { height: 28px !important; }
        .dashboard-calendar-card .fc .fc-daygrid-event { font-size: 9px !important; }

        .caterer-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .dashboard-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        /* Keep the preview compact instead of reserving FullCalendar's aspect-ratio height. */
        .dashboard-calendar-card .fc .fc-view-harness {
            padding-bottom: 0 !important;
            min-height: 0 !important;
        }
        .dashboard-calendar-card .fc .fc-view-harness > .fc-view { position: relative !important; }
        .dashboard-calendar-card .fc .fc-daygrid-body tr { height: 24px !important; }
        .dashboard-calendar-card .fc .fc-daygrid-day-frame { min-height: 24px !important; }
        .dashboard-calendar-card .fc-daygrid-day { height: 24px !important; }
        .dashboard-calendar-card .fc .fc-daygrid-day-events { min-height: 0; margin-bottom: 0; }
        .dashboard-calendar-card .fc .fc-daygrid-day-bottom { padding: 0; }
        .dashboard-calendar-card .fc .fc-daygrid-event { font-size: 8px !important; }
        .dashboard-calendar-card .fc .fc-col-header-cell-cushion,
        .dashboard-calendar-card .fc .fc-daygrid-day-number { padding: 1px 2px; font-size: 9px !important; }

        .container {
            padding: 0 6px;
            margin-top: 86px;
        }

        .hero-section {
            max-width: 100%;
            padding: 10px 12px;
            margin-bottom: 10px;
            border-radius: 10px;
            flex-direction: column;
            gap: 6px;
            align-items: stretch;
            text-align: left;
        }

        .hero-title {
            font-size: 16px;
            line-height: 1.25;
            white-space: normal;
        }

        .hero-section .search-container {
            width: 100%;
            max-width: none;
            flex: none;
            min-width: 0;
            margin-top: 0;
        }

        .hero-section .search-input { width: 100%; box-sizing: border-box; min-height: 40px; padding: 8px 44px 8px 12px; font-size: 16px; }
        .hero-section .search-btn { width: 34px; height: 34px; font-size: 14px; }

        .content-card.dashboard-stats-row {
            grid-template-columns: minmax(0, 1fr);
        }

        .dashboard-stats-row > .dashboard-summary-card + .dashboard-summary-card {
            border-left: 0;
            border-top: 1px solid var(--border-color);
        }

        .caterer-grid {
            grid-template-columns: 1fr;
        }

        .caterer-card {
            grid-template-columns: 96px minmax(0, 1fr);
        }

        .caterer-image {
            width: 96px;
        }

        .caterer-name { font-size: 16px; white-space: normal; overflow-wrap: anywhere; }
        .caterer-meta, .caterer-stats { font-size: 13px; }
        .section-header { gap: 12px; flex-wrap: wrap; }
        .section-title { font-size: 18px; }
        .section-link { min-height: 44px; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; }
        .calendar-modal-content { width: calc(100% - 24px); max-height: 90dvh; }
        .calendar-modal-content .modal-header { padding: 16px; gap: 8px; }
        .calendar-modal-content .modal-header h2 { font-size: 17px; }
        .calendar-modal-content .close-btn { min-width: 44px; min-height: 44px; }
        .large-calendar-wrap, .large-calendar-wrap .calendar-container { padding: 8px; }
        .large-calendar-wrap .fc .fc-toolbar { flex-wrap: wrap; gap: 8px; }
        .large-calendar-wrap .fc .fc-button { min-height: 44px; }
        .large-calendar-wrap .fc .fc-daygrid-day-frame { min-height: 48px; }
    }
</style>
<link rel="stylesheet" href="../assets/css/customer-dashboard-desktop.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/customer-dashboard-desktop.css'); ?>">
<link rel="stylesheet" href="../assets/css/customer-dashboard-theme.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/customer-dashboard-theme.css'); ?>">

<!-- Hero Search Section -->
<div class="dashboard-overview">
<div class="hero-section">
    <h1 class="hero-title">Find the Perfect Event Occation</h1>
    
    <form action="browse-caterer.php" method="GET" class="search-container">
        <input type="text" name="search" class="search-input" placeholder="Search event type" aria-label="Search event type" list="dashboardEventNameSuggestions">
        <datalist id="dashboardEventNameSuggestions">
            <?php foreach ($event_name_suggestions as $event_name): ?>
                <option value="<?php echo htmlspecialchars($event_name); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <button type="submit" class="search-btn" aria-label="Search caterers"><i class="fas fa-search"></i></button>
    </form>
</div>

<!-- Shared payment, upcoming events, and bookings container -->
<div class="content-card dashboard-stats-row">
    <!-- Payment Summary Panel -->
    <div class="content-card dashboard-summary-card">
        <h3>
            Payment Summary
        </h3>
        <div class="summary-stat-grid">
            <a href="booking.php?view=all&status=all" class="summary-stat-box" style="background: rgba(212,175,55,0.1);" title="View all bookings">
                <div class="summary-stat-number" style="color: var(--accent-gold);">
                    <?php echo $payment_stats['total_orders']; ?>
                </div>
                <div class="summary-stat-label">Total Orders</div>
            </a>
            <a href="payments.php?tab=history" class="summary-stat-box" style="background: rgba(76,175,80,0.1);" title="View paid bookings">
                <div class="summary-stat-number" style="color: #4caf50;">
                    <?php echo $payment_stats['paid_orders']; ?>
                </div>
                <div class="summary-stat-label">Paid</div>
            </a>
            <a href="payments.php?tab=pending" class="summary-stat-box" style="background: rgba(244,67,54,0.1);" title="View pending bills">
                <div class="summary-stat-number" style="color: #f44336;">
                    <?php echo $payment_stats['unpaid_orders'] + $payment_stats['partial_orders']; ?>
                </div>
                <div class="summary-stat-label">Pending</div>
            </a>
        </div>
        <div class="payment-totals">
            <div class="payment-total-row">
                <span style="color: var(--text-muted);">Total Spent:</span>
                <span style="color: var(--text-main); font-weight: 600;">₱<?php echo number_format($payment_stats['total_spent'], 2); ?></span>
            </div>
            <div class="payment-total-row">
                <span style="color: var(--text-muted);">Total Paid:</span>
                <span style="color: #4caf50; font-weight: 600;">₱<?php echo number_format($payment_stats['total_paid'], 2); ?></span>
            </div>
            <div class="payment-total-row">
                <span style="color: var(--text-muted);">Balance:</span>
                <span style="color: #f44336; font-weight: 600;">₱<?php echo number_format($payment_stats['total_balance'], 2); ?></span>
            </div>
        </div>
    </div>

    <!-- Upcoming Events Widget -->
    <div class="content-card dashboard-summary-card">
        <h3>
            Upcoming Events
        </h3>
        <?php if (!empty($upcoming_events)): ?>
            <div class="events-scroll">
                <?php foreach ($upcoming_events as $event): ?>
                    <div class="upcoming-event-row" style="background: <?php echo $event['is_urgent'] ? 'rgba(244,67,54,0.1)' : 'rgba(255,255,255,0.02)'; ?>; border-left-color: <?php echo $event['is_urgent'] ? '#f44336' : 'var(--accent-gold)'; ?>;">
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: var(--text-main); font-size: 13px;">
                                <?php echo htmlspecialchars($event['business_name']); ?>
                            </div>
                            <div style="font-size: 11px; color: var(--text-muted);">
                                <?php echo date('M d, Y', strtotime($event['order_date'])); ?> &bull;
                                <?php echo $event['days_left']; ?> days left
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <?php echo getPaymentStatusBadge($event['payment_summary']['payment_status']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="upcoming-empty">
                <p>No upcoming events</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Calendar Widget -->
    <div class="content-card dashboard-summary-card dashboard-calendar-card">
        <h3>
            My Bookings
        </h3>
        <div class="calendar-container">
            <div id="calendar"></div>
        </div>
    </div>
</div>

 </div><!-- /dashboard-overview -->
<div class="dashboard-grid" id="featured-caterers">
    <!-- Left Column: Featured Caterers & Recent Activity -->
    <div class="main-column">
        <div class="section-header">
            <h2 class="section-title"><i class="fas fa-caterer-alt" style="color: var(--accent-gold);"></i> Featured Caterers</h2>
            <a href="browse-caterer.php" class="section-link">View All <i class="fas fa-arrow-right"></i></a>
        </div>

        <div class="caterer-grid">
            <?php if ($featured_stores_result && mysqli_num_rows($featured_stores_result) > 0): ?>
                <?php while($caterer = mysqli_fetch_assoc($featured_stores_result)): ?>
                    <a id="caterer-<?php echo (int)$caterer['id']; ?>" href="browse-caterer.php?store_id=<?php echo $caterer['id']; ?>&source=dashboard" class="caterer-card">
                        <?php if (!empty($caterer['cover_photo']) && is_file(__DIR__ . '/../' . $caterer['cover_photo'])):
                            $coverPosition = in_array($caterer['cover_position'] ?? '', ['center','top','bottom','left','right','top left','top right','bottom left','bottom right'], true) ? $caterer['cover_position'] : 'center';
                        ?>
                            <img src="../<?php echo htmlspecialchars($caterer['cover_photo'], ENT_QUOTES, 'UTF-8'); ?>" class="featured-cover-photo" alt="<?php echo htmlspecialchars($caterer['business_name']); ?> cover" loading="lazy" style="object-position:<?php echo $coverPosition; ?>;" onerror="this.remove()">
                        <?php endif; ?>
                        <img src="../<?php echo !empty($caterer['profile_image']) ? $caterer['profile_image'] : 'uploads/logo.png'; ?>" alt="Caterer" class="caterer-image">
                        <div class="caterer-content">
                            <h3 class="caterer-name"><?php echo htmlspecialchars($caterer['business_name']); ?></h3>
                            <div class="caterer-meta">
                                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($caterer['location'] ?: 'Location N/A'); ?>
                            </div>
                            <div class="caterer-meta">
                                <i class="fas fa-utensils"></i> <?php echo $caterer['product_count']; ?> menu items
                            </div>
                            <div class="caterer-stats">
                                <span><i class="fas fa-star" style="color: #ffd700;"></i> <?php echo $caterer['avg_rating'] ? number_format($caterer['avg_rating'], 1) : 'N/A'; ?> (<?php echo $caterer['review_count']; ?>)</span>
                                <span>Verified <i class="fas fa-check-circle" style="color: var(--success-color);"></i></span>
                            </div>
                        </div>
                    </a>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state" style="grid-column: 1/-1; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-color);">
                    <i class="fas fa-caterer-slash"></i>
                    <p>No featured caterers available at the moment.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Modal -->
<div id="ordersModal" class="modal-overlay">
    <div class="modal-content" id="modalContent">
        <!-- Content loaded via AJAX -->
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (/^#caterer-\d+$/.test(window.location.hash)) {
            const returnTarget = document.getElementById(window.location.hash.slice(1))
                || document.getElementById('featured-caterers');
            requestAnimationFrame(function () {
                returnTarget.scrollIntoView({ block: 'center', behavior: 'instant' });
            });
        }
        var calendarEl = document.getElementById('calendar');



        var ordersModal = document.getElementById('ordersModal');
        var modalContent = document.getElementById('modalContent');

        
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev',
                center: 'title',
                right: 'next'
            },
            height: 'auto',
            events: <?php echo $events_json; ?>,
            dateClick: function(info) {
                loadOrdersForDate(info.dateStr);
            },
            eventClick: function(info) {
                loadOrdersForDate(info.event.startStr.slice(0, 10));
            }
        });
        calendar.render();

        function loadOrdersForDate(date) {
            modalContent.innerHTML = `
                <div class="modal-header"><h2>Loading...</h2></div>
                <div class="modal-body" style="text-align: center;"><i class="fas fa-spinner fa-spin"></i></div>
            `;
            ordersModal.style.display = 'flex';
            
            fetch('dashboard.php?ajax_date=' + date)
                .then(response => response.text())
                .then(html => {
                    modalContent.innerHTML = html;
                })
                .catch(err => {
                    modalContent.innerHTML = '<div class="modal-body">Error loading data.</div>';
                });
        }

        window.closeModal = function() {
            ordersModal.style.display = 'none';


        }

        // Modals only close via the X button — clicking outside does NOT close them
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
