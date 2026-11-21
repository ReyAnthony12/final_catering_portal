<?php
require_once __DIR__ . '/../includes/order_reference_helper.php';
// Start session first as header.php checks it, but we need it for logic before header
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Logic that must run before HTML output (redirects, AJAX, etc.)
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

if (!isLoggedIn() || !in_array(getUserRole(), ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle AJAX request for getting orders by date
if (isset($_GET['ajax_date'])) {
    if (!is_string($_GET['ajax_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ajax_date'])) {
        http_response_code(400);
        exit('Invalid booking date.');
    }
    $date = mysqli_real_escape_string($conn, $_GET['ajax_date']);
    $page_size = 1;
    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM bookings o JOIN users u ON o.customer_id = u.id WHERE o.store_id = '$user_id' AND o.order_date = '$date'");
    $total_orders = $count_result ? (int) mysqli_fetch_assoc($count_result)['total'] : 0;
    $total_pages = max(1, (int) ceil($total_orders / $page_size));
    $orders_page = max(1, min($total_pages, (int) ($_GET['orders_page'] ?? 1)));
    $orders_offset = ($orders_page - 1) * $page_size;
    
    $orders_query = "
        SELECT o.*, u.first_name, u.last_name, p.name as product_name, p.category as product_category
        FROM bookings o 
        JOIN users u ON o.customer_id = u.id 
        LEFT JOIN products p ON o.product_id = p.id
        WHERE o.store_id = '$user_id' 
        AND o.order_date = '$date'
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT $page_size OFFSET $orders_offset
    ";
    $orders_result = mysqli_query($conn, $orders_query);
    
    if ($orders_result && mysqli_num_rows($orders_result) > 0): ?>
        <div class="modal-header" style="position: relative; padding-right: 50px;">
            <div class="orders-heading-copy"><span class="orders-heading-eyebrow">DAILY BOOKINGS</span><h2><?php echo date('F j, Y', strtotime($date)); ?></h2><p><?php echo date('l', strtotime($date)); ?> <span aria-hidden="true">&middot;</span> <?php echo $total_orders; ?> bookings</p></div>
            <button type="button" class="close-btn" aria-label="Close orders" onclick="closeStoreModal()" style="position: absolute; top: 10px; right: 15px; z-index: 9999; width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.1); color: white; border: none; font-size: 24px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; pointer-events: auto !important;">&times;</button>
        </div>
        <div class="modal-body" style="overflow-y: auto; max-height: 400px;">
            <table class="table">
                <thead style="background: rgba(0,0,0,0.2); border-bottom: 2px solid rgba(255,255,255,0.1);">
                    <tr>
                        <th style="padding: 12px; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 600;">Booking #</th>
                        <th style="padding: 12px; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 600;">Customer</th>
                        <th style="padding: 12px; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 600;">Type</th>
                        <th style="padding: 12px; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 600;">Amount</th>
                        <th style="padding: 12px; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 600;">Status</th>
                        <th style="padding: 12px; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 600;">Time</th>
                        <th style="padding: 12px; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 600;">Booked</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($order = mysqli_fetch_assoc($orders_result)): ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); transition: background-color 0.2s;">
                            <td data-label="Booking #" style="white-space: nowrap; font-size: 0.9em; color: var(--text-muted);"><a class="orders-reference-link" aria-label="View booking <?php echo htmlspecialchars((string) $order['order_number'], ENT_QUOTES, 'UTF-8'); ?>" data-booking-id="<?php echo (int) $order['id']; ?>" href="order-details.php?id=<?php echo (int) $order['id']; ?>&amp;dashboard_date=<?php echo rawurlencode($date); ?>&amp;dashboard_page=<?php echo $orders_page; ?>">View</a></td>
                            <td data-label="Customer" style="font-weight: 600; color: #f4f4f5;"><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></td>
                            <td data-label="Type">
                                <span style="display: inline-block; white-space: nowrap; background: rgba(255,255,255,0.1); color: #e4e4e7; padding: 4px 10px; border-radius: 6px; font-size: 0.85em; font-weight: 500;">
                                    <i class="fas fa-user" style="margin-right: 4px; font-size: 0.9em;"></i> Customer
                                </span>
                            </td>
                            <td data-label="Amount" style="font-weight: 600; color: #10b981;">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                            <td data-label="Status">
                                <?php $dashboard_status = strtolower(trim((string) ($order['order_status'] ?? 'pending'))); ?>
                                <span class="status-badge 
                                    <?php echo in_array($dashboard_status, ['pending', 'pending_payment'], true) ? 'status-pending' : 
                                           (in_array($dashboard_status, ['confirmed', 'awaiting_store_confirmation'], true) ? 'status-active' : 'status-closed'); ?>"
                                      style="font-size: 0.75em; letter-spacing: 0.5px; padding: 4px 10px; border-radius: 20px;">
                                    <?php
                                    if ($dashboard_status === 'pending_payment') {
                                        echo 'PENDING';
                                    } elseif ($dashboard_status === 'awaiting_store_confirmation') {
                                        echo 'CONFIRMED';
                                    } else {
                                        echo strtoupper($order['order_status']);
                                    }
                                    ?>
                                </span>
                            </td>
                            <td data-label="Time" style="white-space: nowrap; font-weight: 500;">
                                <?php echo date('h:i A', strtotime($order['order_time'])); ?>
                            </td>
                            <td data-label="Booked" style="white-space: nowrap;">
                                <?php if (!empty($order['created_at'])): ?>
                                    <div style="font-weight: 500;"><?php echo date('M d, Y', strtotime($order['created_at'])); ?></div>
                                    <small style="color: var(--text-muted); opacity: 0.8; font-size: 0.8em;"><?php echo date('h:i A', strtotime($order['created_at'])); ?></small>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">N/A</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <nav class="orders-pagination" aria-label="Booking navigation" data-current-page="<?php echo $orders_page; ?>">
            <span role="status" aria-live="polite">Booking <?php echo $orders_page; ?> of <?php echo $total_orders; ?></span>
            <div>
                <button type="button" data-orders-date="<?php echo htmlspecialchars($date); ?>" data-orders-page="<?php echo $orders_page - 1; ?>" <?php echo $orders_page <= 1 ? 'disabled' : ''; ?> aria-label="Previous booking">&lsaquo;</button>
                <span><?php echo $orders_page; ?> / <?php echo $total_pages; ?></span>
                <button type="button" data-orders-date="<?php echo htmlspecialchars($date); ?>" data-orders-page="<?php echo $orders_page + 1; ?>" <?php echo $orders_page >= $total_pages ? 'disabled' : ''; ?> aria-label="Next booking">&rsaquo;</button>
            </div>
        </nav>
    <?php else: ?>
        <div class="modal-header" style="position: relative; padding-right: 50px;">
            <div class="orders-heading-copy"><span class="orders-heading-eyebrow">DAILY BOOKINGS</span><h2><?php echo date('F j, Y', strtotime($date)); ?></h2><p><?php echo date('l', strtotime($date)); ?> <span aria-hidden="true">&middot;</span> <?php echo $orders_result ? mysqli_num_rows($orders_result) : 0; ?> scheduled orders</p></div>
            <button type="button" class="close-btn" aria-label="Close orders" onclick="closeStoreModal()" style="position: absolute; top: 10px; right: 15px; z-index: 9999; width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.1); color: white; border: none; font-size: 24px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; pointer-events: auto !important;">&times;</button>
        </div>
        <div class="modal-body" style="text-align: center; padding: 40px;">
            <i class="fas fa-calendar-times fa-3x" style="margin-bottom: 20px; color: var(--text-muted);"></i>
            <p style="color: var(--text-muted); font-size: 16px;">No orders for this date.</p>
        </div>
    <?php endif;
    exit();
}

// Fetch caterer details needed for logic (toggle status)
// Header will fetch its own copy for display
$store_query = "SELECT u.*, sd.business_name, sd.is_open, sd.business_open_time, sd.business_close_time, sd.auto_schedule_enabled, sd.subscription_status, sd.subscription_expiry, sd.free_trial_expiry, u.status as user_status 
                FROM users u 
                LEFT JOIN store_details sd ON u.id = sd.user_id 
                WHERE u.id = '$user_id'";
$store_result = mysqli_query($conn, $store_query);
$caterer = mysqli_fetch_assoc($store_result);
$is_store_closed_by_portal = ($caterer['user_status'] === 'closed');
$store_toggle_feedback = '';

$next_action_summary = [
    'payments' => 0,
    'reschedules' => 0,
    'menu_changes' => 0
];

$tomorrow_date = date('Y-m-d', strtotime('+1 day'));
$tomorrow_bookings = [];

$pending_payments_q = mysqli_query($conn, "
    SELECT COUNT(DISTINCT p.order_id) AS total
    FROM payments p
    JOIN bookings o ON p.order_id = o.id
    WHERE o.store_id = '$user_id'
      AND p.status = 'pending'
");
if ($pending_payments_q && $row = mysqli_fetch_assoc($pending_payments_q)) {
    $next_action_summary['payments'] = intval($row['total'] ?? 0);
}

$pending_reschedules_q = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM bookings o
    WHERE o.store_id = '$user_id'
      AND LOWER(COALESCE(o.reschedule_status, '')) = 'pending'
      AND COALESCE(o.requested_order_date, '') <> ''
      AND COALESCE(o.requested_order_time, '') <> ''
");
if ($pending_reschedules_q && $row = mysqli_fetch_assoc($pending_reschedules_q)) {
    $next_action_summary['reschedules'] = intval($row['total'] ?? 0);
}

$pending_menu_changes_q = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM messages m
    JOIN bookings o ON m.order_id = o.id
    WHERE o.store_id = '$user_id'
      AND m.sender_id = o.customer_id
      AND (
          m.subject LIKE '%Menu Change Request%'
          OR m.message LIKE '%Requested Set:%'
      )
      AND NOT EXISTS (
          SELECT 1
          FROM booking_logs ol
          WHERE ol.order_id = o.id
            AND ol.action IN ('Menu Change Approved', 'Menu Change Rejected')
            AND ol.created_at >= m.created_at
      )
");
if ($pending_menu_changes_q && $row = mysqli_fetch_assoc($pending_menu_changes_q)) {
    $next_action_summary['menu_changes'] = intval($row['total'] ?? 0);
}

$tomorrow_bookings_q = mysqli_query($conn, "
    SELECT
        o.id,
        o.order_number,
        o.order_date,
        o.order_time,
        o.order_status,
        o.total_amount,
        u.first_name,
        u.last_name,
        p.name AS product_name,
        p.category AS product_category
    FROM bookings o
    JOIN users u ON o.customer_id = u.id
    LEFT JOIN products p ON o.product_id = p.id
    WHERE o.store_id = '$user_id'
      AND DATE(o.order_date) = '$tomorrow_date'
      AND LOWER(COALESCE(o.order_status, 'pending')) NOT IN ('completed', 'cancelled', 'rejected', 'declined')
    ORDER BY o.order_time ASC, o.created_at ASC
");

if ($tomorrow_bookings_q) {
    while ($tomorrow_booking = mysqli_fetch_assoc($tomorrow_bookings_q)) {
        $tomorrow_bookings[] = $tomorrow_booking;

        $order_id = intval($tomorrow_booking['id']);
        $order_number = mysqli_real_escape_string($conn, $tomorrow_booking['order_number']);
        $reminder_exists_q = mysqli_query($conn, "
            SELECT id
            FROM notifications
            WHERE user_id = '$user_id'
              AND type = 'booking_reminder'
              AND link = 'order-details.php?id=$order_id'
              AND DATE(created_at) = CURDATE()
            LIMIT 1
        ");

        if ($reminder_exists_q && !mysqli_fetch_assoc($reminder_exists_q)) {
            $customer_name = trim(($tomorrow_booking['first_name'] ?? '') . ' ' . ($tomorrow_booking['last_name'] ?? ''));
            $time_label = !empty($tomorrow_booking['order_time']) ? date('h:i A', strtotime($tomorrow_booking['order_time'])) : 'Time not set';
            createNotification(
                $user_id,
                'booking_reminder',
                'Booking Tomorrow: #' . $order_number,
                $customer_name . ' is scheduled for tomorrow at ' . $time_label . '.',
                'order-details.php?id=' . $order_id
            );
        }
    }
}

if (isset($_GET['store_status'])) {
    if ($_GET['store_status'] === 'opened') {
        $store_toggle_feedback = 'Your caterer is now open.';
    } elseif ($_GET['store_status'] === 'closed') {
        $store_toggle_feedback = 'Your caterer is now closed.';
    } elseif ($_GET['store_status'] === 'auto_enabled') {
        $store_toggle_feedback = 'Auto Schedule is now on and follows your profile business hours.';
    } elseif ($_GET['store_status'] === 'auto_disabled') {
        $store_toggle_feedback = 'Auto Schedule is now off. Manual Open/Close control is active again.';
    } elseif ($_GET['store_status'] === 'auto_hours_missing') {
        $store_toggle_feedback = 'Set your business hours in Caterer Profile first before enabling Auto Schedule.';
    } elseif ($_GET['store_status'] === 'error') {
        $store_toggle_feedback = 'Unable to update the caterer status right now.';
    }
}

// Handle automatic schedule mode toggle
if (isset($_POST['toggle_auto_schedule']) && !$is_store_closed_by_portal) {
    $auto_schedule_enabled = !empty($caterer['auto_schedule_enabled']) ? 0 : 1;

    if ($auto_schedule_enabled && (empty($caterer['business_open_time']) || empty($caterer['business_close_time']))) {
        header('Location: dashboard.php?store_status=auto_hours_missing');
        exit();
    }

    $auto_schedule_updated = mysqli_query($conn, "UPDATE store_details SET auto_schedule_enabled = '$auto_schedule_enabled' WHERE user_id = '$user_id'");
    header('Location: dashboard.php?store_status=' . ($auto_schedule_updated ? ($auto_schedule_enabled ? 'auto_enabled' : 'auto_disabled') : 'error'));
    exit();
}

// Handle caterer open/close (only if not closed by portal)
if (isset($_POST['toggle_status']) && !$is_store_closed_by_portal) {
    $new_status = !empty($caterer['is_open']) ? 0 : 1;
    $status_updated = mysqli_query($conn, "UPDATE store_details SET is_open = '$new_status' WHERE user_id = '$user_id'");
    header('Location: dashboard.php?store_status=' . ($status_updated ? ($new_status ? 'opened' : 'closed') : 'error'));
    exit();
}

// Get orders for calendar events
$calendar_events_query = "
    SELECT 
        o.id,
        o.order_number,
        o.total_amount,
        o.order_status,
        o.created_at,
        o.order_date,
        o.product_id,
        o.catering_service_id,
        u.first_name,
        u.last_name
    FROM bookings o 
    JOIN users u ON o.customer_id = u.id 
    WHERE o.store_id = '$user_id'
    ORDER BY o.order_date DESC
";
$calendar_events_result = mysqli_query($conn, $calendar_events_query);

// Prepare events array for calendar
$events = [];
while ($event = mysqli_fetch_assoc($calendar_events_result)) {
    // Use order_date (Event Date) instead of created_at (Booking Date)
    $event_date = date('Y-m-d', strtotime($event['order_date']));
    
    if (!empty($event['catering_service_id'])) {
        $title = "Catering: " . substr($event['first_name'], 0, 1) . ". " . $event['last_name'];
    } elseif (!empty($event['product_id'])) {
        $title = "Product: " . substr($event['first_name'], 0, 1) . ". " . $event['last_name'];
    } else {
        $title = "Booking #" . formatCatererBookingNumber($event['id'], $event['order_number']);
    }
    
    $color = '#d4af37';
    if ($event['order_status'] === 'pending') {
        $color = '#f39c12';
    } elseif ($event['order_status'] === 'confirmed') {
        $color = '#27ae60';
    } elseif ($event['order_status'] === 'cancelled') {
        $color = '#e74c3c';
    } elseif ($event['order_status'] === 'completed') {
        $color = '#95a5a6';
    }
    
    $events[] = [
        'id' => $event['id'],
        'title' => $title,
        'start' => $event_date,
        'color' => $color
    ];
}

$events_json = json_encode($events);

// Get Earnings Analytics
$earnings_query = "
    SELECT 
        SUM(CASE WHEN order_status = 'completed' THEN total_amount ELSE 0 END) as total_sales,
        SUM(CASE WHEN order_status IN ('confirmed', 'verified') THEN total_amount ELSE 0 END) as confirmed_deposits,
        SUM(CASE WHEN order_status = 'pending' THEN total_amount ELSE 0 END) as pending_revenue,
        COUNT(id) as total_orders
    FROM bookings 
    WHERE store_id = '$user_id'
";
$earnings_result = mysqli_query($conn, $earnings_query);
$earnings = mysqli_fetch_assoc($earnings_result);

// Subscription-based summary (no per-order commission deduction)
$monthly_subscription_fee = 500.00;
$gross_revenue = floatval($earnings['total_sales'] ?? 0)
    + floatval($earnings['confirmed_deposits'] ?? 0)
    + floatval($earnings['pending_revenue'] ?? 0);

// INCLUDE HEADER (This starts HTML, Body, Sidebar)
require_once __DIR__ . '/includes/header.php';
?>

<style>
    html {
        scrollbar-color: rgba(212, 175, 55, 0.78) #111111;
    }

    .analytics-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }
    .analytics-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        transition: all 0.3s ease;
    }
    .analytics-card:hover {
        transform: translateY(-5px);
        border-color: var(--accent-gold);
        box-shadow: 0 10px 20px rgba(0,0,0,0.2);
    }
    .analytics-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }
    .analytics-info h4 {
        font-size: 11px;
        color: var(--text-muted);
        margin: 0;
        text-transform: uppercase;
        letter-spacing: 0.1em;
    }
    .analytics-info .value {
        font-size: 22px;
        font-weight: 800;
        color: var(--text-main);
        margin-top: 4px;
    }

    html::-webkit-scrollbar {
        width: 12px;
    }

    html::-webkit-scrollbar-track {
        background: #111111;
    }

    html::-webkit-scrollbar-thumb {
        background: rgba(212, 175, 55, 0.78);
        border-radius: 999px;
        border: 2px solid #111111;
    }

    html::-webkit-scrollbar-thumb:hover {
        background: var(--accent-gold);
    }

    body {
        overflow: hidden;
    }

    .main-content {
        height: 100vh;
        min-height: 100vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        padding: 18px 24px;
    }

    .main-footer {
        display: block;
    }

    .content-header {
        padding: 18px 22px;
        margin-bottom: 18px;
        flex-shrink: 0;
    }

    .content-header .page-title h1 {
        font-size: 22px;
    }

    .content-header .page-title p {
        margin-top: 2px;
    }

    .dashboard-layout {
        display: grid;
        grid-template-columns: minmax(280px, 340px) minmax(0, 1fr);
        gap: 24px;
        align-items: stretch;
        flex: 1;
        min-height: 0;
    }

    .dashboard-layout .card {
        margin-bottom: 0;
        min-height: 0;
        height: 100%;
    }

    .quick-actions-panel {
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .dashboard-layout .quick-actions-panel {
        height: auto;
        align-self: start;
        padding: 12px;
    }

    .quick-actions-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 16px;
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        padding-right: 6px;
        scrollbar-width: thin;
        scrollbar-color: var(--border-color) transparent;
    }

    .quick-actions-grid::-webkit-scrollbar {
        width: 6px;
    }

    .quick-actions-grid::-webkit-scrollbar-track {
        background: rgba(255,255,255,0.06);
        border-radius: 999px;
    }

    .quick-actions-grid::-webkit-scrollbar-thumb {
        background: rgba(212, 175, 55, 0.75);
        border-radius: 999px;
    }

    .quick-actions-grid::-webkit-scrollbar-thumb:hover {
        background: var(--accent-gold);
    }

    .quick-action-card {
        text-decoration: none;
        color: inherit;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 16px;
        background: rgba(255,255,255,0.02);
        display: block;
    }

    .quick-action-card.attention {
        border-color: rgba(241, 196, 15, 0.35);
        background: rgba(241, 196, 15, 0.08);
    }

    .quick-action-notes {
        margin-top: 10px;
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .quick-actions-panel .quick-actions-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 6px;
        align-content: start;
        padding-right: 0;
    }

    .quick-actions-panel .quick-action-card:not(.attention) {
        min-width: 0;
        padding: 7px 4px;
        border-radius: 10px;
        container-type: inline-size;
        border-color: rgba(212, 175, 55, 0.2);
        background: linear-gradient(150deg, rgba(212, 175, 55, 0.10), rgba(255, 255, 255, 0.015) 80%);
        text-align: center;
        transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
    }

    .quick-action-card:not(.attention):hover,
    .quick-action-card:not(.attention):focus-visible {
        transform: translateY(-3px);
        border-color: var(--accent-gold);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
    }

    .quick-action-card:not(.attention):focus-visible {
        outline: 2px solid var(--accent-gold);
        outline-offset: 2px;
    }

    .quick-action-card:not(.attention) > div {
        flex-direction: column-reverse;
        align-items: center !important;
        gap: 8px !important;
    }

    .quick-action-card:not(.attention) > div > div {
        min-width: 0;
        width: 100%;
        display: flex;
        flex-direction: column-reverse;
        align-items: center;
        gap: 4px;
    }

    .quick-action-card:not(.attention) > div > div > div:first-child {
        font-size: 11px !important;
        text-transform: none !important;
        letter-spacing: 0 !important;
        line-height: 1.4;
        font-weight: 600;
        min-height: 2.8em;
    }

    .quick-action-card:not(.attention) > div > div > div:last-child {
        margin-top: 0 !important;
        font-size: min(22px, 16cqi) !important;
        white-space: nowrap;
        overflow-wrap: normal;
        line-height: 1;
        font-weight: 800 !important;
        font-variant-numeric: tabular-nums;
        color: #f4d774 !important;
    }

    .quick-action-card.attention {
        grid-column: 1 / -1;
    }

    .calendar-card {
        min-height: 100%;
        display: flex;
        flex-direction: column;
    }

    #calendar {
        min-height: 0;
        flex: 1;
    }

    .fc .fc-toolbar.fc-header-toolbar {
        margin-bottom: 10px;
        gap: 10px;
        flex-wrap: wrap;
    }

    .fc .fc-toolbar-title {
        font-size: 1.1rem;
    }

    .fc .fc-button {
        padding: 0.35em 0.7em;
        font-size: 0.8rem;
    }

    .fc .fc-daygrid-day-frame {
        min-height: 68px;
    }

    .fc .fc-daygrid-event {
        border-radius: 6px;
        padding: 2px 4px;
        font-size: 0.72rem;
    }

    @media (max-width: 1100px) {
        body {
            overflow: auto;
        }

        .main-content {
            height: auto;
            min-height: 100vh;
            overflow: visible;
        }

        .main-footer {
            display: block;
        }

        .dashboard-layout {
            grid-template-columns: 1fr;
        }

        .quick-actions-grid {
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }

        /* Give the calendar a proper height when stacked */
        .calendar-card {
            min-height: 480px;
        }

        #calendar {
            min-height: 420px;
        }
    }

    @media (max-width: 768px) {
        .dashboard-heading {
            flex-direction: column;
            align-items: stretch !important;
            gap: 16px;
        }

        .dashboard-controls {
            justify-content: flex-start !important;
        }

        .dashboard-controls form {
            flex: 1 1 180px;
        }

        .dashboard-controls .btn {
            width: 100%;
            min-height: 44px;
            white-space: normal;
        }

        .dashboard-layout,
        .dashboard-layout .card {
            min-width: 0;
        }

        .quick-actions-grid {
            grid-template-columns: minmax(0, 1fr);
            overflow: visible;
            padding-right: 0;
        }

        .quick-action-card {
            overflow-wrap: anywhere;
        }

        .fc .fc-toolbar-title {
            font-size: 1rem;
        }

        /* Smaller day cells on mobile */
        .fc .fc-daygrid-day-frame {
            min-height: 52px;
        }

        /* Keep navigation, title, and view controls on one mobile row. */
        .fc .fc-toolbar.fc-header-toolbar {
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            gap: 4px;
        }

        .fc .fc-toolbar-chunk {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .fc .fc-toolbar-title {
            font-size: clamp(0.7rem, 3vw, 1rem);
            white-space: nowrap;
        }

        .fc .fc-toolbar-chunk > :not(:first-child) {
            margin-left: 4px;
        }

        .fc .fc-button {
            padding: 0.3em 0.4em;
            font-size: 0.7rem;
        }

        .content-header {
            padding: 16px;
        }

        /* Mobile-friendly modal: full-width, near-fullscreen */
        .modal-dialog {
            width: 100% !important;
            max-width: 100% !important;
            max-height: 92vh !important;
            margin: 0 !important;
            border-radius: 16px 16px 0 0 !important;
            position: fixed !important;
            bottom: 0 !important;
            left: 0 !important;
            top: auto !important;
        }

        .caterer-modal {
            align-items: flex-end !important;
        }

        .modal-body {
            max-height: 60vh !important;
            overflow-y: auto !important;
            -webkit-overflow-scrolling: touch;
        }

        /* Make table inside modal scrollable horizontally */
        .modal-body .table {
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }
    }

    @media (max-width: 480px) {
        .calendar-card {
            min-height: 400px;
        }

        #calendar {
            min-height: 360px;
        }

        .fc .fc-daygrid-day-frame {
            min-height: 44px;
        }

        .fc .fc-daygrid-event {
            font-size: 0.65rem;
        }
    }
