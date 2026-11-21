<?php
function groupCustomerBookings($result) {
    $groups = [];
    $paid = [[], []];
    $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

    while ($booking = mysqli_fetch_assoc($result)) {
        $fin = getOrderFinancials($booking['id']);
        $booking['financials'] = $fin;
        $grand_total = (float) ($fin['totals']['grand_total'] ?? 0);
        $total_paid = (float) ($fin['totals']['total_paid'] ?? 0);
        $balance = (float) ($fin['totals']['balance'] ?? max(0, $grand_total - $total_paid));

        if (($grand_total > 0 || $total_paid > 0) && $balance <= 0) {
            $date = substr((string) ($booking['order_date'] ?? ''), 0, 10);
            $past = false;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $past = $date < $today;
                $booking['event_date_label'] = $past ? 'Event Date Passed'
                    : ($date === $today ? 'Event Today' : 'Upcoming Event');
            }
            $paid[(int) $past][] = $booking;
            continue;
        }

        $store_id = $booking['store_id'];
        if (!isset($groups[$store_id])) {
            $groups[$store_id] = [
                'business_name' => $booking['business_name'],
                'profile_image' => $booking['profile_image'] ?? '',
                'bookings' => []
            ];
        }
        $groups[$store_id]['bookings'][] = $booking;
    }

    if ($paid[0] || $paid[1]) {
        $groups['fully-paid'] = ['bookings' => array_merge($paid[0], $paid[1])];
    }
    return $groups;
}

function renderBookingEventDateLabel($booking) {
    if (empty($booking['event_date_label'])) {
        return '';
    }
    return '<br><small><strong>' . htmlspecialchars($booking['event_date_label']) . '</strong></small>';
}

function renderFullyPaidBookingsHeader() {
    return '<tr class="caterer-group-header"><td colspan="7" style="padding: 15px; background: rgba(212,175,55,0.1);"><button type="button" id="toggle-fully-paid-bookings" aria-expanded="false" onclick="toggleCatererOrders(&quot;fully-paid&quot;)" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; padding: 0; background: transparent; border: 0; color: inherit; font: inherit; text-align: left; cursor: pointer;"><strong>Fully Paid Bookings</strong><i id="icon-caterer-fully-paid" class="fas fa-chevron-down" aria-hidden="true"></i></button></td></tr>';
}

function renderBookingPaymentNotice(array $booking, array $financials): string {

    if (normalizeCustomerBookingStatus($booking['order_status']) !== 'confirmed'
        || ($financials['totals']['balance'] ?? 0) <= 0) {
        return '';
    }
    foreach ($financials['history'] ?? [] as $payment) {
        if (strtolower((string) ($payment['status'] ?? '')) === 'pending') return '';
    }
    $downpaymentDue = ($financials['down_payment_required'] ?? 0) > ($financials['totals']['total_paid'] ?? 0);
    $label = $downpaymentDue ? 'Downpayment is now open' : 'Booking payment is now open';
    if (!$downpaymentDue && ($financials['totals']['total_paid'] ?? 0) > 0) return '';
    return '<div class="booking-payment-notice"><span class="booking-payment-person" aria-hidden="true">&#128587;</span><span>' . $label . '</span></div>';
}

if (false) {
    require_once '../config.php';
    require_once '../includes/financial_helper.php';
    require_once '../includes/booking_flow_helper.php';
}
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/financial_helper.php';
require_once __DIR__ . '/../includes/booking_flow_helper.php';
require_once __DIR__ . '/../includes/order_reference_helper.php';
requireLogin();

if (getUserRole() !== 'customer') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Generate CSRF token
$csrf_token = generateCSRFToken();

function renderBookingCatererProfile($group) {
    $image = '../' . ltrim(($group['profile_image'] ?? '') ?: 'uploads/logo.png', '/');
    return '<strong class="booking-caterer-profile"><img class="booking-caterer-avatar" src="'
        . htmlspecialchars($image, ENT_QUOTES, 'UTF-8')
        . '" alt="" onerror="this.onerror=null;this.src=\'../uploads/logo.png\';">'
        . htmlspecialchars($group['business_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</strong>';
}

function getCustomerBookingStatusSql($status_filter) {
    switch ($status_filter) {
        case 'pending':
            return " AND LOWER(o.order_status) = 'pending'";
        case 'confirmed':
            return " AND LOWER(o.order_status) IN ('confirmed', 'approved', 'awaiting_store_confirmation')";
        case 'preparing':
            return " AND LOWER(o.order_status) = 'preparing'";
        case 'delivering':
            return " AND LOWER(o.order_status) IN ('delivering', 'ready')";
        case 'completed':
            return " AND LOWER(o.order_status) = 'completed'";
        case 'cancelled':
            return " AND LOWER(o.order_status) IN ('cancelled', 'rejected', 'declined')";
        default:
            return '';
    }
}

// Handle send message to caterer (AJAX request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_to_store'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit();
    }
    
    $store_id = (int) ($_POST['store_id'] ?? 0);
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $owned = mysqli_query($conn, "SELECT id, order_number FROM bookings WHERE id = $order_id AND customer_id = '$user_id' AND store_id = $store_id LIMIT 1");
    $linked_booking = $owned ? mysqli_fetch_assoc($owned) : null;
    if (!$linked_booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found for this caterer.']);
        exit();
    }
    $subject_text = trim((string) ($_POST['subject'] ?? ''));
    if ($subject_text === '' || preg_match('/^Regarding Booking(?:\s|$)/i', $subject_text)) {
        $subject_text = 'Regarding Booking #' . formatCustomerBookingNumber($order_id, $linked_booking['order_number']);
    }
    $subject = mysqli_real_escape_string($conn, $subject_text);
    $message = mysqli_real_escape_string($conn, $_POST['message'] ?? '');

    // Insert message into database
    $query = "INSERT INTO messages (sender_id, receiver_id, order_id, subject, message, created_at) 
              VALUES ('$user_id', '$store_id', '$order_id', '$subject', '$message', NOW())";
    
    if (mysqli_query($conn, $query)) {
        echo json_encode(['success' => true, 'message' => 'Message sent successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error sending message.']);
    }
    exit();
}

// Handle customer menu change request (AJAX request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_menu_change'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit();
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    $requested_set = trim($_POST['requested_set'] ?? '');

    if ($order_id <= 0 || $requested_set === '') {
        echo json_encode(['success' => false, 'message' => 'Please select the new set menu you want to request.']);
        exit();
    }

    mysqli_begin_transaction($conn);
    $order_query = "
        SELECT o.*, p.name as product_name
        FROM bookings o
        LEFT JOIN products p ON o.product_id = p.id
        WHERE o.id = '$order_id' AND o.customer_id = '$user_id'
        LIMIT 1 FOR UPDATE
    ";
    $order_result = mysqli_query($conn, $order_query);
    $order = $order_result ? mysqli_fetch_assoc($order_result) : null;

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    $days_diff = (int) ceil((strtotime($order['order_date']) - time()) / (60 * 60 * 24));
    if ($days_diff <= 5) {
        echo json_encode(['success' => false, 'message' => 'Menu changes are only allowed when the event is more than 5 days away.']);
        exit();
    }

    $pending_request_q = mysqli_query($conn, "
        SELECT m.id
        FROM messages m
        WHERE m.order_id = '$order_id'
          AND m.sender_id = '$user_id'
          AND (m.subject LIKE '%Menu Change Request%' OR m.message LIKE '%Requested Set:%')
        ORDER BY m.created_at DESC, m.id DESC
        LIMIT 1
    ");
    if ($pending_request_q && mysqli_num_rows($pending_request_q) > 0) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Only one menu change request is allowed per booking.']);
        exit();
    }

    $raw_menu = $order['additional_menu'] ?? '';
    $current_set = trim(explode(' | ', $raw_menu)[0] ?? '');
    if ($current_set !== '' && strcasecmp($current_set, $requested_set) === 0) {
        echo json_encode(['success' => false, 'message' => 'You already selected that set menu. Please choose a different set.']);
        exit();
    }

    $requested_set_safe = mysqli_real_escape_string($conn, $requested_set);
    $set_query = "
        SELECT *
        FROM product_sets
        WHERE product_id = '{$order['product_id']}'
        AND set_name = '$requested_set_safe'
        LIMIT 1
    ";
    $set_result = mysqli_query($conn, $set_query);
    $new_set = $set_result ? mysqli_fetch_assoc($set_result) : null;

    if (!$new_set) {
        echo json_encode(['success' => false, 'message' => 'The selected set menu is no longer available.']);
        exit();
    }

    $fin = getOrderFinancials($order_id);
    $current_base_total = (float) ($fin['breakdown']['base_price'] ?? 0);
    $total_paid = (float) ($fin['totals']['total_paid'] ?? 0);
    $pax = max(1, (int) ($order['number_of_pax'] ?? 1));
    $estimated_new_base_total = (float) ($new_set['price'] ?? 0);
    $estimated_difference = $estimated_new_base_total - $current_base_total;
    $additional_needed = max(0, $estimated_new_base_total - $total_paid);

    $subject = mysqli_real_escape_string($conn, 'Menu Change Request for Booking #' . $order['order_number']);
    $message_text = "Customer requested a menu change.\n";
    $message_text .= "Service Type: " . ($order['product_name'] ?: 'Booking') . "\n";
    $message_text .= "Current Set: " . ($current_set !== '' ? $current_set : 'Not specified') . "\n";
    $message_text .= "Requested Set: " . ($new_set['set_name'] ?? $requested_set) . "\n";
    $message_text .= "Person: " . $pax . "\n";
    $message_text .= "Current Set Total: PHP " . number_format($current_base_total, 2) . "\n";
    $message_text .= "New Set Total: PHP " . number_format($estimated_new_base_total, 2) . "\n";
    $message_text .= "Already Paid: PHP " . number_format($total_paid, 2) . "\n";
    $message_text .= "Price Difference: " . ($estimated_difference >= 0 ? '+' : '-') . "PHP " . number_format(abs($estimated_difference), 2) . "\n";
    $message_text .= "Additional Amount Needed: PHP " . number_format($additional_needed, 2) . "\n";
    $message_text .= "Note: Final pricing still depends on existing add-ons, swaps, and caterer approval.";
    $message = mysqli_real_escape_string($conn, $message_text);

    $insert_message = "
        INSERT INTO messages (sender_id, receiver_id, order_id, subject, message, created_at)
        VALUES ('$user_id', '{$order['store_id']}', '$order_id', '$subject', '$message', NOW())
    ";

    if (mysqli_query($conn, $insert_message)) {
        $log_details = "Customer requested menu change from " . ($current_set !== '' ? $current_set : 'current set') . " to " . ($new_set['set_name'] ?? $requested_set);
        $log_details_safe = mysqli_real_escape_string($conn, $log_details);
        mysqli_query($conn, "INSERT INTO booking_logs (order_id, action, details, created_at) VALUES ('$order_id', 'Menu Change Requested', '$log_details_safe', NOW())");
        mysqli_commit($conn);

        if (function_exists('createNotification')) {
            createNotification(
                intval($order['store_id']),
                'menu_change_request',
                'Menu Change Request',
                'Customer requested to change booking #' . $order['order_number'] . ' from ' . ($current_set !== '' ? $current_set : 'current set') . ' to ' . ($new_set['set_name'] ?? $requested_set),
                'messages.php'
            );
        }

        if (function_exists('notifyPortalAdmins')) {
            notifyPortalAdmins(
                'menu_change_request',
                'Menu Change Request Submitted',
                'A customer requested a menu change for booking #' . $order['order_number'] . '.',
                'reports.php'
            );
        }

        echo json_encode(['success' => true, 'message' => 'Your menu change request was sent to the caterer.']);
    } else {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Failed to send the menu change request. Please try again.']);
    }
    exit();
}

