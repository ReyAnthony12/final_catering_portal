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

        $order_id = mysqli_real_escape_string($conn, $_POST['order_id']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);

    // Block "completed" if customer still has unpaid balance
    if (strtolower(trim($status)) === 'completed') {
        $fin_check = getOrderFinancials($order_id);
        $remaining_balance = (float) ($fin_check['totals']['balance'] ?? 0);
        if ($remaining_balance > 0.009) {
            echo json_encode([
                'success' => false,
                'error' => 'Cannot mark as Completed. Customer still has a remaining balance of ?' . number_format($remaining_balance, 2) . '. Please collect full payment first.'
            ]);
            exit();
        }
    }
    
    // Get previous status for inventory logic
    $prev_q = mysqli_query($conn, "SELECT order_status, number_of_pax FROM bookings WHERE id = '$order_id'");
    $prev_data = mysqli_fetch_assoc($prev_q);
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
                 // It failed to update and it's not because it was already set
                 echo json_encode(['success' => false, 'error' => 'Update failed (Database Error)']);
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
            $msg = "Your booking #$ord_num status has been updated to " . ucfirst($status);
            
            // Check if function exists to avoid fatal error
            if (function_exists('createNotification')) {
                createNotification($cust_id, 'order_status', "Order Updated", $msg, "order-details.php?id=$order_id");
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
    $order_id = mysqli_real_escape_string($conn, $_POST['order_id']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);

    // Block "completed" if customer still has unpaid balance
    if (strtolower(trim($status)) === 'completed') {
        $fin_check = getOrderFinancials($order_id);
        $remaining_balance = (float) ($fin_check['totals']['balance'] ?? 0);
        if ($remaining_balance > 0.009) {
            header("Location: reservations.php?error=" . urlencode('Cannot mark as Completed. Remaining balance: ?' . number_format($remaining_balance, 2)));
            exit();
        }
    }
    
    $status_changed = catererChangeReservationStatus($conn, (int) $order_id, (int) $user_id, $status, null);
    if ($status_changed) {
        // ... (existing logic handled by DB triggers or duplicate code - simplified for redirect)
        // ideally we should reuse the logic but for now let's just redirect
    }
    if (!$status_changed) { header('Location: reservations.php?tab=cancelled&error=status_locked'); exit(); }
    header("Location: reservations.php?success=1");
    exit();
}

// ==========================================
// HANDLE ARCHIVE/RESTORE ACTIONS
// ==========================================
if (isset($_POST['archive_order'])) {
    $archive_id = intval($_POST['order_id']);
    mysqli_query($conn, "UPDATE bookings SET is_archived_by_store = 1 WHERE id = '$archive_id' AND store_id = '$user_id'");
    header("Location: reservations.php?tab=" . ($_POST['current_tab'] ?? 'pending'));
    exit();
}

if (isset($_POST['restore_order'])) {
    $restore_id = intval($_POST['order_id']);
    mysqli_query($conn, "UPDATE bookings SET is_archived_by_store = 0 WHERE id = '$restore_id' AND store_id = '$user_id'");
    header("Location: reservations.php?tab=trash");
    exit();
}