</style>

<!-- Keep the mobile header clearance after the dashboard's desktop padding. -->
<style>
    @media (max-width: 992px) {
        .main-content {
            padding: 74px 16px 24px;
        }
    }
    @media (max-width: 480px) {
        .main-content {
            padding-left: 12px;
            padding-right: 12px;
        }
        .dashboard-layout { gap: 16px; }
        .dashboard-layout .card { padding: 16px; }
    }
</style>

<style>
    .content-header:has(.dashboard-heading) {
        padding: 12px;
        margin-bottom: 16px;
        border-radius: 16px;
    }

    .dashboard-heading .page-title:not(:has(*)) {
        display: none;
    }

    .dashboard-heading .dashboard-controls {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        width: 100%;
        max-width: 440px;
        margin-left: auto;
    }

    .dashboard-controls form {
        min-width: 0;
        margin: 0;
    }

    .dashboard-heading .dashboard-controls .btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        width: 100%;
        height: 46px;
        padding: 8px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 600;
        line-height: 1.3;
        white-space: normal;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
    }

    .dashboard-controls .btn i {
        flex-shrink: 0;
        font-size: 13px;
    }

    @media (max-width: 768px) {
        .dashboard-heading .dashboard-controls {
            max-width: none;
            margin-left: 0;
            gap: 8px;
        }
    }