function parseCustomerMenuChangeRequestPayload($message_text) {
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

function getCustomerMenuChangeBadgeHtml($order_id, $customer_id) {
    global $conn;

    $order_id = intval($order_id);
    $customer_id = intval($customer_id);

    $request_q = mysqli_query($conn, "
        SELECT id, created_at
        FROM messages
        WHERE order_id = '$order_id'
          AND sender_id = '$customer_id'
          AND (subject LIKE '%Menu Change Request%' OR message LIKE '%Requested Set:%')
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");

    if (!$request_q || mysqli_num_rows($request_q) === 0) {
        return '';
    }

    $request_row = mysqli_fetch_assoc($request_q);
    $created_at = mysqli_real_escape_string($conn, $request_row['created_at']);

    $approved_q = mysqli_query($conn, "
        SELECT id FROM booking_logs
        WHERE order_id = '$order_id'
          AND action = 'Menu Change Approved'
          AND created_at >= '$created_at'
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $rejected_q = mysqli_query($conn, "
        SELECT id FROM booking_logs
        WHERE order_id = '$order_id'
          AND action = 'Menu Change Rejected'
          AND created_at >= '$created_at'
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");

    $label = 'Pending Caterer Review';
    $style = 'background: rgba(212,175,55,0.14); color: #d4af37; border: 1px solid rgba(212,175,55,0.25);';

    if ($approved_q && mysqli_num_rows($approved_q) > 0) {
        $label = 'Approved Menu Change';
        return '<div style="margin-top:8px;font-size:11px;line-height:1.5;color:#20372b;">' . htmlspecialchars($label) . '</div>';
    } elseif ($rejected_q && mysqli_num_rows($rejected_q) > 0) {
        $label = 'Rejected Menu Change';
        $style = 'background: rgba(239,68,68,0.14); color: #ef4444; border: 1px solid rgba(239,68,68,0.25);';
    }

    return '<div style="margin-top: 8px;"><span style="' . $style . ' display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; border-radius: 999px; font-size: 11px; font-weight: 700;">'
        . '<i class="fas fa-random"></i> ' . htmlspecialchars($label) . '</span></div>';
}

// Handle cancel booking (AJAX request)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
        $order_id = mysqli_real_escape_string($conn, $_POST['order_id']);
        
        // Check if order belongs to user
        $check_query = "SELECT id, order_status FROM bookings WHERE id = '$order_id' AND customer_id = '$user_id'";
        $check_result = mysqli_query($conn, $check_query);
        
        if (mysqli_num_rows($check_result) > 0) {
            $order = mysqli_fetch_assoc($check_result);
            
            $previous_status = strtolower((string) $order['order_status']);
            if (in_array($previous_status, ['pending', 'pending_payment', 'awaiting_store_confirmation'], true)) {
                $cancel_query = "UPDATE bookings SET order_status = 'cancelled'
                                 WHERE id = '$order_id' AND customer_id = '$user_id'
                                   AND LOWER(order_status) = '$previous_status'";
                if (mysqli_query($conn, $cancel_query)) {
                    if (mysqli_affected_rows($conn) !== 1) {
                        echo json_encode(['success' => false, 'message' => 'The booking status has changed. Check the updated booking before trying again.']);
                        exit();
                    }
                    // Log the activity
                    $log_details = "Customer cancelled the booking";
                    mysqli_query($conn, "INSERT INTO booking_logs (order_id, action, details, created_at) VALUES ('$order_id', 'Cancelled', '$log_details', NOW())");
                    
                    echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error cancelling booking.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Only pending bookings can be cancelled.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        }
        exit();
    }

    // Handle delete booking (AJAX request)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_booking'])) {
        $order_id = mysqli_real_escape_string($conn, $_POST['order_id']);
        
        // Check if order belongs to user
        $check_query = "SELECT id, order_status FROM bookings WHERE id = '$order_id' AND customer_id = '$user_id'";
        $check_result = mysqli_query($conn, $check_query);
        
        if (mysqli_num_rows($check_result) > 0) {
            $order = mysqli_fetch_assoc($check_result);
            
            // Only allow deletion for completed, cancelled, or rejected orders
            if (in_array($order['order_status'], ['completed', 'cancelled', 'rejected', 'declined'])) {
                $delete_query = "UPDATE bookings SET is_deleted_by_customer = 1 WHERE id = '$order_id'";
                if (mysqli_query($conn, $delete_query)) {
                    echo json_encode(['success' => true, 'message' => 'Booking moved to trash.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error removing booking.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Only completed or cancelled bookings can be removed.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        }
        exit();
    }

    // Handle restore booking (AJAX request)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_booking'])) {
        $order_id = mysqli_real_escape_string($conn, $_POST['order_id']);
        
        // Check if order belongs to user
        $check_query = "SELECT id FROM bookings WHERE id = '$order_id' AND customer_id = '$user_id'";
        $check_result = mysqli_query($conn, $check_query);
        
        if (mysqli_num_rows($check_result) > 0) {
            $restore_query = "UPDATE bookings SET is_deleted_by_customer = 0 WHERE id = '$order_id'";
            if (mysqli_query($conn, $restore_query)) {
                echo json_encode(['success' => true, 'message' => 'Booking restored.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error restoring booking.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        }
        exit();
    }

    // Handle permanently delete booking (AJAX request)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['permanently_delete_booking'])) {
        $order_id = mysqli_real_escape_string($conn, $_POST['order_id']);

        // Check if order belongs to user and is already in trash
        $check_query = "SELECT id, order_status FROM bookings WHERE id = '$order_id' AND customer_id = '$user_id' AND is_deleted_by_customer = 1";
        $check_result = mysqli_query($conn, $check_query);

        if (mysqli_num_rows($check_result) > 0) {
            // Delete related records first to avoid FK constraint issues
            mysqli_query($conn, "DELETE FROM booking_logs WHERE order_id = '$order_id'");
            mysqli_query($conn, "DELETE FROM messages WHERE order_id = '$order_id'");
            // Permanently delete the booking
            $perm_delete_query = "DELETE FROM bookings WHERE id = '$order_id' AND customer_id = '$user_id'";
            if (mysqli_query($conn, $perm_delete_query)) {
                echo json_encode(['success' => true, 'message' => 'Booking permanently deleted.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error permanently deleting booking.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Booking not found or not in trash.']);
        }
        exit();
    }

    // Handle delete ALL trash bookings (AJAX request)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_trash'])) {
        // Get all trash order IDs for this user
        $trash_ids_q = mysqli_query($conn, "SELECT id FROM bookings WHERE customer_id = '$user_id' AND is_deleted_by_customer = 1");
        if ($trash_ids_q && mysqli_num_rows($trash_ids_q) > 0) {
            $ids = [];
            while ($row = mysqli_fetch_assoc($trash_ids_q)) {
                $ids[] = intval($row['id']);
            }
            $ids_str = implode(',', $ids);
            mysqli_query($conn, "DELETE FROM booking_logs WHERE order_id IN ($ids_str)");
            mysqli_query($conn, "DELETE FROM messages WHERE order_id IN ($ids_str)");
            $del = mysqli_query($conn, "DELETE FROM bookings WHERE id IN ($ids_str) AND customer_id = '$user_id'");
            if ($del) {
                echo json_encode(['success' => true, 'message' => 'All trash bookings permanently deleted.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error deleting all trash bookings.']);
            }
        } else {
            echo json_encode(['success' => true, 'message' => 'Trash is already empty.']);
        }
        exit();
    }

    // Handle get bookings list (AJAX request for real-time updates)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_bookings_list'])) {
$view = isset($_GET['view']) ? $_GET['view'] : 'all';
$status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';
$new_booking = isset($_GET['new_booking']) && $_GET['new_booking'] == '1';
$new_booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$page_notice = '';
$page_notice_type = 'success';

if ($new_booking && $new_booking_id > 0) {
    $page_notice = "";
}

$deleted_filter = ($view === 'trash') ? 'o.is_deleted_by_customer = 1' : '(o.is_deleted_by_customer = 0 OR o.is_deleted_by_customer IS NULL)';
$status_sql = getCustomerBookingStatusSql($status_filter);
$search_sql = '';
if ($search !== '') {
        $search_sql = " AND (
            o.order_number LIKE '%$search%' OR
            COALESCE(u.business_name, '') LIKE '%$search%' OR
            COALESCE(p.name, '') LIKE '%$search%'
        )";
    }

    $bookings_query = "
        SELECT o.*, u.business_name, u.profile_image, u.is_open as store_is_open, u.business_open_time as store_open_time, u.business_close_time as store_close_time, u.auto_schedule_enabled as store_auto_schedule_enabled, p.name as product_name, p.category,
        (SELECT COUNT(*) FROM booking_damages od WHERE od.order_id = o.id) as damage_count,
        (SELECT id FROM reviews r WHERE r.order_id = o.id LIMIT 1) as review_id
        FROM bookings o 
        LEFT JOIN (
            SELECT u.id, u.profile_image, sd.business_name, sd.is_open, sd.business_open_time, sd.business_close_time, sd.auto_schedule_enabled
            FROM users u 
            LEFT JOIN store_details sd ON u.id = sd.user_id 
            WHERE u.role IN ('caterer','store')
        ) u ON o.store_id = u.id 
        LEFT JOIN products p ON o.product_id = p.id 
    WHERE o.customer_id = '$user_id' AND $deleted_filter $status_sql $search_sql
    GROUP BY o.id
    ORDER BY o.id DESC
";
    $bookings_result = mysqli_query($conn, $bookings_query);
    
    $grouped_bookings = groupCustomerBookings($bookings_result);
    if ($grouped_bookings) {
        foreach ($grouped_bookings as $store_id => $group) {
            if ($store_id === 'fully-paid') {
                echo renderFullyPaidBookingsHeader();
            } else {
                echo '<tr class="caterer-group-header" onclick="toggleCatererOrders(' . $store_id . ')" style="cursor: pointer; background: rgba(212,175,55,0.1);">';
                echo '<td colspan="7" style="border: none; padding: 15px;">';
                echo renderBookingCatererProfile($group);
                echo '</td>';
                echo '</tr>';
            }

            foreach ($group['bookings'] as $booking) {
                $normalized_status = normalizeCustomerBookingStatus($booking['order_status']);
                $status_class = getCustomerBookingStatusClass($booking['order_status']);
                $status_text = getCustomerBookingStatusLabel($booking['order_status']);
                
                $fin = $booking['financials'];
                $grand_total = (float) ($fin['totals']['grand_total'] ?? 0);
                $total_paid = (float) ($fin['totals']['total_paid'] ?? 0);

            $balance = (float) ($fin['totals']['balance'] ?? max(0, $grand_total - $total_paid));
            $down_payment_required = (float) ($fin['down_payment_required'] ?? 0);
            $is_amount_missing = ($grand_total <= 0 && $total_paid <= 0);
            $booking_created_at = !empty($booking['created_at']) ? strtotime($booking['created_at']) : null;
            $booking_created_html = $booking_created_at
                ? '<br><small style="color: var(--text-muted);">Booked On: ' . date('M d, Y', $booking_created_at) . ' ' . date('h:i A', $booking_created_at) . '</small>'
                : '';
            
            echo '<tr class="caterer-order-' . $store_id . '" style="display: none;">';
            echo '<td><strong>' . formatCatererBookingNumber($booking['id'], $booking['order_number'], $booking['store_id']) . '</strong></td>';
            echo '<td>' . ($store_id === 'fully-paid' ? renderBookingCatererProfile($booking) : htmlspecialchars($booking['business_name'])) . '</td>';
            $event_type_html = !empty($booking['event_type']) ? '<br><small style="color: var(--text-muted);">Event: <strong>' . htmlspecialchars($booking['event_type']) . '</strong></small>' : '';
            echo '<td>' . htmlspecialchars($booking['package_name'] ?: ($booking['product_name'] ?: 'Catering Service')) . $event_type_html . '</td>';
            
            $time = !empty($booking['order_time']) ? date('h:i A', strtotime($booking['order_time'])) : (!empty($booking['delivery_time']) ? date('h:i A', strtotime($booking['delivery_time'])) : 'N/A');
            echo '<td>' . date('M d, Y', strtotime($booking['order_date'])) . '<br><small style="color: var(--text-muted);">' . $time . '</small>' . $booking_created_html . renderBookingEventDateLabel($booking) . '</td>';
            
            $amount_meta_color = $is_amount_missing ? '#f1c40f' : ($balance > 0 ? '#e74c3c' : '#2ecc71');
            $amount_meta_text = $is_amount_missing ? 'Pending Amount' : ($balance > 0 ? 'Bal: &#8369;' . number_format($balance, 2) : 'Fully Paid');

            echo '<td>
                <div style="font-weight: bold; font-size: 15px;">&#8369;' . number_format((float) ($booking['total_amount'] ?? 0), 2) . '</div>
                <div style="font-size: 12px; color: var(--text-muted);">Down Payment: &#8369;' . number_format($down_payment_required, 2) . '</div>
                ' . ($total_paid > 0 ? '<div style="font-size: 12px; color: #2ecc71;">Advanced Payment: &#8369;' . number_format($total_paid, 2) . '</div>' : '') . '
                <div style="font-size: 12px; font-weight: bold; color: ' . $amount_meta_color . ';">' . $amount_meta_text . '</div>
            </td>';
            
            $menu_change_badge = getCustomerMenuChangeBadgeHtml($booking['id'], $user_id);
            echo '<td><span class="status-badge ' . $status_class . '">' . $status_text . '</span>' . $menu_change_badge . renderBookingPaymentNotice($booking, $fin ?: []) . '</td>';
            
            echo '<td><div class="booking-actions">';
            echo '<a href="booking-details.php?id=' . $booking['id'] . '" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; text-decoration: none;"><i class="fas fa-eye"></i> View</a>';
            
            if ($booking['damage_count'] > 0) {
                 echo '<a href="booking-details.php?id=' . $booking['id'] . '" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; text-decoration: none; color: #e74c3c; border-color: #e74c3c; display: inline-flex; align-items: center; justify-content: center;"><i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i> Damages</a>';
            }
            
            // Cancel Button for Pending Bookings
            if ($normalized_status === 'pending') {
                echo '<button onclick="cancelBooking(' . $booking['id'] . ')" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; color: #e74c3c; border-color: #e74c3c;" title="Cancel Booking"><i class="fas fa-times"></i></button>';
            }

            if ($normalized_status !== 'cancelled') {
                echo '<button onclick="openMessageModal(' . $booking['id'] . ', \'' . addslashes($booking['business_name']) . '\', ' . $booking['store_id'] . ', \'' . formatCustomerBookingNumber($booking['id'], $booking['order_number']) . '\')" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;"><i class="fas fa-envelope"></i></button>';
            }
            
            // Review Button
            if ($normalized_status === 'completed') {
                if ($booking['review_id']) {
                    echo '<button class="btn btn-success" style="padding: 5px 10px; font-size: 12px; cursor: default; background-color: #2ecc71; border-color: #2ecc71; color: #fff;" title="Reviewed"><i class="fas fa-star"></i></button>';
                } else {
                    echo '<button onclick="openReviewModal(' . $booking['id'] . ', ' . $booking['store_id'] . ')" class="btn btn-warning" style="padding: 5px 10px; font-size: 12px; background-color: #f1c40f; border-color: #f1c40f; color: #000;" title="Write a Review"><i class="far fa-star"></i></button>';
                }
            }

            if (!empty($booking['product_id']) && !empty($booking['store_id']) && in_array($normalized_status, ['completed', 'cancelled'], true)) {
                if (isStoreAvailableNow($booking['store_is_open'] ?? 0, $booking['store_open_time'] ?? null, $booking['store_close_time'] ?? null, $booking['store_auto_schedule_enabled'] ?? 0)) {
                    echo '<a href="book-service.php?product_id=' . intval($booking['product_id']) . '&store_id=' . intval($booking['store_id']) . '" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; text-decoration: none; color: var(--accent-gold); border-color: var(--accent-gold);" data-book-again-id="' . (int) $booking['id'] . '" title="Book Again"><i class="fas fa-rotate-right"></i></a>';
                } else {
                    echo '<button class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; color: #f0b4b4; border-color: #6b3b3b; background: rgba(231, 76, 60, 0.08); cursor: not-allowed;" title="Caterer Closed" disabled><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="display:inline-block;vertical-align:middle;flex-shrink:0;"><path d="M3 3l18 18M4 10v10h16v-4M4 4h16l2 6h-8M2 10h5M9 20v-6h5"/></svg></button>';
                }
            }

            echo '<a href="booking-receipt.php?id=' . intval($booking['id']) . '" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; text-decoration: none;" title="Print Receipt"><i class="fas fa-print"></i></a>';
            
            // Delete/Trash Button
            if ($view === 'trash') {
                 echo '<button onclick="restoreBooking(' . $booking['id'] . ')" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; color: #2ecc71; border-color: #2ecc71;" title="Restore to list"><i class="fas fa-undo"></i></button>';
                 echo '<button onclick="permanentlyDeleteBooking(' . $booking['id'] . ')" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; color: #ff4444; border-color: #ff4444; background: rgba(255,68,68,0.08);" title="Delete Permanently"><i class="fas fa-trash-alt"></i></button>';
            } elseif (in_array($normalized_status, ['completed', 'cancelled'], true)) {
                 echo '<button onclick="deleteBooking(' . $booking['id'] . ')" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; color: #e74c3c; border-color: #e74c3c;" title="Move to Trash"><i class="fas fa-trash"></i></button>';
            }
            
            echo '</div></td>';
            echo '</tr>';
            }
        }
    } else {
        echo '<tr class="empty-bookings-row">
            <td colspan="7" style="text-align:center; padding: 20px; border:none; background:transparent;">
                <div class="empty-state" style="border:none; padding:40px 0;">
                    <i class="fas fa-calendar-times"></i>
                    <p>No bookings found.</p>
                    <a href="browse-caterer.php" class="btn btn-primary" style="margin-top: 15px;">Browse Caterers</a>
                </div>
            </td>
        </tr>';
    }
    exit();
}

// Handle get damages details (AJAX request)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_damages'])) {
    $booking_id = mysqli_real_escape_string($conn, $_GET['booking_id']);
    
    // Fetch Damages
    $damages_query = "SELECT * FROM booking_damages WHERE order_id = '$booking_id'";
    $damages_result = mysqli_query($conn, $damages_query);
    $damages = [];
    $total_damage_fee = 0;
    
    if ($damages_result) {
        while ($row = mysqli_fetch_assoc($damages_result)) {
            $damages[] = $row;
            $total_damage_fee += $row['total'];
        }
    }
    
    // Fetch Booking Payment Status
    $order_query = "SELECT damage_payment_status, damage_payment_proof FROM bookings WHERE id = '$booking_id'";
    $order_res = mysqli_query($conn, $order_query);
    $order_data = mysqli_fetch_assoc($order_res);
    $damage_status = $order_data['damage_payment_status'] ?? 'pending';
    
    // Build HTML
    $html = '';
    
    if (empty($damages)) {
        $html .= '<p style="text-align: center; color: #aaa;">No damages reported.</p>';
    } else {
        $html .= '
        <div style="padding: 10px;">
            <table style="width: 100%; border-collapse: collapse; color: #fff; font-size: 14px;">
                <thead>
                    <tr style="border-bottom: 1px solid #444; color: #e74c3c;">
                        <th style="text-align: left; padding: 10px;">Item</th>
                        <th style="text-align: center; padding: 10px;">Qty</th>
                        <th style="text-align: right; padding: 10px;">Price</th>
                        <th style="text-align: right; padding: 10px;">Total</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($damages as $damage) {
            $html .= '
                <tr style="border-bottom: 1px solid #333;">
                    <td style="padding: 10px;">
                        <div style="font-weight: 500;">' . htmlspecialchars($damage['item_name']) . '</div>
                        ' . ($damage['damage_photo'] ? '<div style="margin-top: 5px;"><a href="../' . $damage['damage_photo'] . '" target="_blank" style="color: #d4af37; font-size: 11px;"><i class="fas fa-image"></i> View Photo</a></div>' : '') . '
                    </td>
                    <td style="text-align: center; padding: 10px;">' . $damage['quantity'] . '</td>
                    <td style="text-align: right; padding: 10px;">&#8369;' . number_format($damage['price'], 2) . '</td>
                    <td style="text-align: right; padding: 10px; font-weight: bold; color: #e74c3c;">&#8369;' . number_format($damage['total'], 2) . '</td>
                </tr>';
        }
        
        $html .= '
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align: right; padding: 15px 10px; font-weight: bold;">Total Damage Fee:</td>
                        <td style="text-align: right; padding: 15px 10px; font-weight: bold; color: #e74c3c; font-size: 18px;">&#8369;' . number_format($total_damage_fee, 2) . '</td>
                    </tr>
                </tfoot>
            </table>
            
            <div style="margin-top: 20px; padding: 15px; background: rgba(255,255,255,0.05); border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <span style="color: #aaa;">Payment Status:</span>
                    <span style="font-weight: bold; color: ' . ($damage_status == 'paid' ? '#2ecc71' : '#f39c12') . '; text-transform: uppercase;">' . ($damage_status == 'paid' ? 'PAID' : 'PENDING PAYMENT') . '</span>
                </div>
                
                ' . ($damage_status != 'paid' ? '
                <div style="text-align: center;">
                    <a href="booking-details.php?id=' . $booking_id . '" class="btn btn-primary" style="background: #e74c3c; border: none; width: 100%;">
                        <i class="fas fa-credit-card"></i> Pay / Upload Proof
                    </a>
                    <p style="margin-top: 10px; font-size: 12px; color: #aaa;">Click to go to payment page</p>
                </div>
                ' : '
                <div style="text-align: center; color: #2ecc71;">
                    <i class="fas fa-check-circle" style="font-size: 24px;"></i>
                    <p>Damages fully paid. Thank you!</p>
                </div>
                ') . '
            </div>
        </div>';
    }
    
    echo json_encode(['success' => true, 'html' => $html]);
    exit();
}

// ==============================================
// HANDLE GET BOOKING DETAILS (AJAX REQUEST)
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_booking_details'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $booking_id = mysqli_real_escape_string($conn, $_GET['booking_id']);
    
    // Fetch complete booking details
    $details_query = "
        SELECT 
            o.*,
            u.business_name as store_name,
            p.name as product_name,
            p.description as product_description,
            p.category,
            c.first_name,
            c.last_name,
            c.email,
            c.phone_number as customer_phone,
            c.address as customer_address
        FROM bookings o
        LEFT JOIN (
            SELECT u.id, sd.business_name 
            FROM users u 
            LEFT JOIN store_details sd ON u.id = sd.user_id 
            WHERE u.role IN ('caterer','store')
        ) u ON o.store_id = u.id
        LEFT JOIN products p ON o.product_id = p.id
        LEFT JOIN users c ON o.customer_id = c.id
        WHERE o.id = '$booking_id' AND o.customer_id = '$user_id'
    ";
    
    $details_result = mysqli_query($conn, $details_query);
    
    if ($details_result && mysqli_num_rows($details_result) > 0) {
        $booking = mysqli_fetch_assoc($details_result);

        // Get Financials (Centralized)
        $fin = getOrderFinancials($booking_id);
        
        // Correctly access data from nested structure
        $grand_total = $fin['totals']['grand_total'] ?? 0;
        $total_paid = $fin['totals']['total_paid'] ?? 0;
        $remaining = $fin['totals']['balance'] ?? 0;
        
        // Down Payment comes from the centralized financial summary.
        $package_total = $fin['breakdown']['package_total'] ?? 0;
        $down_payment_required = $fin['down_payment_required'] ?? 0;
        
        // Prepare fee_breakdown for display
        $fee_breakdown = [
            'base_package' => ['total' => $fin['breakdown']['base_price'] ?? 0],
            'addon' => ['total' => $fin['breakdown']['addons'] ?? 0],
            'delivery_fee' => ['total' => 0], // Not currently in financial_helper, assume 0 or need to fetch
            'admin_fee' => ['total' => $fin['breakdown']['extra_fee'] ?? 0],
            'discount' => ['total' => 0], // Not in helper
            'grand_total' => $grand_total
        ];
        
        // Progress
        $progress_percentage = ($grand_total > 0) ? min(100, ($total_paid / $grand_total) * 100) : 0;
        
        // Get order timeline (Keep existing helper or query if available, assuming helpers exist in another file or need to be brought in)
        // Since getOrderTimeline was in payment_functions.php (likely), we might need to recreate it or include it.
        // Let's assume getOrderTimeline is NOT in financial_helper.php. 
        // We should check where getOrderTimeline is defined. 
        // If it was in payment_functions.php, we need to copy it or include payment_functions.php as well but NOT use its financial logic.
        // For now, let's assume it's missing and implement a simple one here or check if we can include payment_functions.php just for timeline.
        // Actually, let's just implement a simple timeline logic here to be safe and independent.
        
        $timeline = [
            'steps' => [
                'pending' => ['status' => 'current', 'label' => 'Pending', 'icon' => 'fa-hourglass-half', 'description' => 'Payment and booking review are still pending'],
                'confirmed' => ['status' => '', 'label' => 'Confirmed', 'icon' => 'fa-check', 'description' => 'Caterer accepted your booking'],
                'preparing' => ['status' => '', 'label' => 'Preparing', 'icon' => 'fa-utensils', 'description' => 'Your booking is being prepared'],
                'delivering' => ['status' => '', 'label' => 'Ongoing', 'icon' => 'fa-calendar-day', 'description' => 'Your event is ongoing or the team is already on-site'],
                'completed' => ['status' => '', 'label' => 'Completed', 'icon' => 'fa-check-circle', 'description' => 'Booking finished successfully']
            ]
        ];
        
        $status_order = ['pending', 'confirmed', 'preparing', 'delivering', 'completed'];
        
        // Fix: Map 'approved' status to 'confirmed' step correctly
        $current_status = strtolower(trim($booking['order_status']));
        
        if ($current_status === 'approved') {
            $current_status = 'confirmed';
        } elseif ($current_status === 'ready') {
            $current_status = 'delivering';
        }
        
        $found = false;
        foreach ($timeline['steps'] as $key => &$step) {
            // Handle Cancelled: Show everything as inactive/gray except maybe first? 
            // Or just don't mark anything as completed/current if cancelled
            if ($current_status === 'cancelled') {
                $step['status'] = ''; // Reset all
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
        
        // If cancelled, maybe mark the whole timeline as such or just leave it empty
        if ($current_status === 'cancelled') {
            // Optional: You could set a specific class for cancelled state
             $timeline['steps']['pending']['status'] = 'completed'; // Show pending as done? Or just nothing.
             // For now, let's leave them empty to indicate "stopped"
        }

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
                $next_action_text = 'Your booking is being prepared. Keep your phone available in case the caterer contacts you.';
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
        
        // Get activity log (Simple fetch)
        $activity_log = [];
        $menu_change_history = [];
        $log_query = "SELECT * FROM booking_logs WHERE order_id = '$booking_id' ORDER BY created_at DESC";
        $log_res = mysqli_query($conn, $log_query);
        if($log_res) {
            while($l = mysqli_fetch_assoc($log_res)) {
                $activity_log[] = [
                    'action' => $l['action'],
                    'details' => $l['details'],
                    'created_at' => $l['created_at']
                ];
                if (in_array($l['action'], ['Menu Change Requested', 'Menu Change Approved', 'Menu Change Rejected'], true)) {
                    $menu_change_history[] = [
                        'action' => $l['action'],
                        'details' => $l['details'],
                        'created_at' => $l['created_at']
                    ];
                }
            }
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
            $menu_change_request = parseCustomerMenuChangeRequestPayload($menu_request_row['message'] ?? '');
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

        // Build HTML response with Dark Theme compatible styles
        $html = '
            <div class="booking-progress-card" style="margin-bottom: 25px; padding: 20px; background: #2a2a2a; border-radius: 8px;">
            <h4 style="color: #d4af37; margin-bottom: 20px;"><i class="fas fa-stream"></i> Booking Progress</h4>
            <div class="order-timeline">
                <div class="timeline-step ' . $timeline['steps']['pending']['status'] . '">
                    <div class="step-icon"><i class="fas ' . $timeline['steps']['pending']['icon'] . '"></i></div>
                    <div class="step-label">' . $timeline['steps']['pending']['label'] . '</div>
                    <div class="step-description">' . $timeline['steps']['pending']['description'] . '</div>
                </div>
                <div class="timeline-step ' . $timeline['steps']['confirmed']['status'] . '">
                    <div class="step-icon"><i class="fas ' . $timeline['steps']['confirmed']['icon'] . '"></i></div>
                    <div class="step-label">' . $timeline['steps']['confirmed']['label'] . '</div>
                    <div class="step-description">' . $timeline['steps']['confirmed']['description'] . '</div>
                </div>
                <div class="timeline-step ' . $timeline['steps']['preparing']['status'] . '">
                    <div class="step-icon"><i class="fas ' . $timeline['steps']['preparing']['icon'] . '"></i></div>
                    <div class="step-label">' . $timeline['steps']['preparing']['label'] . '</div>
                    <div class="step-description">' . $timeline['steps']['preparing']['description'] . '</div>
                </div>
                <div class="timeline-step ' . $timeline['steps']['delivering']['status'] . '">
                    <div class="step-icon"><i class="fas ' . $timeline['steps']['delivering']['icon'] . '"></i></div>
                    <div class="step-label">' . $timeline['steps']['delivering']['label'] . '</div>
                    <div class="step-description">' . $timeline['steps']['delivering']['description'] . '</div>
                </div>
                <div class="timeline-step ' . $timeline['steps']['completed']['status'] . '">
                    <div class="step-icon"><i class="fas ' . $timeline['steps']['completed']['icon'] . '"></i></div>
                    <div class="step-label">' . $timeline['steps']['completed']['label'] . '</div>
                    <div class="step-description">' . $timeline['steps']['completed']['description'] . '</div>
                </div>
            </div>
        </div>

        <!-- Financial Status (Simplified) -->
        <div class="booking-financial-card" style="margin-bottom: 25px; padding: 20px; background: #2a2a2a; border-radius: 8px; text-align: center;">
            <h4 style="color: #d4af37; margin-bottom: 15px;"><i class="fas fa-wallet"></i> Financial Status</h4>
            
            <div class="booking-financial-metrics" style="display: flex; justify-content: space-around; margin-bottom: 20px;">
                <div>
                    <div style="font-size: 12px; color: #a0a0a0;">Grand Total</div>
                    <div style="font-size: 20px; font-weight: bold; color: #d4af37;">&#8369;' . number_format($grand_total, 2) . '</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #a0a0a0;">Status</div>
                    <div class="status-badge status-' . ($fin['status'] == 'paid' ? 'completed' : ($fin['status'] == 'partial' ? 'confirmed' : 'pending')) . '">' . ucfirst($fin['status']) . '</div>
                </div>
            </div>

            ' . (in_array(strtolower($booking['order_status']), ['confirmed', 'preparing', 'delivering', 'completed']) ? '
            <a href="payments.php?id=' . $booking_id . '" class="btn btn-primary" style="display: block; width: 100%; text-align: center; text-decoration: none;">
                <i class="fas fa-file-invoice-dollar"></i> View Payment Details & Pay
            </a>
            ' : '') . '
        </div>

        ' . ($menu_change_request && $menu_change_status ? '
        <div style="margin-bottom: 25px; padding: 16px; background: ' . ($menu_change_status === 'approved' ? 'rgba(46, 204, 113, 0.1)' : ($menu_change_status === 'rejected' ? 'rgba(239, 68, 68, 0.1)' : 'rgba(212, 175, 55, 0.1)')) . '; border: 1px solid ' . ($menu_change_status === 'approved' ? 'rgba(46, 204, 113, 0.2)' : ($menu_change_status === 'rejected' ? 'rgba(239, 68, 68, 0.2)' : 'rgba(212, 175, 55, 0.2)')) . '; border-radius: 8px;">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px;">
                <strong style="color: ' . ($menu_change_status === 'approved' ? '#2ecc71' : ($menu_change_status === 'rejected' ? '#ef4444' : '#d4af37')) . ';"><i class="fas fa-random"></i> Menu Change ' . ucfirst($menu_change_status) . '</strong>
                <span style="font-size: 12px; color: #a0a0a0;">' . htmlspecialchars($menu_change_status_text) . '</span>
            </div>
            <div style="display: grid; gap: 6px; color: #fff; font-size: 13px;">
                <div>Current Set: <strong>' . htmlspecialchars($menu_change_request['current_set'] ?: 'Not specified') . '</strong></div>
                <div>Requested Set: <strong style="color: #d4af37;">' . htmlspecialchars($menu_change_request['requested_set']) . '</strong></div>
                <div>Already Paid: <strong>PHP ' . number_format($menu_change_request['already_paid'], 2) . '</strong></div>
                <div>Additional Needed: <strong>PHP ' . number_format($menu_change_request['additional_needed'], 2) . '</strong></div>
            </div>
            ' . ($menu_change_status === 'rejected' && $menu_change_reject_reason !== '' ? '<div style="margin-top: 10px; font-size: 12px; color: #ef4444;"><i class="fas fa-comment-dots"></i> Reason: ' . htmlspecialchars($menu_change_reject_reason) . '</div>' : '') . '
        </div>
        ' : '') . '

        <!-- Quick Actions -->
        ' . (function($booking_id, $order_date, $store_id, $product_id, $category, $service_type, $current_set_name, $current_base_total, $total_paid, $pax, $menu_change_status) use ($conn) {
            $event_timestamp = strtotime($order_date);
            $days_diff = ceil(($event_timestamp - time()) / (60 * 60 * 24));
            $is_full_menu_change_allowed = $days_diff > 5;
            $is_add_only_window = $days_diff > 0 && $days_diff <= 5;
            $available_sets = [];
            $product_id = intval($product_id);

            if ($product_id > 0) {
                $sets_query = mysqli_query($conn, "SELECT * FROM product_sets WHERE product_id = '$product_id' ORDER BY price ASC, id ASC");
                while ($sets_query && $set_row = mysqli_fetch_assoc($sets_query)) {
                    $available_sets[] = $set_row;
                }
            }

            $current_set_name = trim((string) $current_set_name);
            if ($current_set_name === '' && !empty($available_sets[0]['set_name'])) {
                $current_set_name = trim((string) $available_sets[0]['set_name']);
            }

            $html = '
            <div style="margin-bottom: 25px; padding: 20px; background: #2a2a2a; border-radius: 8px;">
                <h4 style="color: #d4af37; margin-bottom: 15px;"><i class="fas fa-mouse-pointer"></i> Actions</h4>
                <div style="display: grid; gap: 10px;">';

            $html .= '
                    <a href="messages.php?order_id=' . $booking_id . '" class="btn btn-primary" style="display: block; width: 100%; text-align: center; text-decoration: none;">
                        <i class="fas fa-comments"></i> Message Caterer
                    </a>';

            if ($is_add_only_window) {
                $html .= '
                    <a href="book-service.php?product_id=' . intval($product_id) . '&store_id=' . intval($store_id) . '&set=' . urlencode($current_set_name) . '#addons-section" class="btn btn-secondary" style="display: block; width: 100%; text-align: center; text-decoration: none; border: 1px solid #f59e0b; color: #f59e0b; background: transparent;">
                        <i class="fas fa-plus-circle"></i> Request Add-ons Only
                    </a>';
            }

            $html .= '
                </div>
            </div>';

            if ($is_full_menu_change_allowed && !empty($available_sets)) {
                $html .= '
                <div style="margin-bottom: 25px; padding: 20px; background: #2a2a2a; border-radius: 12px; border: 1px solid #3a3a3a;">
                    <h4 style="color: #d4af37; margin-bottom: 15px;"><i class="fas fa-layer-group"></i> Change Menu</h4>
                    <div style="margin-bottom: 12px; color: #a0a0a0; font-size: 13px; line-height: 1.6;">
                        Choose the set menu you want to replace your current set with. The caterer will review your request before anything is finalized.
                    </div>
                    <div style="margin-bottom: 12px; color: #fff;">
                        <strong>Service Type:</strong> <span style="color: #d4af37;">' . htmlspecialchars($service_type ?: ucwords(str_replace('_', ' ', (string) $category))) . '</span>
                    </div>
                    <div style="margin-bottom: 12px; color: #fff;">
                        <strong>Current Set:</strong> <span style="color: #d4af37;">' . htmlspecialchars($current_set_name ?: 'Not specified') . '</span>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="toggleInlineSetMenus(event, ' . $booking_id . ')" style="display: block; width: 100%; text-align: center; text-decoration: none; border: 1px solid #d4af37; color: #d4af37; background: transparent; margin-bottom: 12px; border-radius: 10px;">
                        <i class="fas fa-random"></i> Change Menu
                    </button>
                    <div id="set-menu-list-' . $booking_id . '" style="display: none; border: 1px solid #3a3a3a; border-radius: 12px; overflow: hidden; padding: 14px; background: rgba(255,255,255,0.015);">';

                $html .= '
                    <div style="display: flex; justify-content: flex-end; margin-bottom: 12px;">
                        <button type="button" class="btn btn-secondary" onclick="closeInlineSetMenus(event, ' . $booking_id . ')" style="padding: 8px 12px; border: 1px solid #5a5a5a; color: #d4d4d4; background: transparent; border-radius: 10px;">
                            <i class="fas fa-times"></i> Close Menu List
                        </button>
                    </div>
                    <div style="margin-bottom: 12px; color: #a0a0a0; font-size: 13px;">
                        Choose the set menu you want to replace your current set with. The caterer will review your request before anything is finalized.
                    </div>
                    <div style="display: grid; gap: 12px; margin-bottom: 14px;">
                        <div>
                            <label style="display: block; margin-bottom: 6px; color: #a0a0a0; font-size: 12px;">Current Set</label>
                            <input type="text" value="' . htmlspecialchars($current_set_name ?: 'Not specified') . '" readonly style="width: 100%; padding: 12px 12px; border-radius: 8px; border: 1px solid #3a3a3a; background: #161616; color: #d4af37; font-weight: 600;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 6px; color: #a0a0a0; font-size: 12px;">Replace With</label>
                            <select id="requested-set-' . $booking_id . '" data-current-base="' . htmlspecialchars((string) $current_base_total) . '" data-total-paid="' . htmlspecialchars((string) $total_paid) . '" data-current-pax="' . intval($pax) . '" onchange="updateRequestedSetInfo(' . $booking_id . ')" style="width: 100%; padding: 12px 12px; border-radius: 8px; border: 1px solid #3a3a3a; background: #161616; color: #fff;">
                                <option value="">Select a new set menu</option>';

                foreach ($available_sets as $available_set) {
                    $available_set_name = trim((string) ($available_set['set_name'] ?? ''));
                    if ($available_set_name === '') {
                        continue;
                    }
                    $is_current_set = trim($available_set_name) === trim((string) $current_set_name);
                    if ($is_current_set) {
                        continue;
                    }
                    $html .= '<option value="' . htmlspecialchars($available_set_name) . '" data-price="' . htmlspecialchars((string) ($available_set['price'] ?? 0)) . '" data-pax="' . htmlspecialchars((string) intval($available_set['pax'] ?? 0)) . '">' . htmlspecialchars($available_set_name) . ' - &#8369;' . number_format((float) ($available_set['price'] ?? 0), 2) . '</option>';
                }

                $html .= '
                            </select>
                        </div>
                    </div>
                    <div id="requested-set-info-' . $booking_id . '" style="display: none; margin-bottom: 14px; padding: 12px; border-radius: 10px; background: rgba(212,175,55,0.08); border: 1px solid rgba(212,175,55,0.2); color: #fff;"></div>
                    ' . ($menu_change_status === 'pending' ? '
                    <div style="margin-bottom: 14px; padding: 12px; border-radius: 8px; background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.2); color: #f5c542; font-size: 13px;">
                        <i class="fas fa-hourglass-half"></i> You already have a pending menu change request. Wait for the caterer review before sending another one.
                    </div>
                    <button type="button" class="btn btn-secondary" style="display: block; width: 100%; text-align: center; text-decoration: none; opacity: 0.6; cursor: not-allowed; border-radius: 10px;" disabled>
                        <i class="fas fa-paper-plane"></i> Request Already Pending
                    </button>
                    ' : '
                    <button type="button" onclick="submitMenuChangeRequest(' . $booking_id . ', ' . intval($store_id) . ')" class="btn btn-primary" style="display: block; width: 100%; text-align: center; text-decoration: none; border-radius: 10px;">
                        <i class="fas fa-paper-plane"></i> Send Change Menu Request
                    </button>
                    ') . '
                    <div style="margin-top: 10px; font-size: 12px; color: #a0a0a0;">Pricing flow: the estimated set price updates here, but the final total will still be reviewed with your existing add-ons, swaps, and caterer approval.</div>
                    <div style="margin-top: 16px; border-top: 1px solid #3a3a3a; padding-top: 14px;">';

                foreach ($available_sets as $available_set) {
                    $available_set_name = trim((string) ($available_set['set_name'] ?? ''));
                    if ($available_set_name === '') {
                        continue;
                    }
                    $is_current_set = trim($available_set_name) === trim((string) $current_set_name);
                    $html .= '
                        <div style="padding:14px;border-bottom:1px solid #a9b6a1;background:' . ($is_current_set ? '#c0ceb7' : '#d4dbcd') . ';">
                            <div style="display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px;">
                                <div style="font-weight:700;color:#173d32;min-width:0;overflow-wrap:anywhere;">
                                    ' . htmlspecialchars($available_set_name) . ($is_current_set ? ' <span style="display:inline-block;font-size:11px;margin-left:8px;padding:3px 8px;border-radius:999px;background:#173d32;color:#f0f1e6;">Current Set</span>' : '') . '
                                </div>
                                <div style="color:#173d32;font-weight:700;white-space:nowrap;">&#8369;' . number_format((float) ($available_set['price'] ?? 0), 2) . '</div>
                            </div>
                            ' . (!empty($available_set['pax']) ? '<div style="font-size: 12px; color: #a0a0a0; margin-bottom: 6px;">Good for ' . htmlspecialchars((string) $available_set['pax']) . ' person</div>' : '') . '
                            ' . (function($desc) {
                                if (empty($desc)) return "";
                                $parsed = bookingPricingParseSetDescription($desc);
                                $out = '<div style="font-size: 12px; color: #bdbdbd; line-height: 1.5; margin-top: 8px;">';
                                if (!empty($parsed['groups'])) {
                                    foreach ($parsed['groups'] as $group) {
                                        $out .= '<div style="margin-bottom: 4px;">';
                                        $out .= '<strong style="color: #a0a0a0;">' . htmlspecialchars($group['label']) . ':</strong> ';
                                        $out .= htmlspecialchars(implode(', ', $group['items']));
                                        $out .= '</div>';
                                    }
                                } elseif (!empty($parsed['items'])) {
                                    $out .= htmlspecialchars(implode(', ', $parsed['items']));
                                } else {
                                    $out .= nl2br(htmlspecialchars((string)$desc));
                                }
                                $out .= '</div>';
                                return $out;
                            })($available_set['description'] ?? '') . '
                        </div>';
                }

                $html .= '
                    </div>
                    </div>
                </div>';
            }

            return $html;
        })($booking_id, $booking['order_date'], $booking['store_id'], $booking['product_id'] ?? 0, $booking['category'] ?? '', $booking['product_name'] ?? '', $setName ?? '', $fin['breakdown']['base_price'] ?? 0, $fin['totals']['total_paid'] ?? 0, $booking['number_of_pax'] ?? 1, $menu_change_status) . '
        
        ' . (!empty($menu_change_history) ? '
        <div style="margin-bottom: 25px; padding: 20px; background: #2a2a2a; border-radius: 8px;">
            <h4 style="color: #d4af37; margin-bottom: 15px;"><i class="fas fa-history"></i> Menu Change History</h4>
            <div style="display: grid; gap: 12px;">
                ' . implode('', array_map(function($entry) {
                    $entryColor = '#d4af37';
                    if (($entry['action'] ?? '') === 'Menu Change Approved') $entryColor = '#2ecc71';
                    if (($entry['action'] ?? '') === 'Menu Change Rejected') $entryColor = '#ef4444';
                    return '<div style="padding: 14px; border: 1px solid #3a3a3a; border-radius: 10px; background: rgba(255,255,255,0.02);">
                        <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 6px;">
                            <strong style="color: ' . $entryColor . ';">' . htmlspecialchars($entry['action'] ?? '') . '</strong>
                            <span style="font-size: 12px; color: #a0a0a0;">' . date('M d, Y h:i A', strtotime($entry['created_at'] ?? 'now')) . '</span>
                        </div>
                        <div style="font-size: 13px; color: #fff; line-height: 1.6;">' . nl2br(htmlspecialchars($entry['details'] ?? '')) . '</div>
                    </div>';
                }, $menu_change_history)) . '
            </div>
        </div>
        ' : '') . '

        <!-- Menu Details Breakdown (NEW) -->
        <div class="booking-menu-summary" style="margin-bottom: 25px; padding: 20px; background: #2a2a2a; border-radius: 8px;">
            <h4 style="color: #d4af37; margin-bottom: 15px;"><i class="fas fa-utensils"></i> Menu Details</h4>';
            
            // Parse Menu Logic
            $raw_menu = $booking['additional_menu'] ?? '';
            
            // ... (Menu logic omitted for brevity) ...
            
            // Damage Report Section REMOVED (Moved to separate page)
            
            if (empty($raw_menu)) {
                $html .= '<p style="color: #a0a0a0; font-style: italic;">No specific menu details recorded for this booking.</p>';
            } else {
                $parts = explode(' | ', $raw_menu);
                $setName = trim($parts[0]);
                
                $swaps = [];
                $addons = [];
                $buildMenuItems = [];
                $customRequest = '';
                
                foreach ($parts as $i => $part) {
                    if ($i === 0) continue; 
                    if (strpos($part, 'Added Items:') !== false) {
                        $itemsStr = str_replace('Added Items:', '', $part);
                        $addons = array_map('trim', explode(',', $itemsStr));
                    } elseif (strpos($part, 'Menu Swaps:') !== false) {
                        $itemsStr = str_replace('Menu Swaps:', '', $part);
                        $swaps = array_map('trim', explode(',', $itemsStr));
                    } elseif (strpos($part, 'Build Your Own Menu:') !== false) {
                        $itemsStr = str_replace('Build Your Own Menu:', '', $part);
                        $buildMenuItems = array_values(array_filter(array_map('trim', explode(',', $itemsStr)), 'strlen'));
                    } elseif (strpos($part, 'Custom Add-on Request:') !== false) {
                        $customRequest = trim(str_replace('Custom Add-on Request:', '', $part));
                    }
                }
                
                // Fetch Original Items if Set Name exists
                $originalItems = [];
                if (!empty($setName)) {
                    // Try exact match first
                    $set_query = "SELECT description FROM product_sets ps 
                                  JOIN products p ON ps.product_id = p.id 
                                  WHERE ps.set_name = '$setName' 
                                  AND p.store_id = '{$booking['store_id']}' LIMIT 1";
                        $set_res = mysqli_query($conn, $set_query);
                        if ($set_res && $set_row = mysqli_fetch_assoc($set_res)) {
                            $originalItems = bookingPricingParseSetDescription($set_row['description'])['items'];
                        } else {
                            // Try loose match if exact failed
                            $setNameSafe = mysqli_real_escape_string($conn, $setName);
                            $set_query = "SELECT description FROM product_sets ps 
                                          JOIN products p ON ps.product_id = p.id 
                                          WHERE ps.set_name LIKE '%$setNameSafe%' 
                                          AND p.store_id = '{$booking['store_id']}' LIMIT 1";
                            $set_res = mysqli_query($conn, $set_query);
                            if ($set_res && $set_row = mysqli_fetch_assoc($set_res)) {
                                $originalItems = bookingPricingParseSetDescription($set_row['description'])['items'];
                            }
                        }
                    
                    if (!empty($originalItems)) {
                        $originalItems = array_map(function($item) {
                            return trim(preg_replace('/^[\d\.\-\�]+/', '', $item)); 
                        }, $originalItems);
                        $originalItems = array_filter($originalItems);
                    }
                }
                
                // Display Set Name
                if (!empty($setName)) {
                    $html .= '<div style="margin-bottom: 15px; font-size: 16px; color: #fff;"><strong>Selected Set:</strong> <span style="color: #d4af37;">' . htmlspecialchars($setName) . '</span></div>';
                }
                
                // Display Included Items
                if (!empty($originalItems)) {
                    $html .= '<div style="margin-bottom: 15px;"><strong style="color: #a0a0a0; font-size: 13px;">INCLUDED ITEMS:</strong><ul style="list-style: none; padding: 0; margin-top: 5px;">';
                    foreach ($originalItems as $item) {
                        // Check if swapped
                        $isSwapped = false;
                        $replacedBy = '';
                        foreach ($swaps as $swapStr) {
                            // Format: "Swap [Original] for [Replacement] (+Price)"
                            if (preg_match('/Swap\s+(.*?)\s+for\s+(.*?)\s+\(\+/i', $swapStr, $matches)) {
                                $swapOrig = trim($matches[1]);
                                $cleanItem = trim($item);
                                $swapOrigBase = trim(preg_replace('/\s*\(.*?\)/', '', $swapOrig));
                                $cleanItemBase = trim(preg_replace('/\s*\(.*?\)/', '', $cleanItem));
                                if (strcasecmp($swapOrig, $cleanItem) === 0 || 
                                    ($swapOrigBase !== '' && $cleanItemBase !== '' && strcasecmp($swapOrigBase, $cleanItemBase) === 0) ||
                                    ($swapOrigBase !== '' && stripos($cleanItemBase, $swapOrigBase) !== false) ||
                                    ($cleanItemBase !== '' && stripos($swapOrigBase, $cleanItemBase) !== false)) {
                                    $isSwapped = true;
                                    $replacedBy = trim($matches[2]);
                                    break;
                                }
                            }
                        }
                        
                        if ($isSwapped) {
                            $html .= '<li style="padding: 5px 0; color: #777; text-decoration: line-through;">' . htmlspecialchars($item) . ' <span style="color: #e74c3c; font-size: 11px; margin-left: 5px;">(Replaced)</span></li>';
                        } else {
                            $html .= '<li style="padding: 5px 0; color: #fff;"><i class="fas fa-check" style="color: #2ecc71; margin-right: 8px; font-size: 11px;"></i>' . htmlspecialchars($item) . '</li>';
                        }
                    }
                    $html .= '</ul></div>';
                } elseif (!empty($setName)) {
                     // Fallback if set name exists but items not found
                     $html .= '<div style="margin-bottom: 15px;"><strong style="color: #a0a0a0; font-size: 13px;">INCLUDED ITEMS:</strong><p style="color: #777; font-style: italic;">Standard inclusions for ' . htmlspecialchars($setName) . '</p></div>';
                }
                
                // Display Swaps
                if (!empty($swaps)) {
                    $html .= '<div style="margin-bottom: 15px;"><strong style="color: #e67e22; font-size: 13px;">SWAPPED ITEMS:</strong><ul style="list-style: none; padding: 0; margin-top: 5px;">';
                    foreach ($swaps as $swap) {
                        $cleanSwap = preg_replace('/\(\+?.*?\)/', '', $swap);
                        $html .= '<li style="padding: 5px 0; color: #fff;"><span style="background: rgba(230, 126, 34, 0.2); color: #e67e22; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-right: 5px;">SWAP</span> ' . htmlspecialchars($cleanSwap) . '</li>';
                    }
                    $html .= '</ul></div>';
                }
                
                // Display Add-ons
                if (!empty($addons)) {
                    $html .= '<div style="margin-bottom: 15px;"><strong style="color: #d4af37; font-size: 13px;">ADD-ONS:</strong><ul style="list-style: none; padding: 0; margin-top: 5px;">';
                    foreach ($addons as $addon) {
                        $cleanAddon = preg_replace('/\(\+?.*?\)/', '', $addon);
                        $html .= '<li style="padding: 5px 0; color: #fff;"><span style="background: rgba(212, 175, 55, 0.2); color: #d4af37; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-right: 5px;">ADD-ON</span> ' . htmlspecialchars($cleanAddon) . '</li>';
                    }
                    $html .= '</ul></div>';
                }
                
                if (!empty($buildMenuItems)) {
                    $html .= '<div style="margin-bottom: 15px;"><strong style="color: #93c5fd; font-size: 13px;">BUILD YOUR OWN MENU:</strong><ul style="list-style: none; padding: 0; margin-top: 5px;">';
                    foreach ($buildMenuItems as $buildItem) {
                        $html .= '<li style="padding: 5px 0; color: #fff;"><span style="background: rgba(59, 130, 246, 0.2); color: #93c5fd; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-right: 5px;">CUSTOM</span> ' . htmlspecialchars($buildItem) . '</li>';
                    }
                    $html .= '</ul></div>';
                }

                // Display Custom Request
                if (!empty($customRequest)) {
                    $html .= '<div style="margin-top: 15px; padding: 10px; border: 1px dashed #d4af37; border-radius: 6px; color: #d4af37;"><strong>Special Request:</strong> ' . htmlspecialchars($customRequest) . '</div>';
                }
            }
            
            // Damage Report Section REMOVED from here to move to separate modal
            
        $html .= '
        <div style="margin-top: 24px; margin-bottom: 20px;">
            <h4 style="color: #d4af37; margin-bottom: 10px;">Booking Information</h4>
            <div class="booking-info-columns">
                <div>
                    <p><strong>Booking #:</strong> ' . formatCatererBookingNumber($booking['id'], $booking['order_number'], $booking['store_id']) . '</p>
                    <p><strong>Booking Date:</strong> ' . date('F d, Y', strtotime($booking['order_date'])) . '</p>
                    <p><strong>Booking Time:</strong> ' . date('h:i A', strtotime($booking['order_time'])) . '</p>
                </div>
                <div>
                    <p><strong>Status:</strong> <span class="status-badge ' . getCustomerBookingStatusClass($booking['order_status']) . '">' . htmlspecialchars(getCustomerBookingStatusLabel($booking['order_status'])) . '</span></p>
                    <p><strong>Payment Method:</strong> ' . htmlspecialchars($booking['payment_method']) . '</p>
                </div>
            </div>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: #d4af37; margin-bottom: 10px;">Caterer Information</h4>
            <p><strong>Caterer Name:</strong> ' . htmlspecialchars($booking['store_name']) . '</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: #d4af37; margin-bottom: 10px;">Booking Location</h4>
            <p><strong>Address:</strong> ' . htmlspecialchars((!empty($booking['delivery_address']) ? $booking['delivery_address'] : (!empty($booking['address']) ? $booking['address'] : 'N/A'))) . '</p>
            <p><strong>Booking Date:</strong> ' . date('F d, Y', strtotime($booking['order_date'])) . '</p>
            <p><strong>Booking Time:</strong> ' . (!empty($booking['delivery_time']) ? date('h:i A', strtotime($booking['delivery_time'])) : (!empty($booking['order_time']) ? date('h:i A', strtotime($booking['order_time'])) : 'N/A')) . '</p>
        </div>
        
        <script>
            function toggleFeeBreakdown() {
                var breakdown = document.getElementById("feeBreakdown");
                var icon = document.getElementById("feeToggleIcon");
                if (breakdown.style.display === "none") {
                    breakdown.style.display = "block";
                    icon.classList.remove("fa-chevron-down");
                    icon.classList.add("fa-chevron-up");
                } else {
                    breakdown.style.display = "none";
                    icon.classList.remove("fa-chevron-up");
                    icon.classList.add("fa-chevron-down");
                }
            }
        </script>';
        
        // Add catering-specific details if applicable
        if ($booking['category'] === 'catering_service') {
            $html .= '
            <div style="margin-bottom: 20px;">
                ';
                
            // Check notes for details if columns don't exist in orders table
            if (!empty($booking['notes'] ?? '')) {
                $html .= '<p><strong>Notes:</strong> ' . nl2br(htmlspecialchars((string) ($booking['notes'] ?? ''))) . '</p>';
            }
            
            $html .= '</div>';
        }
        
        // Booking items table
        $html .= '
        <div style="margin-bottom: 20px;">
            <h4 style="color: #d4af37; margin-bottom: 10px;">Booking Items</h4>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>';
        
        // Since we don't have an order_items table in the original code logic (it was checking orders table directly for product_id),
        // we assume single product per order based on the INSERT query in browse-caterer.php
        
        $item_total = $booking['total_amount'];
        $product_name = $booking['product_name'] ?: 'Catering Service';
        $quantity = !empty($booking['quantity']) ? $booking['quantity'] : 1;
        $price = $quantity > 0 ? $booking['total_amount'] / $quantity : $booking['total_amount'];
        
        $html .= '
            <tr>
                <td>' . htmlspecialchars($product_name) . '</td>
                <td>' . $quantity . '</td>
                <td>&#8369;' . number_format($price, 2) . '</td>
                <td>&#8369;' . number_format($item_total, 2) . '</td>
            </tr>';
        
        $html .= '
                </tbody>
            </table>
        </div>';
        $html .= '</div>';

        echo json_encode(['success' => true, 'html' => $html]);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit();
    }
}

$page_title = 'My Bookings';

$new_booking = isset($_GET['new_booking']) && $_GET['new_booking'] == '1';
$new_booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$page_notice = '';
$page_notice_type = 'success';

if ($new_booking && $new_booking_id > 0) {
    $page_notice = "";
}

require_once __DIR__ . '/includes/header.php';

// Get current view
$view = isset($_GET['view']) ? $_GET['view'] : 'all';
$status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';
$deleted_filter = ($view === 'trash') ? 'o.is_deleted_by_customer = 1' : '(o.is_deleted_by_customer = 0 OR o.is_deleted_by_customer IS NULL)';
$status_sql = getCustomerBookingStatusSql($status_filter);
$search_sql = '';
if ($search !== '') {
    $search_sql = " AND (
        o.order_number LIKE '%$search%' OR
        COALESCE(u.business_name, '') LIKE '%$search%' OR
        COALESCE(p.name, '') LIKE '%$search%'
    )";
}

// Get all bookings
$bookings_query = "
    SELECT o.*, u.business_name, u.profile_image, u.is_open as store_is_open, u.business_open_time as store_open_time, u.business_close_time as store_close_time, u.auto_schedule_enabled as store_auto_schedule_enabled, p.name as product_name, p.category,
    (SELECT COUNT(*) FROM booking_damages od WHERE od.order_id = o.id) as damage_count,
    (SELECT id FROM reviews r WHERE r.order_id = o.id LIMIT 1) as review_id
    FROM bookings o 
    LEFT JOIN (
        SELECT u.id, u.profile_image, sd.business_name, sd.is_open, sd.business_open_time, sd.business_close_time, sd.auto_schedule_enabled
        FROM users u 
        LEFT JOIN store_details sd ON u.id = sd.user_id 
        WHERE u.role IN ('caterer','store')
    ) u ON o.store_id = u.id 
    LEFT JOIN products p ON o.product_id = p.id 
    WHERE o.customer_id = '$user_id' AND $deleted_filter $status_sql $search_sql
    GROUP BY o.id
    ORDER BY o.id DESC
";
$bookings_result = mysqli_query($conn, $bookings_query);
if (!$bookings_result) {
    appLogError('Customer booking list query failed', mysqli_error($conn));
    $bookings_result = false;
}
?>

<link rel="stylesheet" href="../assets/css/customer-bookings-theme.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/customer-bookings-theme.css'); ?>">
<style>
    .booking-caterer-profile {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        vertical-align: middle;
    }
    .booking-caterer-avatar {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        object-fit: cover;
        border-radius: 50%;
        border: 1px solid rgba(212, 175, 55, 0.4);
        background: var(--card-bg, #222);
    }
    .booking-view-tabs {
        margin-bottom: 20px;
        display: flex;
        gap: 10px;
        flex-wrap: nowrap;
    }

    .booking-filter-bar {
        margin-bottom: 20px;
        display: flex;
        gap: 12px;
        flex-wrap: nowrap;
        align-items: center;
    }

    .booking-search-wrap {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 44px;
        align-items: center;
        gap: 8px;
        min-width: 260px;
        flex: 1 1 280px;
    }

    .booking-search-input {
        width: 100%;
        min-width: 0;
    }

    .booking-search-btn {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        border: 1px solid rgba(212, 175, 55, 0.35);
        background: rgba(212, 175, 55, 0.08);
        color: var(--accent-gold);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s ease, border-color 0.2s ease;
    }

    .booking-search-btn:hover {
        background: rgba(212, 175, 55, 0.14);
        border-color: rgba(212, 175, 55, 0.55);
    }

    .booking-status-filter {
        max-width: 220px;
    }

    .booking-reset-btn {
        white-space: nowrap;
    }

    .bookings-table-wrap {
        overflow-x: auto;
    }

    .booking-actions {
        display: flex;
        gap: 5px;
        flex-wrap: nowrap;
    }

    /* Timeline Styles for Modal */
    .order-timeline {
        display: flex;
        justify-content: space-between;
        margin: 20px 0;
        position: relative;
    }
    .order-timeline::before {
        content: '';
        position: absolute;
        top: 15px;
        left: 5%;
        right: 5%;
        height: 2px;
        background: #444;
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
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #333;
        border: 2px solid #555;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #aaa;
        font-size: 14px;
        margin-bottom: 5px;
        transition: all 0.3s ease;
    }
    .timeline-step.completed .step-icon {
        background: #4caf50;
        border-color: #4caf50;
        color: white;
    }
    .timeline-step.current .step-icon {
        background: #d4af37;
        border-color: #d4af37;
        color: #000;
        box-shadow: 0 0 10px rgba(212, 175, 55, 0.4);
    }
    .step-label {
        font-size: 11px;
        color: #aaa;
        text-align: center;
    }

    .step-description {
        margin-top: 4px;
        font-size: 10px;
        color: #888;
        text-align: center;
        line-height: 1.4;
        max-width: 95px;
    }
    .timeline-step.completed .step-label,
    .timeline-step.current .step-label {
        color: #fff;
        font-weight: bold;
    }

    /* Progress Bar */
    .progress-bar-container {
        height: 8px;
        background: rgba(255,255,255,0.1);
        border-radius: 4px;
        overflow: hidden;
        margin-top: 5px;
    }
    .progress-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #d4af37, #ffd700);
        transition: width 0.5s ease;
    }
    .progress-bar-fill.complete {
        background: #4caf50;
    }

    .mini-balance-reminder {
        margin: 18px 0 20px;
        padding: 12px 14px;
        border-radius: 8px;
        border: 1px solid rgba(212, 175, 55, 0.18);
        background: rgba(212, 175, 55, 0.08);
        font-size: 12px;
        line-height: 1.5;
        color: #fff;
    }

    .mini-balance-reminder strong {
        color: #d4af37;
    }

    .mini-balance-reminder.paid {
        border-color: rgba(76, 175, 80, 0.18);
        background: rgba(76, 175, 80, 0.08);
    }

    .mini-balance-reminder.paid strong {
        color: #4caf50;
    }

    @media (max-width: 600px) {
        .booking-view-tabs {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .booking-filter-bar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 92px;
            grid-template-areas:
                "search search"
                "status reset";
            align-items: stretch;
            gap: 10px;
        }

        .booking-search-wrap {
            grid-area: search;
            width: 100%;
            max-width: 100%;
        }

        .booking-status-filter {
            grid-area: status;
            width: 100%;
            max-width: 100%;
        }

        .booking-reset-btn {
            grid-area: reset;
            width: 100%;
            max-width: 100%;
            padding-left: 10px;
            padding-right: 10px;
        }

        .booking-search-btn {
            width: 100%;
        }

        .bookings-table-wrap {
            overflow: visible;
        }

        .data-table thead {
            display: none;
        }

        .data-table,
        .data-table tbody,
        .data-table tr,
        .data-table td {
            display: block;
            width: 100%;
        }

        .data-table tbody {
            padding: 8px;
        }

        .data-table tr {
            margin-bottom: 14px;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 14px;
            background: rgba(255,255,255,0.02);
        }

        .data-table td {
            border: none;
            padding: 8px 0;
            text-align: left;
        }

        .data-table td::before {
            display: block;
            margin-bottom: 4px;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .data-table td:nth-child(1)::before { content: 'Booking #'; }
        .data-table td:nth-child(2)::before { content: 'Caterer'; }
        .data-table td:nth-child(3)::before { content: 'Item'; }
        .data-table td:nth-child(4)::before { content: 'Booking Date'; }
        .data-table td:nth-child(5)::before { content: 'Amount'; }
        .data-table td:nth-child(6)::before { content: 'Status'; }
        .data-table td:nth-child(7)::before { content: 'Actions'; }

        .booking-actions {
            padding-top: 4px;
        }
    }

    /* Mobile-friendly booking cards */
    @media (max-width: 768px) {
        .main-content {
            min-width: 0;
        }

        .booking-list-card {
            padding: 12px;
            border-radius: 14px;
        }

        .bookings-table-wrap {
            width: 100%;
            overflow: visible;
        }

        .bookings-table-wrap .data-table,
        .bookings-table-wrap .data-table tbody {
            display: block;
            width: 100%;
        }

        .bookings-table-wrap .data-table thead {
            display: none;
        }

        .bookings-table-wrap .data-table tbody {
            padding: 0;
        }

        .bookings-table-wrap .data-table tr {
            display: block;
            width: 100%;
            margin: 0 0 12px;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 14px;
            background: rgba(255, 255, 255, 0.025);
        }

        .bookings-table-wrap .data-table tr:last-child {
            margin-bottom: 0;
        }

        .bookings-table-wrap .data-table tr.caterer-group-header {
            margin: 14px 0 8px;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent !important;
        }

        .bookings-table-wrap .data-table tr.caterer-group-header:first-child {
            margin-top: 0;
        }

        .bookings-table-wrap .data-table tr.caterer-group-header td {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
            min-width: 0;
            margin: 0;
            padding: 12px 13px !important;
            border: 1px solid rgba(214, 179, 90, 0.22);
            border-radius: 12px;
            background: rgba(214, 179, 90, 0.10);
            text-align: left;
        }

        .bookings-table-wrap .data-table tr.caterer-group-header td::before {
            display: none !important;
        }

        .bookings-table-wrap .data-table tr.caterer-group-header td strong {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .bookings-table-wrap .data-table tr.caterer-group-header td span {
            float: none !important;
            flex: 0 0 auto;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            grid-template-areas:
                "order status"
                "caterer caterer"
                "item item"
                "date date"
                "amount amount"
                "actions actions";
            column-gap: 12px;
            row-gap: 9px;
        }

        .bookings-table-wrap .data-table td {
            display: block;
            width: auto;
            min-width: 0;
            border: 0;
            padding: 0;
            text-align: left;
            overflow-wrap: anywhere;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td::before {
            display: block;
            margin-bottom: 3px;
            color: var(--text-muted);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.06em;
            line-height: 1.2;
            text-transform: uppercase;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(1) {
            grid-area: order;
            align-self: start;
            color: var(--accent-gold);
            font-size: 13px;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(1)::before,
        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(6)::before {
            display: none;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(1) strong {
            display: block;
            font-size: 14px;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(2) {
            grid-area: caterer;
            color: var(--text-main);
            font-weight: 600;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(2)::before {
            content: 'Caterer';
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(3) {
            grid-area: item;
            line-height: 1.45;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(3)::before {
            content: 'Package';
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(4) {
            grid-area: date;
            line-height: 1.45;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(4)::before {
            content: 'Booking date';
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(5) {
            grid-area: amount;
            padding: 11px 12px !important;
            border: 1px solid rgba(214, 179, 90, 0.16);
            border-radius: 10px;
            background: rgba(214, 179, 90, 0.06);
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(5)::before {
            content: 'Payment summary';
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(5) > div:first-child {
            color: var(--accent-gold) !important;
            font-size: 18px !important;
            line-height: 1.25;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(5) > div:not(:first-child) {
            font-size: 12px !important;
            line-height: 1.45;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(6) {
            grid-area: status;
            align-self: start;
            justify-self: end;
            text-align: right;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(6) .status-badge {
            display: inline-flex;
            justify-content: center;
            white-space: nowrap;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(7) {
            grid-area: actions;
            padding-top: 11px;
            border-top: 1px solid var(--border-color);
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) td:nth-child(7)::before {
            content: 'Actions';
            margin-bottom: 7px;
        }

        .bookings-table-wrap .booking-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            width: 100%;
            padding-top: 0;
        }

        .bookings-table-wrap .booking-actions > .btn {
            width: 100%;
            min-width: 0;
            min-height: 40px;
            padding: 9px 7px !important;
            justify-content: center;
            font-size: 12px !important;
            white-space: nowrap;
        }

        .bookings-table-wrap .booking-actions > .btn:first-child {
            grid-column: 1 / -1;
        }

        .bookings-table-wrap .data-table tr.empty-bookings-row {
            display: block;
            margin: 0;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
        }

        .bookings-table-wrap .data-table tr.empty-bookings-row td {
            width: 100%;
            padding: 0;
            text-align: center;
        }

        .bookings-table-wrap .data-table tr.empty-bookings-row td::before {
            display: none !important;
        }

        /* Keep the small order-items table inside the details modal readable. */
        #detailsModal .data-table {
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        #detailsModal .data-table thead {
            display: table-header-group;
        }

        #detailsModal .data-table tbody {
            display: table-row-group;
        }

        #detailsModal .data-table tr {
            display: table-row;
            width: auto;
            margin: 0;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
        }

        #detailsModal .data-table td {
            display: table-cell;
            width: auto;
            padding: 8px 4px;
            border: 0;
            text-align: left;
            overflow-wrap: break-word;
        }

        #detailsModal .data-table td::before {
            display: none !important;
        }

        #detailsModal .data-table th {
            display: table-cell;
            padding: 8px 4px;
        }
    }

    @media (max-width: 380px) {
        .booking-list-card {
            padding: 8px;
        }

        .bookings-table-wrap .data-table tr:not(.caterer-group-header) {
            padding: 12px;
        }

        .bookings-table-wrap .data-table tr.caterer-group-header td {
            padding: 10px 11px !important;
        }

        .bookings-table-wrap .booking-actions {
            gap: 6px;
        }

        .bookings-table-wrap .booking-actions > .btn {
            padding: 8px 4px !important;
            font-size: 11px !important;
        }
    }
</style>

<div class="main-content customer-bookings-panel">

    <?php if ($page_notice): ?>
        <div class="alert alert-<?php echo $page_notice_type === 'error' ? 'error' : 'success'; ?>" style="margin-bottom: 20px;">
            <i class="fas <?php echo $page_notice_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
            <?php echo htmlspecialchars($page_notice); ?>
        </div>
    <?php endif; ?>

    <!-- Booking Filters/Tabs -->
    <div class="booking-view-tabs">
        <button class="btn btn-tab <?php echo ($view === 'all') ? 'active' : ''; ?>" onclick="switchView('all')" id="tab-all" style="<?php echo ($view === 'all') ? 'background: var(--accent-gold); color: #000; border-color: var(--accent-gold);' : ''; ?>">
            <i class="fas fa-list"></i> All Bookings
        </button>
        <button class="btn btn-tab <?php echo ($view === 'trash') ? 'active' : ''; ?>" onclick="switchView('trash')" id="tab-trash" style="<?php echo ($view === 'trash') ? 'background: var(--accent-gold); color: #000; border-color: var(--accent-gold);' : ''; ?>">
            <i class="fas fa-trash"></i> Trash
        </button>
    </div>

    <div class="booking-filter-bar">
        <div class="booking-search-wrap">
            <input type="text" id="bookingSearch" class="form-control booking-search-input" placeholder="Search booking #, caterer, or item" value="<?php echo htmlspecialchars($search); ?>">
            <button type="button" class="booking-search-btn" onclick="applyBookingFilters()">
                <i class="fas fa-search"></i>
            </button>
        </div>
        <select id="bookingStatusFilter" class="form-control booking-status-filter">
            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
            <option value="preparing" <?php echo $status_filter === 'preparing' ? 'selected' : ''; ?>>Preparing</option>
            <option value="delivering" <?php echo $status_filter === 'delivering' ? 'selected' : ''; ?>>Ongoing</option>
            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
        </select>
        <button type="button" class="btn btn-tab booking-reset-btn" onclick="resetBookingFilters()">Reset</button>
    </div>

    <style>
    .booking-caterer-profile {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        vertical-align: middle;
    }
    .booking-caterer-avatar {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        object-fit: cover;
        border-radius: 50%;
        border: 1px solid rgba(212, 175, 55, 0.4);
        background: var(--card-bg, #222);
    }
        .btn-tab {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-tab.active {
            background: var(--accent-gold);
            color: #000;
            border-color: var(--accent-gold);
        }
        .btn-tab:hover:not(.active) {
            background: rgba(255,255,255,0.05);
            color: var(--text-main);
        }
        
        .caterer-group-header td::before {
            display: none !important;
        }
    </style>

    <div class="card booking-list-card">
        <div class="bookings-table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Booking #</th>
                        <th>Caterer</th>
                        <th>Item</th>
                        <th>Booking Date</th>
                        <th style="color: var(--accent-gold);">AMOUNT</th>
                        <th>Status</th>
                        <th style="display: flex; justify-content: space-between; align-items: center;">
                            Actions
                            <button id="deleteAllTrashBtn" onclick="deleteAllTrash()" class="btn btn-outline" style="padding: 2px 6px; font-size: 11px; color: #ff4444; border-color: #ff4444; background: rgba(255,68,68,0.08); display: <?php echo ($view === 'trash') ? 'inline-block' : 'none'; ?>;" title="Permanently Delete All Trash">
                                <i class="fas fa-trash-alt"></i> All
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody id="bookingsTableBody">
                    <?php 
                        $grouped_bookings = groupCustomerBookings($bookings_result);
                    ?>
                    <?php if (!empty($grouped_bookings)): ?>
                        <?php foreach ($grouped_bookings as $store_id => $group): ?>
                            <?php if ($store_id === 'fully-paid'): ?>
                                <?php echo renderFullyPaidBookingsHeader(); ?>
                            <?php else: ?>
                                <tr class="caterer-group-header" onclick="toggleCatererOrders(<?php echo $store_id; ?>)" style="cursor: pointer; background: rgba(212,175,55,0.1);">
                                    <td colspan="7" style="border: none; padding: 15px;">
                                        <?php echo renderBookingCatererProfile($group); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($group['bookings'] as $booking): ?>
                                <tr class="caterer-order-<?php echo $store_id; ?>" style="display: none;">
                                    <td><strong><?php echo formatCatererBookingNumber($booking['id'], $booking['order_number'], $booking['store_id']); ?></strong></td>
                                    <td><?php echo $store_id === 'fully-paid' ? renderBookingCatererProfile($booking) : htmlspecialchars($booking['business_name']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($booking['package_name'] ?: ($booking['product_name'] ?: 'Catering Service')); ?>
                                    <?php if (!empty($booking['event_type'])): ?>
                                        <br><small style="color: var(--text-muted);">Event: <strong><?php echo htmlspecialchars($booking['event_type']); ?></strong></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($booking['order_date'])); ?><br>
                                    <small style="color: var(--text-muted);"><?php echo !empty($booking['order_time']) ? date('h:i A', strtotime($booking['order_time'])) : (!empty($booking['delivery_time']) ? date('h:i A', strtotime($booking['delivery_time'])) : 'N/A'); ?></small>
                                    <?php if (!empty($booking['created_at'])): ?>
                                        <br><small style="color: var(--text-muted);">Booked On: <?php echo date('M d, Y h:i A', strtotime($booking['created_at'])); ?></small>
                                    <?php endif; ?>
                                    <?php echo renderBookingEventDateLabel($booking); ?>
                                </td>
                                <td>
                                    <?php
                                    $fin = $booking['financials'];
                                    $grand_total = (float) ($fin['totals']['grand_total'] ?? 0);
                                    $total_paid = (float) ($fin['totals']['total_paid'] ?? 0);
                                    $balance = (float) ($fin['totals']['balance'] ?? max(0, $grand_total - $total_paid));
                                    $down_payment_required = (float) ($fin['down_payment_required'] ?? 0);
                                    $is_amount_missing = ($grand_total <= 0 && $total_paid <= 0);
                                    $amount_meta_color = $is_amount_missing ? '#f1c40f' : ($balance > 0 ? '#e74c3c' : '#2ecc71');
                                    $amount_meta_text = $is_amount_missing ? 'Pending Amount' : ($balance > 0 ? 'Bal: &#8369;' . number_format($balance, 2) : 'Fully Paid');
                                    ?>
                                    <div style="font-weight: bold; font-size: 15px;">&#8369;<?php echo number_format((float) ($booking['total_amount'] ?? 0), 2); ?></div>
                                    <div style="font-size: 12px; color: var(--text-muted);">Down Payment: &#8369;<?php echo number_format($down_payment_required, 2); ?></div>
                                    <?php if ($total_paid > 0): ?>
                                        <div style="font-size: 12px; color: #2ecc71;">Advanced Payment: &#8369;<?php echo number_format($total_paid, 2); ?></div>
                                    <?php endif; ?>
                                    <div style="font-size: 12px; font-weight: bold; color: <?php echo $amount_meta_color; ?>;">
                                        <?php echo $amount_meta_text; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $normalized_status = normalizeCustomerBookingStatus($booking['order_status']);
                                    $status_class = getCustomerBookingStatusClass($booking['order_status']);
                                    $status_text = getCustomerBookingStatusLabel($booking['order_status']);
                                    $menu_change_badge = getCustomerMenuChangeBadgeHtml($booking['id'], $user_id);
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                    <?php echo $menu_change_badge; ?>
                                    <?php echo renderBookingPaymentNotice($booking, $fin ?: []); ?>
                                </td>
                                <td>
                                    <div class="booking-actions">
                                        <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; text-decoration: none;">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        
                                        <?php if ($booking['damage_count'] > 0): ?>
                                            <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; text-decoration: none; color: #e74c3c; border-color: #e74c3c; display: inline-flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i> Damages
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($normalized_status === 'pending'): ?>
            <button onclick="cancelBooking(<?php echo $booking['id']; ?>)" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; color: #e74c3c; border-color: #e74c3c;" title="Cancel Booking">
                <i class="fas fa-times"></i>
            </button>
        <?php endif; ?>
        
        <?php if ($normalized_status !== 'cancelled'): ?>
                                            <button onclick="openMessageModal(<?php echo $booking['id']; ?>, '<?php echo addslashes($booking['business_name']); ?>', <?php echo $booking['store_id']; ?>, '<?php echo formatCustomerBookingNumber($booking['id'], $booking['order_number']); ?>')" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($normalized_status === 'completed'): ?>
                                            <?php if ($booking['review_id']): ?>
                                                <button class="btn btn-success" style="padding: 5px 10px; font-size: 12px; cursor: default; background-color: #2ecc71; border-color: #2ecc71; color: #fff;" title="Reviewed">
                                                    <i class="fas fa-star"></i>
                                                </button>
                                            <?php else: ?>
                                                <button onclick="openReviewModal(<?php echo $booking['id']; ?>, <?php echo $booking['store_id']; ?>)" class="btn btn-warning" style="padding: 5px 10px; font-size: 12px; background-color: #f1c40f; border-color: #f1c40f; color: #000;" title="Write a Review">
                                                    <i class="far fa-star"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if (!empty($booking['product_id']) && !empty($booking['store_id']) && in_array($normalized_status, ['completed', 'cancelled'], true)): ?>
                                            <?php if (isStoreAvailableNow($booking['store_is_open'] ?? 0, $booking['store_open_time'] ?? null, $booking['store_close_time'] ?? null, $booking['store_auto_schedule_enabled'] ?? 0)): ?>
                                                <a href="book-service.php?product_id=<?php echo intval($booking['product_id']); ?>&store_id=<?php echo intval($booking['store_id']); ?>" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; text-decoration: none; color: var(--accent-gold); border-color: var(--accent-gold);" data-book-again-id="<?php echo (int) $booking['id']; ?>" title="Book Again">
                                                    <i class="fas fa-rotate-right"></i>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; color: #f0b4b4; border-color: #6b3b3b; background: rgba(231, 76, 60, 0.08); cursor: not-allowed;" title="Caterer Closed" disabled>
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="display:inline-block;vertical-align:middle;flex-shrink:0;"><path d="M3 3l18 18M4 10v10h16v-4M4 4h16l2 6h-8M2 10h5M9 20v-6h5"/></svg>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <a href="booking-receipt.php?id=<?php echo $booking['id']; ?>" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; text-decoration: none;" title="Print Receipt">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        
                                        <?php if (in_array($normalized_status, ['completed', 'cancelled'], true)): ?>
            <button onclick="deleteBooking(<?php echo $booking['id']; ?>)" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; color: #e74c3c; border-color: #e74c3c;" title="Move to Trash">
                <i class="fas fa-trash"></i>
            </button>
        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="empty-bookings-row">
                            <td colspan="7" style="text-align:center; padding: 20px; border:none; background:transparent;">
                                <div class="empty-state" style="border:none; padding:40px 0;">
                                    <i class="fas fa-calendar-times"></i>
                                    <p>No bookings found.</p>
                                    <a href="browse-caterer.php" class="btn btn-primary" style="margin-top: 15px;">Browse Caterers</a>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div id="detailsModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: nowrap;">
                <h2 style="margin: 0;">Booking Details</h2>
                <a id="viewMoreDetailsLink" href="booking-details.php" class="btn btn-outline" style="padding: 6px 12px; font-size: 12px; text-decoration: none; color: var(--accent-gold); border-color: var(--accent-gold);">
                    <i class="fas fa-up-right-from-square"></i> View More Details
                </a>
            </div>
            <button type="button" class="close-btn" onclick="closeDetailsModal()">&times;</button>
        </div>
        <div class="modal-body" id="detailsContent">
            <!-- Content loaded via AJAX -->
        </div>
    </div>
</div>

<dialog id="messageSentModal" aria-labelledby="messageSentTitle">
    <h3 id="messageSentTitle">Message sent</h3>
    <p>Message sent successfully!</p>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('messageSentModal').close()">OK</button>
</dialog>
<style>
#messageSentModal{position:fixed;inset:0;margin:auto;width:min(360px,calc(100vw - 32px));height:fit-content;max-height:calc(100dvh - 32px);box-sizing:border-box;overflow:auto;padding:24px;border:1px solid #96a38e;border-radius:12px;background:#d4dbcd;color:#20372b;text-align:center;}
#messageSentModal::backdrop{background:rgb(12 32 25 / 55%);backdrop-filter:blur(4px);}
#messageSentModal h3{margin:0 0 12px;font-size:18px;line-height:1.4;}
#messageSentModal p{margin:0 0 20px;font-size:14px;line-height:1.6;overflow-wrap:anywhere;}
#messageSentModal .btn{min-width:100px;min-height:44px;justify-content:center;}
</style>
<!-- Message Modal -->
<div id="messageModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Send Message</h2>
            <button type="button" class="close-btn" onclick="closeMessageModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="messageSuccess" class="alert alert-success" style="display: none;">
                Message sent successfully!
            </div>
            
            <form id="messageForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" id="messageOrderId" name="order_id">
                <input type="hidden" id="messageStoreId" name="store_id">
                <input type="hidden" name="send_to_store" value="1">
                
                <div class="form-group">
                    <label class="form-label">Caterer</label>
                    <input type="text" id="storeName" class="form-control" readonly style="background: var(--secondary-bg);">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" id="messageSubject" class="form-control" required placeholder="Regarding Booking...">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea name="message" class="form-control" rows="4" required placeholder="Type your message here..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">Send Message</button>
            </form>
        </div>
    </div>
</div>

<link rel="stylesheet" href="../assets/css/customer-review-modal.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/customer-review-modal.css'); ?>">
<!-- Review Modal -->
<div id="reviewModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2>Write a Review</h2>
            <button type="button" class="close-btn" onclick="closeReviewModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="reviewSuccess" class="alert alert-success" style="display: none;">
                Review submitted successfully!
            </div>
            <div id="reviewError" class="alert alert-danger" style="display: none; color: #e74c3c; background: rgba(231, 76, 60, 0.1); padding: 10px; border-radius: 4px; margin-bottom: 15px;"></div>
            
            <form id="reviewForm">
                <input type="hidden" id="reviewOrderId" name="order_id">
                <input type="hidden" id="reviewStoreId" name="store_id">
                
                <div style="text-align: center; margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; color: var(--text-muted);">Overall Rating</label>
                    <div class="star-rating" id="overallRating">
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
                        <label class="form-label" style="font-size: 12px;">Booking Setup</label>
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
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">Submit Review</button>
            </form>
        </div>
    </div>
</div>

<!-- Custom Confirm Modal -->
<div id="confirmActionModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header" style="border-bottom: none; padding-bottom: 0;">
            <h2 id="confirmActionTitle" style="color: var(--accent-gold); margin-top: 0;">Confirm</h2>
            <button type="button" class="close-btn" onclick="closeConfirmModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding-top: 10px;">
            <p id="confirmActionMessage" style="margin-bottom: 25px; color: var(--text-muted); line-height: 1.5;"></p>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeConfirmModal()">Cancel</button>
                <button type="button" id="confirmActionBtn" class="btn btn-primary" style="background: var(--accent-gold); border-color: var(--accent-gold); color: #000;">Delete</button>
            </div>
        </div>
    </div>
</div>

<style>
    .booking-caterer-profile {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        vertical-align: middle;
    }
    .booking-caterer-avatar {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        object-fit: cover;
        border-radius: 50%;
        border: 1px solid rgba(212, 175, 55, 0.4);
        background: var(--card-bg, #222);
    }
    .star-rating {
        font-size: 24px;
        color: #f1c40f;
        cursor: pointer;
    }
    .star-rating i {
        margin: 0 2px;
        transition: transform 0.2s;
    }
    .star-rating i:hover {
        transform: scale(1.2);
    }
</style>

<script>
    let currentOpenBookingId = null;
    const inlineSetMenuState = {};
    
    // Star Rating Logic
    document.querySelectorAll('#overallRating i').forEach(star => {
        star.addEventListener('click', function() {
            const value = this.getAttribute('data-value');
            document.getElementById('overallRatingInput').value = value;
            updateStars(value);
        });
        
        star.addEventListener('mouseover', function() {
            updateStars(this.getAttribute('data-value'), true);
        });
        
        star.addEventListener('mouseout', function() {
            const currentVal = document.getElementById('overallRatingInput').value;
            updateStars(currentVal || 0);
        });
    });
    
    function updateStars(value, isHover = false) {
        document.querySelectorAll('#overallRating i').forEach(star => {
            const starVal = star.getAttribute('data-value');
            if (starVal <= value) {
                star.classList.remove('far');
                star.classList.add('fas');
            } else {
                star.classList.remove('fas');
                star.classList.add('far');
            }
        });
    }

    function openReviewModal(orderId, storeId) {
        document.getElementById('reviewOrderId').value = orderId;
        document.getElementById('reviewStoreId').value = storeId;
        document.getElementById('reviewSuccess').style.display = 'none';
        document.getElementById('reviewError').style.display = 'none';
        document.getElementById('reviewForm').reset();
        document.getElementById('overallRatingInput').value = '';
        updateStars(0);
        document.getElementById('reviewModal').style.display = 'flex';
    }
    
    function closeReviewModal() {
        document.getElementById('reviewModal').style.display = 'none';
    }

    const reviewModal = document.getElementById('reviewModal');
    if (reviewModal) {
        const reviewModalContent = reviewModal.querySelector('.modal-content');

        reviewModal.addEventListener('click', function(event) {
            if (event.target === reviewModal) {
                closeReviewModal();
            }
        });

        if (reviewModalContent) {
            reviewModalContent.addEventListener('click', function(event) {
                event.stopPropagation();
            });
        }
    }
    
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
                    closeReviewModal();
                    refreshBookings(); // Refresh list to show reviewed status
                }, 1500);
            } else {
                const errorDiv = document.getElementById('reviewError');
                errorDiv.innerText = data.message;
                errorDiv.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    });

    function viewBookingDetails(bookingId) {
        currentOpenBookingId = bookingId;
        const modal = document.getElementById('detailsModal');
        const content = document.getElementById('detailsContent');
        const viewMoreLink = document.getElementById('viewMoreDetailsLink');

        if (viewMoreLink) {
            viewMoreLink.href = 'booking-details.php?id=' + bookingId;
        }
        
        // Only show loading if not already open/updating
        if (modal.style.display !== 'flex') {
            content.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            modal.style.display = 'flex';
        }
        
        fetchDetails(bookingId);
    }

    function fetchDetails(bookingId) {
        const content = document.getElementById('detailsContent');
        // Add timestamp
        fetch('booking.php?get_booking_details=1&booking_id=' + bookingId + '&_=' + new Date().getTime())
            .then(response => response.text())
            .then(text => {
                try {
                    const cleaned = String(text || '').replace(/^\uFEFF/, '');
                    let data;
                    try {
                        data = JSON.parse(cleaned);
                    } catch (innerError) {
                        const start = cleaned.indexOf('{');
                        const end = cleaned.lastIndexOf('}');
                        if (start !== -1 && end !== -1 && end > start) {
                            data = JSON.parse(cleaned.slice(start, end + 1));
                        } else {
                            throw innerError;
                        }
                    }
                    if (data.success) {
                        content.innerHTML = data.html;
                        if (inlineSetMenuState[bookingId]) {
                            const setMenuList = document.getElementById('set-menu-list-' + bookingId);
                            if (setMenuList) {
                                setMenuList.style.display = 'block';
                            }
                        }
                    } else {
                        content.innerHTML = '<p class="text-error">' + (data.message || 'Unknown error') + '</p>';
                    }
                } catch (e) {
                    content.innerHTML = '<div style="color: #f44336; padding: 20px;"><strong>Error:</strong><br>' + text.substring(0, 500) + '</div>';
                }
            })
            .catch(error => {
                content.innerHTML = '<p style="color: #f44336;">Error loading details: ' + error.message + '</p>';
            });
    }

    function toggleInlineSetMenus(event, bookingId) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        const setMenuList = document.getElementById('set-menu-list-' + bookingId);
        if (!setMenuList) return;
        inlineSetMenuState[bookingId] = true;
        setMenuList.style.display = 'block';
    }

    function closeInlineSetMenus(event, bookingId) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        const setMenuList = document.getElementById('set-menu-list-' + bookingId);
        if (!setMenuList) return;
        inlineSetMenuState[bookingId] = false;
        setMenuList.style.display = 'none';
    }

    function updateRequestedSetInfo(bookingId) {
        const select = document.getElementById('requested-set-' + bookingId);
        const info = document.getElementById('requested-set-info-' + bookingId);
        if (!select || !info) return;

        const option = select.options[select.selectedIndex];
        if (!option || !option.value) {
            info.style.display = 'none';
            info.innerHTML = '';
            return;
        }

        const rawSetPrice = parseFloat(option.getAttribute('data-price') || '0');
        const rawSetPax = parseInt(option.getAttribute('data-pax') || '0', 10);
        const bookingPax = Math.max(1, parseInt(select.getAttribute('data-current-pax') || '1', 10));
        const currentBase = parseFloat(select.getAttribute('data-current-base') || '0');
        const totalPaid = parseFloat(select.getAttribute('data-total-paid') || '0');
        const newSetTotal = rawSetPax > 0
            ? ((rawSetPrice / rawSetPax) * bookingPax)
            : rawSetPrice;
        const difference = newSetTotal - currentBase;
        const additionalNeeded = Math.max(0, newSetTotal - totalPaid);
        const sign = difference >= 0 ? '+' : '-';

        info.innerHTML =
            '<div style="font-weight: 700; color: #d4af37; margin-bottom: 6px;">Requested Set: ' + option.value + '</div>' +
            '<div style="font-size: 13px; color: #fff; line-height: 1.6;">' +
            'Current set total: PHP ' + currentBase.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '<br>' +
            'New set total: PHP ' + newSetTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '<br>' +
            'Already paid: PHP ' + totalPaid.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '<br>' +
            'Price difference: ' + sign + 'PHP ' + Math.abs(difference).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '<br>' +
            'Additional amount needed now: PHP ' + additionalNeeded.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) +
            '</div>'; 
        info.style.display = 'block';
    }

    function submitMenuChangeRequest(bookingId, storeId) {
        const select = document.getElementById('requested-set-' + bookingId);
        if (!select || !select.value) {
            alert('Please choose the set menu you want to request.');
            return;
        }

        const formData = new FormData();
        formData.append('request_menu_change', '1');
        formData.append('order_id', bookingId);
        formData.append('requested_set', select.value);
        formData.append('store_id', storeId);
        formData.append('csrf_token', '<?php echo $csrf_token; ?>');

        fetch('booking.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            alert(data.message);
            if (data.success) {
                fetchDetails(bookingId);
                refreshBookings();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while sending the menu change request.');
        });
    }

    function closeDetailsModal() {
        document.getElementById('detailsModal').style.display = 'none';
        if (currentOpenBookingId) {
            inlineSetMenuState[currentOpenBookingId] = false;
        }
        currentOpenBookingId = null;
    }

    const detailsModal = document.getElementById('detailsModal');
    if (detailsModal) {
        const detailsModalContent = detailsModal.querySelector('.modal-content');
        const detailsModalBody = document.getElementById('detailsContent');

        detailsModal.addEventListener('click', function(event) {
            event.stopPropagation();
        });

        if (detailsModalContent) {
            detailsModalContent.addEventListener('click', function(event) {
                event.stopPropagation();
            });
        }

        if (detailsModalBody) {
            detailsModalBody.addEventListener('click', function(event) {
                event.stopPropagation();
            });
        }
    }

    function openMessageModal(orderId, storeName, storeId, orderNumber) {
        document.getElementById('messageOrderId').value = orderId;
        document.getElementById('messageStoreId').value = storeId;
        document.getElementById('storeName').value = storeName;
        document.getElementById('messageSubject').value = 'Regarding Booking #' + orderNumber;
        document.getElementById('messageSuccess').style.display = 'none';
        document.getElementById('messageModal').style.display = 'flex';
    }

    function closeMessageModal() {
        document.getElementById('messageModal').style.display = 'none';
    }

    const messageModal = document.getElementById('messageModal');
    if (messageModal) {
        const messageModalContent = messageModal.querySelector('.modal-content');

        messageModal.addEventListener('click', function(event) {
            // Keep the message form open until the close button is pressed.
            event.stopPropagation();
        });

        if (messageModalContent) {
            messageModalContent.addEventListener('click', function(event) {
                event.stopPropagation();
            });
        }
    }

    document.getElementById('messageForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('booking.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                closeMessageModal();
                document.getElementById('messageForm').reset();
                document.getElementById('messageSuccess').style.display = 'none';
                document.getElementById('messageSentModal').showModal();
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            alert('Error sending message');
        });
    });

    function cancelBooking(orderId) {
        if (!confirm('Are you sure you want to CANCEL this booking? This action cannot be undone.')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('cancel_booking', '1');
        formData.append('order_id', orderId);
        
        fetch('booking.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                alert(data.message);
            }
            refreshBookings();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while cancelling the booking.');
        });
    }

    function deleteBooking(orderId) {
        if (!confirm('Are you sure you want to remove this booking from your history? This cannot be undone.')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('delete_booking', '1');
        formData.append('order_id', orderId);
        
        fetch('booking.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Refresh list
                refreshBookings();
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while deleting the booking.');
        });
    }

    // Real-time updates for booking list
    let currentView = '<?php echo $view; ?>';
    let currentStatusFilter = '<?php echo $status_filter; ?>';
    let currentSearch = <?php echo json_encode($search); ?>;

    function switchView(view) {
        currentView = view;
        
        const deleteAllBtn = document.getElementById('deleteAllTrashBtn');
        if (deleteAllBtn) {
            deleteAllBtn.style.display = (view === 'trash') ? 'inline-block' : 'none';
        }

        // If table doesn't exist (e.g. empty state), reload page with new view
        if (!document.getElementById('bookingsTableBody')) {
            const params = new URLSearchParams({
                view: view,
                status: currentStatusFilter,
                search: currentSearch
            });
            window.location.href = 'booking.php?' + params.toString();
            return;
        }

        // Update URL to remember tab on refresh
        const params = new URLSearchParams(window.location.search);
        params.set('view', view);
        window.history.pushState({}, '', window.location.pathname + '?' + params.toString());

        // Update Tabs
        document.querySelectorAll('.btn-tab').forEach(btn => {
            btn.classList.remove('active');
            // Restore default style
            btn.style.backgroundColor = 'transparent';
            btn.style.color = 'var(--text-muted)';
            btn.style.borderColor = 'var(--border-color)';
        });
        
        const activeBtn = document.getElementById('tab-' + view);
        if(activeBtn) {
            activeBtn.classList.add('active');
            activeBtn.style.backgroundColor = 'var(--accent-gold)';
            activeBtn.style.color = '#000';
            activeBtn.style.borderColor = 'var(--accent-gold)';
        }

        refreshBookings();
    }

    function applyBookingFilters() {
        const searchInput = document.getElementById('bookingSearch');
        const statusInput = document.getElementById('bookingStatusFilter');
        currentSearch = searchInput ? searchInput.value.trim() : '';
        currentStatusFilter = statusInput ? statusInput.value : 'all';
        if (!document.getElementById('bookingsTableBody')) {
            const params = new URLSearchParams({
                view: currentView,
                status: currentStatusFilter,
                search: currentSearch
            });
            window.location.href = 'booking.php?' + params.toString();
            return;
        }

        // Update URL to remember filters on refresh
        const params = new URLSearchParams(window.location.search);
        if (currentStatusFilter !== 'all') params.set('status', currentStatusFilter);
        else params.delete('status');
        if (currentSearch) params.set('search', currentSearch);
        else params.delete('search');
        window.history.pushState({}, '', window.location.pathname + '?' + params.toString());

        refreshBookings();
    }

    function resetBookingFilters() {
        const searchInput = document.getElementById('bookingSearch');
        const statusInput = document.getElementById('bookingStatusFilter');
        if (searchInput) searchInput.value = '';
        if (statusInput) statusInput.value = 'all';
        currentSearch = '';
        currentStatusFilter = 'all';
        if (!document.getElementById('bookingsTableBody')) {
            const params = new URLSearchParams({ view: currentView });
            window.location.href = 'booking.php?' + params.toString();
            return;
        }

        // Update URL to remove filters on refresh
        const params = new URLSearchParams(window.location.search);
        params.delete('status');
        params.delete('search');
        window.history.pushState({}, '', window.location.pathname + '?' + params.toString());

        refreshBookings();
    }

    function getVisibleBookingRowDisplay() {
        return (window.matchMedia && window.matchMedia('(max-width: 768px)').matches)
            ? 'grid'
            : 'table-row';
    }

    function expandedBookingStoresKey() {
        return 'customerBookingExpanded:' + <?php echo json_encode((string) $user_id); ?> + ':' + currentView;
    }

    const expandedBookingGroups = new Map();

    function readExpandedBookingStores() {
        const key = expandedBookingStoresKey();
        if (expandedBookingGroups.has(key)) return expandedBookingGroups.get(key);
        try {
            const saved = JSON.parse(sessionStorage.getItem(key) || '[]');
            return new Set(Array.isArray(saved) ? saved.map(String).filter(id => id === 'fully-paid' || /^\d+$/.test(id)) : []);
        } catch (error) {
            return new Set();
        }
    }

    function restoreExpandedBookingStores() {
        const expanded = readExpandedBookingStores();
        document.querySelectorAll('#bookingsTableBody tr[class*="caterer-order-"]').forEach(row => {
            const match = row.className.match(/caterer-order-(\d+|fully-paid)/);
            if (!match) return;
            const isExpanded = expanded.has(match[1]);
            row.style.display = isExpanded ? getVisibleBookingRowDisplay() : 'none';
            setBookingGroupExpanded(match[1], isExpanded);
        });
    }

    function setBookingGroupExpanded(groupId, isExpanded) {
        const icon = document.getElementById('icon-caterer-' + groupId);
        if (icon) icon.className = isExpanded ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
        if (groupId === 'fully-paid') {
            document.getElementById('toggle-fully-paid-bookings')?.setAttribute('aria-expanded', String(isExpanded));
        }
    }

    function toggleCatererOrders(storeId) {
        const rows = document.querySelectorAll('.caterer-order-' + storeId);

        // Check current state: visible = expanded, none = collapsed
        const isExpanded = rows.length > 0 && rows[0].style.display !== 'none';
        const expanded = readExpandedBookingStores();
        if (isExpanded) expanded.delete(String(storeId));
        else expanded.add(String(storeId));
        expandedBookingGroups.set(expandedBookingStoresKey(), expanded);
        try {
            sessionStorage.setItem(expandedBookingStoresKey(), JSON.stringify([...expanded]));
        } catch (error) {
            // The current page remains usable when browser storage is unavailable.
        }

        rows.forEach(row => {
            row.style.display = isExpanded ? 'none' : getVisibleBookingRowDisplay();
        });

        setBookingGroupExpanded(storeId, !isExpanded);
    }

    function refreshBookings() {
        // Add timestamp to prevent caching
        const params = new URLSearchParams({
            get_bookings_list: '1',
            view: currentView,
            status: currentStatusFilter,
            search: currentSearch,
            _: new Date().getTime().toString()
        });

        fetch('booking.php?' + params.toString())
            .then(response => response.text())
            .then(html => {
                const tbody = document.getElementById('bookingsTableBody');
                const scrollAreas = [...document.querySelectorAll('.booking-list-card, .bookings-table-wrap')]
                    .map(element => ({ element, top: element.scrollTop, left: element.scrollLeft }));
                if (tbody) {
                    if (html.trim().includes('<tr')) {
                        tbody.innerHTML = html;
                        // Read the latest choice, including clicks made while loading.
                        restoreExpandedBookingStores();
                    } else {
                        tbody.innerHTML = '<tr class="empty-bookings-row"><td colspan="7" style="text-align:center; padding: 20px; color: var(--text-muted);">No bookings found.</td></tr>';
                    }
                    scrollAreas.forEach(({ element, top, left }) => {
                        element.scrollTop = top;
                        element.scrollLeft = left;
                    });
                } else if (document.querySelector('.empty-state') && html.trim().includes('<tr')) {
                    // If we were in empty state but now have data, reload to get table structure back
                    location.reload();
                }
            })
            .catch(err => console.error('Error refreshing bookings:', err));
    }

    const bookingSearchInput = document.getElementById('bookingSearch');
    if (bookingSearchInput) {
        bookingSearchInput.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                applyBookingFilters();
            }
        });

        let bookingSearchTimer = null;
        bookingSearchInput.addEventListener('input', function() {
            clearTimeout(bookingSearchTimer);
            bookingSearchTimer = setTimeout(() => {
                applyBookingFilters();
            }, 350);
        });
    }

    const bookingStatusFilter = document.getElementById('bookingStatusFilter');
    if (bookingStatusFilter) {
        bookingStatusFilter.addEventListener('change', applyBookingFilters);
    }

    function restoreBooking(orderId) {
        if (!confirm('Restore this booking to your main list?')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('restore_booking', '1');
        formData.append('order_id', orderId);
        
        fetch('booking.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                refreshBookings();
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while restoring the booking.');
        });
    }

    let customConfirmCallback = null;

    function showConfirmModal(title, message, callback) {
        document.getElementById('confirmActionTitle').textContent = title;
        document.getElementById('confirmActionMessage').textContent = message;
        customConfirmCallback = callback;
        document.getElementById('confirmActionModal').style.display = 'flex';
    }

    function closeConfirmModal() {
        document.getElementById('confirmActionModal').style.display = 'none';
        customConfirmCallback = null;
    }

    document.getElementById('confirmActionBtn').addEventListener('click', function() {
        if (customConfirmCallback) {
            customConfirmCallback();
        }
        closeConfirmModal();
    });

    function permanentlyDeleteBooking(orderId) {
        showConfirmModal(
            'Confirm Delete',
            'This will permanently delete this booking and all its records. This action CANNOT be undone.\n\nAre you sure you want to permanently delete this booking?',
            function() {
                const formData = new FormData();
                formData.append('permanently_delete_booking', '1');
                formData.append('order_id', orderId);

                fetch('booking.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        refreshBookings();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while permanently deleting the booking.');
                });
            }
        );
    }

    function deleteAllTrash() {
        showConfirmModal(
            'Empty Trash',
            'This will permanently delete ALL bookings in your trash. This action CANNOT be undone.\n\nAre you absolutely sure you want to empty the trash?',
            function() {
                const formData = new FormData();
                formData.append('delete_all_trash', '1');

                fetch('booking.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        refreshBookings();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while emptying the trash.');
                });
            }
        );
    }

    restoreExpandedBookingStores();
    window.addEventListener('pageshow', restoreExpandedBookingStores);

    // Refresh every 3 seconds while retaining expanded caterers.
    setInterval(function() {
        refreshBookings();
        if (currentOpenBookingId && !inlineSetMenuState[currentOpenBookingId]) {
            fetchDetails(currentOpenBookingId);
        }
    }, 3000);