// ==========================================
// HANDLE REAL-TIME DATA FETCH (AJAX)
// ==========================================
if (isset($_GET['fetch_reservations'])) {
    $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'pending';
    $action_filter = isset($_GET['action']) ? trim($_GET['action']) : 'all';
    
    // Build Query based on Tab
    $where_clause = "WHERE o.store_id = '$user_id'";
    
    if ($current_tab == 'trash') {
        $where_clause .= " AND o.is_archived_by_store = 1";
    } else {
        $where_clause .= " AND o.is_archived_by_store = 0";
        
        if ($current_tab == 'today') {
            // Orders placed today (by created_at), any active status
            $where_clause .= " AND o.created_at IS NOT NULL AND DATE(o.created_at) = CURDATE()";
        } elseif ($current_tab == 'completed') {
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

    $order_by_sql = ($current_tab === 'today')
        ? "ORDER BY o.created_at DESC"
        : "ORDER BY o.order_date ASC";

    $reservations_query = "
        SELECT o.*, u.first_name, u.last_name, u.phone_number, p.name as product_name, p.category,
               o.payment_status, o.delivery_type
        FROM bookings o 
        JOIN users u ON o.customer_id = u.id 
        LEFT JOIN products p ON o.product_id = p.id 
        $where_clause
        $order_by_sql
    ";
    $reservations_result = mysqli_query($conn, $reservations_query);

    if (mysqli_num_rows($reservations_result) > 0) {
        while ($reservation = mysqli_fetch_assoc($reservations_result)) {
            // Get Financials (Real-time Balance)
            $fin = getOrderFinancials($reservation['id']);
            $balance = $fin['totals']['balance'];
            $total = $fin['totals']['grand_total'];
            $paid = $fin['totals']['total_paid'];
            $downPaymentRequired = (float) ($fin['down_payment_required'] ?? 0);
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
            $isBookedToday = !empty($reservation['created_at']) && date('Y-m-d', strtotime($reservation['created_at'])) === date('Y-m-d');
            
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

            // Amount Display Logic
            $amountDisplay = '<div style="font-weight: bold;">?' . number_format($total, 2) . '</div>';
            $amountDisplay .= '<div style="color: var(--text-muted); font-size: 11px;">DP: &#8369;' . number_format($downPaymentRequired, 2) . '</div>';
            if ($balance > 0) {
                $amountDisplay .= '<div style="color: var(--error-color); font-size: 11px;">Bal: ?' . number_format($balance, 2) . '</div>';
            } else {
                $amountDisplay .= '<div style="color: var(--success-color); font-size: 11px;">Fully Paid</div>';
            }

            // 5 Days Before Logic
            $eventDate = strtotime($reservation['order_date']);
            $now = time();
            $diff = $eventDate - $now;
            $days = floor($diff / (60 * 60 * 24));
            $isUpcomingSoon = ($days >= 0 && $days <= 5);
            
            $rowStyle = '';
            $dateStyle = '';
            if ($isBookedToday || $current_tab === 'today') {
                $rowStyle = 'background: rgba(46, 204, 113, 0.07); border-left: 3px solid #2ecc71;';
            } elseif ($isUpcomingSoon && $current_tab == 'pending') {
                $rowStyle = 'background: rgba(243, 156, 18, 0.05); border-left: 3px solid #f39c12;';
                $dateStyle = 'color: #f39c12; font-weight: bold;';
            }

            echo '<tr style="' . $rowStyle . '">
                <td>
                    <strong>' . $reservation['order_number'] . '</strong>
                    ' . ($isBookedToday ? '<div class="new-today-chip"><i class="fas fa-star"></i> NEW</div>' : '') . '
                </td>
                <td>
                    <div>' . htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']) . '</div>
                    <small style="color: var(--text-muted);">' . htmlspecialchars($reservation['phone_number']) . '</small>
                </td>
                <td>
                    <div style="' . $dateStyle . '">' . date('M d, Y', strtotime($reservation['order_date'])) . '</div>
                    <small style="color: var(--text-muted);">' . date('h:i A', strtotime($reservation['order_time'])) . '</small>
                    ' . (!empty($reservation['created_at']) ? '<div style="font-size: 10px; color: var(--text-muted); margin-top: 2px;">Booked: ' . date('M d, Y h:i A', strtotime($reservation['created_at'])) . '</div>' : '') . '
                    ' . ($isUpcomingSoon && $current_tab == 'pending' && !$isBookedToday ? '<div style="font-size: 10px; color: #f39c12; margin-top: 2px;"><i class="fas fa-exclamation-circle"></i> Upcoming</div>' : '') . '
                </td>
                <td>' . $amountDisplay . '</td>
                <td>
                    <span class="status-badge ' . $statusClass . '">' . $statusDisplay . '</span>
                    ' . $actionBadgesHtml . '
                </td>
                <td>
                    <form action="order-details.php" method="get" style="display: inline;">
                        <input type="hidden" name="id" value="' . intval($reservation['id']) . '">
                        <button type="submit" class="btn details-link"
                           style="' . $btnStyle . ' padding: 6px 12px; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                            <i class="fas ' . $btnIcon . '"></i> ' . ($hasCustomizations ? 'Custom Order' : 'View Details') . '
                        </button>
                    </form>
                </td>
                <td>';
            
            if ($current_tab == 'trash') {
                echo '<form method="POST" style="display:inline;" onsubmit="return confirm(\'Restore this order?\');">
                        <input type="hidden" name="order_id" value="' . $reservation['id'] . '">
                        <button type="submit" name="restore_order" class="btn" style="background: rgba(46, 204, 113, 0.2); color: #2ecc71; border: 1px solid #2ecc71; padding: 5px 10px; font-size: 12px; cursor: pointer;">
                            <i class="fas fa-undo"></i> Restore
                        </button>
                      </form>';
            } else {
                $completedDisabledAttr = ($balance > 0.009) ? ' disabled title="Pay remaining balance first before marking as Completed"' : '';
                $completedLabel = ($balance > 0.009)
                    ? 'Completed (Pay balance first)'
                    : 'Completed';
                echo '<div style="display: flex; gap: 5px; align-items: center;">
                        <select class="status-select" 
                                style="width: auto; padding-right: 20px;"
                                onfocus="isInteracting=true"
                                onblur="isInteracting=false"
                                onchange="updateStatus(' . $reservation['id'] . ', this.value, this)">
                            <option value="pending" ' . (strtolower($reservation['order_status']) === 'pending' ? 'selected' : '') . '>Pending</option>
                            ' . (strtolower($reservation['order_status']) === 'awaiting_store_confirmation'
                                ? '<option value="confirmed" selected disabled>Confirmed</option>'
                                : '') . '
                            <option value="confirmed" ' . (strtolower($reservation['order_status']) === 'confirmed' ? 'selected' : '') . ' style="color: #2ecc71;">Accept Booking</option>
                            <option value="preparing" ' . (strtolower($reservation['order_status']) === 'preparing' ? 'selected' : '') . ' style="color: #3498db;">Preparing</option>
                            <option value="completed" ' . (strtolower($reservation['order_status']) === 'completed' ? 'selected' : '') . ' style="color: #95a5a6;"' . $completedDisabledAttr . '>' . $completedLabel . '</option>
                            <option value="cancelled" ' . (strtolower($reservation['order_status']) === 'cancelled' ? 'selected' : '') . ' style="color: #e74c3c;">Cancelled</option>
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
            
            echo '</td>
            </tr>';
        }
    } else {
        $emptyLabel = ($current_tab === 'today')
            ? 'No new bookings placed today.'
            : ('No ' . htmlspecialchars($current_tab) . ' reservations found.');
        echo '<tr><td colspan="7" style="text-align:center; padding: 40px; color: var(--text-muted);">
                <i class="fas fa-inbox" style="font-size: 32px; margin-bottom: 10px; opacity: 0.5;"></i><br>
                ' . $emptyLabel . '
              </td></tr>';
    }
    exit();
}

