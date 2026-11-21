<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/financial_helper.php';
require_once __DIR__ . '/../includes/booking_flow_helper.php';
require_once __DIR__ . '/../includes/order_reference_helper.php';
require_once __DIR__ . '/../includes/reschedule_helper.php';
require_once __DIR__ . '/../includes/payment_functions.php';
requireLogin();

if (getUserRole() !== 'customer') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$csrf_token = generateCSRFToken();

// Get booking ID from URL
$booking_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($booking_id <= 0) {
    header('Location: booking.php');
    exit();
}

// Fetch complete booking details
$details_query = "
    SELECT 
        o.*,
        u.business_name as store_name,
        u.is_open as store_is_open,
        u.business_open_time as store_open_time,
        u.business_close_time as store_close_time,
        u.auto_schedule_enabled as store_auto_schedule_enabled,
        p.name as product_name,
        p.description as product_description,
        p.category,
        c.first_name,
        c.last_name,
        c.email,
        c.phone_number as customer_phone,
        c.address as customer_address,
        (SELECT id FROM reviews r WHERE r.order_id = o.id LIMIT 1) as review_id
    FROM bookings o
    LEFT JOIN (
        SELECT u.id, sd.business_name, sd.is_open, sd.business_open_time, sd.business_close_time, sd.auto_schedule_enabled
        FROM users u 
        LEFT JOIN store_details sd ON u.id = sd.user_id 
        WHERE u.role IN ('caterer','store')
    ) u ON o.store_id = u.id
    LEFT JOIN products p ON o.product_id = p.id
    LEFT JOIN users c ON o.customer_id = c.id
    WHERE o.id = '$booking_id' AND o.customer_id = '$user_id'
";

$details_result = mysqli_query($conn, $details_query);

if (!$details_result || mysqli_num_rows($details_result) === 0) {
    header('Location: booking.php');
    exit();
}

$booking = mysqli_fetch_assoc($details_result);
$reschedule_already_used = !empty($booking['reschedule_requested_at']) || in_array(strtolower((string) ($booking['reschedule_status'] ?? '')), ['pending', 'approved', 'rejected'], true);
$schedule_store = (int) $booking['store_id'];
$schedule_result = mysqli_query($conn, "SELECT business_open_time, business_close_time, booking_gap_days FROM store_details WHERE user_id = '$schedule_store'");
$schedule_settings = $schedule_result ? (mysqli_fetch_assoc($schedule_result) ?: []) : [];
$reschedule_min_date = date('Y-m-d', strtotime('+' . max(1, (int) ($schedule_settings['booking_gap_days'] ?? 0)) . ' days'));
$reschedule_times = [];
$open_minutes = !empty($schedule_settings['business_open_time']) ? (int) substr($schedule_settings['business_open_time'], 0, 2) * 60 + (int) substr($schedule_settings['business_open_time'], 3, 2) : 0;
$close_minutes = !empty($schedule_settings['business_close_time']) ? (int) substr($schedule_settings['business_close_time'], 0, 2) * 60 + (int) substr($schedule_settings['business_close_time'], 3, 2) : 1439;
if ($close_minutes <= $open_minutes) $close_minutes += 1440;
for ($minute = $open_minutes; $minute <= $close_minutes && $minute < $open_minutes + 1440; $minute += 30) {
    $reschedule_times[] = sprintf('%02d:%02d', intdiv($minute % 1440, 60), $minute % 60);
}
$booking_created_at = !empty($booking['created_at']) ? strtotime($booking['created_at']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reschedule_request'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        header('Location: booking-details.php?id=' . $booking_id . '&reschedule=invalid_token');
        exit();
    }

    $normalized_status = normalizeOrderWorkflowStatus($booking['order_status'] ?? '');
    $event_timestamp = strtotime(($booking['order_date'] ?? '') . ' ' . ($booking['order_time'] ?? '00:00:00'));
    $days_until_event = (int) floor(($event_timestamp - time()) / 86400);

    if (in_array($normalized_status, ['completed', 'cancelled'], true) || $days_until_event < 2) {
        header('Location: booking-details.php?id=' . $booking_id . '&reschedule=not_allowed');
        exit();
    }

    if (($booking['reschedule_status'] ?? '') === 'pending') {
        header('Location: booking-details.php?id=' . $booking_id . '&reschedule=already_pending');
        exit();
    }

    if ($reschedule_already_used) {
        header('Location: booking-details.php?id=' . $booking_id . '&reschedule=already_used');
        exit();
    }
    $requested_date = trim($_POST['requested_order_date'] ?? '');
    $requested_time = trim($_POST['requested_order_time'] ?? '');
    $reschedule_reason = trim($_POST['reschedule_reason'] ?? '');

    if ($requested_date === '' || $requested_time === '' || $reschedule_reason === '') {
        header('Location: booking-details.php?id=' . $booking_id . '&reschedule=missing_fields');
        exit();
    }

    $date_availability = checkStoreAvailability($schedule_store, $requested_date);
    if (strtotime($requested_date . ' ' . $requested_time) <= time()
        || $requested_date < $reschedule_min_date
        || !in_array(substr($requested_time, 0, 5), $reschedule_times, true)
        || empty($date_availability['is_available'])) {
        header('Location: booking-details.php?id=' . $booking_id . '&reschedule=invalid_schedule');
        exit();
    }

    $result = submitRescheduleRequest($conn, [
        'order_id' => $booking_id,
        'customer_id' => $user_id,
        'store_id' => intval($booking['store_id']),
        'order_number' => $booking['order_number'] ?? '',
        'current_date' => date('F d, Y', strtotime($booking['order_date'])),
        'current_time' => !empty($booking['order_time']) ? date('h:i A', strtotime($booking['order_time'])) : 'N/A',
        'requested_date' => $requested_date,
        'requested_time' => $requested_time,
        'reason' => $reschedule_reason
    ]);

    header('Location: booking-details.php?id=' . $booking_id . ($result['success'] ? '&reschedule=sent' : '&reschedule=failed'));
    exit();
}

function parseCustomerMenuChangeRequestDetails($message_text) {
    $details = [
        'current_set' => '',
        'requested_set' => '',
        'current_total' => 0,
        'new_total' => 0,
        'already_paid' => 0,
        'difference' => 0,
        'additional_needed' => 0
    ];

    $patterns = [
        'current_set' => '/Current Set:\s*(.+)/i',
        'requested_set' => '/Requested Set:\s*(.+)/i',
        'current_total' => '/Current Set Total:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'new_total' => '/New Set Total:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'already_paid' => '/Already Paid:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'difference' => '/Price Difference:\s*([+-]?)PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'additional_needed' => '/Additional Amount Needed:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i'
    ];

    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $message_text, $matches)) {
            if (in_array($key, ['current_total', 'new_total', 'already_paid', 'additional_needed'], true)) {
                $details[$key] = floatval(str_replace(',', '', $matches[1]));
            } elseif ($key === 'difference') {
                $amount = floatval(str_replace(',', '', $matches[2]));
                $details[$key] = (($matches[1] ?? '') === '-') ? -$amount : $amount;
            } else {
                $details[$key] = trim($matches[1]);
            }
        }
    }

    return $details;
}

// ==========================================
// PARSE MENU DETAILS
// ==========================================
// Fetch Product Set Details for comparison
$sets_lookup = [];
$available_sets = [];
$sets_query = "
    SELECT ps.* 
    FROM product_sets ps
    WHERE ps.product_id = '{$booking['product_id']}'
    ORDER BY ps.price ASC, ps.id ASC
";
$sets_result = mysqli_query($conn, $sets_query);
while ($set = mysqli_fetch_assoc($sets_result)) {
    $name = trim((string) ($set['set_name'] ?? ''));
    if ($name === '') {
        continue;
    }
    $sets_lookup[$name] = $set;
    $available_sets[] = $set;
}

// Parse Additional Menu
$raw_menu = $booking['additional_menu'] ?? '';
$parts = explode(' | ', $raw_menu);

$setName = '';
$swaps = [];
$addons = [];
$customRequest = '';

if (!empty($parts)) {
    $setName = trim($parts[0]);
    
    foreach ($parts as $i => $part) {
        if ($i === 0) continue; 
        
        if (strpos($part, 'Added Items:') !== false) {
            $itemsStr = str_replace('Added Items:', '', $part);
            $addons = [];
            if (preg_match_all('/(?:^|,\s*)(.+?)\s*(\(\+?\s*[\d,.]+\))/', $itemsStr, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $addons[] = trim($m[1]) . " " . $m[2];
                }
            }
        } elseif (strpos($part, 'Menu Swaps:') !== false) {
            $itemsStr = str_replace('Menu Swaps:', '', $part);
            $swaps = [];
            // Robust parsing for Swaps
            if (preg_match_all('/(?:^|,\s*)(.+?)\s*(\(\+?\s*[\d,.]+\))/', $itemsStr, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $swaps[] = trim($m[1]) . " " . $m[2];
                }
            } else {
                $swaps = array_map('trim', explode(',', $itemsStr));
            }
        } elseif (strpos($part, 'Custom Add-on Request:') !== false) {
            $customRequest = trim(str_replace('Custom Add-on Request:', '', $part));
        }
    }
}