</script>

<script>
(() => {
    const key = 'bookingReceiptReturn';
    const heading = document.querySelector('.bookings-table-wrap thead');
    if (heading) {
        const updateHeadingHeight = () => {
            document.querySelector('.booking-list-card')?.style.setProperty('--booking-heading-height', heading.getBoundingClientRect().height + 'px');
        };
        new ResizeObserver(updateHeadingHeight).observe(heading);
        updateHeadingHeight();
    }
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href*="booking-receipt.php"], a[href*="booking-details.php"], a[href*="book-service.php"]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
        const list = document.querySelector('.booking-list-card');
        const expanded = [...document.querySelectorAll('#bookingsTableBody tr[class*="caterer-order-"]')]
            .filter(row => row.style.display !== 'none')
            .map(row => row.className.match(/caterer-order-(\d+|fully-paid)/)?.[1]).filter(Boolean);
        sessionStorage.setItem(key, JSON.stringify({
            url: location.href, expanded: [...new Set(expanded)],
            listScroll: list?.scrollTop || 0, pageScroll: window.scrollY,
            areas: [...document.querySelectorAll('.booking-list-card, .bookings-table-wrap')]
                .map(element => ({ selector: element.classList.contains('booking-list-card') ? '.booking-list-card' : '.bookings-table-wrap', top: element.scrollTop, left: element.scrollLeft }))
        }));
        event.preventDefault();
        const destination = new URL(link.href, location.href);
        if (destination.pathname.endsWith('/book-service.php')) {
            destination.searchParams.set('cancel_from', 'bookings');
            destination.searchParams.set('cancel_return', location.pathname + location.search);
        }
        location.href = destination.href;
    });
    function restoreReceiptPosition() {
        const raw = sessionStorage.getItem(key);
        if (!raw) return;
        const saved = JSON.parse(raw);
        if (saved.url !== location.href) return;
        saved.expanded.forEach(id => {
            setBookingGroupExpanded(id, true);
            document.querySelectorAll('.caterer-order-' + id).forEach(row => {
                row.style.display = getVisibleBookingRowDisplay();
            });
        });
        requestAnimationFrame(() => {
            const list = document.querySelector('.booking-list-card');
            if (list) list.scrollTop = saved.listScroll;
            (saved.areas || []).forEach(area => {
                const element = document.querySelector(area.selector);
                if (element) { element.scrollTop = area.top; element.scrollLeft = area.left; }
            });
            window.scrollTo(0, saved.pageScroll);
            sessionStorage.removeItem(key);
        });
    }
    window.addEventListener('pageshow', restoreReceiptPosition);
})();
</script>