</style>

<?php if ($is_store_closed_by_portal): ?>
    <div class="card" style="text-align: center; padding: 60px 20px;">
        <i class="fas fa-lock fa-5x" style="color: #f44336; margin-bottom: 30px;"></i>
        <h2 style="color: var(--text-main);">Account Temporarily Closed</h2>
        <p style="color: var(--text-muted); font-size: 18px; max-width: 600px; margin: 20px auto;">
            Your caterer account has been closed by the Portal Administrator. 
        </p>
    </div>
<?php else: ?>
    <?php if ($store_toggle_feedback !== ''):
        $status_feedback_error = in_array($_GET['store_status'] ?? '', ['error', 'auto_hours_missing'], true);
        $status_feedback_titles = [
            'opened' => 'You’re open for bookings',
            'closed' => 'Your caterer is now closed',
            'auto_enabled' => 'Auto Schedule enabled',
            'auto_disabled' => 'Manual control enabled',
            'auto_hours_missing' => 'Business hours needed',
            'error' => 'Update unsuccessful',
        ];
    ?>
    <dialog id="storeStatusFeedback" aria-labelledby="storeStatusFeedbackTitle" aria-describedby="storeStatusFeedbackMessage" class="store-status-feedback<?php echo $status_feedback_error ? ' is-error' : ''; ?>">
        <form method="dialog">
            <button class="status-feedback-close" aria-label="Close notification" value="close"><i class="fas fa-times" aria-hidden="true"></i></button>
            <div class="status-feedback-icon" aria-hidden="true"><i class="fas <?php echo $status_feedback_error ? 'fa-exclamation-triangle' : 'fa-check'; ?>"></i></div>
            <span class="status-feedback-eyebrow"><?php echo $status_feedback_error ? 'Action needed' : 'Settings updated'; ?></span>
            <h2 id="storeStatusFeedbackTitle"><?php echo htmlspecialchars($status_feedback_titles[$_GET['store_status'] ?? ''] ?? 'Caterer status updated', ENT_QUOTES, 'UTF-8'); ?></h2>
            <p id="storeStatusFeedbackMessage"><?php echo htmlspecialchars($store_toggle_feedback, ENT_QUOTES, 'UTF-8'); ?></p>
            <button class="status-feedback-done" value="done" autofocus>Got it</button>
        </form>
    </dialog>
    <style>
        #storeStatusFeedback {
            box-sizing: border-box; position: fixed; inset: 0; margin: auto;
            width: calc(100% - 32px); max-width: 420px; max-height: calc(100dvh - 32px);
            overflow-y: auto; padding: 32px 28px 26px; border: 1px solid #a4b49c;
            border-radius: 22px; background: #edf1e7; color: #20372b;
            box-shadow: 0 24px 80px #102c3440; text-align: center; font-family: inherit;
        }
        #storeStatusFeedback::backdrop { background: #102a236b; backdrop-filter: blur(5px); }
        #storeStatusFeedback form { margin: 0; }
        #storeStatusFeedback .status-feedback-close {
            position: absolute; top: 10px; right: 10px; width: 44px; height: 44px;
            border: 0; border-radius: 50%; background: transparent; color: #425642; cursor: pointer;
        }
        #storeStatusFeedback .status-feedback-icon {
            display: grid; place-items: center; width: 64px; height: 64px; margin: 8px auto 20px;
            border-radius: 50%; background: #d5e4cf; color: #27583e; font-size: 26px;
        }
        #storeStatusFeedback.is-error .status-feedback-icon { background: #f3e2cb; color: #805115; }
        #storeStatusFeedback .status-feedback-eyebrow { color: #52674c; font-size: 11px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; }
        #storeStatusFeedback h2 { margin: 10px 0 12px; color: #20372b; font-size: clamp(21px, 5vw, 25px); line-height: 1.25; }
        #storeStatusFeedback p { margin: 0; color: #425642; font-size: 14px; line-height: 1.7; overflow-wrap: anywhere; }
        #storeStatusFeedback .status-feedback-done { margin-top: 26px; width: 100%; min-height: 46px; padding: 12px 20px; border: 1px solid #173d32; border-radius: 11px; background: #173d32; color: #f5f6ef; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; }
        #storeStatusFeedback button:focus-visible { outline: 3px solid #638658; outline-offset: 3px; }
        html:has(#storeStatusFeedback[open]) { overflow: hidden; }
        @media (max-width: 380px) { #storeStatusFeedback { padding: 28px 20px 22px; border-radius: 18px; } }
        @media (prefers-reduced-motion: no-preference) {
            #storeStatusFeedback[open] { animation: status-feedback-enter .22s ease-out; }
            @keyframes status-feedback-enter { from { opacity: 0; transform: translateY(12px) scale(.98); } to { opacity: 1; transform: none; } }
        }
    </style>
    <script>
        (() => {
            const feedback = document.getElementById('storeStatusFeedback');
            feedback.showModal();
            // Remove the one-time result so refreshing does not reopen the notification.
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.delete('store_status');
            window.history.replaceState(window.history.state, '', currentUrl);
        })();
    </script>
    <?php endif; ?>


<?php endif; ?>
<div class="dashboard-layout">
        <div class="card calendar-card">
            
<div class="quick-actions-toolbar">
    <div class="dashboard-heading" style="width: 100%; display: flex; justify-content: space-between; align-items: center;">
        <div class="page-title">
            
            <?php if ($is_store_closed_by_portal): ?>
                <div class="alert alert-error" style="margin-top: 15px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>ACCOUNT CLOSED</strong> - Your caterer account has been closed by the Portal Admin. 
                    You can only access the Messages section to contact the portal for inquiries.
                </div>
            <?php elseif ($caterer['subscription_status'] === 'trial' && $caterer['free_trial_expiry']): ?>
                <p style="color: var(--warning); font-weight: bold; margin-top: 5px;">
                    <i class="fas fa-clock"></i> Free Trial ends: <?php echo date('F d, Y', strtotime($caterer['free_trial_expiry'])); ?>
                </p>
            <?php endif; ?>
        </div>
        
        
        <?php if (!$is_store_closed_by_portal): ?>
            <div class="dashboard-controls">
                <form method="POST" action="">
                    <button
                        type="submit"
                        name="toggle_status"
                        class="btn <?php echo $caterer['is_open'] ? 'btn-danger' : 'btn-primary'; ?>"
                        <?php echo !empty($caterer['auto_schedule_enabled']) ? 'disabled title="Turn off Auto Schedule to use manual Open/Close."' : ''; ?>
                        style="<?php echo !empty($caterer['auto_schedule_enabled']) ? 'opacity: 0.6; cursor: not-allowed;' : ''; ?>"
                    >
                        <i class="fas fa-power-off"></i> 
                        <?php echo $caterer['is_open'] ? 'Close Caterer' : 'Open Caterer'; ?>
                    </button>
                </form>
                <form method="POST" action="">
                    <button
                        type="submit"
                        name="toggle_auto_schedule"
                        class="btn"
                        style="background: <?php echo !empty($caterer['auto_schedule_enabled']) ? 'rgba(46, 204, 113, 0.15)' : 'rgba(52, 152, 219, 0.12)'; ?>; border: 1px solid <?php echo !empty($caterer['auto_schedule_enabled']) ? 'rgba(46, 204, 113, 0.45)' : 'rgba(52, 152, 219, 0.35)'; ?>; color: <?php echo !empty($caterer['auto_schedule_enabled']) ? '#9cf0be' : '#8fcfff'; ?>;"
                    >
                        <i class="fas fa-clock"></i>
                        <?php echo !empty($caterer['auto_schedule_enabled']) ? 'Auto Schedule Off' : 'Auto Schedule On'; ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>


        <div class="card-header calendar-heading">
                <div><span class="calendar-eyebrow">YOUR SCHEDULE</span><h3>Booking Calendar</h3></div>
                <span class="calendar-month-count" id="calendarMonthCount" aria-live="polite"></span>
            </div>
            <div id="calendar"></div>
        </div>
    </div>

<!-- Modal Overlay (must be BEFORE modal in DOM so modal renders on top) -->
<div id="storeModalOverlay" class="modal-overlay" style="display: none; z-index: 1040;"></div>

<!-- Modal Container - CENTERED DESIGN -->
<div id="caterer-modal" role="dialog" aria-modal="true" aria-label="Orders for selected date" tabindex="-1" class="caterer-modal" style="display: none; z-index: 1050;">
    <div class="modal-dialog">
        <div id="storeModalContent">
            <!-- Modal content will be loaded here -->
        </div>
    </div>
</div>

<link rel="stylesheet" href="../assets/css/caterer-calendar-modal.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-calendar-modal.css'); ?>">
<link rel="stylesheet" href="../assets/css/caterer-dashboard-calendar.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-dashboard-calendar.css'); ?>">
<link rel="stylesheet" href="../assets/css/caterer-dashboard-desktop.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-dashboard-desktop.css'); ?>">
<!-- Page Specific Scripts -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<script>
    // Dashboard initialization function
    function initDashboard() {
        var calendarEl = document.getElementById('calendar');
        
        // Parse PHP events data safely
        var eventsData = <?php echo !empty($events_json) ? $events_json : '[]'; ?>;
        
        if (calendarEl && !calendarEl.dataset.initialized) {
            calendarEl.dataset.initialized = 'true';
            var isMobile = window.innerWidth <= 760;
            var returnDate = new URLSearchParams(location.search).get('booking_date');
            if (!/^\d{4}-\d{2}-\d{2}$/.test(returnDate || '')) returnDate = null;
            var fitFrame = null;
            var previousCalendarWidth = null;
            function scheduleCalendarFit() {
                if (fitFrame !== null) return;
                fitFrame = requestAnimationFrame(function () {
                    fitFrame = null;
                    fitMobileCalendar();
                });
            }
            function fitMobileCalendar() {
                var height = 'auto';
                if (window.innerWidth <= 992) {
                    var card = calendarEl.closest('.calendar-card');
                    var main = calendarEl.closest('.main-content');
                    var availableHeight = main.getBoundingClientRect().bottom - parseFloat(getComputedStyle(main).paddingBottom) - calendarEl.getBoundingClientRect().top - parseFloat(getComputedStyle(card).paddingBottom);
                    if (availableHeight <= 0 || !Number.isFinite(availableHeight)) return;
                    height = Math.floor(availableHeight);
                } else {
                    var desktopCard = calendarEl.closest('.calendar-card');
                    var desktopStyle = getComputedStyle(desktopCard);
                    var desktopHeight = desktopCard.getBoundingClientRect().bottom
                        - calendarEl.getBoundingClientRect().top
                        - parseFloat(desktopStyle.paddingBottom)
                        - parseFloat(desktopStyle.borderBottomWidth);
                    if (desktopHeight <= 0 || !Number.isFinite(desktopHeight)) return;
                    height = Math.floor(desktopHeight);
                }
                var width = Math.floor(calendarEl.getBoundingClientRect().width);
                if (calendar.getOption('height') !== height) {
                    calendar.setOption('height', height);
                } else if (previousCalendarWidth !== width) {
                    calendar.updateSize();
                }
                previousCalendarWidth = width;
            }
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                initialDate: returnDate || undefined,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: isMobile ? '' : 'dayGridMonth,dayGridWeek'
                },
                height: 'auto',
                aspectRatio: window.innerWidth > 1100 ? 1.9 : 1.35,
                fixedWeekCount: false,
                showNonCurrentDates: false,
                eventDisplay: 'none',
                windowResize: function() {
                    var stacked = window.innerWidth <= 760;
                    calendar.setOption('headerToolbar', {
                        left: 'prev,next today',
                        center: 'title',
                        right: stacked ? '' : 'dayGridMonth,dayGridWeek'
                    });
                    scheduleCalendarFit();
                },
                events: eventsData,
                firstDay: 0,
                buttonText: { today: 'Today', month: 'Month', week: 'Week' },
                dayHeaderFormat: { weekday: 'short' },
                datesSet: function(info) {
                    scheduleCalendarFit();
                    const month = info.view.currentStart;
                    const count = eventsData.filter(event => {
                        const day = new Date(event.start + 'T00:00:00');
                        return day.getMonth() === month.getMonth() && day.getFullYear() === month.getFullYear();
                    }).length;
                    document.getElementById('calendarMonthCount').textContent = count + (count === 1 ? ' booking' : ' bookings');
                },
                dayCellDidMount: function(info) {
                    if (info.isOther) return;
                    const d = info.date;
                    const key = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                    const count = eventsData.filter(event => event.start === key).length;
                    if (!count) return;
                    info.el.classList.add('calendar-has-bookings');
                    const indicator = document.createElement('button');
                    indicator.type = 'button';
                    indicator.className = 'calendar-day-bookings';
                    indicator.setAttribute('aria-label', count + ' bookings on ' + d.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }));
                    const number = document.createElement('strong');
                    number.textContent = count;
                    const label = document.createElement('span');
                    label.textContent = count === 1 ? 'order' : 'orders';
                    indicator.append(number, label);
                    indicator.addEventListener('click', function(e) { e.stopPropagation(); loadOrdersForDate(key); });
                    info.el.querySelector('.fc-daygrid-day-frame').append(indicator);
                },
                eventDidMount: function(info) { info.el.title = info.event.title; },
                dateClick: function(info) {
                    // Load orders for selected date via AJAX
                    loadOrdersForDate(info.dateStr);
                },
                eventClick: function(info) {
                    // Tap on event also opens the day modal
                    var dateStr = info.event.startStr ? info.event.startStr.substring(0, 10) : info.event.start.toISOString().substring(0, 10);
                    loadOrdersForDate(dateStr);
                }
            });
            
            calendar.render();
            scheduleCalendarFit();
            if (window.ResizeObserver) {
                var calendarSizeObserver = new ResizeObserver(scheduleCalendarFit);
                calendarSizeObserver.observe(calendarEl.closest('.calendar-card'));
                calendarSizeObserver.observe(document.querySelector('.calendar-heading'));
                calendarSizeObserver.observe(document.querySelector('.quick-actions-toolbar'));
            }
            window.addEventListener('pageshow', scheduleCalendarFit);
            window.addEventListener('load', scheduleCalendarFit);
            if (window.visualViewport) window.visualViewport.addEventListener('resize', scheduleCalendarFit);
            if (document.fonts) document.fonts.ready.then(scheduleCalendarFit);
            if (returnDate) {
                loadOrdersForDate(returnDate, Math.max(1, Number(new URLSearchParams(location.search).get('booking_page')) || 1));
            }
        }
        
        console.log('Caterer Dashboard initialized with calendar');
    }
    
    let ordersPreviousFocus = null;
    let ordersPreviousOverflow = '';
    let ordersRequest = null;
    function loadOrdersForDate(date, page = 1) {
        const returnUrl = new URL(location.href);
        returnUrl.searchParams.set('booking_date', date);
        returnUrl.searchParams.set('booking_page', page);
        history.replaceState(history.state, '', returnUrl);
        document.querySelectorAll('#calendar .calendar-selected-day').forEach(el => el.classList.remove('calendar-selected-day'));
        const selected = document.querySelector('#calendar [data-date="' + date + '"]');
        if (selected) selected.classList.add('calendar-selected-day');
        const modalContent = document.getElementById('storeModalContent');
        const storeModal = document.getElementById('caterer-modal');
        const modalOverlay = document.getElementById('storeModalOverlay');
        const previousPage = Number(modalContent.querySelector('[data-current-page]')?.dataset.currentPage || 0);
        const isSliding = storeModal.style.display === 'flex' && previousPage > 0;
        
        document.body.append(modalOverlay, storeModal);
        if (storeModal.style.display !== 'flex') {
            ordersPreviousFocus = document.activeElement;
            ordersPreviousOverflow = document.body.style.overflow;
        }
        document.body.style.overflow = 'hidden';
        if (ordersRequest) ordersRequest.abort();
        ordersRequest = new AbortController();
        const request = ordersRequest;
        // Show loading in modal
        modalContent.setAttribute('aria-busy', 'true');
        if (!isSliding) modalContent.innerHTML = `
            <div class="modal-header" style="position: relative; padding-right: 50px;">
                <h2>Loading...</h2>
                <button type="button" class="close-btn" aria-label="Close orders" onclick="closeStoreModal()" style="position: absolute; top: 10px; right: 15px; z-index: 9999; width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.1); color: white; border: none; font-size: 24px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; pointer-events: auto !important;">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center; padding: 40px;">
                <i class="fas fa-spinner fa-spin fa-2x" style="color: var(--accent-blue);"></i>
            </div>
        `;
        
        // Show modal
        storeModal.style.display = 'flex';
        modalOverlay.style.display = 'block';
        
        storeModal.querySelector('.close-btn').focus();
        // Fetch orders data
        fetch(`?ajax_date=${encodeURIComponent(date)}&orders_page=${encodeURIComponent(page)}`, { signal: request.signal, cache: 'no-store' })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(data => {
                if (request.signal.aborted) return;
                modalContent.innerHTML = data;
                modalContent.removeAttribute('aria-busy');
                const bookingBody = modalContent.querySelector('.modal-body');
                if (isSliding && bookingBody && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    bookingBody.animate([
                        { transform: `translateX(${page > previousPage ? '24px' : '-24px'})`, opacity: 0 },
                        { transform: 'translateX(0)', opacity: 1 }
                    ], { duration: 220, easing: 'ease-out' });
                }
                if (isSliding) {
                    const direction = page > previousPage ? 'Next booking' : 'Previous booking';
                    const button = modalContent.querySelector(`button[aria-label="${direction}"]:not(:disabled)`)
                        || modalContent.querySelector('.orders-pagination button:not(:disabled)');
                    button?.focus({ preventScroll: true });
                } else modalContent.querySelector('.close-btn')?.focus();
                const bookingId = new URLSearchParams(location.search).get('booking_id');
                if (/^\d+$/.test(bookingId || '')) {
                    const bookingLink = modalContent.querySelector('[data-booking-id="' + bookingId + '"]');
                    bookingLink?.focus({ preventScroll: true });
                    bookingLink?.scrollIntoView({ block: 'nearest' });
                }
                
                // Add event listener to close button
                const closeBtn = modalContent.querySelector('.close-btn');
                if (closeBtn) {
                    closeBtn.addEventListener('click', closeStoreModal);
                }
                
                // Add event listener to modal footer close button
                const modalFooterBtn = modalContent.querySelector('.modal-footer .btn-secondary');
                if (modalFooterBtn) {
                    modalFooterBtn.addEventListener('click', closeStoreModal);
                }
            })
            .catch(error => {
                if (error.name === 'AbortError') return;
                console.error('Error loading orders:', error);
                modalContent.removeAttribute('aria-busy');
                modalContent.innerHTML = `
                    <div class="modal-header" style="position: relative; padding-right: 50px;">
                        <h2>Error</h2>
                        <button type="button" class="close-btn" aria-label="Close orders" onclick="closeStoreModal()" style="position: absolute; top: 10px; right: 15px; z-index: 9999; width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.1); color: white; border: none; font-size: 24px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; pointer-events: auto !important;">&times;</button>
                    </div>
                    <div class="modal-body" style="text-align: center; padding: 40px;">
                        <i class="fas fa-exclamation-circle fa-3x" style="color: var(--danger); margin-bottom: 20px;"></i>
                        <p style="color: var(--danger); font-size: 16px;">Error loading orders. Please try again.</p>
                    </div>
                `;
            });
    }
    
    // Make closeStoreModal globally accessible so inline onclick in AJAX-loaded HTML can reach it
    window.closeStoreModal = function closeStoreModal() {
        const storeModal = document.getElementById('caterer-modal');
        const modalOverlay = document.getElementById('storeModalOverlay');
        if (!storeModal || storeModal.style.display === 'none') return;
        if (ordersRequest) ordersRequest.abort();
        document.body.style.overflow = ordersPreviousOverflow;
        if (ordersPreviousFocus?.isConnected) ordersPreviousFocus.focus();
        storeModal.style.display = 'none';
        if (modalOverlay) modalOverlay.style.display = 'none';
        const returnUrl = new URL(location.href);
        ['booking_date', 'booking_page', 'booking_id'].forEach(key => returnUrl.searchParams.delete(key));
        history.replaceState(history.state, '', returnUrl);
    }
    
    // Refresh the restored day when browser Back restores a cached dashboard.
    window.addEventListener('pageshow', function(event) {
        if (!event.persisted) return;
        const params = new URLSearchParams(location.search);
        const date = params.get('booking_date');
        if (/^\d{4}-\d{2}-\d{2}$/.test(date || '')) {
            loadOrdersForDate(date, Math.max(1, Number(params.get('booking_page')) || 1));
        }
    });

    // Initialize dashboard on page load
    document.addEventListener('DOMContentLoaded', function() {
        if (!<?php echo $is_store_closed_by_portal ? 'true' : 'false'; ?>) {
            initDashboard();
        }

        // Event delegation: catch .close-btn clicks anywhere inside the modal
        const catererModal = document.getElementById('caterer-modal');
        if (catererModal) {
            let swipeStart = null;
            catererModal.addEventListener('touchstart', function (event) {
                const body = event.target.closest('.modal-body');
                swipeStart = body && event.touches.length === 1
                    ? { x: event.touches[0].clientX, y: event.touches[0].clientY } : null;
            }, { passive: true });
            catererModal.addEventListener('touchend', function (event) {
                if (!swipeStart || !event.changedTouches.length) return;
                const dx = event.changedTouches[0].clientX - swipeStart.x;
                const dy = event.changedTouches[0].clientY - swipeStart.y;
                swipeStart = null;
                if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
                if (document.getElementById('storeModalContent').getAttribute('aria-busy') === 'true') return;
                catererModal.querySelector(`button[aria-label="${dx < 0 ? 'Next booking' : 'Previous booking'}"]:not(:disabled)`)?.click();
            }, { passive: true });
            catererModal.addEventListener('touchcancel', function () { swipeStart = null; }, { passive: true });
            catererModal.addEventListener('click', function(e) {
                const bookingLink = e.target.closest('a[data-booking-id]');
                if (bookingLink) {
                    const returnUrl = new URL(location.href);
                    returnUrl.searchParams.set('booking_id', bookingLink.dataset.bookingId);
                    history.replaceState(history.state, '', returnUrl);
                }
                const pageButton = e.target.closest('button[data-orders-page]');
                if (pageButton && !pageButton.disabled) {
                    loadOrdersForDate(pageButton.dataset.ordersDate, Number(pageButton.dataset.ordersPage));
                    return;
                }
                if (e.target.closest('.close-btn')) {
                    window.closeStoreModal();
                }
            });
        }
        
        // Close modal with ESC key
        document.addEventListener('keydown', function(e) {
            const dialog = document.getElementById('caterer-modal');
            if (e.key === 'Tab' && dialog?.style.display === 'flex') {
                const items = Array.from(dialog.querySelectorAll('button, a[href], [tabindex="0"]'));
                const first = items[0], last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); }
            }
            if (e.key === 'Escape') {
                window.closeStoreModal();
            }
        });
        
        // Clicking outside modal to close is disabled as requested by the user.

        document.querySelectorAll('.js-auto-hide-alert').forEach(function(alertBox) {
            window.setTimeout(function() {
                alertBox.style.transition = 'opacity 0.35s ease, transform 0.35s ease, margin 0.35s ease';
                alertBox.style.opacity = '0';
                alertBox.style.transform = 'translateY(-6px)';

                window.setTimeout(function() {
                    if (alertBox && alertBox.parentNode) {
                        alertBox.parentNode.removeChild(alertBox);
                    }
                }, 350);
            }, 3000);
        });
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