// Get current tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'pending';
$action_filter = isset($_GET['action']) ? trim($_GET['action']) : 'all';

// Build Query based on Tab
$where_clause = "WHERE o.store_id = '$user_id'";

if ($current_tab == 'trash') {
    $where_clause .= " AND o.is_archived_by_store = 1";
} else {
    $where_clause .= " AND o.is_archived_by_store = 0";
    
    if ($current_tab == 'today') {
        // Orders placed today (by created_at), any active status
        $where_clause .= " AND o.created_at IS NOT NULL AND DATE(o.created_at) = CURDATE()";
    } elseif ($current_tab == 'completed') {
        $where_clause .= " AND o.order_status = 'completed'";
    } elseif ($current_tab == 'cancelled') {
        $where_clause .= " AND o.order_status IN ('cancelled', 'rejected', 'declined')";
    } else {
        // Pending / Upcoming
        $where_clause .= " AND o.order_status NOT IN ('completed', 'cancelled', 'rejected', 'declined')";
    }
}

$where_clause .= getReservationsActionFilterSql($action_filter);

// Count new bookings placed today (for tab badge)
$today_bookings_count = 0;
$today_count_q = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS cnt
     FROM bookings o
     WHERE o.store_id = '$user_id'
       AND o.is_archived_by_store = 0
       AND o.created_at IS NOT NULL
       AND DATE(o.created_at) = CURDATE()"
);
if ($today_count_q && ($today_count_row = mysqli_fetch_assoc($today_count_q))) {
    $today_bookings_count = (int) ($today_count_row['cnt'] ?? 0);
}

$order_by_sql = ($current_tab === 'today')
    ? "ORDER BY o.created_at DESC"
    : "ORDER BY o.order_date ASC";