<script>
(() => {
 const key='bookAgainListOrigin';
 document.addEventListener('click',event=>{
  const link=event.target.closest('a[data-book-again-id]');
  if(!link||event.ctrlKey||event.metaKey||event.shiftKey||event.button!==0)return;
  event.preventDefault();event.stopImmediatePropagation();
  const row=link.closest('tr');
  const expanded=[...document.querySelectorAll('#bookingsTableBody tr[class*="caterer-order-"]')].filter(el=>el.style.display!=='none').map(el=>el.className.match(/caterer-order-(\d+|fully-paid)/)?.[1]).filter(Boolean);
  const areas=[...document.querySelectorAll('.booking-list-card,.bookings-table-wrap')].map(el=>({selector:el.classList.contains('booking-list-card')?'.booking-list-card':'.bookings-table-wrap',top:el.scrollTop,left:el.scrollLeft}));
  sessionStorage.setItem(key,JSON.stringify({url:location.href,id:link.dataset.bookAgainId,expanded:[...new Set(expanded)],areas,top:scrollY,left:scrollX}));
  const target=new URL(link.href);target.searchParams.set('cancel_from','bookings');target.searchParams.set('cancel_return',location.pathname+location.search);
  location.href=target.href;
 },true);
 window.addEventListener('pageshow',()=>{
  let saved;try{saved=JSON.parse(sessionStorage.getItem(key)||'null')}catch(_){return}
  if(!saved||saved.url!==location.href)return;
  saved.expanded.forEach(id=>{setBookingGroupExpanded(id,true);document.querySelectorAll('.caterer-order-'+id).forEach(row=>{row.style.display=getVisibleBookingRowDisplay()})});
  requestAnimationFrame(()=>requestAnimationFrame(()=>{
   saved.areas.forEach(area=>{const el=document.querySelector(area.selector);if(el){el.scrollTop=area.top;el.scrollLeft=area.left}});
   window.scrollTo(saved.left,saved.top);
   const link=document.querySelector('a[data-book-again-id="'+saved.id+'"]');
   const row=link?.closest('tr');
   if(row){const box=row.getBoundingClientRect();if(box.top<0||box.bottom>innerHeight)row.scrollIntoView({block:'center',inline:'nearest'});}
   sessionStorage.removeItem(key);
  }));
 });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


