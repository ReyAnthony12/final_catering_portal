<?php
// Start session first as header.php checks it
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/reservation_status_helper.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/financial_helper.php';

if (!isLoggedIn() || !in_array(getUserRole(), ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

require_once __DIR__ . '/../includes/order_reference_helper.php';
$booking_display_numbers = getCatererBookingNumbers($user_id)['ids'];

// ==========================================
// AUTO-COMPLETE PAST FULLY PAID ORDERS
// ==========================================
$current_date_str = date('Y-m-d');
$auto_complete_q = mysqli_query($conn, "
    SELECT id, order_status 
    FROM bookings 
    WHERE store_id = '$user_id' 
      AND order_status IN ('confirmed', 'approved', 'preparing', 'ready', 'delivering')
      AND order_date < '$current_date_str'
");
if ($auto_complete_q) {
    while ($auto_row = mysqli_fetch_assoc($auto_complete_q)) {
        $a_order_id = $auto_row['id'];
        $a_fin = getOrderFinancials($a_order_id);
        if ($a_fin) {
            $a_balance = (float) ($a_fin['totals']['balance'] ?? 0);
            if ($a_balance <= 0.01) {
                if (!catererChangeReservationStatus($conn, (int) $a_order_id, (int) $user_id, 'completed', $auto_row['order_status'])) continue;
                // Auto-complete order
                $evt_check = mysqli_query($conn, "SELECT status FROM events WHERE order_id = '$a_order_id'");
                $evt = mysqli_fetch_assoc($evt_check);
                $event_already_completed = ($evt && $evt['status'] === 'Completed');
                
                $usage_q = mysqli_query($conn, "SELECT iu.id, iu.inventory_item_id, iu.quantity_used, ii.item_type 
                                                FROM inventory_usage iu 
                                                JOIN inventory_items ii ON iu.inventory_item_id = ii.id 
                                                WHERE iu.order_id = '$a_order_id' AND iu.status != 'returned'");
                while ($usage = mysqli_fetch_assoc($usage_q)) {
                    if (!$event_already_completed && $usage['item_type'] === 'equipment') {
                        $qty = $usage['quantity_used'];
                        $item_id = $usage['inventory_item_id'];
                        mysqli_query($conn, "UPDATE inventory_items SET available_stock = available_stock + '$qty' WHERE id = '$item_id'");
                        mysqli_query($conn, "UPDATE inventory_usage SET status = 'returned' WHERE id = '{$usage['id']}'");
                        mysqli_query($conn, "INSERT INTO inventory_logs (inventory_item_id, store_id, change_amount, change_type, reference_id, reason) VALUES ('$item_id', '$user_id', '$qty', 'returned', '$a_order_id', 'Booking #$a_order_id auto-completed (Equipment returned)')");
                    } else {
                        $new_status = ($usage['item_type'] === 'equipment') ? 'returned' : 'consumed';
                        mysqli_query($conn, "UPDATE inventory_usage SET status = '$new_status' WHERE id = '{$usage['id']}'");
                    }
                }
                
                mysqli_query($conn, "UPDATE events SET status = 'Completed' WHERE order_id = '$a_order_id'");

                
                $order_q = mysqli_query($conn, "SELECT customer_id, order_number FROM bookings WHERE id = '$a_order_id'");
                $order_data = mysqli_fetch_assoc($order_q);
                if ($order_data) {
                    $cust_id = $order_data['customer_id'];
                    $ord_num = $order_data['order_number'];
                    $msg = "Your booking #$ord_num has been automatically marked as completed.";
                    if (function_exists('createNotification')) {
                        createNotification($cust_id, 'order_status', "Order Completed", $msg, "booking-details.php?id=$a_order_id");
                    }
                }
            }
        }
    }
}

// ==========================================
// AUTO-PREPARE TODAY'S CONFIRMED ORDERS
// ==========================================
$auto_prepare_q = mysqli_query($conn, "
    SELECT id 
    FROM bookings 
    WHERE store_id = '$user_id' 
      AND order_status = 'confirmed'
      AND order_date <= '$current_date_str'
");
if ($auto_prepare_q) {
    while ($ap_row = mysqli_fetch_assoc($auto_prepare_q)) {
        $ap_order_id = $ap_row['id'];
        $ap_fin = getOrderFinancials($ap_order_id);
        
        if ($ap_fin) {
            $verified_paid = (float) ($ap_fin['totals']['total_paid'] ?? 0);
            $down_payment_required = (float) ($ap_fin['down_payment_required'] ?? 0);
            $downpayment_covered = $down_payment_required > 0 ? ($verified_paid >= $down_payment_required) : ($verified_paid > 0);
            
            if ($downpayment_covered) {
                // Update bookings table
                if (!catererChangeReservationStatus($conn, (int) $ap_order_id, (int) $user_id, 'preparing', 'confirmed')) continue;
                
                // Update events table status
                mysqli_query($conn, "UPDATE events SET status = 'Prepared' WHERE order_id = '$ap_order_id'");
                
                // Notify customer
                $order_q = mysqli_query($conn, "SELECT customer_id, order_number FROM bookings WHERE id = '$ap_order_id'");
                $order_data = mysqli_fetch_assoc($order_q);
                if ($order_data) {
                    $cust_id = $order_data['customer_id'];
                    $ord_num = $order_data['order_number'];
                    $msg = "Your booking #$ord_num status has been automatically updated to Preparing because today is the event day.";
                    if (function_exists('createNotification')) {
                        createNotification($cust_id, 'order_status', "Order Updated", $msg, "booking-details.php?id=$ap_order_id");
                    }
                }
            }
        }
    }
}

function reservationCustomerAvatar($customer) {
    $path = ltrim((string) ($customer['profile_image'] ?? ''), '/');
    $fallback = '<div class="reservation-customer-avatar reservation-avatar-fallback" aria-hidden="true"><i class="fas fa-user"></i></div>';
    if (strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false || !is_file(__DIR__ . '/../' . $path)) {
        return $fallback;
    }
    $src = '../' . $path . '?v=' . filemtime(__DIR__ . '/../' . $path);
    return '<img class="reservation-customer-avatar" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="Customer profile photo" width="42" height="42" onerror="this.hidden=true;this.nextElementSibling.hidden=false;">' . str_replace('<div ', '<div hidden ', $fallback);
}

function parseStoreReservationMenuChangeRequest($message_text) {
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

function getReservationActionFlags($conn, $reservation, $financials) {
    $order_id = intval($reservation['id'] ?? 0);
    $customer_id = intval($reservation['customer_id'] ?? 0);
    $verified_paid = (float) ($financials['totals']['total_paid'] ?? 0);
    $pending_payments = (float) ($financials['totals']['pending_payments'] ?? 0);
    $down_payment_required = (float) ($financials['down_payment_required'] ?? 0);
    $downpayment_covered = $down_payment_required > 0 ? ($verified_paid >= $down_payment_required) : ($verified_paid > 0);
    $flags = [
        'payment_review' => $pending_payments > 0 && !$downpayment_covered,
        'reschedule_review' => strtolower(trim((string) ($reservation['reschedule_status'] ?? ''))) === 'pending'
            && !empty($reservation['requested_order_date'])
            && !empty($reservation['requested_order_time']),
        'menu_review' => false
    ];

    if ($order_id <= 0 || $customer_id <= 0) {
        return $flags;
    }

    $menu_request_q = mysqli_query($conn, "
        SELECT id, message, created_at
        FROM messages
        WHERE order_id = '$order_id'
          AND sender_id = '$customer_id'
          AND (subject LIKE '%Menu Change Request%' OR message LIKE '%Requested Set:%')
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");

    if ($menu_request_q && $menu_request_row = mysqli_fetch_assoc($menu_request_q)) {
        $menu_change_request = parseStoreReservationMenuChangeRequest($menu_request_row['message'] ?? '');
        if (!empty($menu_change_request['requested_set'])) {
            $status_log_q = mysqli_query($conn, "
                SELECT id
                FROM booking_logs
                WHERE order_id = '$order_id'
                  AND action IN ('Menu Change Approved', 'Menu Change Rejected')
                  AND created_at >= '{$menu_request_row['created_at']}'
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            $flags['menu_review'] = !($status_log_q && mysqli_fetch_assoc($status_log_q));
        }
    }

    return $flags;
}

function getReservationsActionFilterSql($filter_value) {
    $filter_value = trim((string) $filter_value);
    if ($filter_value === 'payments') {
        return "
            AND EXISTS (
                SELECT 1
                FROM payments pay
                WHERE pay.order_id = o.id
                  AND pay.status = 'pending'
            )
            AND COALESCE((
                SELECT SUM(pay_verified.amount)
                FROM payments pay_verified
                WHERE pay_verified.order_id = o.id
                  AND pay_verified.status = 'verified'
            ), 0) < (
                CASE
                    WHEN COALESCE(o.down_payment_required, 0) > 0 THEN o.down_payment_required
                    ELSE COALESCE(o.total_amount, 0) * 0.5
                END
            )
        ";
    }

    if ($filter_value === 'reschedules') {
        return "
            AND LOWER(COALESCE(o.reschedule_status, '')) = 'pending'
            AND COALESCE(o.requested_order_date, '') <> ''
            AND COALESCE(o.requested_order_time, '') <> ''
        ";
    }

    if ($filter_value === 'menu_changes') {
        return "
            AND EXISTS (
                SELECT 1
                FROM messages m
                WHERE m.order_id = o.id
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
            )
        ";
    }

    if ($filter_value === 'needs_attention') {
        return "
            AND (
                EXISTS (
                    SELECT 1
                    FROM payments pay
                    WHERE pay.order_id = o.id
                      AND pay.status = 'pending'
                )
                AND COALESCE((
                    SELECT SUM(pay_verified.amount)
                    FROM payments pay_verified
                    WHERE pay_verified.order_id = o.id
                      AND pay_verified.status = 'verified'
                ), 0) < (
                    CASE
                        WHEN COALESCE(o.down_payment_required, 0) > 0 THEN o.down_payment_required
                        ELSE COALESCE(o.total_amount, 0) * 0.5
                    END
                )
                OR (
                    LOWER(COALESCE(o.reschedule_status, '')) = 'pending'
                    AND COALESCE(o.requested_order_date, '') <> ''
                    AND COALESCE(o.requested_order_time, '') <> ''
                )
                OR EXISTS (
                    SELECT 1
                    FROM messages m
                    WHERE m.order_id = o.id
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
                )
            )
        ";
    }

    return '';
}

// Check if caterer is closed by portal
    $store_status_query = "SELECT u.status as user_status FROM users u WHERE u.id = '$user_id'";
    $store_status_result = mysqli_query($conn, $store_status_query);
    $store_status = mysqli_fetch_assoc($store_status_result);
    $is_store_closed_by_portal = ($store_status['user_status'] === 'closed');
    
    // Caterer closed override
    if ($is_store_closed_by_portal) {
        if (isset($_POST['update_status_ajax'])) {
            echo json_encode(['success' => false, 'error' => 'Caterer is closed by admin.']);
            exit();
        }
    }

// ============ Check subscription and free trial status ============
$can_update_status = false;
$subscription_query = "SELECT subscription_status, subscription_expiry, free_trial_start, free_trial_used, free_trial_expiry FROM store_details WHERE user_id = '$user_id'";
$subscription_result = mysqli_query($conn, $subscription_query);
$subscription = mysqli_fetch_assoc($subscription_result);

if (!$is_store_closed_by_portal) {
    if ($subscription && $subscription['subscription_status'] === 'active') {
        $current_date = date('Y-m-d');
        if ($current_date <= $subscription['subscription_expiry']) {
            $can_update_status = true;
        } else {
            mysqli_query($conn, "UPDATE store_details SET subscription_status = 'inactive' WHERE user_id = '$user_id'");
        }
    } else if ($subscription && $subscription['free_trial_start'] && !$subscription['free_trial_used']) {
        if (date('Y-m-d') <= $subscription['free_trial_expiry']) {
            $can_update_status = true;
        } else {
            mysqli_query($conn, "UPDATE store_details SET free_trial_used = 1 WHERE user_id = '$user_id'");
        }
    }
}

// Handle status update (AJAX)
if (isset($_POST['update_status_ajax'])) {
        if (!$can_update_status) {
            echo json_encode(['success' => false, 'error' => 'Subscription inactive or expired.']);
            exit();
        }

    $order_id = intval($_POST['order_id'] ?? 0);
    $status = strtolower(trim((string) ($_POST['status'] ?? '')));

    if ($order_id <= 0 || !in_array($status, ['pending', 'confirmed', 'preparing', 'ready', 'delivering', 'completed', 'cancelled', 'rejected', 'declined'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid reservation status update.']);
        exit();
    }

    $terminal = mysqli_query($conn, "SELECT order_status FROM bookings WHERE id = '$order_id' AND store_id = '$user_id' LIMIT 1");
    $terminalBooking = $terminal ? mysqli_fetch_assoc($terminal) : null;
    if (!$terminalBooking || in_array(strtolower($terminalBooking['order_status']), ['cancelled', 'rejected', 'declined'], true)) {
        if (isset($_POST['update_status_ajax'])) {
            echo json_encode(['success' => false, 'error' => 'This booking is already cancelled. Refresh the reservation list.']);
        } else {
            header('Location: reservations.php?tab=cancelled');
        }
        exit();
    }

    $order_id_sql = mysqli_real_escape_string($conn, (string) $order_id);
    $owned_order_q = mysqli_query($conn, "SELECT order_status, number_of_pax FROM bookings WHERE id = '$order_id_sql' AND store_id = '$user_id' LIMIT 1");
    $prev_data = $owned_order_q ? mysqli_fetch_assoc($owned_order_q) : null;
    if (!$prev_data) {
        echo json_encode(['success' => false, 'error' => 'Reservation not found.']);
        exit();
    }

    if (in_array($status, ['cancelled', 'rejected', 'declined'])) {
        $financials = getOrderFinancials($order_id);
        $balance = (float) ($financials['totals']['balance'] ?? 0);
        $total = (float) ($financials['totals']['grand_total'] ?? 0);
        $paid = (float) ($financials['totals']['total_paid'] ?? 0);
        if ($paid >= $total && $total > 0) {
            echo json_encode(['success' => false, 'error' => 'This reservation cannot be cancelled because it is already fully paid.']);
            exit();
        }
    }

    if ($status === 'completed') {
        $financials = getOrderFinancials($order_id);
        $balance = (float) ($financials['totals']['balance'] ?? 0);
        if ($balance > 0.01) {
            echo json_encode(['success' => false, 'error' => 'This reservation cannot be completed until the customer is fully paid.']);
            exit();
        }
    }

    if ($status === 'preparing') {
        $financials = getOrderFinancials($order_id);
        $verified_paid = (float) ($financials['totals']['total_paid'] ?? 0);
        $down_payment_required = (float) ($financials['down_payment_required'] ?? 0);
        $downpayment_covered = $down_payment_required > 0 ? ($verified_paid >= $down_payment_required) : ($verified_paid > 0);
        if (!$downpayment_covered) {
            echo json_encode(['success' => false, 'error' => 'Cannot set status to Preparing. The customer has not yet paid the down payment.']);
            exit();
        }
        // Get the event date from the order
        $event_date_q = mysqli_query($conn, "SELECT order_date FROM bookings WHERE id = '$order_id_sql' LIMIT 1");
        $event_date_row = mysqli_fetch_assoc($event_date_q);
        $event_date_val = $event_date_row ? date('Y-m-d', strtotime($event_date_row['order_date'])) : '';
        if ($event_date_val !== date('Y-m-d')) {
            echo json_encode(['success' => false, 'error' => 'Preparing can only be set on the event day (' . date('M d, Y', strtotime($event_date_val)) . ').']);
            exit();
        }
    }

    if ($status === 'pending' && in_array(strtolower($prev_data['order_status'] ?? ''), ['preparing', 'ready', 'delivering', 'completed'])) {
        echo json_encode(['success' => false, 'error' => 'Cannot revert status back to Pending once the order is already being prepared.']);
        exit();
    }

    if ($status === 'confirmed' && in_array(strtolower($prev_data['order_status'] ?? ''), ['preparing', 'ready', 'delivering', 'completed'])) {
        echo json_encode(['success' => false, 'error' => 'Cannot revert status back to Accept Booking once the order is already being prepared.']);
        exit();
    }
    
    // Get previous status for inventory logic
    $prev_status = strtolower($prev_data['order_status']);
    
        if (catererChangeReservationStatus($conn, (int) $order_id, (int) $user_id, $status, $prev_status)) {
            // Inventory Automation: Track Usage

        
        // Inventory and notices run only after the guarded status change.

        // Get necessary data
        $order_details_q = mysqli_query($conn, "SELECT product_id, number_of_pax, order_date, additional_menu FROM bookings WHERE id = '$order_id'");
        $order_details = mysqli_fetch_assoc($order_details_q);
        $product_id = $order_details['product_id'];
        $pax_count = intval($order_details['number_of_pax']);
        $event_date = $order_details['order_date'];
        $selected_set_name = trim((string) ($order_details['additional_menu'] ?? ''));

        // Get customer name for event table
        $cust_q = mysqli_query($conn, "SELECT u.first_name, u.last_name, p.name as service_name, o.total_amount 
                                       FROM bookings o 
                                       JOIN users u ON o.customer_id = u.id 
                                       JOIN products p ON o.product_id = p.id 
                                       WHERE o.id = '$order_id'");
        $cust_data = mysqli_fetch_assoc($cust_q);
        $cust_name = $cust_data['first_name'] . ' ' . $cust_data['last_name'];
        $service_name = $cust_data['service_name'];
        $total_amount = $cust_data['total_amount'];

        if (in_array($status, ['confirmed', 'preparing', 'ready', 'delivering'])) {
            // Check/Create Event Record
            $event_check = mysqli_query($conn, "SELECT id FROM events WHERE order_id = '$order_id'");
            $event_id = 0;
            
            if (mysqli_num_rows($event_check) == 0) {
                $status_for_event = ($status == 'confirmed') ? 'Reserved' : 'Prepared';
                $ins_event = "INSERT INTO events (store_id, order_id, customer_name, service_type, pax, price, event_date, status) 
                              VALUES ('$user_id', '$order_id', '$cust_name', '$service_name', '$pax_count', '$total_amount', '$event_date', '$status_for_event')";
                if (mysqli_query($conn, $ins_event)) {
                    $event_id = mysqli_insert_id($conn);
                }
            } else {
                $e_row = mysqli_fetch_assoc($event_check);
                $event_id = $e_row['id'];
                // Update status if needed
                if ($status == 'preparing' || $status == 'ready' || $status == 'delivering') {
                    mysqli_query($conn, "UPDATE events SET status = 'Prepared' WHERE id = '$event_id'");
                }
            }

            // 1. Get mapped items for the selected set, with fallback to general mappings
            $mapping_filter = "AND (si.set_name IS NULL OR si.set_name = '')";
            if ($selected_set_name !== '') {
                $selected_set_name_esc = mysqli_real_escape_string($conn, $selected_set_name);
                $specific_map_check = mysqli_query($conn, "SELECT id FROM service_inventory WHERE service_id = '$product_id' AND set_name = '$selected_set_name_esc' LIMIT 1");
                if ($specific_map_check && mysqli_num_rows($specific_map_check) > 0) {
                    $mapping_filter = "AND si.set_name = '$selected_set_name_esc'";
                }
            }

            $mappings = mysqli_query($conn, "SELECT si.*, ii.item_name FROM service_inventory si JOIN inventory_items ii ON si.inventory_item_id = ii.id WHERE si.service_id = '$product_id' $mapping_filter");
            while ($map = mysqli_fetch_assoc($mappings)) {
                $qty_needed = max(0, (int) $map['quantity_required']);
                if (!empty($map['is_pax_dependent']) && $qty_needed > 0) {
                    $qty_needed = max(0, $pax_count);
                }
                $item_id = $map['inventory_item_id'];
                $item_name = $map['item_name'];
                
                // 2. Check existing usage (Inventory Usage Table)
                $usage_q = mysqli_query($conn, "SELECT id FROM inventory_usage WHERE order_id = '$order_id' AND inventory_item_id = '$item_id'");
                if ($usage_row = mysqli_fetch_assoc($usage_q)) {
                     mysqli_query($conn, "UPDATE inventory_usage SET status = 'active' WHERE id = '{$usage_row['id']}'");
                } else {
                    // New reservation - Deduct Stock
                    mysqli_query($conn, "UPDATE inventory_items SET available_stock = available_stock - '$qty_needed' WHERE id = '$item_id'");
                    mysqli_query($conn, "INSERT INTO inventory_usage (inventory_item_id, order_id, event_date, quantity_used, status) VALUES ('$item_id', '$order_id', '$event_date', '$qty_needed', 'active')");
                    mysqli_query($conn, "INSERT INTO inventory_logs (inventory_item_id, store_id, change_amount, change_type, reference_id, reason) VALUES ('$item_id', '$user_id', '-$qty_needed', 'deducted', '$order_id', 'Booking #$order_id confirmed')");
                }
                
                // 3. Sync to Event Preparation (Event Inventory Table)
                if ($event_id > 0) {
                    $ei_check = mysqli_query($conn, "SELECT id FROM event_inventory WHERE event_id = '$event_id' AND item_name = '$item_name'");
                    if (mysqli_num_rows($ei_check) == 0) {
                        mysqli_query($conn, "INSERT INTO event_inventory (event_id, item_name, quantity_required) VALUES ('$event_id', '$item_name', '$qty_needed')");
                    }
                }
            }
        } elseif (in_array($status, ['cancelled', 'rejected', 'declined'])) {
            // Release stock
            $usage_q = mysqli_query($conn, "SELECT id, inventory_item_id, quantity_used FROM inventory_usage WHERE order_id = '$order_id'");
            while ($usage = mysqli_fetch_assoc($usage_q)) {
                $qty = $usage['quantity_used'];
                $item_id = $usage['inventory_item_id'];
                mysqli_query($conn, "UPDATE inventory_items SET available_stock = available_stock + '$qty' WHERE id = '$item_id'");
                mysqli_query($conn, "DELETE FROM inventory_usage WHERE id = '{$usage['id']}'");
                mysqli_query($conn, "INSERT INTO inventory_logs (inventory_item_id, store_id, change_amount, change_type, reference_id, reason) VALUES ('$item_id', '$user_id', '$qty', 'restocked', '$order_id', 'Booking #$order_id cancelled')");
            }
        } elseif ($status === 'completed') {
            // Check if event is already completed (to prevent double return)
            $evt_check = mysqli_query($conn, "SELECT status FROM events WHERE order_id = '$order_id'");
            $evt = mysqli_fetch_assoc($evt_check);
            $event_already_completed = ($evt && $evt['status'] === 'Completed');

            // Handle returns for equipment only
            $usage_q = mysqli_query($conn, "SELECT iu.id, iu.inventory_item_id, iu.quantity_used, ii.item_type 
                                            FROM inventory_usage iu 
                                            JOIN inventory_items ii ON iu.inventory_item_id = ii.id 
                                            WHERE iu.order_id = '$order_id' AND iu.status != 'returned'");
            while ($usage = mysqli_fetch_assoc($usage_q)) {
                if (!$event_already_completed && $usage['item_type'] === 'equipment') {
                    // Return to stock
                    $qty = $usage['quantity_used'];
                    $item_id = $usage['inventory_item_id'];
                    mysqli_query($conn, "UPDATE inventory_items SET available_stock = available_stock + '$qty' WHERE id = '$item_id'");
                    mysqli_query($conn, "UPDATE inventory_usage SET status = 'returned' WHERE id = '{$usage['id']}'");
                    mysqli_query($conn, "INSERT INTO inventory_logs (inventory_item_id, store_id, change_amount, change_type, reference_id, reason) VALUES ('$item_id', '$user_id', '$qty', 'returned', '$order_id', 'Booking #$order_id completed (Equipment returned)')");
                } else {
                    // Consumable OR Event already handled return -> Just mark as processed
                    $new_status = ($usage['item_type'] === 'equipment') ? 'returned' : 'consumed';
                    mysqli_query($conn, "UPDATE inventory_usage SET status = '$new_status' WHERE id = '{$usage['id']}'");
                }
            }
        }

        if (mysqli_affected_rows($conn) == 0) {
        // Check if it was already that status
        $check_q = mysqli_query($conn, "SELECT order_status FROM bookings WHERE id = '$order_id'");
        $current = mysqli_fetch_assoc($check_q);
        
        if ($current) {
             // Compare normalized statuses
             $current_s = strtolower($current['order_status']);
             $new_s = strtolower($status);
             
             if ($current_s != $new_s) {
                 $error = $current_s !== strtolower($prev_status)
                     ? 'The booking status has changed. Check the updated booking before trying again.'
                     : 'Unable to update the booking status.';
                 echo json_encode(['success' => false, 'error' => $error]);
                 exit();
             }
        }
    }
        // Create notification for customer
        $order_q = mysqli_query($conn, "SELECT customer_id, order_number FROM bookings WHERE id = '$order_id'");
        $order_data = mysqli_fetch_assoc($order_q);
        if ($order_data) {
            $cust_id = $order_data['customer_id'];
            $ord_num = $order_data['order_number'];
            if (in_array(strtolower($status), ['confirmed', 'approved'])) {
                $msg = "Good news! Your booking #$ord_num has been approved by the caterer. You may now proceed to settle your payment.";
                $subject = "Booking Approved - Payment Required";
                
                // Insert into messages table
                $msg_safe = mysqli_real_escape_string($conn, $msg);
                $subj_safe = mysqli_real_escape_string($conn, $subject);
                mysqli_query($conn, "INSERT INTO messages (sender_id, receiver_id, order_id, subject, message, created_at) 
                                     VALUES ('$user_id', '$cust_id', '$order_id', '$subj_safe', '$msg_safe', NOW())");
                                     
                if (function_exists('createNotification')) {
                    createNotification($cust_id, 'order_status', "Booking Approved", $msg, "booking-details.php?id=$order_id");
                }
            } else {
                $msg = "Your booking #$ord_num status has been updated to " . ucfirst($status);
                if (function_exists('createNotification')) {
                    createNotification($cust_id, 'order_status', "Order Updated", $msg, "booking-details.php?id=$order_id");
                }
            }
        }
        echo json_encode(['success' => true]);
    } else {
        appLogError('Caterer reservations status update failed', mysqli_error($conn));
        echo json_encode(['success' => false, 'error' => 'Unable to update the reservation right now. Please try again.']);
    }
    exit();
}

// Handle legacy form post (fallback)
if (isset($_POST['update_status'])) {
    $order_id = intval($_POST['order_id'] ?? 0);
    $terminal = mysqli_query($conn, "SELECT order_status FROM bookings WHERE id = '$order_id' AND store_id = '$user_id' LIMIT 1");
    $terminalBooking = $terminal ? mysqli_fetch_assoc($terminal) : null;
    if (!$terminalBooking || in_array(strtolower($terminalBooking['order_status']), ['cancelled', 'rejected', 'declined'], true)) {
        if (isset($_POST['update_status_ajax'])) {
            echo json_encode(['success' => false, 'error' => 'This booking is already cancelled. Refresh the reservation list.']);
        } else {
            header('Location: reservations.php?tab=cancelled');
        }
        exit();
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    $status = strtolower(trim((string) ($_POST['status'] ?? '')));

    if (in_array($status, ['cancelled', 'rejected', 'declined'])) {
        $financials = getOrderFinancials($order_id);
        $balance = (float) ($financials['totals']['balance'] ?? 0);
        $total = (float) ($financials['totals']['grand_total'] ?? 0);
        $paid = (float) ($financials['totals']['total_paid'] ?? 0);
        if ($paid >= $total && $total > 0) {
            header('Location: reservations.php?error=cannot_cancel_paid');
            exit();
        }
    }

    if ($status === 'completed') {
        $financials = getOrderFinancials($order_id);
        $balance = (float) ($financials['totals']['balance'] ?? 0);
        if ($balance > 0.01) {
            header('Location: reservations.php?error=full_payment_required');
            exit();
        }
    }
    
    $status_changed = catererChangeReservationStatus($conn, (int) $order_id, (int) $user_id, $status, $terminalBooking['order_status']);
    if ($status_changed) {
        // Create notification and message for customer
        $order_q = mysqli_query($conn, "SELECT customer_id, order_number FROM bookings WHERE id = '$order_id'");
        $order_data = mysqli_fetch_assoc($order_q);
        if ($order_data) {
            $cust_id = $order_data['customer_id'];
            $ord_num = $order_data['order_number'];
            if (in_array(strtolower($status), ['confirmed', 'approved'])) {
                $msg = "Good news! Your booking #$ord_num has been approved by the caterer. You may now proceed to settle your payment.";
                $subject = "Booking Approved - Payment Required";
                
                // Insert into messages table
                $msg_safe = mysqli_real_escape_string($conn, $msg);
                $subj_safe = mysqli_real_escape_string($conn, $subject);
                mysqli_query($conn, "INSERT INTO messages (sender_id, receiver_id, order_id, subject, message, created_at) 
                                     VALUES ('$user_id', '$cust_id', '$order_id', '$subj_safe', '$msg_safe', NOW())");
                                     
                if (function_exists('createNotification')) {
                    createNotification($cust_id, 'order_status', "Booking Approved", $msg, "booking-details.php?id=$order_id");
                }
            } else {
                $msg = "Your booking #$ord_num status has been updated to " . ucfirst($status);
                if (function_exists('createNotification')) {
                    createNotification($cust_id, 'order_status', "Order Updated", $msg, "booking-details.php?id=$order_id");
                }
            }
        }
    }
    if (!$status_changed) { header('Location: reservations.php?tab=cancelled&error=status_locked'); exit(); }
    header("Location: reservations.php?success=1");
    exit();
}

// ==========================================
// HANDLE ARCHIVE/RESTORE ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_all_trash'])) {
    $trash_restored = mysqli_query($conn, "UPDATE bookings SET is_archived_by_store = 0 WHERE is_archived_by_store = 1 AND store_id = '$user_id'");
    header('Location: reservations.php?tab=trash' . ($trash_restored ? '' : '&error=trash_restore_failed'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_trash'])) {
    // Keep shared booking/payment records; 2 means removed from the caterer's trash.
    $trash_deleted = mysqli_query($conn, "UPDATE bookings SET is_archived_by_store = 2 WHERE is_archived_by_store = 1 AND store_id = '$user_id'");
    header('Location: reservations.php?tab=trash&' . ($trash_deleted ? 'success=trash_deleted' : 'error=trash_delete_failed'));
    exit();
}

if (isset($_POST['archive_all_cancelled'])) {
    mysqli_query($conn, "UPDATE bookings SET is_archived_by_store = 1 WHERE order_status IN ('cancelled', 'rejected', 'declined') AND is_archived_by_store = 0 AND store_id = '$user_id'");
    header("Location: reservations.php?tab=cancelled");
    exit();
}

if (isset($_POST['archive_order'])) {
    $archive_id = intval($_POST['order_id']);
    mysqli_query($conn, "UPDATE bookings SET is_archived_by_store = 1 WHERE id = '$archive_id' AND store_id = '$user_id' AND is_archived_by_store = 0");
    header("Location: reservations.php?tab=" . ($_POST['current_tab'] ?? 'pending'));
    exit();
}

if (isset($_POST['restore_order'])) {
    $restore_id = intval($_POST['order_id']);
    mysqli_query($conn, "UPDATE bookings SET is_archived_by_store = 0 WHERE id = '$restore_id' AND store_id = '$user_id' AND is_archived_by_store = 1");
    header("Location: reservations.php?tab=trash");
    exit();
}

// ==========================================
// HANDLE REAL-TIME DATA FETCH (AJAX)
// ==========================================
// ==========================================
// HANDLE CUSTOMER BOOKINGS FETCH (AJAX)
// ==========================================
if (isset($_GET['fetch_customer_bookings'])) {
    $customer_id = intval($_GET['customer_id']);

    // Fetch customer info
    $cust_q = mysqli_query($conn, "SELECT first_name, last_name, phone_number FROM users WHERE id = '$customer_id'");
    $cust = mysqli_fetch_assoc($cust_q);

    // Fetch ALL bookings of this customer with this caterer
    $q = "
        SELECT o.id, o.order_number, o.order_date, o.order_time, o.total_amount, o.order_status,
               o.created_at, o.additional_menu, o.number_of_pax,
               p.name as product_name
        FROM bookings o
        LEFT JOIN products p ON o.product_id = p.id
        WHERE o.customer_id = '$customer_id' AND o.store_id = '$user_id'
        ORDER BY o.created_at DESC, o.id DESC
    ";
    $res = mysqli_query($conn, $q);

    $customer_name  = htmlspecialchars($cust['first_name'] . ' ' . $cust['last_name']);
    $customer_phone = htmlspecialchars($cust['phone_number']);

    $all_bookings = [];
    if ($res && mysqli_num_rows($res) > 0) {
        while ($row = mysqli_fetch_assoc($res)) {
            $all_bookings[] = $row;
        }
    }
    $total_bookings = count($all_bookings);

    // ---- Header ----
    echo '<div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; flex-wrap:wrap; gap:10px;">';
    echo '  <div>';
    echo '    <h3 style="margin:0; color:var(--accent-gold); font-size:18px;"><i class="fas fa-user" style="margin-right:8px;"></i>' . $customer_name . '</h3>';
    echo '    <p style="margin:5px 0 0; color:var(--text-muted); font-size:13px;"><i class="fas fa-phone" style="margin-right:6px;"></i>' . $customer_phone . '</p>';
    echo '  </div>';
    echo '  <div style="text-align:right;">';
    echo '    <div style="font-size:12px; color:var(--text-muted);">Total Bookings</div>';
    echo '    <div style="font-size:22px; font-weight:bold; color:var(--accent-gold);">' . $total_bookings . '</div>';
    echo '  </div>';
    echo '</div>';

    if ($total_bookings === 0) {
        echo '<div style="text-align:center; padding:40px; color:var(--text-muted);">';
        echo '  <i class="fas fa-calendar-times" style="font-size:32px; margin-bottom:10px; opacity:0.5;"></i><br>';
        echo '  No bookings found for this customer.';
        echo '</div>';
        exit();
    }

    // ---- Group bookings by status (matches customer booking.php flow) ----
    $status_groups = [
        'upcoming'  => ['label' => 'Upcoming / Active', 'icon' => 'fa-calendar-check', 'color' => '#d4af37', 'bookings' => []],
        'completed' => ['label' => 'Completed',         'icon' => 'fa-check-circle',   'color' => '#2ecc71', 'bookings' => []],
        'cancelled' => ['label' => 'Cancelled',         'icon' => 'fa-times-circle',   'color' => '#e74c3c', 'bookings' => []],
    ];

    foreach ($all_bookings as $booking) {
        $s = strtolower($booking['order_status']);
        if (in_array($s, ['cancelled', 'rejected', 'declined'])) {
            $status_groups['cancelled']['bookings'][] = $booking;
        } elseif ($s === 'completed') {
            $status_groups['completed']['bookings'][] = $booking;
        } else {
            $status_groups['upcoming']['bookings'][] = $booking;
        }
    }

    foreach ($status_groups as $group_key => $group) {
        if (empty($group['bookings'])) continue;

        $group_id = 'cbg-' . $group_key . '-' . $customer_id;

        // ---- Accordion group header ----
        echo '<div style="margin-bottom:12px; border:1px solid var(--border-color); border-radius:8px; overflow:hidden;">';
        echo '<div onclick="var el=document.getElementById(\'' . $group_id . '\'); el.style.display=(el.style.display===\'none\'?\'block\':\'none\');"
                   style="display:flex; justify-content:space-between; align-items:center; padding:12px 16px;
                          background:rgba(212,175,55,0.08); cursor:pointer; user-select:none;">';
        echo '  <div style="display:flex; align-items:center; gap:10px;">';
        echo '    <i class="fas ' . $group['icon'] . '" style="color:' . $group['color'] . ';"></i>';
        echo '    <strong style="color:var(--text-color);">' . $group['label'] . '</strong>';
        echo '    <span style="background:' . $group['color'] . '; color:#000; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:700;">' . count($group['bookings']) . '</span>';
        echo '  </div>';
        echo '  <i class="fas fa-chevron-down" style="color:var(--text-muted);"></i>';
        echo '</div>';

        // ---- Bookings table inside group (visible by default for upcoming, collapsed for others) ----
        $default_display = ($group_key === 'upcoming') ? 'block' : 'none';
        echo '<div id="' . $group_id . '" style="display:' . $default_display . ';">';
        echo '<div style="overflow-x:auto;">';
        echo '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
        echo '<thead><tr style="background:rgba(255,255,255,0.03); border-bottom:1px solid var(--border-color);">';
        echo '  <th style="padding:10px 12px; text-align:left; color:var(--text-muted); font-weight:600; white-space:nowrap;">Booking #</th>';
        echo '  <th style="padding:10px 12px; text-align:left; color:var(--text-muted); font-weight:600;">Service / Set</th>';
        echo '  <th style="padding:10px 12px; text-align:left; color:var(--text-muted); font-weight:600; white-space:nowrap;">Date &amp; Time</th>';
        echo '  <th style="padding:10px 12px; text-align:left; color:var(--text-muted); font-weight:600;">Amount</th>';
        echo '  <th style="padding:10px 12px; text-align:left; color:var(--text-muted); font-weight:600;">Status</th>';
        echo '  <th style="padding:10px 12px; text-align:left; color:var(--text-muted); font-weight:600;">Action</th>';
        echo '</tr></thead><tbody>';

        foreach ($group['bookings'] as $idx => $booking) {
            $fin         = getOrderFinancials($booking['id']);
            $grand_total = (float) ($fin['totals']['grand_total'] ?? 0);
            $total_paid  = (float) ($fin['totals']['total_paid'] ?? 0);
            $balance     = (float) ($fin['totals']['balance'] ?? 0);
            $dp_required = (float) ($fin['down_payment_required'] ?? 0);

            $s = strtolower($booking['order_status']);
            if ($s === 'approved') $s = 'confirmed';

            $statusClass = 'status-pending';
            if ($s === 'confirmed')                                      $statusClass = 'status-confirmed';
            if (in_array($s, ['cancelled', 'rejected', 'declined']))     $statusClass = 'status-cancelled';
            if ($s === 'completed')                                      $statusClass = 'status-completed';
            if ($s === 'preparing')                                      $statusClass = 'status-preparing';
            $statusLabel = ucfirst($s);

            // Parse set name from additional_menu
            $set_name = '';
            $raw_menu = $booking['additional_menu'] ?? '';
            if (!empty($raw_menu)) {
                $parts    = explode(' | ', $raw_menu);
                $set_name = trim($parts[0]);
            }
            $service_label = htmlspecialchars($booking['product_name'] ?: 'Catering Service');
            if ($set_name !== '') {
                $service_label .= '<br><small style="color:var(--text-muted);">' . htmlspecialchars($set_name) . '</small>';
            }

            // Amount display
            $amount_html  = '<div style="font-weight:bold;">&#8369;' . number_format($grand_total, 2) . '</div>';
            $amount_html .= '<div style="font-size:11px; color:var(--text-muted);">DP: &#8369;' . number_format($dp_required, 2) . '</div>';
            if ($total_paid > 0) {
                $amount_html .= '<div style="font-size:11px; color:#2ecc71;">Paid: &#8369;' . number_format($total_paid, 2) . '</div>';
            }
            if ($balance > 0.01) {
                $amount_html .= '<div style="font-size:11px; color:#e74c3c;">Bal: &#8369;' . number_format($balance, 2) . '</div>';
            } else {
                $amount_html .= '<div style="font-size:11px; color:#2ecc71;">Fully Paid</div>';
            }

            $row_bg = ($idx % 2 === 0) ? '' : 'background:rgba(255,255,255,0.02);';

            echo '<tr style="border-bottom:1px solid var(--border-color); ' . $row_bg . '">';
            echo '  <td style="padding:10px 12px; white-space:nowrap;">';
            echo '    <strong style="color:var(--accent-gold);">' . $booking_display_numbers[(int) $booking['id']] . '</strong>';
            if (!empty($booking['created_at'])) {
                echo '<br><small style="color:var(--text-muted); font-size:10px;">Booked: ' . date('M d, Y', strtotime($booking['created_at'])) . '</small>';
            }
            echo '  </td>';
            echo '  <td style="padding:10px 12px;">' . $service_label . '</td>';
            echo '  <td style="padding:10px 12px; white-space:nowrap;">';
            echo '    <div style="color:var(--accent-gold); font-weight:bold;">' . date('M d, Y', strtotime($booking['order_date'])) . '</div>';
            if (!empty($booking['order_time'])) {
                echo '    <small style="color:var(--text-muted);">' . date('h:i A', strtotime($booking['order_time'])) . '</small>';
            }
            echo '  </td>';
            echo '  <td style="padding:10px 12px;">' . $amount_html . '</td>';
            echo '  <td style="padding:10px 12px;"><span class="status-badge ' . $statusClass . '">' . $statusLabel . '</span></td>';
            echo '  <td style="padding:10px 12px; white-space:nowrap;">';
            echo '    <a href="order-details.php?id=' . intval($booking['id']) . '" class="btn"';
            echo '       style="background:var(--info-color,#3498db); color:#fff; padding:5px 10px; font-size:12px;';
            echo '              text-decoration:none; border-radius:4px; display:inline-flex; align-items:center; gap:5px;">';
            echo '      <i class="fas fa-eye"></i> View';
            echo '    </a>';
            echo '  </td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>'; // overflow wrapper
        echo '</div>'; // group body
        echo '</div>'; // group card
    }

    exit();
}
if (isset($_GET['fetch_reservations'])) {
    $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'pending';
    $action_filter = isset($_GET['action']) ? trim($_GET['action']) : 'all';
    
    // Build Query based on Tab
    $focused_customer_id = max(0, (int) ($_GET['focus_customer'] ?? 0));
    $where_clause = "WHERE o.store_id = '$user_id'";
    if ($focused_customer_id) $where_clause .= " AND o.customer_id = $focused_customer_id";
    
    if ($current_tab == 'trash') {
        $where_clause .= " AND o.is_archived_by_store = 1";
    } else {
        $where_clause .= " AND o.is_archived_by_store = 0";
        
        if ($current_tab == 'completed') {
            $where_clause .= " AND o.order_status = 'completed'";
        } elseif ($current_tab == 'cancelled') {
            $where_clause .= " AND o.order_status IN ('cancelled', 'rejected', 'declined')";
        } else {
            // Pending / Upcoming
            $where_clause .= " AND o.order_status NOT IN ('completed', 'cancelled', 'rejected', 'declined')";
        }
    }

    $where_clause .= getReservationsActionFilterSql($action_filter);

    // Pre-fetch Product Sets for lookup (to show original menu items)
    $sets_lookup = [];
    $sets_query = "
        SELECT ps.* 
        FROM product_sets ps
        JOIN products p ON ps.product_id = p.id
        WHERE p.store_id = '$user_id'
    ";
    $sets_result = mysqli_query($conn, $sets_query);
    while ($set = mysqli_fetch_assoc($sets_result)) {
        $name = $set['set_name'] ?? $set['name'];
        $sets_lookup[$name] = $set;
    }

    $reservations_query = "
        SELECT o.*, u.first_name, u.last_name, u.phone_number, u.profile_image, p.name as product_name, p.category,
               o.payment_status, o.delivery_type
        FROM bookings o 
        JOIN users u ON o.customer_id = u.id 
        LEFT JOIN products p ON o.product_id = p.id 
        $where_clause
        ORDER BY o.created_at DESC, o.id DESC
    ";
    $reservations_result = mysqli_query($conn, $reservations_query);

    // Collect all processed rows first for grouping
    $processed_rows = [];

    if (mysqli_num_rows($reservations_result) > 0) {
        while ($reservation = mysqli_fetch_assoc($reservations_result)) {
            // Get Financials (Real-time Balance)
            $fin = getOrderFinancials($reservation['id']);
            $balance = $fin['totals']['balance'];
            $total = $fin['totals']['grand_total'];
            $paid = $fin['totals']['total_paid'];
            $downPaymentRequired = (float) ($fin['down_payment_required'] ?? 0);
            $isFullyPaid = $balance <= 0.01;
            $action_flags = getReservationActionFlags($conn, $reservation, $fin);
            $action_items = [];
            if ($action_flags['payment_review']) $action_items[] = 'Payment Proof Pending';
            if ($action_flags['reschedule_review']) $action_items[] = 'Reschedule Pending';
            if ($action_flags['menu_review']) $action_items[] = 'Menu Change Pending';
            $hasPendingActions = !empty($action_items);
            $actionBadgesHtml = '';
            if ($hasPendingActions) {
                foreach ($action_items as $action_item) {
                    $actionBadgesHtml .= '<span class="action-needed-chip"><i class="fas fa-bell"></i> ' . htmlspecialchars($action_item) . '</span>';
                }
                $actionBadgesHtml = '<div class="action-needed-list">' . $actionBadgesHtml . '</div>';
            }

            // Parse Additional Menu Data for this row
            $raw_menu = $reservation['additional_menu'] ?? '';
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
                        $addons = array_map('trim', explode(',', $itemsStr));
                    } elseif (strpos($part, 'Menu Swaps:') !== false) {
                        $itemsStr = str_replace('Menu Swaps:', '', $part);
                        $swaps = array_map('trim', explode(',', $itemsStr));
                    } elseif (strpos($part, 'Custom Add-on Request:') !== false) {
                        $customRequest = trim(str_replace('Custom Add-on Request:', '', $part));
                    }
                }
            }

            $hasCustomizations = !empty($swaps) || !empty($addons) || !empty($customRequest);
            $btnStyle = $hasCustomizations ? "background: #e67e22; color: #fff;" : "background: var(--info-color); color: #fff;";
            $btnIcon = $hasCustomizations ? "fa-sliders-h" : "fa-list";

            $normalizedReservationStatus = strtolower(trim((string) ($reservation['order_status'] ?? 'pending')));
            if ($normalizedReservationStatus === 'approved') $normalizedReservationStatus = 'confirmed';
            if ($normalizedReservationStatus === 'pending_payment') {
                $normalizedReservationStatus = 'pending';
            } elseif ($normalizedReservationStatus === 'awaiting_store_confirmation') {
                $normalizedReservationStatus = 'confirmed';
            }
            $statusDisplay = ucfirst($normalizedReservationStatus ?: 'pending');
            $statusClass = 'status-pending';
            if ($normalizedReservationStatus === 'confirmed') $statusClass = 'status-confirmed';
            if ($normalizedReservationStatus === 'cancelled') $statusClass = 'status-cancelled';
            if ($normalizedReservationStatus === 'completed') $statusClass = 'status-completed';
            if ($normalizedReservationStatus === 'preparing') $statusClass = 'status-preparing';

            // Amount Display Logic
            $isDpPaid = $downPaymentRequired > 0 ? ($paid >= $downPaymentRequired) : ($paid > 0);
            $dpStatusHtml = $isDpPaid ? '<span style="color: var(--success-color); margin-left: 4px;">(Paid)</span>' : '<span style="color: var(--error-color); margin-left: 4px;">(Not Paid)</span>';

            $amountDisplay = '<div style="font-weight: bold;">&#8369;' . number_format($total, 2) . '</div>';
            $amountDisplay .= '<div style="color: var(--text-muted); font-size: 11px;">DP: &#8369;' . number_format($downPaymentRequired, 2) . $dpStatusHtml . '</div>';
            if ($paid > 0) {
                $amountDisplay .= '<div style="color: var(--reservation-success); font-size: 11px;">Advanced Payment: &#8369;' . number_format($paid, 2) . '</div>';
            }
            if ($balance > 0) {
                $amountDisplay .= '<div style="color: var(--error-color); font-size: 11px;">Bal: &#8369;' . number_format($balance, 2) . '</div>';
            } else {
                $amountDisplay .= '<div style="color: var(--success-color); font-size: 11px;">Fully Paid</div>';
            }

            // 5 Days Before Logic
            $eventDate = strtotime($reservation['order_date']);
            $now = time();
            $diff = $eventDate - $now;
            $days = floor($diff / (60 * 60 * 24));
            $isUpcomingSoon = ($days >= 0 && $days <= 5);
            $isEventDay = (date('Y-m-d') === date('Y-m-d', $eventDate));

            $rowStyle = '';
            $dateStyle = '';
            if ($isUpcomingSoon && $current_tab == 'pending') {
                $rowStyle = 'background: rgba(243, 156, 18, 0.05); border-left: 3px solid #f39c12;';
                $dateStyle = 'color: #f39c12; font-weight: bold;';
            }

            // Build actions HTML
            $actionsHtml = '';
            if ($current_tab == 'trash') {
                $actionsHtml = '<form method="POST" style="display:inline;" onsubmit="return confirm(\'Restore this order?\');">
                        <input type="hidden" name="order_id" value="' . $reservation['id'] . '">
                        <button type="submit" name="restore_order" class="btn" style="background: rgba(46, 204, 113, 0.2); color: #2ecc71; border: 1px solid #2ecc71; padding: 5px 10px; font-size: 12px; cursor: pointer;">
                            <i class="fas fa-undo"></i> Restore
                        </button>
                      </form>';
            } else {
                $currentStatus     = strtolower($reservation['order_status']);
                $preparingDisabled = ($currentStatus !== 'preparing') && (!$isDpPaid || !$isEventDay);
                $preparingLabel    = 'Preparing';
                if ($currentStatus !== 'preparing') {
                    if (!$isDpPaid)       $preparingLabel .= ' (Down payment required)';
                    elseif (!$isEventDay) $preparingLabel .= ' (Available on event day)';
                }

                $actionsHtml = '<div style="display: flex; gap: 5px; align-items: center;">
                        <select class="status-select"
                                style="width: auto; padding-right: 20px;"
                                onfocus="isInteracting=true"
                                onblur="isInteracting=false"
                                onchange="updateStatus(' . $reservation['id'] . ', this.value)">
                            <option value="pending" ' . ($currentStatus === 'pending' ? 'selected' : '') . (in_array($currentStatus, ['preparing', 'ready', 'delivering', 'completed']) ? ' disabled' : '') . '>Pending</option>
                            ' . ($currentStatus === 'awaiting_store_confirmation'
                                ? '<option value="confirmed" selected disabled>Confirmed</option>'
                                : '') . '
                            <option value="confirmed" ' . ($currentStatus === 'confirmed' ? 'selected' : '') . (in_array($currentStatus, ['preparing', 'ready', 'delivering', 'completed']) ? ' disabled' : '') . ' style="color: #2ecc71;">Accept Booking</option>
                            <option value="preparing" ' . ($currentStatus === 'preparing' ? 'selected' : '') . ($preparingDisabled ? ' disabled' : '') . ' style="color: #3498db;">' . htmlspecialchars($preparingLabel) . '</option>
                            <option value="completed" ' . ($currentStatus === 'completed' ? 'selected' : '') . (!$isFullyPaid && $currentStatus !== 'completed' ? ' disabled' : '') . ' style="color: #95a5a6;">Completed' . (!$isFullyPaid ? ' (Full payment required)' : '') . '</option>
                            <option value="cancelled" ' . ($currentStatus === 'cancelled' ? 'selected' : '') . ($isFullyPaid ? ' disabled' : '') . ' style="color: #e74c3c;">Cancelled' . ($isFullyPaid ? ' (Cannot cancel, fully paid)' : '') . '</option>
                        </select>

                        <a href="event_preparation.php?order_id=' . $reservation['id'] . '" class="btn" title="Event Preparation" style="background: transparent; color: #3498db; border: 1px solid var(--border-color); padding: 5px 8px; font-size: 12px; cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-tasks"></i>
                        </a>

                        <form method="POST" style="display:inline;" onsubmit="return confirm(\'Move to trash?\');">
                            <input type="hidden" name="order_id" value="' . $reservation['id'] . '">
                            <input type="hidden" name="current_tab" value="' . $current_tab . '">
                            <button type="submit" name="archive_order" class="btn" title="Move to Trash" style="background: transparent; color: var(--error-color); border: 1px solid var(--border-color); padding: 5px 8px; font-size: 12px; cursor: pointer;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                      </div>';
            }

            // Store processed row data
            $actionsHtml = lockCancelledReservationControls($actionsHtml, (string) $reservation['order_status']);
                    $processed_rows[] = [
                'reservation'       => $reservation,
                'rowStyle'          => $rowStyle,
                'dateStyle'         => $dateStyle,
                'amountDisplay'     => $amountDisplay,
                'statusClass'       => $statusClass,
                'statusDisplay'     => $statusDisplay,
                'actionBadgesHtml'  => $actionBadgesHtml,
                'btnStyle'          => $btnStyle,
                'btnIcon'           => $btnIcon,
                'hasCustomizations' => $hasCustomizations,
                'actionsHtml'       => $actionsHtml,
                'isUpcomingSoon'    => $isUpcomingSoon,
                'customer_id'       => $reservation['customer_id'],
            ];
        }

        // --- Group processed_rows by customer_id (accordion, same as customer/booking.php) ---
        $customer_groups = [];
        foreach ($processed_rows as $row) {
            $cid = $row['customer_id'];
            if (!isset($customer_groups[$cid])) {
                $customer_groups[$cid] = [
                    'name'  => htmlspecialchars($row['reservation']['first_name'] . ' ' . $row['reservation']['last_name']),
                    'phone' => htmlspecialchars($row['reservation']['phone_number']),
                    'avatar' => reservationCustomerAvatar($row['reservation']),
                    'rows'  => [],
                ];
            }
            $customer_groups[$cid]['rows'][] = $row;
        }

        // --- Render accordion: one header row per customer, booking rows hidden by default ---
        foreach ($customer_groups as $cid => $group) {
            $group_id   = 'cust-group-' . $cid . '-' . md5($current_tab);
            $book_count = count($group['rows']);

            // Customer header row (clickable — same as caterer row in customer/booking.php)
            echo '<tr data-customer-id="' . (int) $cid . '" class="caterer-group-header" onclick="toggleCustomerOrders(\'' . $group_id . '\')"
                      style="cursor:pointer; background:rgba(212,175,55,0.08);">';
            echo '  <td colspan="6" style="padding:14px 16px; border:none;">';
            echo '    <div style="display:flex; justify-content:space-between; align-items:center;">';
            echo '      <div style="display:flex; align-items:center; gap:12px;">';
            echo $group['avatar'];
            echo '        <strong style="color:var(--accent-gold); font-size:14px;">' . $group['name'] . '</strong>';
            echo '        <span style="background:var(--accent-gold); color:#000; border-radius:999px; padding:2px 9px; font-size:11px; font-weight:700;">';
            echo           $book_count . ' ' . ($book_count === 1 ? 'booking' : 'bookings');
            echo '        </span>';
            echo '      </div>';
            echo '    </div>';
            echo '  </td>';
            echo '</tr>';

            // Individual booking rows (hidden by default)
            foreach ($group['rows'] as $row) {
                $reservation = $row['reservation'];
                echo '<tr class="customer-order-' . $group_id . '"
                          style="display:none;  ' . $row['rowStyle'] . '">';
                echo '  <td><strong>' . $booking_display_numbers[(int) $reservation['id']] . '</strong></td>';
                echo '  <td>';
                echo '    <div style="' . $row['dateStyle'] . '">' . date('M d, Y', strtotime($reservation['order_date'])) . '</div>';
                echo '    <small style="color:var(--text-muted);">' . date('h:i A', strtotime($reservation['order_time'])) . '</small>';
                if (!empty($reservation['created_at'])) {
                    echo '    <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">Booked: ' . date('M d, Y h:i A', strtotime($reservation['created_at'])) . '</div>';
                }
                if ($row['isUpcomingSoon'] && $current_tab == 'pending') {
                    echo '    <div style="font-size:10px; color:var(--reservation-warning); margin-top:2px;"><i class="fas fa-exclamation-circle"></i> Upcoming</div>';
                }
                echo '  </td>';
                echo '  <td>' . $row['amountDisplay'] . '</td>';
                echo '  <td>';
                echo '    <span class="status-badge ' . $row['statusClass'] . '">' . $row['statusDisplay'] . '</span>';
                echo '    ' . $row['actionBadgesHtml'];
                echo '  </td>';
                echo '  <td>';
                echo '    <form action="order-details.php" method="get" style="display:inline;">';
                echo '<input type="hidden" name="reservation_tab" value="' . htmlspecialchars($current_tab, ENT_QUOTES) . '"><input type="hidden" name="reservation_action" value="' . htmlspecialchars($action_filter, ENT_QUOTES) . '">';
                echo '      <input type="hidden" name="id" value="' . intval($reservation['id']) . '">';
                echo '      <button type="submit" class="btn details-link"' . (!$row['hasCustomizations'] ? ' name="payment_transactions" value="1"' : '') . '';
                echo '              style="' . $row['btnStyle'] . ' padding:6px 12px; font-size:12px; display:inline-flex; align-items:center; gap:5px; cursor:pointer;">';
                echo '        <i class="fas ' . $row['btnIcon'] . '"></i> ' . ($row['hasCustomizations'] ? 'Custom Booking' : 'View Details');
                echo '      </button>';
                echo '    </form>';
                echo '  </td>';
                echo '  <td>' . $row['actionsHtml'] . '</td>';
                echo '</tr>';
            }
        }
    } else {
        echo '<tr><td colspan="6" style="text-align:center; padding: 40px; color: var(--text-muted);">
                <i class="fas fa-inbox" style="font-size: 32px; margin-bottom: 10px; opacity: 0.5;"></i><br>
                No ' . $current_tab . ' reservations found.
              </td></tr>';
    }
    exit();
}

// Get current tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'pending';
$action_filter = isset($_GET['action']) ? trim($_GET['action']) : 'all';

// Build Query based on Tab
$focused_customer_id = max(0, (int) ($_GET['focus_customer'] ?? 0));
$where_clause = "WHERE o.store_id = '$user_id'";
if ($focused_customer_id) $where_clause .= " AND o.customer_id = $focused_customer_id";

if ($current_tab == 'trash') {
    $where_clause .= " AND o.is_archived_by_store = 1";
} else {
    $where_clause .= " AND o.is_archived_by_store = 0";
    
    if ($current_tab == 'completed') {
        $where_clause .= " AND o.order_status = 'completed'";
    } elseif ($current_tab == 'cancelled') {
        $where_clause .= " AND o.order_status IN ('cancelled', 'rejected', 'declined')";
    } else {
        // Pending / Upcoming
        $where_clause .= " AND o.order_status NOT IN ('completed', 'cancelled', 'rejected', 'declined')";
    }
}

$where_clause .= getReservationsActionFilterSql($action_filter);

// Get all reservations
$reservations_query = "
    SELECT o.*, u.first_name, u.last_name, u.phone_number, u.profile_image, p.name as product_name, p.category,
           o.payment_status, o.delivery_type
    FROM bookings o 
    JOIN users u ON o.customer_id = u.id 
    LEFT JOIN products p ON o.product_id = p.id 
    $where_clause
    ORDER BY o.created_at DESC, o.id DESC
";
$reservations_result = mysqli_query($conn, $reservations_query);

// Pre-fetch Product Sets for lookup (to show original menu items)
$sets_lookup = [];
$sets_query = "
    SELECT ps.* 
    FROM product_sets ps
    JOIN products p ON ps.product_id = p.id
    WHERE p.store_id = '$user_id'
";
$sets_result = mysqli_query($conn, $sets_query);
while ($set = mysqli_fetch_assoc($sets_result)) {
    // Key by Set Name for easy lookup
    $name = $set['set_name'] ?? $set['name'];
    $sets_lookup[$name] = $set;
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .reservations-page-header {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-bottom: 20px;
    }

    .reservations-page-header h1 {
        color: var(--accent-gold);
        margin: 0;
    }

    .reservations-page-header p {
        margin: 0;
        color: var(--text-muted);
    }

    .tabs-container {
        margin-bottom: 20px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        gap: 20px;
    }

    .reservations-table-wrap {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .details-link {
        padding: 6px 12px;
        font-size: 12px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .actions-desktop {
        display: flex;
        gap: 5px;
        align-items: center;
    }

    .mobile-status-grid {
        display: none;
    }

    .mobile-inline-actions {
        display: none;
    }

    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.8);
        backdrop-filter: blur(5px);
        align-items: center;
        justify-content: center;
    }
    
    .modal-content {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        width: 90%;
        max-width: 700px;
        max-height: 90vh;
        overflow-y: auto;
        position: relative;
        animation: slideIn 0.3s ease;
        padding: 0;
        display: flex;
        flex-direction: column;
    }

    .modal-header {
        padding: 20px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: rgba(255,255,255,0.02);
    }

    .modal-header h2 {
        margin: 0;
        color: var(--accent-gold);
        font-size: 20px;
    }

    .close-modal {
        color: var(--text-muted);
        font-size: 24px;
        cursor: pointer;
        transition: color 0.2s;
    }

    .close-modal:hover {
        color: #fff;
    }

    .modal-body {
        padding: 20px;
        color: var(--text-main);
    }

    .order-section {
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 1px dashed var(--border-color);
    }
    
    .order-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }

    .section-label {
        font-size: 12px;
        text-transform: uppercase;
        color: var(--text-muted);
        letter-spacing: 1px;
        margin-bottom: 10px;
        display: block;
    }

    .item-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .item-list li {
        padding: 8px 12px;
        margin-bottom: 6px;
        background: rgba(255,255,255,0.03);
        border-radius: 6px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .item-highlight {
        color: var(--accent-gold);
        font-weight: 600;
    }

    .badge-swap {
        background: rgba(231, 76, 60, 0.15);
        color: #e74c3c;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
        border: 1px solid rgba(231, 76, 60, 0.3);
    }

    .badge-addon {
        background: rgba(212, 175, 55, 0.15);
        color: #d4af37;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
        border: 1px solid rgba(212, 175, 55, 0.3);
    }

    .price-col {
        font-family: monospace;
        color: var(--text-muted);
    }

    .total-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid var(--border-color);
        font-size: 18px;
        font-weight: bold;
        color: var(--accent-gold);
    }

    .original-menu-item {
        color: var(--text-muted);
        font-style: italic;
    }
    
    .original-menu-item.swapped-out {
        text-decoration: line-through;
        opacity: 0.5;
    }
    
    /* Dropdown Styling */
    .status-select {
        background-color: var(--input-bg) !important;
        color: var(--text-main) !important;
        border: 1px solid var(--border-color);
        padding: 6px 10px;
        border-radius: 4px;
        cursor: pointer;
        width: 100%;
        font-size: 13px;
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%23d4af37%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
        background-repeat: no-repeat;
        background-position: right 8px center;
        background-size: 10px;
        padding-right: 25px;
    }
    
    .status-select option {
        background-color: #1f2937; /* Dark background for options */
        color: #f9fafb; /* Light text */
        padding: 8px;
    }
    
    .status-select:focus {
        outline: none;
        border-color: var(--accent-gold);
    }

    @media (max-width: 768px) {
        .reservations-page-header {
            padding: 2px 2px 0;
        }

        .tabs-container {
            gap: 10px;
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 8px;
            scrollbar-width: none;
        }

        .tabs-container::-webkit-scrollbar {
            display: none;
        }

        .tab-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex: 0 0 auto;
            font-size: 12px;
        }

        .reservations-table-wrap {
            overflow: visible;
        }

        .reservations-table-wrap .table,
        .reservations-table-wrap .table tbody,
        .reservations-table-wrap .table tr,
        .reservations-table-wrap .table td {
            display: block;
            width: 100%;
        }

        .reservations-table-wrap .table thead {
            display: none;
        }

        .reservations-table-wrap .table tr {
            border: 1px solid var(--border-color);
            border-radius: 14px;
            background: rgba(255,255,255,0.02);
            padding: 14px;
            margin-bottom: 14px;
            box-sizing: border-box;
        }

        .reservations-table-wrap .table td {
            border-bottom: none;
            padding: 8px 0;
        }

        .reservations-table-wrap .table td::before {
            content: '';
            display: block;
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .reservations-table-wrap .table td:nth-child(1)::before { content: 'Booking #'; }
        .reservations-table-wrap .table td:nth-child(2)::before { content: 'Date & Time'; }
        .reservations-table-wrap .table td:nth-child(3)::before { content: 'Amount'; }
        .reservations-table-wrap .table td:nth-child(4)::before { content: 'Status'; }
        .reservations-table-wrap .table td:nth-child(5)::before { content: 'Details'; }
        .reservations-table-wrap .table td:nth-child(6)::before { content: 'Actions'; }

        .reservations-table-wrap .table td:last-child > div,
        .reservations-table-wrap .table td:last-child form,
        .reservations-table-wrap .table td:last-child .btn,
        .reservations-table-wrap .table td:last-child .status-select,
        .reservations-table-wrap .table td:nth-child(5) .btn,
        .reservations-table-wrap .table td:nth-child(5) .details-link {
            width: 100% !important;
        }

        .reservations-table-wrap .table td:last-child > div,
        .actions-desktop {
            flex-direction: column;
            align-items: stretch !important;
        }

        .reservations-table-wrap .table td:last-child .btn,
        .reservations-table-wrap .table td:nth-child(5) .btn,
        .reservations-table-wrap .table td:nth-child(5) .details-link {
            justify-content: center;
        }

        .actions-desktop {
            display: none !important;
        }

        .mobile-status-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 8px;
        }

        .mobile-status-btn {
            border: 1px solid var(--border-color);
            background: rgba(255,255,255,0.03);
            color: var(--text-main);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 12px;
            font-weight: 600;
            min-height: 42px;
            cursor: pointer;
        }

        .mobile-status-btn.is-active {
            border-color: var(--accent-gold);
            color: var(--accent-gold);
            background: rgba(212, 175, 55, 0.08);
        }

        .mobile-inline-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 8px;
        }

        .mobile-inline-actions form {
            margin: 0;
        }
    }
</style>

<link rel="stylesheet" href="../assets/css/caterer-reservations.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-reservations.css'); ?>">

<div class="card reservations-card">
<div class="tabs-wrapper" style="display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid var(--border-color); margin-bottom: 20px;">
    <div class="tabs-container" style="border-bottom: none; margin-bottom: 0; flex: 1; max-width: calc(100% - 110px);">
        <a href="?tab=pending" class="tab-link <?php echo $current_tab == 'pending' ? 'active' : ''; ?>" style="padding: 10px 0; color: var(--text-muted); text-decoration: none; border-bottom: 2px solid transparent; transition: all 0.2s;">
            <i class="fas fa-clock"></i> Upcoming / Pending
        </a>
        <a href="?tab=completed" class="tab-link <?php echo $current_tab == 'completed' ? 'active' : ''; ?>" style="padding: 10px 0; color: var(--text-muted); text-decoration: none; border-bottom: 2px solid transparent; transition: all 0.2s;">
            <i class="fas fa-check-circle"></i> Completed
        </a>
        <a href="?tab=cancelled" class="tab-link <?php echo $current_tab == 'cancelled' ? 'active' : ''; ?>" style="padding: 10px 0; color: var(--text-muted); text-decoration: none; border-bottom: 2px solid transparent; transition: all 0.2s;">
            <i class="fas fa-times-circle"></i> Cancelled
        </a>
        <a href="?tab=trash" class="tab-link <?php echo $current_tab == 'trash' ? 'active' : ''; ?>" style="padding: 10px 0; color: var(--text-muted); text-decoration: none; border-bottom: 2px solid transparent; transition: all 0.2s;">
            <i class="fas fa-trash"></i> Trash
        </a>
    </div>
    
</div>

<style>
    .tab-link.active {
        color: var(--accent-gold) !important;
        border-bottom-color: var(--accent-gold) !important;
        font-weight: bold;
    }
    .tab-link:hover {
        color: var(--text-main) !important;
    }
</style>

<?php 
if (isset($_GET['success']) && $_GET['success'] === 'trash_deleted') {
    $success_message = "All bookings have been deleted from your Trash.";
}
?>
<?php if (($_GET['error'] ?? '') === 'trash_delete_failed'): ?>
    <div class="alert alert-danger" role="alert">Unable to empty Trash. Please try again.</div>
<?php endif; ?>
<?php if (($_GET['error'] ?? '') === 'trash_restore_failed'): ?>
    <div class="alert alert-danger" role="alert">Unable to restore bookings. Please try again.</div>
<?php endif; ?>
<?php if (isset($success_message)): ?>
    <div id="reservation-success-message" class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
    </div>
    <script>
        setTimeout(function () {
            document.getElementById('reservation-success-message')?.remove();
        }, 3000);
    </script>
<?php endif; ?>


    <?php if ($focused_customer_id): ?>
    <div class="reservation-customer-focus">
        Showing bookings for the selected customer.
        <a href="?tab=<?php echo htmlspecialchars($current_tab, ENT_QUOTES, 'UTF-8'); ?>">Show all customers</a>
    </div>
<?php endif; ?>
<div class="reservations-bulk-strip"><?php if (!$focused_customer_id && in_array($current_tab, ['cancelled', 'trash'], true)): ?>
    <div class="reservations-list-toolbar">
        <?php if ($current_tab === 'trash'): ?>
        <form method="POST" data-confirm-action="restore" onsubmit="return confirmReservationBulkAction(event);">
            <button type="submit" name="restore_all_trash" class="btn reservations-bulk-delete" title="Restore All" aria-label="Restore all bookings from Trash">
                <i class="fas fa-undo" aria-hidden="true"></i>
            </button>
        </form>
        <?php endif; ?>
        <form method="POST" data-confirm-action="<?php echo $current_tab === 'trash' ? 'delete' : 'archive'; ?>" onsubmit="return confirmReservationBulkAction(event);">
            <button type="submit" name="<?php echo $current_tab === 'trash' ? 'delete_all_trash' : 'archive_all_cancelled'; ?>" class="btn reservations-bulk-delete" title="<?php echo $current_tab === 'trash' ? 'Empty Trash' : 'Move all cancelled bookings to Trash'; ?>" aria-label="<?php echo $current_tab === 'trash' ? 'Empty Trash' : 'Move all cancelled bookings to Trash'; ?>">
                <i class="fas fa-trash" aria-hidden="true"></i>
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>
    <div class="reservations-table-wrap">
        <table class="table">
            <colgroup>
                <col class="reservation-col-number">
                <col class="reservation-col-date">
                <col class="reservation-col-amount">
                <col class="reservation-col-status">
                <col class="reservation-col-details">
                <col class="reservation-col-actions">
            </colgroup>
            <thead>
                <tr>
                    <th>Booking #</th>
                    <th>Date & Time</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Details</th>
                    <th class="reservations-actions-heading"><div class="reservations-actions-heading-content"><span>Actions</span>    </div></th>
                </tr>
            </thead>
            <tbody id="reservations-body">
<?php
                // --- Collect all rows first, then render accordion (mirrors AJAX handler) ---
                $init_processed_rows = [];
                while ($reservation = mysqli_fetch_assoc($reservations_result)) {
                    $fin = getOrderFinancials($reservation['id']);
                    $balance = $fin['totals']['balance'];
                    $total   = $fin['totals']['grand_total'];
                    $paid    = $fin['totals']['total_paid'];
                    $downPaymentRequired = (float) ($fin['down_payment_required'] ?? 0);
                    $isFullyPaid = $balance <= 0.01;
                    $action_flags = getReservationActionFlags($conn, $reservation, $fin);
                    $action_items = [];
                    if ($action_flags['payment_review'])    $action_items[] = 'Payment Proof Pending';
                    if ($action_flags['reschedule_review']) $action_items[] = 'Reschedule Pending';
                    if ($action_flags['menu_review'])       $action_items[] = 'Menu Change Pending';
                    $hasPendingActions = !empty($action_items);
                    $actionBadgesHtml = '';
                    if ($hasPendingActions) {
                        foreach ($action_items as $action_item) {
                            $actionBadgesHtml .= '<span class="action-needed-chip"><i class="fas fa-bell"></i> ' . htmlspecialchars($action_item) . '</span>';
                        }
                        $actionBadgesHtml = '<div class="action-needed-list">' . $actionBadgesHtml . '</div>';
                    }
                    $raw_menu = $reservation['additional_menu'] ?? '';
                    $parts = explode(' | ', $raw_menu);
                    $setName = ''; $swaps = []; $addons = []; $customRequest = '';
                    if (!empty($parts)) {
                        $setName = trim($parts[0]);
                        foreach ($parts as $i => $part) {
                            if ($i === 0) continue;
                            if (strpos($part, 'Added Items:') !== false)             $addons  = array_map('trim', explode(',', str_replace('Added Items:', '', $part)));
                            elseif (strpos($part, 'Menu Swaps:') !== false)          $swaps   = array_map('trim', explode(',', str_replace('Menu Swaps:', '', $part)));
                            elseif (strpos($part, 'Custom Add-on Request:') !== false) $customRequest = trim(str_replace('Custom Add-on Request:', '', $part));
                        }
                    }
                    $hasCustomizations = !empty($swaps) || !empty($addons) || !empty($customRequest);
                    $btnStyle = $hasCustomizations ? "background: #e67e22; color: #fff;" : "background: var(--info-color); color: #fff;";
                    $btnIcon  = $hasCustomizations ? "fa-sliders-h" : "fa-list";
                    $nrs = strtolower(trim((string) ($reservation['order_status'] ?? 'pending')));
                    if ($nrs === 'approved')                        $nrs = 'confirmed';
                    if ($nrs === 'pending_payment')                 $nrs = 'pending';
                    elseif ($nrs === 'awaiting_store_confirmation') $nrs = 'confirmed';
                    $statusDisplay = ucfirst($nrs ?: 'pending');
                    $statusClass   = 'status-pending';
                    if ($nrs === 'confirmed') $statusClass = 'status-confirmed';
                    if ($nrs === 'cancelled') $statusClass = 'status-cancelled';
                    if ($nrs === 'completed') $statusClass = 'status-completed';
                    if ($nrs === 'preparing') $statusClass = 'status-preparing';
                    $isDpPaid = $downPaymentRequired > 0 ? ($paid >= $downPaymentRequired) : ($paid > 0);
                    $dpStatusHtml = $isDpPaid
                        ? '<span style="color:var(--success-color);margin-left:4px;">(Paid)</span>'
                        : '<span style="color:var(--error-color);margin-left:4px;">(Not Paid)</span>';
                    $amountDisplay  = '<div style="font-weight:bold;">&#8369;' . number_format($total, 2) . '</div>';
                    $amountDisplay .= '<div style="color:var(--text-muted);font-size:11px;">DP: &#8369;' . number_format($downPaymentRequired, 2) . $dpStatusHtml . '</div>';
                    if ($paid > 0)    $amountDisplay .= '<div style="color:var(--reservation-success);font-size:11px;">Advanced Payment: &#8369;' . number_format($paid, 2) . '</div>';
                    if ($balance > 0) $amountDisplay .= '<div style="color:var(--error-color);font-size:11px;">Bal: &#8369;' . number_format($balance, 2) . '</div>';
                    else              $amountDisplay .= '<div style="color:var(--success-color);font-size:11px;">Fully Paid</div>';
                    $eventDate      = strtotime($reservation['order_date']);
                    $now            = time();
                    $days           = floor(($eventDate - $now) / (60 * 60 * 24));
                    $isUpcomingSoon = ($days >= 0 && $days <= 5);
                    $isEventDay     = (date('Y-m-d') === date('Y-m-d', $eventDate));
                    $rowStyle = ''; $dateStyle = '';
                    if ($isUpcomingSoon && $current_tab == 'pending') {
                        $rowStyle  = 'background:rgba(243,156,18,0.05);border-left:3px solid #f39c12;';
                        $dateStyle = 'color:var(--reservation-warning);font-weight:bold;';
                    }
                    if ($current_tab == 'trash') {
                        $actionsHtml = '<form method="POST" style="display:inline;" onsubmit="return confirm(\'Restore this order?\');">
                            <input type="hidden" name="order_id" value="' . $reservation['id'] . '">
                            <button type="submit" name="restore_order" class="btn" style="background:rgba(46,204,113,0.2);color:#2ecc71;border:1px solid #2ecc71;padding:5px 10px;font-size:12px;cursor:pointer;">
                                <i class="fas fa-undo"></i> Restore
                            </button>
                        </form>';
                    } else {
                        $currentStatus     = strtolower($reservation['order_status']);
                        $preparingDisabled = ($currentStatus !== 'preparing') && (!$isDpPaid || !$isEventDay);
                        $preparingLabel    = 'Preparing';
                        if ($currentStatus !== 'preparing') {
                            if (!$isDpPaid)       $preparingLabel .= ' (Down payment required)';
                            elseif (!$isEventDay) $preparingLabel .= ' (Available on event day)';
                        }
                        $actionsHtml = '<div style="display:flex;gap:5px;align-items:center;">
                            <select class="status-select" style="width:auto;padding-right:20px;"
                                    onfocus="isInteracting=true" onblur="isInteracting=false"
                                    onchange="updateStatus(' . $reservation['id'] . ', this.value)">
                                <option value="pending"'   . ($currentStatus === 'pending' ? ' selected' : '') . (in_array($currentStatus, ['preparing','ready','delivering','completed']) ? ' disabled' : '') . '>Pending</option>'
                                . ($currentStatus === 'awaiting_store_confirmation' ? '<option value="confirmed" selected disabled>Confirmed</option>' : '')
                                . '<option value="confirmed"'  . ($currentStatus === 'confirmed' ? ' selected' : '') . (in_array($currentStatus, ['preparing','ready','delivering','completed']) ? ' disabled' : '') . ' style="color:#2ecc71;">Accept Booking</option>
                                <option value="preparing"' . ($currentStatus === 'preparing' ? ' selected' : '') . ($preparingDisabled ? ' disabled' : '') . ' style="color:#3498db;">' . htmlspecialchars($preparingLabel) . '</option>
                                <option value="completed"' . ($currentStatus === 'completed' ? ' selected' : '') . (!$isFullyPaid && $currentStatus !== 'completed' ? ' disabled' : '') . ' style="color:#95a5a6;">Completed' . (!$isFullyPaid ? ' (Full payment required)' : '') . '</option>
                                <option value="cancelled"' . ($currentStatus === 'cancelled' ? ' selected' : '') . ($isFullyPaid ? ' disabled' : '') . ' style="color:#e74c3c;">Cancelled' . ($isFullyPaid ? ' (Cannot cancel, fully paid)' : '') . '</option>
                            </select>
                            <a href="event_preparation.php?order_id=' . $reservation['id'] . '" class="btn" title="Event Preparation" style="background:transparent;color:#3498db;border:1px solid var(--border-color);padding:5px 8px;font-size:12px;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-tasks"></i>
                            </a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm(\'Move to trash?\');">
                                <input type="hidden" name="order_id" value="' . $reservation['id'] . '">
                                <input type="hidden" name="current_tab" value="' . $current_tab . '">
                                <button type="submit" name="archive_order" class="btn" title="Move to Trash" style="background:transparent;color:var(--error-color);border:1px solid var(--border-color);padding:5px 8px;font-size:12px;cursor:pointer;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>';
                    }
                    $actionsHtml = lockCancelledReservationControls($actionsHtml, (string) $reservation['order_status']);
                    $init_processed_rows[] = [
                        'reservation'       => $reservation,
                        'rowStyle'          => $rowStyle,
                        'dateStyle'         => $dateStyle,
                        'amountDisplay'     => $amountDisplay,
                        'statusClass'       => $statusClass,
                        'statusDisplay'     => $statusDisplay,
                        'actionBadgesHtml'  => $actionBadgesHtml,
                        'btnStyle'          => $btnStyle,
                        'btnIcon'           => $btnIcon,
                        'hasCustomizations' => $hasCustomizations,
                        'actionsHtml'       => $actionsHtml,
                        'isUpcomingSoon'    => $isUpcomingSoon,
                        'customer_id'       => $reservation['customer_id'],
                    ];
                }
                if (empty($init_processed_rows)) {
                    echo '<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">
                        <i class="fas fa-inbox" style="font-size:32px;margin-bottom:10px;opacity:0.5;"></i><br>
                        No ' . $current_tab . ' reservations found.
                    </td></tr>';
                } else {
                    $init_customer_groups = [];
                    foreach ($init_processed_rows as $row) {
                        $cid = $row['customer_id'];
                        if (!isset($init_customer_groups[$cid])) {
                            $init_customer_groups[$cid] = [
                                'name'  => htmlspecialchars($row['reservation']['first_name'] . ' ' . $row['reservation']['last_name']),
                                'phone' => htmlspecialchars($row['reservation']['phone_number']),
                    'avatar' => reservationCustomerAvatar($row['reservation']),
                                'rows'  => [],
                            ];
                        }
                        $init_customer_groups[$cid]['rows'][] = $row;
                    }
                    foreach ($init_customer_groups as $cid => $group) {
                        $group_id   = 'cust-group-' . $cid . '-' . md5($current_tab);
                        $book_count = count($group['rows']);
                        echo '<tr data-customer-id="' . (int) $cid . '" class="caterer-group-header" onclick="toggleCustomerOrders(\'' . $group_id . '\')"
                                  style="cursor:pointer;background:rgba(212,175,55,0.08);">
                                <td colspan="6" style="padding:14px 16px;border:none;">
                                    <div style="display:flex;justify-content:space-between;align-items:center;">
                                        <div style="display:flex;align-items:center;gap:12px;">
                                            ' . $group['avatar'] . '<strong style="color:var(--accent-gold);font-size:14px;">' . $group['name'] . '</strong>
                                            <span style="background:var(--accent-gold);color:#000;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:700;">'
                                                . $book_count . ' ' . ($book_count === 1 ? 'booking' : 'bookings') .
                                            '</span>
                                        </div>
                                    </div>
                                </td>
                              </tr>';
                        foreach ($group['rows'] as $row) {
                            $reservation = $row['reservation'];
                            echo '<tr class="customer-order-' . $group_id . '" style="display:none; ' . $row['rowStyle'] . '">
                                <td><strong>' . $booking_display_numbers[(int) $reservation['id']] . '</strong></td>
                                <td>
                                    <div style="' . $row['dateStyle'] . '">' . date('M d, Y', strtotime($reservation['order_date'])) . '</div>
                                    <small style="color:var(--text-muted);">' . date('h:i A', strtotime($reservation['order_time'])) . '</small>'
                                    . (!empty($reservation['created_at']) ? '<div style="font-size:10px;color:var(--text-muted);margin-top:2px;">Booked: ' . date('M d, Y h:i A', strtotime($reservation['created_at'])) . '</div>' : '')
                                    . ($row['isUpcomingSoon'] && $current_tab == 'pending' ? '<div style="font-size:10px;color:var(--reservation-warning);margin-top:2px;"><i class="fas fa-exclamation-circle"></i> Upcoming</div>' : '') . '
                                </td>
                                <td>' . $row['amountDisplay'] . '</td>
                                <td>
                                    <span class="status-badge ' . $row['statusClass'] . '">' . $row['statusDisplay'] . '</span>
                                    ' . $row['actionBadgesHtml'] . '
                                </td>
                                <td>
                                    <form action="order-details.php" method="get" style="display:inline;">
                                        <input type="hidden" name="reservation_tab" value="' . htmlspecialchars($current_tab, ENT_QUOTES) . '">
                                        <input type="hidden" name="reservation_action" value="' . htmlspecialchars($action_filter, ENT_QUOTES) . '">
                                        <input type="hidden" name="id" value="' . intval($reservation['id']) . '">
                                        <button type="submit" class="btn details-link"' . (!$row['hasCustomizations'] ? ' name="payment_transactions" value="1"' : '') . '
                                                style="' . $row['btnStyle'] . ' padding:6px 12px;font-size:12px;display:inline-flex;align-items:center;gap:5px;cursor:pointer;">
                                            <i class="fas ' . $row['btnIcon'] . '"></i> ' . ($row['hasCustomizations'] ? 'Custom Booking' : 'View Details') . '
                                        </button>
                                    </form>
                                </td>
                                <td>' . $row['actionsHtml'] . '</td>
                            </tr>';
                        }
                    }
                }
                ?>

            </tbody>
        </table>
    </div>
</div>

<style>
    .btn-save-status {
        background: #d4af37;
        color: white;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background 0.2s;
        flex-shrink: 0; /* Prevent shrinking */
    }
    
    .btn-save-status:hover {
        background: #059669;
    }

    .action-needed-list {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 8px;
    }

    .action-needed-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        background: rgba(212, 175, 55, 0.14);
        border: 1px solid rgba(212, 175, 55, 0.26);
        color: var(--accent-gold);
    }
</style>

<!-- Order Details Modal -->
<dialog id="reservationBulkConfirm" aria-labelledby="reservationBulkTitle" aria-describedby="reservationBulkDescription">
    <div class="reservation-confirm-icon"><i class="fas fa-undo" aria-hidden="true"></i></div>
    <h2 id="reservationBulkTitle">Restore all bookings?</h2>
    <p id="reservationBulkDescription">All bookings in Trash will return to their respective lists.</p>
    <div class="reservation-confirm-actions">
        <button type="button" class="reservation-confirm-cancel" onclick="document.getElementById('reservationBulkConfirm').close()">Cancel</button>
        <button type="button" id="reservationBulkProceed">Restore All</button>
    </div>
</dialog>
<script>
let pendingReservationBulkAction = null;
let approvedReservationBulkForm = null;
function confirmReservationBulkAction(event) {
    const form = event.target;
    if (approvedReservationBulkForm === form) {
        approvedReservationBulkForm = null;
        return true;
    }
    const action = form.dataset.confirmAction;
    const copy = {
        restore: ['Restore all bookings?', 'All bookings in Trash will return to their respective lists.', 'Restore All', 'fa-undo'],
        delete: ['Delete all bookings in Trash?', 'These bookings will be removed from your Trash. You cannot restore them afterward.', 'Delete All', 'fa-trash'],
        archive: ['Move cancelled bookings to Trash?', 'You can restore these bookings from Trash later.', 'Move to Trash', 'fa-trash']
    }[action];
    pendingReservationBulkAction = { form, submitter: event.submitter };
    const dialog = document.getElementById('reservationBulkConfirm');
    document.getElementById('reservationBulkTitle').textContent = copy[0];
    document.getElementById('reservationBulkDescription').textContent = copy[1];
    document.getElementById('reservationBulkProceed').textContent = copy[2];
    dialog.querySelector('.reservation-confirm-icon i').className = 'fas ' + copy[3];
    dialog.dataset.action = action;
    dialog.showModal();
    dialog.querySelector('.reservation-confirm-cancel').focus();
    return false;
}
document.getElementById('reservationBulkProceed').addEventListener('click', function () {
    const pending = pendingReservationBulkAction;
    if (!pending) return;
    pendingReservationBulkAction = null;
    document.getElementById('reservationBulkConfirm').close();
    approvedReservationBulkForm = pending.form;
    pending.form.requestSubmit(pending.submitter);
});
document.getElementById('reservationBulkConfirm').addEventListener('close', function () {
    pendingReservationBulkAction = null;
});
</script>
<div id="orderDetailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h2 id="modalOrderNo">Order Details</h2>
                <small id="modalCustomer" style="color: var(--text-muted);"></small>
            </div>
            <span class="close-modal" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            
            <!-- Base Set & Original Menu -->
            <div class="order-section">
                <span class="section-label"><i class="fas fa-utensils"></i> Base Menu Set</span>
                <div id="modalSetName" style="font-size: 18px; font-weight: bold; color: var(--text-main); margin-bottom: 10px;"></div>
                <div style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px;">
                    <span class="section-label" style="margin-bottom: 5px;">Original Inclusions:</span>
                    <ul id="modalOriginalList" style="margin: 0; padding-left: 20px; color: var(--text-muted); font-size: 14px;"></ul>
                </div>
            </div>

            <!-- Customizations -->
            <div class="order-section" id="customizationSection">
                <span class="section-label"><i class="fas fa-sliders-h"></i> Customizations</span>
                
                <div id="swapsContainer" style="margin-bottom: 15px;">
                    <strong style="color: var(--warning-color); font-size: 14px; display: block; margin-bottom: 5px;">Swapped Items</strong>
                    <ul id="modalSwapsList" class="item-list"></ul>
                </div>
                
                <div id="addonsContainer">
                    <strong style="color: var(--info-color); font-size: 14px; display: block; margin-bottom: 5px;">Add-ons</strong>
                    <ul id="modalAddonsList" class="item-list"></ul>
                </div>
            </div>

            <!-- Custom Request -->
            <div class="order-section" id="customRequestSection" style="display: none;">
                <span class="section-label"><i class="fas fa-comment-dots"></i> Special Requests</span>
                <div id="modalCustomRequest" style="padding: 15px; background: rgba(212, 175, 55, 0.1); border: 1px dashed var(--accent-gold); border-radius: 8px; color: var(--accent-gold);"></div>
            </div>

            <!-- Summary -->
            <div class="total-row">
                <span>Total Amount</span>
                <span id="modalTotal">?0.00</span>
            </div>
            <div style="text-align: right; color: var(--text-muted); font-size: 14px;">
                <span id="modalPax"></span> Pax
            </div>
            <div style="text-align: right; color: var(--text-muted); font-size: 12px; margin-top: 4px;">
                <span id="modalDownPayment"></span>
            </div>
            <div style="text-align: right; color: var(--text-muted); font-size: 12px; margin-top: 4px;">
                <span id="modalPackagePrice"></span>
            </div>
            <div style="text-align: right; color: var(--text-muted); font-size: 12px; margin-top: 4px;">
                <span id="modalBookedAt"></span>
            </div>
            
            <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border-color);">
                <strong style="color: var(--text-main); display: block; margin-bottom: 5px;">Delivery Address:</strong>
                <p id="modalAddress" style="color: var(--text-muted); margin: 0;"></p>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Stop the live refresh from replacing a details link while it is being
        // tapped. This is especially important on touch devices, where a row
        // refresh between pointer-down and pointer-up cancels the click.
        document.addEventListener('pointerdown', function(event) {
            if (event.target.closest('a.details-link')) {
                isInteracting = true;
            }
        });

        document.addEventListener('click', function(event) {
            const detailsLink = event.target.closest('a.details-link');
            if (!detailsLink) return;

            event.preventDefault();
            isInteracting = true;
            window.location.assign(detailsLink.href);
        });

        document.querySelectorAll('.view-order-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                try {
                    const data = JSON.parse(this.dataset.order);
                    viewOrderDetails(data);
                } catch (e) {
                    console.error('Error parsing order data', e);
                    alert('Error viewing order details. Please try again.');
                }
            });
        });
    });

    function viewOrderDetails(data) {
        // Populate Basic Info
        document.getElementById('modalOrderNo').textContent = data.order_number;
        document.getElementById('modalCustomer').textContent = data.customer_name + ' (' + data.contact + ')';
        document.getElementById('modalSetName').textContent = data.set_name || 'Standard Package';
        document.getElementById('modalTotal').textContent = '?' + parseFloat(data.total_amount).toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('modalPax').textContent = data.pax;
        document.getElementById('modalDownPayment').textContent = 'Required Down Payment: \u20B1' + parseFloat(data.down_payment_required || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('modalPackagePrice').textContent = data.package_price > 0
            ? 'Package Price: \u20B1' + parseFloat(data.package_price).toLocaleString('en-US', {minimumFractionDigits: 2})
            : '';
        document.getElementById('modalBookedAt').textContent = data.created_at
            ? 'Booking Created: ' + new Date(String(data.created_at).replace(' ', 'T')).toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit' })
            : '';
        document.getElementById('modalAddress').textContent = data.delivery_address;

        // Populate Original List
        const originalList = document.getElementById('modalOriginalList');
        originalList.innerHTML = '';
        
        if (data.original_items && data.original_items.length > 0) {
            data.original_items.forEach(item => {
                // Check if this item was swapped out
                let isSwapped = false;
                let replacedBy = '';
                
                data.swaps.forEach(swapStr => {
                    // Format: "Swap [Original] for [Replacement] (+Price)"
                    const regex = /Swap\s+(.*?)\s+for\s+(.*?)\s+\(\+/i;
                    const match = swapStr.match(regex);
                    if (match) {
                        const swapOrig = match[1].trim().toLowerCase();
                        const itm = item.trim().toLowerCase();
                        const swapOrigBase = swapOrig.replace(/\s*\(.*?\)/g, '').trim();
                        const itmBase = itm.replace(/\s*\(.*?\)/g, '').trim();
                        if (swapOrig === itm || (swapOrigBase && itmBase && (itmBase === swapOrigBase || itmBase.includes(swapOrigBase) || swapOrigBase.includes(itmBase)))) {
                            isSwapped = true;
                            replacedBy = match[2].trim();
                        }
                    }
                });

                const li = document.createElement('li');
                if (isSwapped) {
                    li.className = 'original-menu-item swapped-out';
                    li.innerHTML = `${item} <span style="font-size: 11px; color: var(--warning-color); margin-left: 10px;">(Replaced by ${replacedBy})</span>`;
                } else {
                    li.textContent = item;
                }
                originalList.appendChild(li);
            });
        } else {
            // Fallback: If no original items found (e.g. Set Name mismatch), show Raw Menu string to ensure caterer sees SOMETHING
            if (data.raw_menu) {
                const li = document.createElement('li');
                li.style.color = 'var(--text-main)';
                li.innerHTML = '<strong>Full Order String:</strong><br>' + data.raw_menu.replace(/ \| /g, '<br>');
                originalList.appendChild(li);
            } else {
                originalList.innerHTML = '<li>No details available for this set.</li>';
            }
        }

        // Populate Swaps
        const swapsList = document.getElementById('modalSwapsList');
        swapsList.innerHTML = '';
        const swapsContainer = document.getElementById('swapsContainer');
        
        if (data.swaps && data.swaps.length > 0) {
            swapsContainer.style.display = 'block';
            data.swaps.forEach(swap => {
                const li = document.createElement('li');
                // Highlight the "for [Replacement]" part
                // Format: Swap X for Y (+Price)
                const formatted = swap.replace(/for\s+(.*?)\s+\(/, 'for <span class="item-highlight">$1</span> (');
                li.innerHTML = `<span class="badge-swap">SWAP</span> <span>${formatted}</span>`;
                swapsList.appendChild(li);
            });
        } else {
            swapsContainer.style.display = 'none';
        }

        // Populate Addons
        const addonsList = document.getElementById('modalAddonsList');
        addonsList.innerHTML = '';
        const addonsContainer = document.getElementById('addonsContainer');
        
        if (data.addons && data.addons.length > 0) {
            addonsContainer.style.display = 'block';
            data.addons.forEach(addon => {
                const li = document.createElement('li');
                // Format: Name (+Price)
                // Highlight Name
                const formatted = addon.replace(/^(.*?)\s+\(/, '<span class="item-highlight">$1</span> (');
                li.innerHTML = `<span class="badge-addon">ADD-ON</span> <span>${formatted}</span>`;
                addonsList.appendChild(li);
            });
        } else {
            addonsContainer.style.display = 'none';
        }

        // Custom Request
        const customSection = document.getElementById('customRequestSection');
        if (data.custom_request) {
            customSection.style.display = 'block';
            document.getElementById('modalCustomRequest').textContent = data.custom_request;
        } else {
            customSection.style.display = 'none';
        }

        // Show Modal
        document.getElementById('orderDetailsModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('orderDetailsModal').style.display = 'none';
    }
    
    // Close on outside click
    window.onclick = function(event) {
        if (event.target == document.getElementById('orderDetailsModal')) {
            closeModal();
        }
    }

    // Update Status (AJAX)
    function updateStatus(orderId, status) {
        // Immediately set isInteracting to false so the refresh isn't blocked
        isInteracting = false;
        
        const formData = new FormData();
        formData.append('update_status_ajax', '1');
        formData.append('order_id', orderId);
        formData.append('status', status);

        fetch('reservations.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success toast (using function from header.php if available)
                if (typeof showToast === 'function') {
                    showToast('Success', 'Order status updated successfully!', 'success');
                }
                
                // CRITICAL: Force refresh immediately to reflect changes
                setTimeout(() => {
                    refreshReservations(true);
                }, 100);
            } else {
                if (typeof showToast === 'function') {
                    showToast('Error', 'Failed to update status: ' + (data.error || 'Unknown error'), 'error');
                } else {
                    alert('Failed to update status: ' + (data.error || 'Unknown error'));
                }
                // Refresh to revert UI to actual state
                refreshReservations(true);
            }
        })
        .catch(err => {
            console.error('Error updating status:', err);
            if (typeof showToast === 'function') {
                showToast('Error', 'Network error occurred', 'error');
            }
        });
    }

    // Flag to track user interaction
    let isInteracting = false;

    // Add event listeners to initial select elements
    function addInteractionListeners() {
        document.querySelectorAll('.status-select').forEach(select => {
            select.onfocus = function() { isInteracting = true; };
            select.onblur = function() { isInteracting = false; };
            // Also handle click just in case
            select.onclick = function() { isInteracting = true; };
        });
    }

    // Call on load
    document.addEventListener('DOMContentLoaded', function() {
        addInteractionListeners();
        // ... existing listeners
        document.querySelectorAll('.view-order-btn').forEach(btn => {
            // ...
        });
    });

    function applyActionFilter(actionValue) {
        const urlParams = new URLSearchParams(window.location.search);
        const currentTab = urlParams.get('tab') || 'pending';
        const nextUrl = new URL('reservations.php', window.location.href);
        nextUrl.searchParams.set('tab', currentTab);
        if (actionValue && actionValue !== 'all') {
            nextUrl.searchParams.set('action', actionValue);
        } else {
            nextUrl.searchParams.delete('action');
        }
        window.location.href = nextUrl.toString();
    }

    let reservationsRefreshInFlight = false;

    // Real-time Updates
    function refreshReservations(force = false) {
        if (reservationsRefreshInFlight || reservationGroupAnimations.size) return;
        // Skip update if user is interacting with a dropdown, unless forced
        if (isInteracting && !force) {
            console.log('Skipping refresh due to user interaction');
            return;
        }

        const urlParams = new URLSearchParams(window.location.search);
        const currentTab = urlParams.get('tab') || 'pending';
        const currentAction = urlParams.get('action') || 'all';

        reservationsRefreshInFlight = true;
        // Add timestamp to prevent caching
        fetch('reservations.php?fetch_reservations=1&tab=' + currentTab + '&action=' + encodeURIComponent(currentAction) + '&focus_customer=' + encodeURIComponent(urlParams.get('focus_customer') || '') + '&_=' + new Date().getTime())
            .then(response => response.text())
            .then(html => {
                // A request may have started before the user tapped a control.
                // Check again here so that an in-flight response cannot replace
                // the link before its click is handled.
                if (reservationGroupAnimations.size || (isInteracting && !force)) {
                    return;
                }

                const tbody = document.getElementById('reservations-body');
                if (tbody) {
                    const listPosition = captureReservationListState();
                    // Capture state at response time, including clicks during the request.
                    const expandedGroups = new Set();
                    tbody.querySelectorAll('tr[class]').forEach(row => {
                        if (row.style.display === 'none') return;
                        row.classList.forEach(name => {
                            if (name.startsWith('customer-order-')) expandedGroups.add(name);
                        });
                    });
                    tbody.innerHTML = html;
                    tbody.querySelectorAll('tr[class]').forEach(row => {
                        if (Array.from(row.classList).some(name => expandedGroups.has(name))) {
                            row.style.display = '';
                        }
                    });
                    restoreReservationListState(listPosition);
                    saveReservationListState();
                    // Re-attach listeners to new elements
                    addInteractionListeners();
                    
                    // Re-attach modal listeners
                    document.querySelectorAll('.view-order-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            try {
                                const data = JSON.parse(this.dataset.order);
                                viewOrderDetails(data);
                            } catch (e) {
                                console.error('Error parsing order data', e);
                            }
                        });
                    });

                    // Reset interaction flag if forced
                    if (force) isInteracting = false;
                }
            })
            .catch(err => console.error('Error refreshing reservations:', err))
            .finally(() => { reservationsRefreshInFlight = false; });
    }

    // Keep bookings current while preserving expanded customer groups.
    setInterval(refreshReservations, 3000);
</script>


<!-- Customer Bookings Modal -->
<div id="customerBookingsModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.7);">
    <div class="modal-content" style="background-color: var(--card-bg); margin: 5% auto; padding: 25px; border: 1px solid var(--border-color); border-radius: 8px; max-width: 800px; position: relative;">
        <span class="close" onclick="closeCustomerModal()" style="color: var(--text-muted); float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
        <h2 style="margin-top: 0; color: var(--text-color); border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">Customer Booking History</h2>
        <div id="cb-modal-body" style="margin-top: 20px; max-height: 60vh; overflow-y: auto;">
            <div style="text-align:center; padding: 40px; color: var(--text-muted);">
                <i class="fas fa-spinner fa-spin fa-2x"></i><br><br>Loading bookings...
            </div>
        </div>
    </div>
</div>

<script>
// Toggle booking rows under a customer group header (same as toggleCatererOrders in customer/booking.php)
const reservationGroupAnimations = new Map();
const focusedReservationCustomer = new URLSearchParams(location.search).get('focus_customer');
let latestOpenedReservationGroup = null;
function openFocusedReservationRows() {
    if (!focusedReservationCustomer) return;
    document.querySelectorAll('#reservations-body tr[class*="customer-order-"]').forEach(row => {
        row.style.display = '';
    });
}
document.querySelectorAll('.tabs-container .tab-link').forEach(link => {
    link.addEventListener('click', () => {
        const url = new URL(link.href);
        const openGroup = [...document.querySelectorAll('#reservations-body tr[class*="customer-order-"]')]
            .filter(row => row.style.display !== 'none')
            .flatMap(row => [...row.classList].filter(name => name.startsWith('customer-order-')));
        const preferred = latestOpenedReservationGroup && 'customer-order-' + latestOpenedReservationGroup;
        const group = openGroup.includes(preferred) ? preferred : openGroup.at(-1);
        const customer = group?.match(/^customer-order-cust-group-(\d+)-/)?.[1] || focusedReservationCustomer;
        if (customer) url.searchParams.set('focus_customer', customer);
        link.href = url.href;
    });
});
const reservationListStateKey = 'reservation-list:' + <?php echo json_encode((string) $user_id); ?> + ':' +
    (new URLSearchParams(location.search).get('tab') || 'pending') + ':' +
    (new URLSearchParams(location.search).get('action') || 'all') + ':' + (new URLSearchParams(location.search).get('focus_customer') || 'all');
function captureReservationListState() {
    const list = document.querySelector('.reservations-table-wrap');
    if (!list) return null;
    const open = new Set();
    list.querySelectorAll('tr[class]').forEach(row => {
        if (row.style.display === 'none') return;
        row.classList.forEach(name => { if (name.startsWith('customer-order-')) open.add(name); });
    });
    const top = list.getBoundingClientRect().top;
    const anchor = Array.from(list.querySelectorAll('.caterer-group-header[data-customer-id]'))
        .find(row => row.getBoundingClientRect().bottom > top);
    return { open: Array.from(open), scrollTop: list.scrollTop,
        anchor: anchor?.dataset.customerId,
        offset: anchor ? anchor.getBoundingClientRect().top - top : 0 };
}
function restoreReservationListState(state) {
    const list = document.querySelector('.reservations-table-wrap');
    if (!list || !state || !Array.isArray(state.open)) return;
    const open = new Set(state.open);
    list.querySelectorAll('tr[class]').forEach(row => {
        if (Array.from(row.classList).some(name => open.has(name))) row.style.display = '';
    });
    list.scrollTop = Number(state.scrollTop) || 0;
    const anchor = Array.from(list.querySelectorAll('.caterer-group-header[data-customer-id]'))
        .find(row => row.dataset.customerId === state.anchor);
    if (anchor && Number.isFinite(state.offset)) {
        list.scrollTop += anchor.getBoundingClientRect().top - list.getBoundingClientRect().top - state.offset;
    }
}
function saveReservationListState() {
    try { sessionStorage.setItem(reservationListStateKey, JSON.stringify(captureReservationListState())); } catch (_) {}
}
document.addEventListener('DOMContentLoaded', function () {
    try { restoreReservationListState(JSON.parse(sessionStorage.getItem(reservationListStateKey))); } catch (_) {}
    openFocusedReservationRows();
    const list = document.querySelector('.reservations-table-wrap');
    let saveFrame = null;
    list?.addEventListener('scroll', function () {
        if (saveFrame !== null) return;
        saveFrame = requestAnimationFrame(function () { saveFrame = null; saveReservationListState(); });
    }, { passive: true });
});
window.addEventListener('pagehide', saveReservationListState);
document.addEventListener('submit', function(event) {
    if (event.target.matches('form[action="order-details.php"]')) saveReservationListState();
}, true);
window.addEventListener('pageshow', function(event) {
    if (event.persisted) refreshReservations(true);
});
function toggleCustomerOrders(groupId) {
    markReservationCustomerRead(groupId);
    const rows   = document.querySelectorAll('.customer-order-' + groupId);
    const icon   = document.getElementById('icon-' + groupId);
    if (!rows.length) return;
    const previous = reservationGroupAnimations.get(groupId);
    const opening = previous ? !previous.opening : rows[0].style.display === 'none';
    if (opening) latestOpenedReservationGroup = groupId;
    if (previous) previous.animations.forEach(animation => {
        animation.onfinish = null;
        animation.finish();
    });
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const mobile = window.matchMedia('(max-width: 768px)').matches;
    const state = { opening, animations: [] };
    reservationGroupAnimations.set(groupId, state);

    rows.forEach(row => {
        row.style.display = '';
        if (reducedMotion || typeof row.animate !== 'function') {
            row.style.display = opening ? '' : 'none';
            return;
        }
        const styles = getComputedStyle(row);
        const expanded = { opacity: 1 };
        const collapsed = { opacity: 0 };
        // Animate mobile grid cards without disturbing desktop table columns.
        if (mobile) {
            ['height', 'minHeight', 'paddingTop', 'paddingBottom', 'marginBottom',
                'borderTopWidth', 'borderBottomWidth'].forEach(property => {
                expanded[property] = styles[property];
                collapsed[property] = '0px';
            });
        }
        const animation = row.animate(opening ? [collapsed, expanded] : [expanded, collapsed], {
            duration: 180, easing: 'cubic-bezier(0.2, 0, 0, 1)'
        });
        animation.onfinish = () => { row.style.display = opening ? '' : 'none'; };
        state.animations.push(animation);
    });
    Promise.all(state.animations.map(animation => animation.finished.catch(() => {}))).then(() => {
        if (reservationGroupAnimations.get(groupId) === state) reservationGroupAnimations.delete(groupId);
        saveReservationListState();
    });

    if (icon) {
        icon.style.transform = opening ? 'rotate(180deg)' : '';
    }
}
</script>

<script>
function openCustomerModal(customerId) {
    // Show modal and reset content
    const modal = document.getElementById('customerBookingsModal');
    const modalBody = document.getElementById('cb-modal-body');
    
    modal.style.display = 'block';
    modalBody.innerHTML = '<div style="text-align:center; padding: 40px; color: var(--text-muted);"><i class="fas fa-spinner fa-spin fa-2x"></i><br><br>Loading bookings...</div>';

    // Fetch data
    fetch('reservations.php?fetch_customer_bookings=1&customer_id=' + customerId)
        .then(response => response.text())
        .then(html => {
            modalBody.innerHTML = html;
        })
        .catch(err => {
            modalBody.innerHTML = '<div style="color: var(--error-color); text-align:center; padding: 20px;">Error loading bookings.</div>';
            console.error('Fetch error:', err);
        });
}

function closeCustomerModal() {
    document.getElementById('customerBookingsModal').style.display = 'none';
}

// Close when clicking outside the modal content
window.addEventListener('click', function(event) {
    const modal = document.getElementById('customerBookingsModal');
    if (event.target == modal) {
        closeCustomerModal();
    }
});
</script>

<script>
let reservationBadgeGroups = {}, reservationBadgeToken = '', reservationBadgeBusy = false;
const reservationBadgePending = new Set();
function paintReservationBadges() {
    const unreadTotal = Object.entries(reservationBadgeGroups).reduce((sum, [id, ids]) => sum + (reservationBadgePending.has(id) ? 0 : ids.length), 0);
    if (typeof syncReservationSidebarBadge === 'function') syncReservationSidebarBadge(unreadTotal);
    document.querySelectorAll('.caterer-group-header[data-customer-id]').forEach(row => {
        const id = row.dataset.customerId;
        const name = row.querySelector('strong');
        if (!name) return;
        let badge = row.querySelector('.reservation-unread-badge');
        const count = reservationBadgePending.has(id) ? 0 : (reservationBadgeGroups[id] || []).length;
        if (!count) { if (badge) badge.remove(); return; }
        if (!badge) {
            badge = document.createElement('b'); badge.className = 'reservation-unread-badge';
            name.insertAdjacentElement('afterend', badge);
        }
        badge.textContent = count;
        badge.setAttribute('aria-label', count + ' unread notifications');
    });
}
async function loadReservationBadges() {
    if (reservationBadgeBusy || document.hidden) return;
    reservationBadgeBusy = true;
    try {
        const response = await fetch('../api/reservation-notifications.php', {cache:'no-store'});
        const data = await response.json();
        if (data.success) { reservationBadgeGroups = data.groups; reservationBadgeToken = data.token; paintReservationBadges(); }
    } catch (error) { console.error('Unable to refresh booking notifications', error); }
    finally { reservationBadgeBusy = false; }
}
async function markReservationCustomerRead(groupId) {
    const id = groupId.split('-')[2];
    const ids = [...(reservationBadgeGroups[id] || [])];
    if (!ids.length || reservationBadgePending.has(id)) return;
    reservationBadgePending.add(id); paintReservationBadges();
    try {
        const response = await fetch('../api/reservation-notifications.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body:JSON.stringify({customer_id:id, ids, token:reservationBadgeToken})
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error('Unable to mark notifications read');
        reservationBadgeGroups[id] = (reservationBadgeGroups[id] || []).filter(value => !ids.includes(value));
        if (typeof updateNotifCount === 'function') updateNotifCount();
        if (typeof loadNotifications === 'function') loadNotifications();
    } catch (error) { console.error(error); }
    finally { reservationBadgePending.delete(id); paintReservationBadges(); loadReservationBadges(); }
}
const reservationBody = document.getElementById('reservations-body');
if (reservationBody) new MutationObserver(paintReservationBadges).observe(reservationBody, {childList:true});
loadReservationBadges();
setInterval(loadReservationBadges, 3000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) loadReservationBadges(); });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