// Get all reservations
$reservations_query = "
    SELECT o.*, u.first_name, u.last_name, u.phone_number, p.name as product_name, p.category,
           o.payment_status, o.delivery_type
    FROM bookings o 
    JOIN users u ON o.customer_id = u.id 
    LEFT JOIN products p ON o.product_id = p.id 
    $where_clause
    $order_by_sql
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
        .reservations-table-wrap .table td:nth-child(2)::before { content: 'Customer'; }
        .reservations-table-wrap .table td:nth-child(3)::before { content: 'Date & Time'; }
        .reservations-table-wrap .table td:nth-child(4)::before { content: 'Amount'; }
        .reservations-table-wrap .table td:nth-child(5)::before { content: 'Status'; }
        .reservations-table-wrap .table td:nth-child(6)::before { content: 'Details'; }
        .reservations-table-wrap .table td:nth-child(7)::before { content: 'Actions'; }

        .reservations-table-wrap .table td:last-child > div,
        .reservations-table-wrap .table td:last-child form,
        .reservations-table-wrap .table td:last-child .btn,
        .reservations-table-wrap .table td:last-child .status-select,
        .reservations-table-wrap .table td:nth-child(6) .btn,
        .reservations-table-wrap .table td:nth-child(6) .details-link {
            width: 100% !important;
        }

        .reservations-table-wrap .table td:last-child > div,
        .actions-desktop {
            flex-direction: column;
            align-items: stretch !important;
        }

        .reservations-table-wrap .table td:last-child .btn,
        .reservations-table-wrap .table td:nth-child(6) .btn,
        .reservations-table-wrap .table td:nth-child(6) .details-link {
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

<?php
    $tab_query_suffix = '';
    if (!empty($action_filter) && $action_filter !== 'all') {
        $tab_query_suffix = '&action=' . urlencode($action_filter);
    }
?>

<div class="reservations-page-header">
    <h1>Booking Management</h1>
    <p>Manage customer orders and catering bookings</p>
</div>

<?php if ($today_bookings_count > 0 && $current_tab !== 'today'): ?>
    <div class="today-alert-banner">
        <div class="today-alert-left">
            <i class="fas fa-bell"></i>
            <div>
                <strong><?php echo (int) $today_bookings_count; ?> new booking<?php echo $today_bookings_count > 1 ? 's' : ''; ?> today</strong>
                <span>Review who booked today so nothing gets missed.</span>
            </div>
        </div>
        <a href="?tab=today<?php echo $tab_query_suffix; ?>" class="today-alert-btn">View New Today</a>
    </div>
<?php endif; ?>

<?php if ($current_tab === 'today'): ?>
    <div class="today-section-note">
        <i class="fas fa-calendar-day"></i>
        Showing bookings placed <strong>today</strong>
        <?php if ($today_bookings_count > 0): ?>
            <span class="today-section-count"><?php echo (int) $today_bookings_count; ?></span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs-container reservations-tabs">
    <a href="?tab=today<?php echo $tab_query_suffix; ?>" class="tab-link <?php echo $current_tab == 'today' ? 'active' : ''; ?>">
        <i class="fas fa-calendar-day"></i>
        <span>New Today</span>
        <?php if ($today_bookings_count > 0): ?>
            <span class="today-count-badge"><?php echo (int) $today_bookings_count; ?></span>
        <?php endif; ?>
    </a>
    <a href="?tab=pending<?php echo $tab_query_suffix; ?>" class="tab-link <?php echo $current_tab == 'pending' ? 'active' : ''; ?>">
        <i class="fas fa-clock"></i>
        <span>Upcoming / Pending</span>
    </a>
    <a href="?tab=completed<?php echo $tab_query_suffix; ?>" class="tab-link <?php echo $current_tab == 'completed' ? 'active' : ''; ?>">
        <i class="fas fa-check-circle"></i>
        <span>Completed</span>
    </a>
    <a href="?tab=cancelled<?php echo $tab_query_suffix; ?>" class="tab-link <?php echo $current_tab == 'cancelled' ? 'active' : ''; ?>">
        <i class="fas fa-times-circle"></i>
        <span>Cancelled</span>
    </a>
    <a href="?tab=trash<?php echo $tab_query_suffix; ?>" class="tab-link <?php echo $current_tab == 'trash' ? 'active' : ''; ?>">
        <i class="fas fa-trash"></i>
        <span>Trash</span>
    </a>
</div>

<style>
    .reservations-tabs .tab-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 2px;
        color: var(--text-muted);
        text-decoration: none;
        border-bottom: 2px solid transparent;
        transition: all 0.2s;
        white-space: nowrap;
        font-size: 14px;
    }
    .reservations-tabs .tab-link.active {
        color: var(--accent-gold) !important;
        border-bottom-color: var(--accent-gold) !important;
        font-weight: 700;
    }
    .reservations-tabs .tab-link:hover {
        color: var(--text-main) !important;
    }
    .today-count-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 18px;
        height: 18px;
        padding: 0 6px;
        border-radius: 999px;
        background: #2ecc71;
        color: #0b1a12;
        font-size: 11px;
        font-weight: 700;
        line-height: 1;
    }
    .today-alert-banner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 16px;
        padding: 12px 14px;
        border-radius: 10px;
        border: 1px solid rgba(46, 204, 113, 0.35);
        background: rgba(46, 204, 113, 0.08);
    }
    .today-alert-left {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        color: var(--text-main);
    }
    .today-alert-left i {
        color: #2ecc71;
        margin-top: 3px;
    }
    .today-alert-left strong {
        display: block;
        margin-bottom: 2px;
    }
    .today-alert-left span {
        color: var(--text-muted);
        font-size: 13px;
    }
    .today-alert-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 12px;
        border-radius: 8px;
        background: #2ecc71;
        color: #0b1a12;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }
    .today-section-note {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 14px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(46, 204, 113, 0.1);
        border: 1px solid rgba(46, 204, 113, 0.25);
        color: var(--text-main);
        font-size: 13px;
    }
    .today-section-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        border-radius: 999px;
        background: #2ecc71;
        color: #0b1a12;
        font-size: 11px;
        font-weight: 700;
    }
    .new-today-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-top: 4px;
        padding: 2px 8px;
        border-radius: 999px;
        background: rgba(46, 204, 113, 0.15);
        color: #2ecc71;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.2px;
    }
    @media (max-width: 640px) {
        .today-alert-banner {
            flex-direction: column;
            align-items: stretch;
        }
        .today-alert-btn {
            width: 100%;
        }
    }