if ($setName === '' && !empty($available_sets[0]['set_name'])) {
    $setName = trim((string) $available_sets[0]['set_name']);
}

// Get Original Items
$originalItems = [];
$originalMenuGroups = [];
$menuLookupName = $setName;
if ((!isset($sets_lookup[$menuLookupName]) || $menuLookupName === '') && !empty($booking['package_name'])) {
    $menuLookupName = trim((string) $booking['package_name']);
}
if ($menuLookupName !== '' && !isset($sets_lookup[$menuLookupName]) && !empty($booking['package_id'])) {
    foreach ($available_sets as $available_set) {
        if ((int) ($available_set['id'] ?? 0) === (int) $booking['package_id']) {
            $menuLookupName = trim((string) ($available_set['set_name'] ?? ''));
            break;
        }
    }
}
if ($menuLookupName !== '' && isset($sets_lookup[$menuLookupName])) {
    $parsedMenu = bookingPricingParseSetDescription($sets_lookup[$menuLookupName]['description'] ?? '');
    $originalItems = $parsedMenu['items'];
    $originalMenuGroups = $parsedMenu['groups'];
}
// ==========================================

// Get Financials (Centralized)
$fin = getOrderFinancials($booking_id);

// Fix: Correctly access array keys from getOrderFinancials
if ($fin) {
    $grand_total = $fin['totals']['grand_total'];
    $total_paid = $fin['totals']['total_paid'];
    $remaining = $fin['totals']['balance'];
    $package_total = $fin['breakdown']['package_total'];
    $down_payment_required = $fin['down_payment_required'] ?? 0;
    $progress_percentage = ($grand_total > 0) ? min(100, ($total_paid / $grand_total) * 100) : 0;
    
    // Ensure damages key exists
    $damages_total = $fin['breakdown']['damages'] ?? 0;
} else {
    // Fallback if financial helper fails (should not happen)
    $grand_total = 0;
    $total_paid = 0;
    $remaining = 0;
    $package_total = 0;
    $down_payment_required = 0;
    $progress_percentage = 0;
    $damages_total = 0;
}

$down_payment_credited = min($total_paid, $down_payment_required);
$down_payment_settled = $down_payment_required > 0
    && $down_payment_credited >= $down_payment_required;

// Get order timeline (Manual Implementation since payment_functions.php is removed)
$timeline = [
    'steps' => [
        'pending' => ['status' => 'current', 'label' => 'Pending', 'icon' => 'fa-hourglass-half', 'description' => 'Payment and booking review are still pending'],
        'confirmed' => ['status' => '', 'label' => 'Confirmed', 'icon' => 'fa-check', 'description' => 'Caterer accepted your booking'],
        'preparing' => ['status' => '', 'label' => 'Preparing', 'icon' => 'fa-utensils', 'description' => 'Your order is being prepared'],
        'delivering' => ['status' => '', 'label' => 'Ongoing', 'icon' => 'fa-calendar-day', 'description' => 'Your event is ongoing or the team is already on-site'],
        'completed' => ['status' => '', 'label' => 'Completed', 'icon' => 'fa-check-circle', 'description' => 'Booking finished successfully']
    ]
];

$status_order = ['pending', 'confirmed', 'preparing', 'delivering', 'completed'];
$current_status = strtolower(trim($booking['order_status']));

if ($current_status === 'approved') {
    $current_status = 'confirmed';
} elseif ($current_status === 'ready') {
    $current_status = 'delivering';
}

$found = false;
foreach ($timeline['steps'] as $key => &$step) {
    if ($current_status === 'cancelled') {
        $step['status'] = '';
        continue;
    }

    if ($key == $current_status) {
        $step['status'] = 'current';
        $found = true;
    } elseif (!$found) {
        $step['status'] = 'completed';
    } else {
        $step['status'] = '';
    }
}
unset($step);

$next_action_title = 'Next Action';
$next_action_text = 'Monitor your booking updates and follow any caterer instructions if needed.';

switch ($current_status) {
    case 'pending':
        $next_action_text = 'Wait for the caterer to review and confirm your booking request.';
        break;
    case 'confirmed':
        $next_action_text = $remaining > 0
            ? 'Prepare your remaining balance and monitor updates from the caterer.'
            : 'Your booking is confirmed. Just monitor caterer updates and prepare for your event.';
        break;
    case 'preparing':
        $next_action_text = 'Your order is being prepared. Keep your phone available in case the caterer contacts you.';
        break;
    case 'delivering':
        $next_action_title = 'Event Ongoing';
        $next_action_text = 'Your catering event is ongoing. Stay available in case the caterer needs coordination during setup or service.';
        break;
    case 'completed':
        $next_action_title = 'Booking Completed';
        $next_action_text = 'Your booking is complete. You may review the caterer or book again when needed.';
        break;
    case 'cancelled':
        $next_action_title = 'Booking Closed';
        $next_action_text = 'This booking has been cancelled. You may browse caterers again if you want to make a new booking.';
        break;
}

// Get activity log (Manual Query)
$activity_log = [];
$menu_change_history = [];
$reschedule_history = [];
$log_query = "SELECT ol.*, u.first_name, u.last_name 
              FROM booking_logs ol 
              LEFT JOIN users u ON ol.user_id = u.id
              WHERE ol.order_id = '$booking_id' 
              ORDER BY ol.created_at DESC";
$log_res = mysqli_query($conn, $log_query);
if ($log_res) {
    while ($row = mysqli_fetch_assoc($log_res)) {
        // Map icon based on action
        $icon = 'fa-info';
        if (strpos($row['action'], 'payment') !== false) $icon = 'fa-wallet';
        if (strpos($row['action'], 'status') !== false) $icon = 'fa-exchange-alt';
        
        $activity_log[] = [
            'icon' => $icon,
            'description' => $row['action'],
            'details' => $row['details'],
            'created_at' => $row['created_at']
        ];

        if (in_array($row['action'], ['Menu Change Requested', 'Menu Change Approved', 'Menu Change Rejected'], true)) {
            $menu_change_history[] = [
                'action' => $row['action'],
                'details' => $row['details'],
                'created_at' => $row['created_at']
            ];
        }

        if (in_array($row['action'], ['Reschedule Requested', 'Reschedule Approved', 'Reschedule Rejected'], true)) {
            $reschedule_history[] = [
                'action' => $row['action'],
                'details' => $row['details'],
                'created_at' => $row['created_at']
            ];
        }
    }
}

$event_timestamp = strtotime(($booking['order_date'] ?? '') . ' ' . ($booking['order_time'] ?? '00:00:00'));
$days_diff = (int) floor(($event_timestamp - time()) / 86400);
$reschedule_status = strtolower(trim((string) ($booking['reschedule_status'] ?? '')));
$can_request_reschedule = !$reschedule_already_used && !in_array($current_status, ['completed', 'cancelled'], true) && $days_diff >= 2;
$reschedule_status_text = 'No active request';
$reschedule_status_class = 'status-pending';

if ($reschedule_status === 'pending') {
    $reschedule_status_text = 'Waiting for Caterer Review';
} elseif ($reschedule_status === 'approved') {
    $reschedule_status_text = 'Approved by Caterer';
    $reschedule_status_class = 'status-completed';
} elseif ($reschedule_status === 'rejected') {
    $reschedule_status_text = 'Rejected by Caterer';
    $reschedule_status_class = 'status-cancelled';
}

$menu_change_request = null;
$menu_change_status = null;
$menu_change_status_text = '';
$menu_change_reject_reason = '';
$menu_request_q = mysqli_query($conn, "
    SELECT *
    FROM messages
    WHERE order_id = '$booking_id'
      AND sender_id = '$user_id'
      AND (subject LIKE '%Menu Change Request%' OR message LIKE '%Requested Set:%')
    ORDER BY created_at DESC, id DESC
    LIMIT 1
");
if ($menu_request_q && $menu_request_row = mysqli_fetch_assoc($menu_request_q)) {
    $menu_change_request = parseCustomerMenuChangeRequestDetails($menu_request_row['message'] ?? '');
    if (!empty($menu_change_request['requested_set'])) {
        $approved_q = mysqli_query($conn, "
            SELECT id FROM booking_logs
            WHERE order_id = '$booking_id'
              AND action = 'Menu Change Approved'
              AND created_at >= '{$menu_request_row['created_at']}'
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $rejected_q = mysqli_query($conn, "
            SELECT id FROM booking_logs
            WHERE order_id = '$booking_id'
              AND action = 'Menu Change Rejected'
              AND created_at >= '{$menu_request_row['created_at']}'
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");

        if ($approved_q && mysqli_num_rows($approved_q) > 0) {
            $menu_change_status = 'approved';
            $menu_change_status_text = 'Approved by Caterer';
        } elseif ($rejected_q && mysqli_num_rows($rejected_q) > 0) {
            $menu_change_status = 'rejected';
            $menu_change_status_text = 'Rejected by Caterer';
            $reject_log_q = mysqli_query($conn, "
                SELECT details FROM booking_logs
                WHERE order_id = '$booking_id'
                  AND action = 'Menu Change Rejected'
                  AND created_at >= '{$menu_request_row['created_at']}'
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            if ($reject_log_q && $reject_log = mysqli_fetch_assoc($reject_log_q)) {
                if (preg_match('/Reason:\s*(.+)$/i', $reject_log['details'] ?? '', $matches)) {
                    $menu_change_reject_reason = trim($matches[1]);
                }
            }
        } else {
            $menu_change_status = 'pending';
            $menu_change_status_text = 'Waiting for Caterer Review';
        }
    } else {
        $menu_change_request = null;
    }
}

// Fetch Damages (Still needed for specific display logic if separate)
$damages_query = "SELECT * FROM booking_damages WHERE order_id = '$booking_id'";
$damages_result = mysqli_query($conn, $damages_query);
$damages = [];
$total_damage_fee = 0; // Recalculated but should match fin['breakdown']['damages']
while ($row = mysqli_fetch_assoc($damages_result)) {
    $damages[] = $row;
    $total_damage_fee += $row['total'];
}
// Note: $total_damage_fee should be same as $fin['breakdown']['damages']

$page_title = 'Booking Details - Booking #' . htmlspecialchars(formatOrderReference($booking['id']));
require_once __DIR__ . '/includes/header.php';
?>

<style>
    :root {
        --accent-gold: #d6b35a;
        --card-bg: #1f2328;
        --border-color: rgba(255, 255, 255, 0.1);
        --text-main: #f5f1e8;
        --text-muted: #c1b8aa;
        --secondary-bg: #23272d;
        --success-color: #4caf50;
        --danger-color: #f44336;
        --warning-text: #ffc107;
    }

    .booking-details-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 30px;
    }
    
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--accent-gold);
        text-decoration: none;
        margin-bottom: 25px;
        font-size: 15px;
        transition: color 0.2s;
    }
    
    .back-link:hover {
        color: #fff;
        text-decoration: underline;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        flex-wrap: wrap;
        gap: 20px;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 20px;
    }
    
    .page-header h1 {
        color: var(--accent-gold);
        font-size: 28px;
        margin: 0;
    }
    
    .order-number {
        color: var(--text-muted);
        font-size: 14px;
        margin-top: 5px;
        font-family: monospace;
    }
    
    .details-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 30px;
    }

    .left-column,
    .right-column {
        display: flex;
        flex-direction: column;
    }

    .sidebar-actions { order: 1; }
    .sidebar-financial { order: 2; }
    .sidebar-policy { order: 3; }

    .overview-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .overview-card {
        background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.015));
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 18px;
    }

    .overview-label {
        color: var(--text-muted);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-bottom: 8px;
    }

    .overview-value {
        color: var(--text-main);
        font-size: 16px;
        font-weight: 700;
        line-height: 1.5;
    }

    .overview-value.accent {
        color: var(--accent-gold);
    }

    .overview-help {
        margin-top: 6px;
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.5;
    }
    
    @media (max-width: 992px) {
        .overview-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .details-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .overview-grid {
            grid-template-columns: 1fr;
        }
    }
    
    .detail-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    
    .detail-card h3 {
        color: var(--accent-gold);
        font-size: 18px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 10px;
    }
    
    .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    
    .detail-row:last-child {
        border-bottom: none;
    }
    
    .detail-label {
        color: var(--text-muted);
        font-size: 14px;
    }
    
    .detail-value {
        color: var(--text-main);
        font-weight: 500;
        text-align: right;
    }
    
    /* Timeline */
    .order-timeline {
        display: flex;
        justify-content: space-between;
        margin: 30px 0;
        position: relative;
    }
    
    .order-timeline::before {
        content: '';
        position: absolute;
        top: 20px;
        left: 5%;
        right: 5%;
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
    
    .step-icon {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: var(--secondary-bg);
        border: 2px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        font-size: 16px;
        margin-bottom: 10px;
        transition: all 0.3s ease;
    }
    
    .timeline-step.completed .step-icon {
        background: var(--success-color);
        border-color: var(--success-color);
        color: white;
    }
    
    .timeline-step.current .step-icon {
        background: var(--accent-gold);
        border-color: var(--accent-gold);
        color: #000;
        box-shadow: 0 0 15px rgba(212, 175, 55, 0.4);
    }
    
    .step-label {
        font-size: 12px;
        color: var(--text-muted);
        text-align: center;
        font-weight: 500;
    }

    .step-description {
        margin-top: 4px;
        font-size: 11px;
        color: var(--text-muted);
        text-align: center;
        line-height: 1.4;
        max-width: 110px;
    }
    
    .timeline-step.completed .step-label,
    .timeline-step.current .step-label {
        color: var(--text-main);
        font-weight: bold;
    }

    @media (max-width: 600px) {
        .order-timeline {
            flex-direction: column;
            gap: 16px;
            margin: 20px 0;
        }

        .order-timeline::before {
            top: 0;
            bottom: 0;
            left: 20px;
            right: auto;
            width: 2px;
            height: auto;
        }

        .timeline-step {
            flex-direction: row;
            align-items: flex-start;
            gap: 12px;
            text-align: left;
        }

        .step-icon {
            width: 40px;
            height: 40px;
            margin-bottom: 0;
            flex-shrink: 0;
        }

        .step-label {
            text-align: left;
            font-size: 13px;
        }

        .step-description {
            max-width: none;
            text-align: left;
            font-size: 12px;
            line-height: 1.5;
        }
    }
    
    /* Payment Summary */
    .payment-summary {
        background: linear-gradient(145deg, rgba(30,30,30,1) 0%, rgba(20,20,20,1) 100%);
        border: 1px solid var(--accent-gold);
    }

    .balance-reminder {
        margin: 20px 0 0;
        padding: 16px;
        border-radius: 12px;
        border: 1px solid rgba(212, 175, 55, 0.18);
        background: rgba(212, 175, 55, 0.08);
        text-align: left;
    }

    .balance-reminder strong {
        display: block;
        margin-bottom: 6px;
        color: var(--accent-gold);
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .balance-reminder .amount {
        font-size: 28px;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 8px;
    }

    .balance-reminder .hint {
        font-size: 13px;
        color: var(--text-muted);
        line-height: 1.6;
    }

    .balance-reminder.paid {
        border-color: rgba(76, 175, 80, 0.2);
        background: rgba(76, 175, 80, 0.08);
    }

    .balance-reminder.paid strong {
        color: var(--success-color);
    }

    .next-action-card {
        margin: 18px 0 0;
        padding: 16px;
        border-radius: 12px;
        border: 1px solid rgba(59, 130, 246, 0.22);
        background: rgba(59, 130, 246, 0.08);
        text-align: left;
    }

    .next-action-card strong {
        display: block;
        margin-bottom: 6px;
        color: #93c5fd;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .next-action-card .hint {
        font-size: 13px;
        color: var(--text-main);
        line-height: 1.6;
    }
    
    .payment-row.total {
        font-size: 20px;
        font-weight: bold;
        color: var(--accent-gold);
        padding-top: 20px;
        margin-top: 15px;
        border-top: 1px dashed var(--accent-gold);
    }
    
    .progress-bar {
        height: 10px;
        background: rgba(255,255,255,0.1);
        border-radius: 5px;
        overflow: hidden;
        margin: 15px 0;
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--accent-gold), #ffd700);
        border-radius: 5px;
        transition: width 0.5s ease;
    }
    
    /* Activity Log */
    .activity-log {
        max-height: 350px;
        overflow-y: auto;
        padding-right: 10px;
    }
    
    .activity-item {
        display: flex;
        gap: 15px;
        padding: 15px 0;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    
    .activity-item:last-child {
        border-bottom: none;
    }
    
    .activity-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: rgba(255,255,255,0.05);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--accent-gold);
        flex-shrink: 0;
        font-size: 14px;
    }
    
    .activity-content {
        flex: 1;
    }
    
    .activity-title {
        color: var(--text-main);
        font-weight: 500;
        margin-bottom: 5px;
        font-size: 14px;
    }
    
    .activity-time {
        color: var(--text-muted);
        font-size: 11px;
    }
    
    /* Action Buttons */
    .action-buttons {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    
    .btn {
        padding: 15px 20px;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: all 0.3s ease;
        border: none;
        width: 100%;
    }
    
    .btn-primary {
        background: var(--accent-gold);
        color: #000;
        box-shadow: 0 4px 15px rgba(212, 175, 55, 0.2);
    }
    
    .btn-primary:hover {
        background: #e6c260;
        transform: translateY(-2px);
    }
    
    .btn-secondary {
        background: transparent;
        color: var(--text-muted);
        border: 1px solid var(--border-color);
    }
    
    .btn-secondary:hover {
        border-color: var(--text-main);
        color: var(--text-main);
        background: rgba(255,255,255,0.05);
    }
    
    /* Status Badge */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .status-pending {
        background: rgba(255, 193, 7, 0.15);
        color: #ffc107;
        border: 1px solid rgba(255, 193, 7, 0.3);
    }
    
    .status-confirmed {
        background: rgba(33, 150, 243, 0.15);
        color: #2196f3;
        border: 1px solid rgba(33, 150, 243, 0.3);
    }
    
    .status-completed {
        background: rgba(76, 175, 80, 0.15);
        color: #4caf50;
        border: 1px solid rgba(76, 175, 80, 0.3);
    }
    
    .status-cancelled {
        background: rgba(244, 67, 54, 0.15);
        color: #f44336;
        border: 1px solid rgba(244, 67, 54, 0.3);
    }

    /* Menu List Styling */
    .menu-list-item {
        padding: 12px 15px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: background 0.2s;
    }
    
    .menu-list-item:last-child {
        border-bottom: none;
    }
    
    .menu-list-item:hover {
        background: rgba(255,255,255,0.02);
    }
    
    .menu-icon {
        margin-right: 10px;
        width: 20px;
        text-align: center;
    }

    /* Scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
    }
    ::-webkit-scrollbar-track {
        background: #1b1f24; 
    }
    ::-webkit-scrollbar-thumb {
        background: #333; 
        border-radius: 4px;
    }
    ::-webkit-scrollbar-thumb:hover {
        background: #555; 
    }
</style>

<link rel="stylesheet" href="../assets/css/customer-booking-details-theme.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/customer-booking-details-theme.css'); ?>">
<div class="booking-details-container">

    
    <?php if (isset($_GET['reschedule'])): ?>
        <?php
            $reschedule_notice = '';
            $reschedule_notice_style = 'background: rgba(212, 175, 55, 0.08); border: 1px solid rgba(212, 175, 55, 0.25); color: var(--accent-gold);';
            switch ($_GET['reschedule']) {
                case 'sent':
                    $reschedule_notice = 'Your reschedule request was sent to the caterer for review.';
                    $reschedule_notice_style = 'background: rgba(46, 204, 113, 0.08); border: 1px solid rgba(46, 204, 113, 0.25); color: #8ff0b0;';
                    break;
                case 'already_pending':
                    $reschedule_notice = 'You already have a pending reschedule request for this booking.';
                    break;
                case 'already_used':
                    $reschedule_notice = 'Only one reschedule request is allowed per booking.';
                    break;
                case 'not_allowed':
                    $reschedule_notice = 'Reschedule requests are not allowed for this booking right now.';
                    $reschedule_notice_style = 'background: rgba(231, 76, 60, 0.08); border: 1px solid rgba(231, 76, 60, 0.25); color: #ff9b9b;';
                    break;
                case 'missing_fields':
                    $reschedule_notice = 'Please complete the new date, new time, and reason before submitting.';
                    $reschedule_notice_style = 'background: rgba(231, 76, 60, 0.08); border: 1px solid rgba(231, 76, 60, 0.25); color: #ff9b9b;';
                    break;
                case 'invalid_schedule':
                    $reschedule_notice = 'Please choose a valid future schedule for your reschedule request.';
                    $reschedule_notice_style = 'background: rgba(231, 76, 60, 0.08); border: 1px solid rgba(231, 76, 60, 0.25); color: #ff9b9b;';
                    break;
                case 'failed':
                case 'invalid_token':
                    $reschedule_notice = 'Unable to send the reschedule request right now. Please try again.';
                    $reschedule_notice_style = 'background: rgba(231, 76, 60, 0.08); border: 1px solid rgba(231, 76, 60, 0.25); color: #ff9b9b;';
                    break;
            }
        ?>
        <?php if ($reschedule_notice !== ''): ?>
            <dialog id="rescheduleResultModal" aria-labelledby="rescheduleResultTitle" style="width:min(380px,calc(100vw - 32px));max-height:calc(100dvh - 32px);box-sizing:border-box;overflow-y:auto;padding:24px;border:1px solid #96a38e;border-radius:12px;background:#d4dbcd;color:#20372b;text-align:center;">
                <h3 id="rescheduleResultTitle" style="margin:0 0 12px;font-size:18px;"><?php echo $_GET['reschedule'] === 'sent' ? 'Request submitted' : 'Reschedule request'; ?></h3>
                <p style="margin:0 0 20px;font-size:14px;line-height:1.6;overflow-wrap:anywhere;"><?php echo htmlspecialchars($reschedule_notice); ?></p>
                <button type="button" class="btn btn-primary" style="min-width:96px;min-height:44px;" onclick="document.getElementById('rescheduleResultModal').close()">OK</button>
            </dialog>
            <style>
            html body #rescheduleResultModal {
                position: fixed !important;
                inset: 0 !important;
                margin: auto !important;
                width: min(380px, calc(100vw - 32px)) !important;
                height: fit-content !important;
                max-height: calc(100dvh - 32px) !important;
                padding: 28px 24px !important;
                box-sizing: border-box;
                overflow-y: auto;
                text-align: center;
            }
            #rescheduleResultModal h3 { line-height: 1.4; }
            #rescheduleResultModal .btn { width: auto !important; min-width: 112px; margin: 0 auto; justify-content: center; }
            #rescheduleResultModal::backdrop{background:rgb(12 32 25 / 50%);backdrop-filter:blur(4px);}
            @media(max-width:480px){html body #rescheduleResultModal{padding:24px 20px!important;}}
            </style>
            <script>
            window.addEventListener('load', function() {
                document.getElementById('rescheduleResultModal').showModal();
                const url = new URL(location.href);
                url.searchParams.delete('reschedule');
                history.replaceState(history.state, '', url);
            });
            </script>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Order Timeline -->
        <!-- Booking actions -->
        <div class="booking-top-actions">
    <a href="booking.php" class="back-link" aria-label="Back to My Bookings" title="Back to My Bookings">
        <i class="fas fa-arrow-left" aria-hidden="true"></i>
    </a>
            
            <?php
                // Modification Policy Logic
                $event_timestamp = strtotime($booking['order_date']);
                $current_timestamp = time();
                $days_diff = ceil(($event_timestamp - $current_timestamp) / (60 * 60 * 24));
                $is_full_menu_change_allowed = $days_diff > 5;
                $is_add_only_window = $days_diff > 0 && $days_diff <= 5;
            ?>
            <!-- Actions Card -->
            <div class="detail-card sidebar-actions">
                <div class="action-buttons">
                    <a href="messages.php?order_id=<?php echo $booking_id; ?>" class="btn btn-primary" title="Message Caterer" aria-label="Message Caterer">
                        <i class="fas fa-comments" aria-hidden="true"></i>
                    <span class="booking-action-label">Message Caterer</span></a>

                    
                    <?php if ($is_full_menu_change_allowed): ?>
                        <?php if ($menu_change_status === 'pending'): ?>
                            <button type="button" class="btn btn-secondary" style="border-color: var(--accent-gold); color: var(--accent-gold); opacity: 0.6; cursor: not-allowed;" disabled title="Request Pending" aria-label="Request Pending">
                                <i class="fas fa-hourglass-half" aria-hidden="true"></i>
                            <span class="booking-action-label">Request Pending</span></button>
                        <?php else: ?>
                        <?php endif; ?>
                    <?php elseif ($is_add_only_window): ?>
                        <a href="book-service.php?product_id=<?php echo intval($booking['product_id']); ?>&store_id=<?php echo intval($booking['store_id']); ?>&set=<?php echo urlencode($setName); ?>&amp;cancel_from=booking_addons&amp;source_booking_id=<?php echo (int) $booking_id; ?>&return_url=<?php echo urlencode('booking-details.php?id=' . intval($booking['id'])); ?>#addons-section" class="btn btn-secondary" style="border-color: var(--warning-text); color: var(--warning-text);" title="Request Add-ons Only" aria-label="Request Add-ons Only">
                            <i class="fas fa-plus-circle" aria-hidden="true"></i>
                        <span class="booking-action-label">Request Add-ons Only</span></a>
                    <?php endif; ?>
                    
                    <!-- Keep payment status visible, with no action once fully paid. -->
                    <?php if ($remaining > 0): ?>
                        <a href="payments.php?id=<?php echo $booking_id; ?>" onclick="openBalanceModal(event, this.href)" class="btn btn-secondary" style="border-color: var(--accent-gold); color: var(--accent-gold);" title="Pay Balance" aria-label="Pay Balance" id="bookingPayBalance"<?php echo customerBookingDownpaymentIsOpen($current_status, $fin ?: []) ? ' data-downpayment-open' : ''; ?>>
                            <svg class="pay-balance-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 8V5a2 2 0 0 0-2-2H6a3 3 0 0 0 0 6h14v11H6a3 3 0 0 1-3-3V6"/><path d="M20 12h-5v5h5"/><path d="M17 14.5h.01"/></svg>
                        <span class="booking-action-label">Pay Balance</span></a>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary" disabled title="Fully Paid" aria-label="Fully Paid">
                            <svg class="pay-balance-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 8V5a2 2 0 0 0-2-2H6a3 3 0 0 0 0 6h14v11H6a3 3 0 0 1-3-3V6"/><path d="M20 12h-5v5h5"/><path d="M17 14.5h.01"/></svg>
                        <span class="booking-action-label">Fully Paid</span></button>
                    <?php endif; ?>

                    <?php if ($can_request_reschedule): ?>
                        <?php if ($reschedule_status === 'pending'): ?>
                            <button type="button" class="btn btn-secondary" style="border-color: var(--accent-gold); color: var(--accent-gold); opacity: 0.6; cursor: not-allowed;" disabled title="Reschedule Pending" aria-label="Reschedule Pending">
                                <i class="fas fa-hourglass-half" aria-hidden="true"></i>
                            <span class="booking-action-label">Reschedule Pending</span></button>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('rescheduleRequestCard').scrollIntoView({behavior: 'smooth', block: 'start'});" style="border-color: var(--accent-gold); color: var(--accent-gold);" title="Request Reschedule" aria-label="Request Reschedule">
                                <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                            <span class="booking-action-label">Request Reschedule</span></button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($booking['product_id']) && !empty($booking['store_id']) && in_array(normalizeOrderWorkflowStatus($booking['order_status']), ['completed', 'cancelled'], true)): ?>
                        <?php if (isStoreAvailableNow($booking['store_is_open'] ?? 0, $booking['store_open_time'] ?? null, $booking['store_close_time'] ?? null, $booking['store_auto_schedule_enabled'] ?? 0)): ?>
                            <a href="book-service.php?product_id=<?php echo intval($booking['product_id']); ?>&store_id=<?php echo intval($booking['store_id']); ?>&return_url=<?php echo urlencode('booking-details.php?id=' . intval($booking['id'])); ?>" class="btn btn-secondary" style="border-color: var(--accent-gold); color: var(--accent-gold);" title="Book Again" aria-label="Book Again">
                                <i class="fas fa-rotate-right" aria-hidden="true"></i>
                            <span class="booking-action-label">Book Again</span></a>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary" style="border-color: #6b3b3b; color: #f0b4b4; background: rgba(231, 76, 60, 0.08); opacity: 0.75; cursor: not-allowed;" disabled title="Caterer Closed" aria-label="Caterer Closed">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="display:block;flex-shrink:0;"><path d="M3 3l18 18M4 10v10h16v-4M4 4h16l2 6h-8M2 10h5M9 20v-6h5"/></svg>
                            <span class="booking-action-label">Caterer Closed</span></button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($current_status === 'completed'): ?>
                        <?php if (!empty($booking['review_id'])): ?>
                            <button type="button" class="btn btn-secondary" style="background: rgba(46, 204, 113, 0.18); border-color: #2ecc71; color: #9cf0be; cursor: default;" disabled title="Reviewed" aria-label="Reviewed">
                                <i class="fas fa-star" aria-hidden="true"></i>
                            <span class="booking-action-label">Reviewed</span></button>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary" onclick="openReviewModal(<?php echo $booking_id; ?>, <?php echo intval($booking['store_id']); ?>)" style="border-color: #f1c40f; color: #f1c40f;" title="Rate Caterer" aria-label="Rate Caterer">
                                <i class="far fa-star" aria-hidden="true"></i>
                                <span class="booking-action-label">Rate Caterer</span>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <a href="booking-receipt.php?id=<?php echo $booking_id; ?>&amp;return_to=details" class="btn btn-secondary" style="border-color: var(--accent-gold); color: var(--accent-gold);" title="Print Receipt" aria-label="Print Receipt">
                        <i class="fas fa-print" aria-hidden="true"></i>
                    <span class="booking-action-label">Print Receipt</span></a>
                </div>
            </div>

        </div>
    <div class="detail-card booking-progress-panel">
        <h3><i class="fas fa-stream"></i> Booking Progress</h3>
        <div class="order-timeline" id="order-timeline-container">
            <?php foreach ($timeline['steps'] as $key => $step): ?>
            <div class="timeline-step <?php echo $step['status']; ?>">
                <div class="step-icon">
                    <i class="fas <?php echo $step['icon']; ?>"></i>
                </div>
                <div class="step-label"><?php echo $step['label']; ?></div>
                <div class="step-description"><?php echo $step['description']; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="overview-grid">
        <div class="overview-card">
            <div class="overview-label">Caterer</div>
            <div class="overview-value accent"><?php echo htmlspecialchars($booking['store_name'] ?? 'N/A'); ?></div>
            <div class="overview-help"><?php echo htmlspecialchars($booking['product_name'] ?? 'Booking Service'); ?></div>
        </div>
        <div class="overview-card">
            <div class="overview-label">Event schedule</div>
            <div class="overview-value overview-schedule">
                <span><?php echo date('M d, Y', strtotime($booking['order_date'])); ?></span>
                <span style="font-size: 14px; font-weight: 600; color: var(--text-muted);">
                    <?php echo !empty($booking['order_time']) ? date('h:i A', strtotime($booking['order_time'])) : 'Time not set'; ?>
                </span>
            </div>
            <div class="overview-help"><?php echo $days_diff > 0 ? $days_diff . ' days left before the event' : 'Event is today or already passed'; ?></div>
            <?php if ($booking_created_at): ?>
                <div class="overview-help">Ordered On: <?php echo date('F d, Y', $booking_created_at); ?> at <?php echo date('h:i A', $booking_created_at); ?></div>
            <?php endif; ?>
        </div>
        <div class="overview-card">
            <div class="overview-label">Balance</div>
            <div class="overview-value accent"><?php echo $remaining > 0 ? '&#8369;' . number_format($remaining, 2) : 'Fully Paid'; ?></div>
            <?php if ($down_payment_credited > 0): ?>
                <div class="overview-value">
                    <?php if ($down_payment_settled): ?>
                        <i class="fas fa-check-circle" aria-hidden="true"></i>
                        Down Payment Paid
                    <?php else: ?>
                        Partial Down Payment
                    <?php endif; ?>
                    <span>&#8369;<?php echo number_format($down_payment_credited, 2); ?></span>
                </div>
            <?php endif; ?>
            <div class="overview-help"><?php echo $remaining > 0 ? 'Remaining balance to settle' : 'No unpaid balance remaining'; ?></div>
        </div>
        <div class="overview-card">
            <div class="overview-label"><?php echo htmlspecialchars($next_action_title); ?></div>
            <div class="overview-value"><?php echo htmlspecialchars($reschedule_status === 'pending' ? 'Caterer Review Needed' : ucfirst($current_status)); ?></div>
            <div class="overview-help"><?php echo htmlspecialchars($next_action_text); ?></div>
        </div>
    </div>
    
    <div class="details-grid">
        <!-- LEFT COLUMN -->
        <div class="left-column">
            
            <!-- Caterer & Booking Info Combined -->
            <div class="detail-card booking-info-card">
                <h3><i class="fas fa-caterer"></i> Caterer & Booking Info</h3>
                
                <div class="detail-row">
                    <span class="detail-label">Venue Address</span>
                    <span class="detail-value" style="max-width: 60%; text-align: right;">
                        <?php 
                        $address = !empty($booking['delivery_address']) ? $booking['delivery_address'] : (!empty($booking['address']) ? $booking['address'] : 'N/A');
                        echo nl2br(htmlspecialchars($address)); 
                        ?>
                    </span>
                </div>
                
                <?php if (!empty($booking['number_of_pax'])): ?>
                <div class="detail-row">
                    <span class="detail-label">Number of Person</span>
                    <span class="detail-value"><?php echo htmlspecialchars($booking['number_of_pax']); ?> Person</span>
                </div>
                <?php endif; ?>

                <div class="detail-row">
                    <span class="detail-label">Event / Package Type</span>
                    <span class="detail-value" style="color: var(--accent-gold); font-weight: 600;">
                        <?php echo htmlspecialchars($booking['product_name'] ?? 'Standard Booking'); ?>
                    </span>
                </div>
            <!-- Menu & Package Details -->
    <div class="page-header booking-info-reference">
        <div>
            <div class="order-number">Booking #<?php echo htmlspecialchars(formatOrderReference($booking_id, $booking['order_number'])); ?></div>
        </div>
        <div id="header-status-badge" class="status-badge <?php echo getCustomerBookingStatusClass($booking['order_status']); ?>">
            <i class="fas fa-circle" style="font-size: 8px;"></i>
            <?php echo getCustomerBookingStatusLabel($booking['order_status']); ?>
        </div>
    </div>

            <aside id="booking-confirmation-note" class="booking-confirmation-note" aria-live="polite"<?php echo $current_status === 'pending' ? '' : ' hidden'; ?>>
                <strong>Waiting for caterer confirmation</strong>
                <div>Your booking request has been sent. You can pay the downpayment once the caterer confirms your booking.</div>
            </aside>

            <section class="booking-info-menu">
                <h3><i class="fas fa-utensils"></i> Menu & Package Details</h3>
                <div id="menu-details-container">
<?php require __DIR__ . '/includes/booking-menu-details.php'; ?>
                </div>
            </section>
            
            <!-- Damage Report Section (Only if exists) -->
            <?php if (!empty($damages)): ?>
            <section class="booking-info-incident">
                <h3 style="color: var(--danger-color); border-bottom-color: rgba(244, 67, 54, 0.3);">
                    <i class="fas fa-exclamation-triangle"></i> Incident Report
                </h3>
                
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border-color); color: var(--text-muted); font-size: 12px; text-transform: uppercase;">
                                <th style="text-align: left; padding: 10px;">Item</th>
                                <th style="text-align: center; padding: 10px;">Qty</th>
                                <th style="text-align: right; padding: 10px;">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($damages as $damage): ?>
                                <tr style="border-bottom: 1px solid var(--border-color);">
                                    <td style="padding: 10px;">
                                        <div style="font-weight: 500; color: var(--text-main);"><?php echo htmlspecialchars($damage['item_name']); ?></div>
                                        <?php if ($damage['damage_photo']): ?>
                                            <a href="../<?php echo $damage['damage_photo']; ?>" target="_blank" style="font-size: 11px; color: var(--accent-gold); text-decoration: none;">
                                                <i class="fas fa-image"></i> View Photo
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center; padding: 10px; color: var(--text-muted);"><?php echo $damage['quantity']; ?></td>
                                    <td style="text-align: right; padding: 10px; font-weight: bold; color: var(--danger-color);">&#8369;<?php echo number_format($damage['total'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
            </section>
            <?php endif; ?>
            </div>

            <div class="detail-card" id="rescheduleRequestCard">
                <h3>Reschedule Request</h3>
                <div class="reschedule-content" style="display: grid; gap: 14px;">
                    <div class="reschedule-summary" style="padding: 14px; border-radius: 10px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.02);">
                        <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 8px;">
                            <strong style="color: var(--accent-gold);">Request Status</strong>
                            <span class="status-badge <?php echo $reschedule_status_class; ?>"><?php echo htmlspecialchars($reschedule_status_text); ?></span>
                        </div>
                        <div style="font-size: 13px; color: var(--text-muted); line-height: 1.6;">
                            Current Schedule:
                            <strong style="color: var(--text-main);"><?php echo date('F d, Y', strtotime($booking['order_date'])); ?> at <?php echo !empty($booking['order_time']) ? date('h:i A', strtotime($booking['order_time'])) : 'N/A'; ?></strong>
                        </div>
                        <?php if (!empty($booking['requested_order_date']) && !empty($booking['requested_order_time'])): ?>
                            <div style="font-size: 13px; color: var(--text-muted); line-height: 1.6; margin-top: 6px;">
                                Requested Schedule:
                                <strong style="color: var(--text-main);"><?php echo date('F d, Y', strtotime($booking['requested_order_date'])); ?> at <?php echo date('h:i A', strtotime($booking['requested_order_time'])); ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($booking['reschedule_reason'])): ?>
                            <div style="margin-top: 8px; font-size: 13px; color: var(--text-muted);">
                                Reason: <span style="color: var(--text-main);"><?php echo htmlspecialchars($booking['reschedule_reason']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($booking['reschedule_response'])): ?>
                            <div style="margin-top: 8px; font-size: 13px; color: var(--text-muted);">
                                Caterer Response: <span style="color: var(--text-main);"><?php echo htmlspecialchars($booking['reschedule_response']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($can_request_reschedule): ?>
                        <?php if ($reschedule_status !== 'pending'): ?>
                            <form method="POST" style="display: grid; gap: 12px;">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="submit_reschedule_request" value="1">
                                <div class="reschedule-schedule-fields" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:16px;">
                                    <div>
                                        <label class="detail-label" style="display: block; margin-bottom: 6px;">New Event Date</label>
                                        <select name="requested_order_date" id="rescheduleDate" class="form-control" required style="width:100%;min-width:0;min-height:44px;box-sizing:border-box;"><option value="" disabled selected hidden>Loading available dates…</option></select>
                                    </div>
                                    <div>
                                        <label class="detail-label" style="display: block; margin-bottom: 6px;">New Event Time</label>
                                        <select name="requested_order_time" class="form-control" required style="width:100%;min-width:0;min-height:44px;box-sizing:border-box;">
                                            <option value="" disabled selected hidden>Select event time</option>
                                            <?php foreach ($reschedule_times as $slot): ?><option value="<?php echo $slot; ?>"><?php echo date('g:i A', strtotime($slot)); ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="detail-label" style="display: block; margin-bottom: 6px;">Reason for Reschedule</label>
                                    <textarea name="reschedule_reason" class="form-control" rows="4" required placeholder="Explain why you need to move the event schedule."></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary" style="justify-content: center;">
                                    <i class="fas fa-paper-plane"></i> Submit Reschedule Request
                                </button>
                            </form>
                        <?php else: ?>
                        <?php endif; ?>
                    <?php elseif (!$reschedule_already_used): ?>
                        <div class="reschedule-unavailable-notice" style="padding: 12px; border-radius: 10px; background: rgba(244, 67, 54, 0.08); border: 1px solid rgba(244, 67, 54, 0.18); color: #ffb3b3; font-size: 13px; line-height: 1.6;">
                            Reschedule requests are only available for active bookings that are at least 2 days away from the event date.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            

            
        </div>
        

    </div>

</div>

<link rel="stylesheet" href="../assets/css/customer-review-modal.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/customer-review-modal.css'); ?>">
<div id="reviewModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.72); z-index: 1200; align-items: center; justify-content: center; padding: 20px;">
    <div class="modal-content" style="max-width: 500px; width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden;">
        <div class="modal-header" style="padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0; color: var(--accent-gold); font-size: 22px;">Write a Review</h2>
            <button type="button" class="close-btn" onclick="closeReviewModal()" style="background: none; border: none; color: var(--text-muted); font-size: 26px; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 24px;">
            <div id="reviewSuccess" class="alert alert-success" style="display: none; margin-bottom: 15px;">
                Review submitted successfully!
            </div>
            <div id="reviewError" class="alert alert-danger" style="display: none; color: #e74c3c; background: rgba(231, 76, 60, 0.1); padding: 10px; border-radius: 8px; margin-bottom: 15px;"></div>

            <form id="reviewForm">
                <input type="hidden" id="reviewOrderId" name="order_id">
                <input type="hidden" id="reviewStoreId" name="store_id">

                <div style="text-align: center; margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; color: var(--text-muted);">Overall Rating</label>
                    <div class="star-rating" id="overallRating" style="font-size: 28px; color: #f1c40f; cursor: pointer;">
                        <i class="far fa-star" data-value="1"></i>
                        <i class="far fa-star" data-value="2"></i>
                        <i class="far fa-star" data-value="3"></i>
                        <i class="far fa-star" data-value="4"></i>
                        <i class="far fa-star" data-value="5"></i>
                    </div>
                    <input type="hidden" name="overall_rating" id="overallRatingInput" required>
                </div>

                <div class="rating-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div>
                        <label class="form-label" style="font-size: 12px;">Service</label>
                        <select name="service_rating" class="form-control" required>
                            <option value="5">5 - Excellent</option>
                            <option value="4">4 - Good</option>
                            <option value="3">3 - Average</option>
                            <option value="2">2 - Poor</option>
                            <option value="1">1 - Terrible</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 12px;">Food Quality</label>
                        <select name="food_rating" class="form-control" required>
                            <option value="5">5 - Delicious</option>
                            <option value="4">4 - Good</option>
                            <option value="3">3 - Average</option>
                            <option value="2">2 - Poor</option>
                            <option value="1">1 - Bad</option>
                        </select>
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="form-label" style="font-size: 12px;">Delivery / Setup</label>
                        <select name="delivery_rating" class="form-control" required>
                            <option value="5">5 - On Time & Perfect</option>
                            <option value="4">4 - Good</option>
                            <option value="3">3 - Average</option>
                            <option value="2">2 - Late / Issues</option>
                            <option value="1">1 - Terrible</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Feedback</label>
                    <textarea name="comment" class="form-control" rows="4" placeholder="Share your experience..."></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">Submit Review</button>
            </form>
        </div>
    </div>
</div>

<script>
    const reviewStars = document.querySelectorAll('#overallRating i');

    function highlightStars(value) {
        reviewStars.forEach(star => {
            const starValue = parseInt(star.dataset.value, 10);
            star.className = starValue <= value ? 'fas fa-star' : 'far fa-star';
        });
    }

    reviewStars.forEach(star => {
        star.addEventListener('click', function() {
            const value = parseInt(this.dataset.value, 10);
            document.getElementById('overallRatingInput').value = value;
            highlightStars(value);
        });

        star.addEventListener('mouseenter', function() {
            highlightStars(parseInt(this.dataset.value, 10));
        });
    });

    const overallRating = document.getElementById('overallRating');
    if (overallRating) {
        overallRating.addEventListener('mouseleave', function() {
            const currentVal = parseInt(document.getElementById('overallRatingInput').value || '0', 10);
            highlightStars(currentVal);
        });
    }

    function openReviewModal(orderId, storeId) {
        document.getElementById('reviewOrderId').value = orderId;
        document.getElementById('reviewStoreId').value = storeId;
        document.getElementById('reviewForm').reset();
        document.getElementById('overallRatingInput').value = '';
        highlightStars(0);
        document.getElementById('reviewSuccess').style.display = 'none';
        document.getElementById('reviewError').style.display = 'none';
        document.getElementById('reviewForm').style.display = 'block';
        document.getElementById('reviewModal').style.display = 'flex';
    }

    function closeReviewModal() {
        document.getElementById('reviewModal').style.display = 'none';
    }

    document.getElementById('reviewModal').addEventListener('click', function(event) {
        if (event.target === this) {
            closeReviewModal();
        }
    });

    document.getElementById('reviewForm').addEventListener('submit', function(e) {
        e.preventDefault();

        if (!document.getElementById('overallRatingInput').value) {
            alert('Please select a star rating.');
            return;
        }

        const formData = new FormData(this);

        fetch('submit_review.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('reviewSuccess').style.display = 'block';
                document.getElementById('reviewForm').style.display = 'none';
                setTimeout(() => {
                    window.location.reload();
                }, 1200);
            } else {
                const errorDiv = document.getElementById('reviewError');
                errorDiv.innerText = data.message;
                errorDiv.style.display = 'block';
            }
        })
        .catch(() => {
            alert('An error occurred. Please try again.');
        });
    });

    async function submitDetailsMenuChange(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        const result = document.getElementById('detailsMenuChangeResult');
        form.dataset.saving = 'true';
        button.disabled = true;
        try {
            const response = await fetch('booking.php', {method:'POST', body:new FormData(form), credentials:'same-origin'});
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Unable to submit the request.');
            result.textContent = data.message || 'Menu change request sent to the caterer.';
            form.querySelector('select').disabled = true;
            button.textContent = 'Request Pending';
        } catch (error) {
            result.textContent = error.message;
            button.disabled = false;
        } finally {
            delete form.dataset.saving;
        }
    }
    function toggleSetMenus() {
        const setMenus = document.getElementById('available-set-menus');
        if (!setMenus) return;
        setMenus.style.display = setMenus.style.display === 'none' ? 'block' : 'none';
    }

    // Real-time updates
    const bookingId = <?php echo $booking_id; ?>;
    
    function fetchUpdates() {
        fetch(`get_booking_updates.php?id=${bookingId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update Timeline
                    const timelineContainer = document.getElementById('order-timeline-container');
                    if (timelineContainer && timelineContainer.innerHTML !== data.timeline_html) {
                        timelineContainer.innerHTML = data.timeline_html;
                    }
                    
                    // Update Menu Details
                    const menuContainer = document.getElementById('menu-details-container');
                    const menuChangeForm = document.getElementById('detailsMenuChangeForm');
                    const editingMenuChange = menuChangeForm && (menuChangeForm.dataset.saving === 'true' || menuChangeForm.contains(document.activeElement) || !!menuChangeForm.querySelector('select')?.value);
                    if (menuContainer && !editingMenuChange && menuContainer.innerHTML !== data.menu_html) {
                        const menusOpen = document.getElementById('available-set-menus')?.style.display === 'block';
                        menuContainer.innerHTML = data.menu_html;
                        const setMenus = document.getElementById('available-set-menus');
                        if (menusOpen && setMenus) setMenus.style.display = 'block';
                    }
                    
                    // Update Financial Status
                    const financialContainer = document.getElementById('financial-status-container');
                    if (financialContainer && financialContainer.innerHTML !== data.financial_html) {
                        financialContainer.innerHTML = data.financial_html;
                    }
                    
                    const confirmationNote = document.getElementById('booking-confirmation-note');
                    if (confirmationNote && typeof data.awaiting_confirmation === 'boolean') {
                        confirmationNote.hidden = !data.awaiting_confirmation;
                    }

                    const payBalance = document.getElementById('bookingPayBalance');
                    if (payBalance && typeof data.downpayment_open === 'boolean') {
                        payBalance.toggleAttribute('data-downpayment-open', data.downpayment_open);
                    }

                    // Update Header Status Badge
                    const badge = document.getElementById('header-status-badge');
                    if (badge) {
                        badge.className = `status-badge ${data.status_badge_class}`;
                        if (badge.innerHTML !== data.status_badge_html) {
                            badge.innerHTML = data.status_badge_html;
                        }
                    }
                }
            })
            .catch(err => console.error('Error fetching updates:', err));
    }

    // Poll every 15 seconds — reduced from 3s to avoid server congestion during button clicks
    setInterval(fetchUpdates, 3000);
</script>

<script>
(() => {
    try {
        const saved = JSON.parse(sessionStorage.getItem('bookingReceiptReturn') || 'null');
        if (!saved) return;
        const destination = new URL(saved.url);
        if (destination.origin !== location.origin || !destination.pathname.endsWith('/customer/booking.php')) return;
        const backLink = document.querySelector('.booking-details-container .back-link');
        if (backLink) backLink.href = destination.href;
    } catch (_) { /* Use the normal bookings link if no saved position is available. */ }
})();
</script>
<script>
(() => {
    const key = 'bookingDetailsPosition:' + <?php echo json_encode((string) $user_id . ':' . (string) $booking_id); ?>;
    const panel = document.querySelector('.booking-details-container');
    let restoring = true;
    let saved;
    try { saved = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) {}
    function savePosition() {
        if (restoring) return;
        try {
            sessionStorage.setItem(key, JSON.stringify({

                pageTop: window.scrollY, pageLeft: window.scrollX,
                menuOpen: document.getElementById('available-set-menus')?.style.display === 'block',
                requestedSet: document.getElementById('detailsRequestedSet')?.value || ''
            }));
        } catch (_) {}
    }
    function restorePosition() {
        if (saved) {
            const menu = document.getElementById('available-set-menus');
            if (menu) menu.style.display = saved.menuOpen ? 'block' : 'none';
            const selection = document.getElementById('detailsRequestedSet');
            if (selection && Array.from(selection.options).some(option => option.value === saved.requestedSet)) {
                selection.value = saved.requestedSet;
            }
            window.scrollTo(Number(saved.pageLeft) || 0, (Number(saved.pageTop) || 0) + (Number(saved.top) || 0));
        }
        restoring = false;
    }
    panel?.querySelectorAll('a[href*="booking-receipt.php"], a[href*="cancel_from=booking_addons"]').forEach(link => link.addEventListener('click', savePosition));
    panel?.addEventListener('click', () => queueMicrotask(savePosition));
    panel?.addEventListener('change', savePosition);
    window.addEventListener('scroll', savePosition, { passive: true });
    window.addEventListener('pagehide', savePosition);
    if (document.readyState === 'complete') restorePosition();
    else window.addEventListener('load', restorePosition, { once: true });
    window.addEventListener('pageshow', event => { if (!event.persisted) restorePosition(); });
})();
</script>
<dialog id="balancePaymentModal" aria-labelledby="balancePaymentTitle" style="width:min(880px,calc(100vw - 20px));height:min(740px,90dvh);max-height:90dvh;padding:0;border:1px solid #96a38e;border-radius:12px;background:#d4dbcd;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border-bottom:1px solid #96a38e;">
        <strong id="balancePaymentTitle" tabindex="-1" style="font-size:13px;color:#173d32;">Pay balance</strong>
        <button type="button" onclick="closeBalanceModal()" aria-label="Close payment" style="background:#173d32;color:#f0f1e6;border:0;border-radius:6px;padding:5px 10px;">×</button>
    </div>
    <iframe id="balancePaymentFrame" title="Booking balance payment" onload="compactBalanceFrame(this)" style="display:block;width:100%;height:calc(100% - 47px);border:0;"></iframe>