</style>

<?php if (isset($success_message)): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
    </div>
<?php endif; ?>


<div class="card">
    <div class="reservations-table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Booking #</th>
                    <th>Customer</th>
                    <th>Date & Time</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Details</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="reservations-body">
                <?php if (!$reservations_result || mysqli_num_rows($reservations_result) === 0): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding: 40px; color: var(--text-muted);">
                            <i class="fas fa-inbox" style="font-size: 32px; margin-bottom: 10px; opacity: 0.5;"></i><br>
                            <?php echo $current_tab === 'today' ? 'No new bookings placed today.' : ('No ' . htmlspecialchars($current_tab) . ' reservations found.'); ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php while ($reservations_result && $reservation = mysqli_fetch_assoc($reservations_result)): ?>
                    <?php 
                        // Get Financials (Real-time Balance)
                        $fin = getOrderFinancials($reservation['id']);
                        $balance = $fin['totals']['balance'];
                        $total = $fin['totals']['grand_total'];
                        $paid = $fin['totals']['total_paid'];
                        $downPaymentRequired = (float) ($fin['down_payment_required'] ?? 0);
                        $action_flags = getReservationActionFlags($conn, $reservation, $fin);
                        $action_items = [];
                        if ($action_flags['payment_review']) $action_items[] = 'Payment Proof Pending';
                        if ($action_flags['reschedule_review']) $action_items[] = 'Reschedule Pending';
                        if ($action_flags['menu_review']) $action_items[] = 'Menu Change Pending';
                        $hasPendingActions = !empty($action_items);
                        
                        // Parse Additional Menu Data for this row
                        $raw_menu = $reservation['additional_menu'] ?? '';
                        $parts = explode(' | ', $raw_menu);
                        
                        $setName = '';
                        $swaps = [];
                        $addons = [];
                        $customRequest = '';
                        
                        // Heuristic Parsing
                        if (!empty($parts)) {
                            $setName = trim($parts[0]);
                            foreach ($parts as $i => $part) {
                                if ($i === 0) continue; // Skip set name
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
                        
                        // Lookup Original Set Items
                        $originalItems = [];
                        if ($setName && isset($sets_lookup[$setName])) {
                            $setDesc = $sets_lookup[$setName]['description'];
                            $originalItems = bookingPricingParseSetDescription($setDesc)['items'];
                            $originalItems = array_map(function($item) {
                                return trim(preg_replace('/^[\d\.\-\�]+/', '', $item)); // Remove bullets/numbers
                            }, $originalItems);
                            $originalItems = array_filter($originalItems); // Remove empty
                        }

                        // Prepare Data Object for JS
                        $detailsData = [
                            'order_number' => $reservation['order_number'],
                            'customer_name' => $reservation['first_name'] . ' ' . $reservation['last_name'],
                            'set_name' => !empty($reservation['package_name']) ? $reservation['package_name'] : $setName,
                            'package_price' => (float) ($reservation['package_price'] ?? 0),
                            'original_items' => array_values($originalItems),
                            'swaps' => $swaps,
                            'addons' => $addons,
                            'custom_request' => $customRequest,
                            'total_amount' => $reservation['total_amount'],
                            'pax' => $reservation['number_of_pax'],
                            'delivery_address' => $reservation['address'],
                            'contact' => $reservation['phone_number'],
                            'created_at' => $reservation['created_at'] ?? '',
                            'down_payment_required' => $downPaymentRequired,
                            'raw_menu' => $raw_menu // Fallback
                        ];
                        
                        $hasCustomizations = !empty($swaps) || !empty($addons) || !empty($customRequest);
                        $btnStyle = $hasCustomizations ? "background: #e67e22; color: #fff;" : "background: var(--info-color); color: #fff;";
                        $btnIcon = $hasCustomizations ? "fa-sliders-h" : "fa-list";
                        
                        // Amount Display Logic
                        $amountDisplay = '<div style="font-weight: bold;">?' . number_format($total, 2) . '</div>';
                        $amountDisplay .= '<div style="color: var(--text-muted); font-size: 11px;">DP: &#8369;' . number_format($downPaymentRequired, 2) . '</div>';
                        if ($balance > 0) {
                            $amountDisplay .= '<div style="color: var(--error-color); font-size: 11px;">Bal: ?' . number_format($balance, 2) . '</div>';
                        } else {
                            $amountDisplay .= '<div style="color: var(--success-color); font-size: 11px;">Fully Paid</div>';
                        }

                        // 5 Days Before Logic
                        $eventDate = strtotime($reservation['order_date']);
                        $now = time();
                        $diff = $eventDate - $now;
                        $days = floor($diff / (60 * 60 * 24));
                        $isUpcomingSoon = ($days >= 0 && $days <= 5);
                        $isBookedToday = !empty($reservation['created_at']) && date('Y-m-d', strtotime($reservation['created_at'])) === date('Y-m-d');
                        
                        $rowStyle = '';
                        $dateStyle = '';
                        if ($isBookedToday || $current_tab === 'today') {
                            $rowStyle = 'background: rgba(46, 204, 113, 0.07); border-left: 3px solid #2ecc71;';
                        } elseif ($isUpcomingSoon && $current_tab == 'pending') {
                            $rowStyle = 'background: rgba(243, 156, 18, 0.05); border-left: 3px solid #f39c12;';
                            $dateStyle = 'color: #f39c12; font-weight: bold;';
                        }
                    ?>
                    <tr style="<?php echo $rowStyle; ?>">
                        <td>
                            <strong><?php echo $reservation['order_number']; ?></strong>
                            <?php if ($isBookedToday): ?>
                                <div class="new-today-chip"><i class="fas fa-star"></i> NEW</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']); ?></div>
                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars($reservation['phone_number']); ?></small>
                        </td>
                        <td>
                            <div style="<?php echo $dateStyle; ?>"><?php echo date('M d, Y', strtotime($reservation['order_date'])); ?></div>
                            <small style="color: var(--text-muted);"><?php echo date('h:i A', strtotime($reservation['order_time'])); ?></small>
                            <?php if (!empty($reservation['created_at'])): ?>
                                <div style="font-size: 10px; color: var(--text-muted); margin-top: 2px;">Booked: <?php echo date('M d, Y h:i A', strtotime($reservation['created_at'])); ?></div>
                            <?php endif; ?>
                            <?php if ($isUpcomingSoon && $current_tab == 'pending' && !$isBookedToday): ?>
                                <div style="font-size: 10px; color: #f39c12; margin-top: 2px;"><i class="fas fa-exclamation-circle"></i> Upcoming</div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $amountDisplay; ?></td>
                        <td>
                            <?php 
                                $normalizedReservationStatus = strtolower(trim((string) ($reservation['order_status'] ?? 'pending')));
                                if ($normalizedReservationStatus === 'approved') $normalizedReservationStatus = 'confirmed';
                                if ($normalizedReservationStatus === 'pending_payment') {
                                    $normalizedReservationStatus = 'pending';
                                } elseif ($normalizedReservationStatus === 'awaiting_store_confirmation') {
                                    $normalizedReservationStatus = 'confirmed';
                                }
                                $statusDisplay = ucfirst($normalizedReservationStatus ?: 'pending');
                                if ($statusDisplay === 'Approved') $statusDisplay = 'Confirmed';
                                $statusClass = 'status-pending';
                                if (in_array($normalizedReservationStatus, ['approved', 'confirmed'], true)) $statusClass = 'status-confirmed';
                            ?>
                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusDisplay; ?></span>
                            <?php if ($hasPendingActions): ?>
                                <div class="action-needed-list">
                                    <?php foreach ($action_items as $action_item): ?>
                                        <span class="action-needed-chip"><i class="fas fa-bell"></i> <?php echo htmlspecialchars($action_item); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                    <form action="order-details.php" method="get" style="display: inline;">
                        <input type="hidden" name="id" value="<?php echo intval($reservation['id']); ?>">
                        <button type="submit" class="btn details-link"
                           style="<?php echo $btnStyle; ?>; cursor: pointer;">
                            <i class="fas <?php echo $btnIcon; ?>"></i> <?php echo $hasCustomizations ? 'Custom Order' : 'View Details'; ?>
                        </button>
                    </form>
                </td>
                        <td>
                            <?php if ($current_tab == 'trash'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this order?');">
                                    <input type="hidden" name="order_id" value="<?php echo $reservation['id']; ?>">
                                    <button type="submit" name="restore_order" class="btn" style="background: rgba(46, 204, 113, 0.2); color: #2ecc71; border: 1px solid #2ecc71; padding: 5px 10px; font-size: 12px; cursor: pointer;">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="actions-desktop">
                                    <?php
                                        $completedDisabled = ($balance > 0.009);
                                        $completedLabel = $completedDisabled ? 'Completed (Pay balance first)' : 'Completed';
                                    ?>
                                    <select class="status-select" 
                                            style="width: auto; padding-right: 20px;"
                                            onfocus="isInteracting=true"
                                            onblur="isInteracting=false"
                                            onchange="updateStatus(<?php echo $reservation['id']; ?>, this.value, this)">
                                        <option value="pending" <?php echo strtolower($reservation['order_status']) === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <?php if (strtolower($reservation['order_status']) === 'awaiting_store_confirmation'): ?>
                                            <option value="confirmed" selected disabled>Confirmed</option>
                                        <?php endif; ?>
                                        <option value="confirmed" <?php echo strtolower($reservation['order_status']) === 'confirmed' ? 'selected' : ''; ?> style="color: #2ecc71;">Accept Booking</option>
                                        <option value="preparing" <?php echo strtolower($reservation['order_status']) === 'preparing' ? 'selected' : ''; ?> style="color: #3498db;">Preparing</option>
                                        <option value="completed" <?php echo strtolower($reservation['order_status']) === 'completed' ? 'selected' : ''; ?> style="color: #95a5a6;" <?php echo $completedDisabled ? 'disabled title="Pay remaining balance first before marking as Completed"' : ''; ?>><?php echo $completedLabel; ?></option>
                                        <option value="cancelled" <?php echo strtolower($reservation['order_status']) === 'cancelled' ? 'selected' : ''; ?> style="color: #e74c3c;">Cancelled</option>
                                    </select>
                                    
                                    <a href="event_preparation.php?order_id=<?php echo $reservation['id']; ?>" class="btn" title="Event Preparation" style="background: transparent; color: #3498db; border: 1px solid var(--border-color); padding: 5px 8px; font-size: 12px; cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-tasks"></i>
                                    </a>

                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Move to trash?');">
                                        <input type="hidden" name="order_id" value="<?php echo $reservation['id']; ?>">
                                        <input type="hidden" name="current_tab" value="<?php echo $current_tab; ?>">
                                        <button type="submit" name="archive_order" class="btn" title="Move to Trash" style="background: transparent; color: var(--error-color); border: 1px solid var(--border-color); padding: 5px 8px; font-size: 12px; cursor: pointer;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                                <div class="mobile-status-grid">
                                    <?php
                                        $status_options = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'preparing' => 'Preparing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
                                        $current_status_value = strtolower($reservation['order_status'] ?: 'pending');
                                        $completedBlocked = ($balance > 0.009);
                                    ?>
                                    <?php if ($current_status_value === 'awaiting_store_confirmation'): ?>
                                        <div style="grid-column: 1 / -1; font-size: 12px; color: var(--accent-gold); margin-bottom: 6px;">
                                            Payment received. Tap Confirm to accept this booking.
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($completedBlocked): ?>
                                        <div style="grid-column: 1 / -1; font-size: 11px; color: var(--error-color); margin-bottom: 6px;">
                                            <i class="fas fa-lock"></i> Pay remaining balance (?<?php echo number_format($balance, 2); ?>) before marking Completed.
                                        </div>
                                    <?php endif; ?>
                                    <?php foreach ($status_options as $status_value => $status_label): ?>
                                        <?php
                                            $isCompletedBlocked = ($status_value === 'completed' && $completedBlocked);
                                            $btnLabel = $isCompletedBlocked ? 'Completed (Pay first)' : $status_label;
                                        ?>
                                        <button
                                            type="button"
                                            class="mobile-status-btn <?php echo $current_status_value === $status_value ? 'is-active' : ''; ?>"
                                            <?php if ($isCompletedBlocked): ?>
                                                disabled
                                                style="opacity: 0.45; cursor: not-allowed;"
                                                title="Pay remaining balance first"
                                            <?php else: ?>
                                                onclick="updateStatus(<?php echo $reservation['id']; ?>, '<?php echo $status_value; ?>')"
                                            <?php endif; ?>
                                        >
                                            <?php echo $btnLabel; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mobile-inline-actions">
                                    <a href="event_preparation.php?order_id=<?php echo $reservation['id']; ?>" class="btn" title="Event Preparation" style="background: transparent; color: #3498db; border: 1px solid var(--border-color); padding: 10px 12px; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                                        <i class="fas fa-tasks"></i> Prepare
                                    </a>
                                    <form method="POST" onsubmit="return confirm('Move to trash?');">
                                        <input type="hidden" name="order_id" value="<?php echo $reservation['id']; ?>">
                                        <input type="hidden" name="current_tab" value="<?php echo $current_tab; ?>">
                                        <button type="submit" name="archive_order" class="btn" title="Move to Trash" style="background: transparent; color: var(--error-color); border: 1px solid var(--border-color); padding: 10px 12px; font-size: 12px; cursor: pointer; width: 100%; justify-content: center;">
                                            <i class="fas fa-trash"></i> Trash
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
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
    function updateStatus(orderId, status, selectEl) {
        // Immediately set isInteracting to false so the refresh isn't blocked
        isInteracting = false;

        // Client-side guard: do not allow Completed if option is disabled (unpaid balance)
        if (String(status).toLowerCase() === 'completed' && selectEl) {
            const completedOpt = Array.from(selectEl.options || []).find(function(opt) {
                return String(opt.value).toLowerCase() === 'completed';
            });
            if (completedOpt && completedOpt.disabled) {
                if (typeof showToast === 'function') {
                    showToast('Payment Required', 'Cannot mark as Completed. Customer still has a remaining balance. Collect full payment first.', 'error');
                } else {
                    alert('Cannot mark as Completed. Customer still has a remaining balance. Collect full payment first.');
                }
                // Revert select to previous value via refresh
                refreshReservations(true);
                return;
            }
        }
        
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
                    showToast('Error', data.error || 'Failed to update status', 'error');
                } else {
                    alert(data.error || 'Failed to update status');
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

    // The table is refreshed in the background. Handle detail links at the
    // document level so they still work after a refresh replaces table rows.
    document.addEventListener('click', function(event) {
        const detailsLink = event.target.closest('a.details-link');
        if (!detailsLink) {
            return;
        }

        event.preventDefault();
        isInteracting = true;
        window.location.assign(detailsLink.href);
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

    // Real-time Updates
    function refreshReservations(force = false) {
        // Skip update if user is interacting with a dropdown, unless forced
        if (isInteracting && !force) {
            console.log('Skipping refresh due to user interaction');
            return;
        }

        const urlParams = new URLSearchParams(window.location.search);
        const currentTab = urlParams.get('tab') || 'pending';
        const currentAction = urlParams.get('action') || 'all';

        // Add timestamp to prevent caching
        fetch('reservations.php?fetch_reservations=1&tab=' + currentTab + '&action=' + encodeURIComponent(currentAction) + '&_=' + new Date().getTime())
            .then(response => response.text())
            .then(html => {
                const tbody = document.getElementById('reservations-body');
                if (tbody) {
                    tbody.innerHTML = html;
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
            .catch(err => console.error('Error refreshing reservations:', err));
    }

    // Do not replace table rows on a timer. Replacing the row while a user is
    // clicking a link can cancel navigation; status changes already refresh it.
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