</dialog>
<style>
#balancePaymentModal {
    position: fixed !important; inset: 0 !important; margin: auto !important;
    width: min(400px, calc(100vw - 24px)) !important;
    height: min(480px, calc(100vh - 40px)) !important;
    height: min(480px, calc(100dvh - 40px)) !important;
    max-width: calc(100vw - 24px) !important; max-height: calc(100dvh - 24px) !important;
    box-sizing: border-box;
}
#balancePaymentModal[open] { display: flex; flex-direction: column; }
#balancePaymentModal > div:first-child { flex: 0 0 auto; padding: 5px 10px !important; gap: 10px; }
#balancePaymentTitle { font-size: 12px !important; line-height: 1.4; }
#balancePaymentModal > div:first-child button { width: 40px; height: 40px; padding: 0 !important; font-size: 20px; }
#balancePaymentFrame { flex: 1 1 auto; min-height: 0; height: auto !important; }
#balancePaymentFrame.balance-loading { visibility: hidden; }
#balancePaymentTitle:focus { outline: none; }
#balancePaymentModal > div:first-child button:is(:focus,:focus-visible) { outline: none !important; box-shadow: none !important; }
#balancePaymentModal::backdrop { background: rgb(12 32 25 / 65%); }
@media (max-width: 600px) {
    #balancePaymentModal { width: min(400px, calc(100vw - 20px)) !important; height: min(480px, calc(100dvh - 32px)) !important; }
    #balancePaymentModal > div:first-child { padding: 6px 10px !important; }
}
</style>
<script>
document.getElementById('balancePaymentModal').addEventListener('cancel', event => event.preventDefault());
function openBalanceModal(event, url) {
    if (event) event.preventDefault();
    const frame = document.getElementById('balancePaymentFrame');
    if (frame.getAttribute('src') !== url) {
        frame.classList.add('balance-loading');
        frame.src = url;
    }
    const modal = document.getElementById('balancePaymentModal');
    if (!modal.open) modal.showModal();
    document.getElementById('balancePaymentTitle').focus({preventScroll:true});
    const pageUrl = new URL(location.href);
    pageUrl.searchParams.set('pay_balance', '1');
    history.replaceState(history.state, '', pageUrl);
}
function closeBalanceModal() {
    const pageUrl = new URL(location.href);
    pageUrl.searchParams.delete('pay_balance');
    pageUrl.searchParams.delete('payment_step');
    history.replaceState(history.state, '', pageUrl);
    document.getElementById('balancePaymentModal').close();
}
if (new URL(location.href).searchParams.get('pay_balance') === '1') {
    openBalanceModal(null, 'payments.php?id=<?php echo (int) $booking_id; ?>');
}
function compactBalanceFrame(frame) {
    try {
        const doc = frame.contentDocument;
        if (!doc || !doc.querySelector('.payment-details-container')) {
            frame.classList.remove('balance-loading');
            return;
        }
        doc.body.classList.add('balance-embedded');
        const paymentBadge = doc.querySelector('.payment-details-container .badge-status');
        if (paymentBadge) {
            const label = paymentBadge.textContent.trim().toLowerCase();
            paymentBadge.textContent = label.charAt(0).toUpperCase() + label.slice(1);
        }
        // Ensure every receipt cell has a label at all viewport widths.
        doc.querySelectorAll('.table-custom').forEach(table => {
            const headings = [...table.querySelectorAll('thead th')].map(cell => cell.textContent.trim());
            if (headings.length === 7 && headings.some(label => /receipt/i.test(label))) {
                table.classList.add('payment-transaction-cards');
            }
            table.querySelectorAll('tbody tr').forEach(row => {
                [...row.cells].forEach((cell, index) => {
                    if (cell.colSpan > 1) return;
                    const label = (headings[index] || '').trim().toLowerCase();
                    cell.dataset.label = label ? label.charAt(0).toUpperCase() + label.slice(1) : '';
                });
            });
        });
        const payModal = doc.getElementById('payModal');
        if (payModal && !payModal.dataset.inlinePayment) {
            payModal.dataset.inlinePayment = 'true';
            const title = document.getElementById('balancePaymentTitle');
            const setPaymentView = active => {
                doc.body.classList.toggle('balance-payment-form-active', active);
                title.textContent = active ? 'Submit payment proof' : 'Pay balance';
                const pageUrl = new URL(location.href);
                if (active) pageUrl.searchParams.set('payment_step', 'proof');
                else pageUrl.searchParams.delete('payment_step');
                history.replaceState(history.state, '', pageUrl);
                doc.defaultView.scrollTo(0, 0);
            };
            const payAction = doc.getElementById('pay-action-btn');
            payAction?.addEventListener('click', event => {
                event.preventDefault();
                event.stopImmediatePropagation();
                payModal.style.display = 'block';
                setPaymentView(true);
            }, true);
            const backButton = payModal.querySelector('.close-btn');
            if (backButton) {
                backButton.textContent = '← Back';
                backButton.setAttribute('aria-label', 'Back to payment summary');
                backButton.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    payModal.style.display = 'none';
                    setPaymentView(false);
                    payAction?.focus({ preventScroll: true });
                }, true);
            }
            // Also handle payment forms opened automatically by the payment page.
            new doc.defaultView.MutationObserver(() => {
                setPaymentView(payModal.style.display !== 'none');
            }).observe(payModal, { attributes: true, attributeFilter: ['style'] });
            if (doc.querySelector('.payment-proof-received')) {
                payModal.style.display = 'none';
            } else if (new URL(location.href).searchParams.get('payment_step') === 'proof') {
                payModal.style.display = 'block';
            }
            setPaymentView(payModal.style.display !== 'none');
        }
        if (!doc.getElementById('compact-balance-styles')) {
            const style = doc.createElement('link');
            style.id = 'compact-balance-styles';
            style.rel = 'stylesheet';
            style.href = '../assets/css/customer-balance-embed.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/customer-balance-embed.css'); ?>';
            style.addEventListener('load', () => {
                requestAnimationFrame(() => frame.classList.remove('balance-loading'));
            }, {once:true});
            style.addEventListener('error', () => frame.classList.remove('balance-loading'), {once:true});
            doc.head.appendChild(style);
        } else {
            frame.classList.remove('balance-loading');
        }
    } catch (_) { frame.classList.remove('balance-loading'); }
}
</script>
<script>
(async function loadRescheduleDates() {
    const select = document.getElementById('rescheduleDate');
    if (!select) return;
    const start = <?php echo json_encode($reschedule_min_date); ?>;
    const end = <?php echo json_encode(date('Y-m-d', strtotime('+1 year'))); ?>;
    try {
        const response = await fetch('get_store_availability.php?store_id=<?php echo $schedule_store; ?>&start=' + start + '&end=' + end, {cache:'no-store'});
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error();
        select.replaceChildren(new Option('Select available date', ''));
        select.options[0].disabled = true;
        select.options[0].hidden = true;
        for (let day = new Date(start + 'T12:00:00'); ; day.setDate(day.getDate() + 1)) {
            const key = day.getFullYear() + '-' + String(day.getMonth()+1).padStart(2,'0') + '-' + String(day.getDate()).padStart(2,'0');
            if (key > end) break;
            if ((data.holidays || []).includes(key) || data.dates?.[key]?.is_available === false) continue;
            select.add(new Option(day.toLocaleDateString('en-US', {month:'long',day:'numeric',year:'numeric'}), key));
        }
    } catch (_) {
        select.replaceChildren(new Option('Unable to load dates. Please reload.', ''));
        select.setCustomValidity('Reload the page to check available dates.');
    }
})();
</script>
<link rel="stylesheet" href="../assets/css/reschedule-picker.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/reschedule-picker.css'); ?>">
<script src="../assets/js/reschedule-picker.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/reschedule-picker.js'); ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
