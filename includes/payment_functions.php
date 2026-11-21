<?php
/**
 * Payment Functions for Customer Module
 * Handles installment payments, fee calculations, and order tracking
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/financial_helper.php';

/**
 * Get payment summary for an order
 * @param int $order_id
 * @return array Payment summary data
 */
function getOrderPaymentSummary($order_id) {
    global $conn;

    $order_id = intval($order_id);
    $financials = getOrderFinancials($order_id);
    if (!$financials) {
        return null;
    }

    $total_amount = floatval($financials['totals']['grand_total'] ?? 0);
    $down_payment_required = floatval($financials['down_payment_required'] ?? 0);
    $total_paid = floatval($financials['totals']['total_paid'] ?? 0);
    $remaining_balance = floatval($financials['totals']['balance'] ?? max(0, $total_amount - $total_paid));
    $payment_status = (string) ($financials['status'] ?? 'unpaid');
    $dp_paid = min($total_paid, $down_payment_required);
    $dp_remaining = max(0, $down_payment_required - $dp_paid);
    $progress_percentage = ($total_amount > 0) ? ($total_paid / $total_amount) * 100 : 0;

    return [
        'order_id' => $order_id,
        'total_amount' => $total_amount,
        'down_payment_required' => $down_payment_required,
        'down_payment_paid' => $dp_paid,
        'down_payment_remaining' => $dp_remaining,
        'total_paid' => $total_paid,
        'remaining_balance' => $remaining_balance,
        'payment_status' => $payment_status,
        'progress_percentage' => round($progress_percentage, 2),
        'dp_progress_percentage' => ($down_payment_required > 0) ? round(($dp_paid / $down_payment_required) * 100, 2) : 0
    ];
}

/**
 * Get payment history for an order
 * @param int $order_id
 * @return array Payment records
 */
function getOrderPaymentHistory($order_id) {
    global $conn;
    
    $order_id = mysqli_real_escape_string($conn, $order_id);
    
    $query = "SELECT p.*, u.first_name, u.last_name 
              FROM payments p 
              LEFT JOIN users u ON p.confirmed_by = u.id
              WHERE p.order_id = '$order_id' 
              ORDER BY p.created_at DESC";
    
    $result = mysqli_query($conn, $query);
    
    if (!$result) {
        // Log error or handle gracefully
        error_log("Payment history query failed: " . mysqli_error($conn));
        return [];
    }

    $payments = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $payments[] = $row;
    }
    
    return $payments;
}

/**
 * Add a new payment
 * @param array $payment_data
 * @return bool|int Payment ID on success, false on failure
 */
function addPayment($payment_data) {
    global $conn;
    
    $order_id = mysqli_real_escape_string($conn, $payment_data['order_id']);
    $customer_id = mysqli_real_escape_string($conn, $payment_data['customer_id']);
    $store_id = mysqli_real_escape_string($conn, $payment_data['store_id']);
    $amount = floatval($payment_data['amount']);
    $payment_type = mysqli_real_escape_string($conn, $payment_data['payment_type'] ?? 'installment');
    $payment_method = mysqli_real_escape_string($conn, $payment_data['payment_method'] ?? '');
    $receipt_image = mysqli_real_escape_string($conn, $payment_data['receipt_image'] ?? '');
    $notes = mysqli_real_escape_string($conn, $payment_data['notes'] ?? '');
    
    // Validate order exists and is not cancelled
    $order_check = mysqli_query($conn, "SELECT order_status FROM bookings WHERE id = '$order_id'");
    $order = mysqli_fetch_assoc($order_check);
    
    if (!$order) {
        return ['success' => false, 'error' => 'Order not found'];
    }
    
    if ($order['order_status'] === 'cancelled') {
        return ['success' => false, 'error' => 'Cannot add payment to cancelled order'];
    }
    
    // Check for overpayment
    $summary = getOrderPaymentSummary($order_id);
    if ($amount > $summary['remaining_balance']) {
        return ['success' => false, 'error' => 'Payment amount exceeds remaining balance'];
    }
    
    if ($amount <= 0) {
        return ['success' => false, 'error' => 'Invalid payment amount'];
    }
    
    // Insert payment
    $query = "INSERT INTO payments (order_id, customer_id, store_id, amount, payment_type, 
               payment_method, receipt_image, notes, status, created_at) 
               VALUES ('$order_id', '$customer_id', '$store_id', '$amount', '$payment_type', 
               '$payment_method', '$receipt_image', '$notes', 'pending', NOW())";
    
    if (mysqli_query($conn, $query)) {
        $payment_id = mysqli_insert_id($conn);
        
        // Log the payment
        logOrderActivity($order_id, $customer_id, 'customer', 'payment_submitted', 
            'Payment of ₱' . number_format($amount, 2) . ' submitted for approval');
        
        return ['success' => true, 'payment_id' => $payment_id];
    }
    
    return ['success' => false, 'error' => mysqli_error($conn)];
}

/**
 * Get detailed fee breakdown for an order
 * @param int $order_id
 * @return array Fee breakdown
 */
function getOrderFeeBreakdown($order_id) {
    $breakdown = [
        'base_package' => ['items' => [], 'total' => 0],
        'addon' => ['items' => [], 'total' => 0],
        'delivery_fee' => ['items' => [], 'total' => 0],
        'admin_fee' => ['items' => [], 'total' => 0],
        'discount' => ['items' => [], 'total' => 0],
        'damages' => ['items' => [], 'total' => 0],
        'grand_total' => 0
    ];
    
    // Use the same booking totals as the payment pages, without a separate fee table.
    $financials = getOrderFinancials(intval($order_id));
    if (!$financials) {
        return $breakdown;
    }

    $categories = [
        'base_package' => ['base_price', 'Base package'],
        'addon' => ['addons', 'Add-ons'],
        'admin_fee' => ['extra_fee', 'Extra fee'],
        'damages' => ['damages', 'Damages']
    ];
    foreach ($categories as $type => [$source, $description]) {
        $amount = bookingPricingRound($financials['breakdown'][$source]);
        $breakdown[$type]['total'] = $amount;
        if ($amount != 0) {
            $breakdown[$type]['items'][] = [
                'order_id' => intval($order_id),
                'fee_type' => $type,
                'description' => $description,
                'amount' => $amount,
                'quantity' => 1
            ];
        }
    }
    $breakdown['grand_total'] = $financials['totals']['grand_total'];
    
    return $breakdown;
}

/**
 * Log order activity
 * @param int $order_id
 * @param int $user_id
 * @param string $user_role
 * @param string $action
 * @param string $details
 * @param string $old_value (optional)
 * @param string $new_value (optional)
 * @return bool
 */
function logOrderActivity($order_id, $user_id, $user_role, $action, $details, $old_value = null, $new_value = null) {
    global $conn;
    
    $order_id = mysqli_real_escape_string($conn, $order_id);
    $user_id = mysqli_real_escape_string($conn, $user_id);
    $user_role = mysqli_real_escape_string($conn, $user_role);
    $action = mysqli_real_escape_string($conn, $action);
    $details = mysqli_real_escape_string($conn, $details);
    $old_value = $old_value ? mysqli_real_escape_string($conn, $old_value) : null;
    $new_value = $new_value ? mysqli_real_escape_string($conn, $new_value) : null;
    
    $query = "INSERT INTO booking_logs (order_id, user_id, user_role, action, details, old_value, new_value, created_at) 
              VALUES ('$order_id', '$user_id', '$user_role', '$action', '$details', 
              " . ($old_value ? "'$old_value'" : "NULL") . ", 
              " . ($new_value ? "'$new_value'" : "NULL") . ", NOW())";
    
    return mysqli_query($conn, $query);
}

/**
 * Get order activity log
 * @param int $order_id
 * @return array Activity log entries
 */
function getOrderActivityLog($order_id) {
    global $conn;
    
    $order_id = mysqli_real_escape_string($conn, $order_id);
    
    $query = "SELECT ol.*, u.first_name, u.last_name 
              FROM booking_logs ol 
              LEFT JOIN users u ON ol.user_id = u.id
              WHERE ol.order_id = '$order_id' 
              ORDER BY ol.created_at DESC";
    
    $result = mysqli_query($conn, $query);
    $logs = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $logs[] = $row;
    }
    
    return $logs;
}

/**
 * Get order timeline status
 * @param int $order_id
 * @return array Timeline data
 */
function getOrderTimeline($order_id) {
    global $conn;
    
    $order_id = mysqli_real_escape_string($conn, $order_id);
    
    // Use order_status as the source of truth
    $query = "SELECT order_status FROM bookings WHERE id = '$order_id'";
    $result = mysqli_query($conn, $query);
    $order = mysqli_fetch_assoc($result);
    
    if (!$order) {
        return null;
    }
    
    $timeline_steps = [
        'pending' => ['label' => 'Pending', 'icon' => 'fa-clock', 'order' => 1],
        'confirmed' => ['label' => 'Confirmed', 'icon' => 'fa-check', 'order' => 2],
        'preparing' => ['label' => 'Preparing', 'icon' => 'fa-utensils', 'order' => 3],
        'ready' => ['label' => 'Ready', 'icon' => 'fa-box', 'order' => 4],
        'completed' => ['label' => 'Completed', 'icon' => 'fa-check-circle', 'order' => 5]
    ];
    
    // Map DB status to timeline keys
    // Caterer statuses: pending, approved, preparing, completed, cancelled
    $db_status = strtolower($order['order_status']);
    $current_status_key = 'pending';
    
    switch ($db_status) {
        case 'pending':
            $current_status_key = 'pending';
            break;
        case 'approved':
            $current_status_key = 'confirmed';
            break;
        case 'preparing':
            $current_status_key = 'preparing';
            break;
        case 'delivering': // Handle potential delivery status
        case 'ready':
            $current_status_key = 'ready';
            break;
        case 'completed':
            $current_status_key = 'completed';
            break;
        case 'cancelled':
            $current_status_key = 'cancelled';
            break;
        default:
            $current_status_key = 'pending';
    }
    
    $current_order = $timeline_steps[$current_status_key]['order'] ?? 1;
    
    // If status is 'completed', ensure 'ready' is also marked passed if it was skipped
    if ($current_status_key === 'completed') {
        // current_order is 5. ready is 4. So ready will be marked 'completed' by the loop below.
    }
    
    $timeline = [];
    foreach ($timeline_steps as $key => $step) {
        $status = 'pending'; // Default inactive
        
        if ($db_status === 'cancelled') {
             $status = 'cancelled';
        } else {
            if ($step['order'] < $current_order) {
                $status = 'completed'; // Passed
            } elseif ($step['order'] == $current_order) {
                $status = 'current'; // Active
            }
        }
        
        $timeline[$key] = [
            'label' => $step['label'],
            'icon' => $step['icon'],
            'status' => $status,
            'order' => $step['order']
        ];
    }
    
    return [
        'current_status' => $current_status_key,
        'updated_at' => $order['updated_at'] ?? date('Y-m-d H:i:s'),
        'steps' => $timeline
    ];
}

/**
 * Get upcoming events for customer
 * @param int $customer_id
 * @param int $limit
 * @return array Upcoming events
 */
function getUpcomingEvents($customer_id, $limit = 5) {
    global $conn;
    
    $customer_id = mysqli_real_escape_string($conn, $customer_id);
    $limit = intval($limit);
    
    $query = "SELECT o.*, sd.business_name, p.name as product_name
              FROM bookings o 
              LEFT JOIN store_details sd ON o.store_id = sd.user_id
              LEFT JOIN products p ON o.product_id = p.id
              WHERE o.customer_id = '$customer_id' 
              AND o.order_date >= CURDATE()
              AND o.order_status != 'cancelled'
              ORDER BY o.order_date ASC
              LIMIT $limit";
    
    $result = mysqli_query($conn, $query);
    $events = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $payment_summary = getOrderPaymentSummary($row['id']);
        $row['payment_summary'] = $payment_summary;
        
        // Calculate days until event
        $event_date = new DateTime($row['order_date']);
        $today = new DateTime();
        $days_left = $today->diff($event_date)->days;
        $row['days_left'] = $days_left;
        $row['is_urgent'] = ($days_left <= 3 && $payment_summary['payment_status'] != 'paid');
        
        $events[] = $row;
    }
    
    return $events;
}

/**
 * Get customer payment statistics
 * @param int $customer_id
 * @return array Payment stats
 */
function getCustomerPaymentStats($customer_id) {
    global $conn;
    
    $customer_id = mysqli_real_escape_string($conn, $customer_id);
    
    // Total orders
    $orders_query = "SELECT COUNT(*) as total, 
                     SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid,
                     SUM(CASE WHEN payment_status = 'partial' THEN 1 ELSE 0 END) as partial,
                     SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid
                     FROM bookings WHERE customer_id = '$customer_id' AND order_status != 'cancelled'";
    $orders_result = mysqli_query($conn, $orders_query);
    $orders_stats = mysqli_fetch_assoc($orders_result);
    
    // Total spending
    $spending_query = "SELECT SUM(total_amount) as total_spent FROM bookings 
                       WHERE customer_id = '$customer_id' AND order_status != 'cancelled'";
    $spending_result = mysqli_query($conn, $spending_query);
    $spending = mysqli_fetch_assoc($spending_result);
    
    // Total paid from verified payments (downpayment + additional), not only fully-paid orders
    $paid_query = "
        SELECT COALESCE(SUM(paid_amount), 0) AS total_paid
        FROM (
            SELECT o.id,
                   GREATEST(
                       COALESCE((
                           SELECT SUM(p.amount)
                           FROM payments p
                           WHERE p.order_id = o.id
                             AND p.status IN ('verified', 'confirmed')
                       ), 0),
                       COALESCE(o.down_payment_paid, 0)
                   ) AS paid_amount
            FROM bookings o
            WHERE o.customer_id = '$customer_id'
              AND o.order_status != 'cancelled'
        ) paid_orders
    ";
    $paid_result = mysqli_query($conn, $paid_query);
    $paid = $paid_result ? mysqli_fetch_assoc($paid_result) : ['total_paid' => 0];
    
    $total_spent = floatval($spending['total_spent'] ?? 0);
    $total_paid = floatval($paid['total_paid'] ?? 0);
    if ($total_paid > $total_spent) {
        $total_paid = $total_spent;
    }
    
    return [
        'total_orders' => intval($orders_stats['total'] ?? 0),
        'paid_orders' => intval($orders_stats['paid'] ?? 0),
        'partial_orders' => intval($orders_stats['partial'] ?? 0),
        'unpaid_orders' => intval($orders_stats['unpaid'] ?? 0),
        'total_spent' => $total_spent,
        'total_paid' => $total_paid,
        'total_balance' => $total_spent - $total_paid
    ];
}

function getStoreBookingCapacity($store_id) {
    global $conn;

    $store_id = mysqli_real_escape_string($conn, $store_id);
    $result = mysqli_query($conn, "
        SELECT COALESCE(NULLIF(max_bookings_per_day, 0), 5) AS max_bookings_per_day, booking_gap_days
        FROM store_details
        WHERE user_id = '$store_id'
        LIMIT 1
    ");

    if ($result && ($row = mysqli_fetch_assoc($result))) {
        return max(1, intval($row['max_bookings_per_day'] ?? 5));
    }

    return 5;
}

function buildStoreAvailabilitySummary($date, $total_orders, $active_orders, $max_orders_per_day) {
    $max_orders_per_day = max(1, intval($max_orders_per_day));
    $active_orders = max(0, intval($active_orders));
    $slots_remaining = max(0, $max_orders_per_day - $active_orders);
    $is_available = $active_orders < $max_orders_per_day;
    $almost_full_threshold = max(1, $max_orders_per_day - 1);
    $is_almost_full = $is_available && $active_orders >= $almost_full_threshold;

    $status = 'available';
    if (!$is_available) {
        $status = 'fully_booked';
    } elseif ($is_almost_full) {
        $status = 'almost_full';
    }

    return [
        'date' => $date,
        'total_orders' => intval($total_orders),
        'active_orders' => $active_orders,
        'max_orders_per_day' => $max_orders_per_day,
        'is_available' => $is_available,
        'slots_remaining' => $slots_remaining,
        'is_almost_full' => $is_almost_full,
        'status' => $status
    ];
}

/**
 * Check if caterer is available for date
 * @param int $store_id
 * @param string $date
 * @return array Availability info
 */
function checkStoreAvailability($store_id, $date) {
    global $conn;

    $store_id = mysqli_real_escape_string($conn, $store_id);
    $date = mysqli_real_escape_string($conn, $date);

    // Check if the date is a holiday for this caterer
    $holiday_check = mysqli_query($conn, "SELECT id FROM store_holidays WHERE store_id = '$store_id' AND holiday_date = '$date' LIMIT 1");
    if ($holiday_check && mysqli_num_rows($holiday_check) > 0) {
        return [
            'date'              => $date,
            'total_orders'      => 0,
            'active_orders'     => 0,
            'max_orders_per_day'=> getStoreBookingCapacity($store_id),
            'is_available'      => false,
            'slots_remaining'   => 0,
            'is_almost_full'    => false,
            'status'            => 'holiday',
            'is_holiday'        => true,
        ];
    }

    $query = "SELECT COUNT(*) as order_count,
              SUM(CASE WHEN order_status != 'cancelled' THEN 1 ELSE 0 END) as active_count
              FROM bookings
              WHERE store_id = '$store_id' AND DATE(order_date) = '$date'";

    $result = mysqli_query($conn, $query);
    $data = $result ? mysqli_fetch_assoc($result) : [];

    return buildStoreAvailabilitySummary(
        $date,
        intval($data['order_count'] ?? 0),
        intval($data['active_count'] ?? 0),
        getStoreBookingCapacity($store_id)
    );
}

function getStoreAvailabilityRange($store_id, $start_date, $end_date) {
    global $conn;

    $store_id = mysqli_real_escape_string($conn, $store_id);
    $start_date = mysqli_real_escape_string($conn, $start_date);
    $end_date = mysqli_real_escape_string($conn, $end_date);
    $capacity = getStoreBookingCapacity($store_id);

    // Get Gap Days
    $gap_res = mysqli_query($conn, "SELECT COALESCE(booking_gap_days, 0) AS gap FROM store_details WHERE user_id = '$store_id' LIMIT 1");
    $gap_days = ($gap_res && $row = mysqli_fetch_assoc($gap_res)) ? intval($row['gap']) : 0;

    // Get Holidays
    $holidays = [];
    $hol_res = mysqli_query($conn, "SELECT holiday_date FROM store_holidays WHERE store_id = '$store_id'");
    if ($hol_res) {
        while ($hr = mysqli_fetch_assoc($hol_res)) {
            $holidays[] = $hr['holiday_date'];
        }
    }

    $availability = [];
    $query = mysqli_query($conn, "
        SELECT DATE(order_date) AS booking_date,
               COUNT(*) AS order_count,
               SUM(CASE WHEN order_status != 'cancelled' THEN 1 ELSE 0 END) AS active_count
        FROM bookings
        WHERE store_id = '$store_id'
          AND DATE(order_date) BETWEEN '$start_date' AND '$end_date'
        GROUP BY DATE(order_date)
    ");

    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $availability[$row['booking_date']] = buildStoreAvailabilitySummary(
                $row['booking_date'],
                intval($row['order_count'] ?? 0),
                intval($row['active_count'] ?? 0),
                $capacity
            );
        }
    }

    return [
        'max_bookings_per_day' => $capacity,
        'booking_gap_days' => $gap_days,
        'holidays' => $holidays,
        'dates' => $availability
    ];
}

/**
 * Get payment status badge HTML
 * @param string $status
 * @return string HTML badge
 */
function getPaymentStatusBadge($status) {
    $badges = [
        'paid' => '<span class="payment-badge paid"><i class="fas fa-check-circle"></i> Fully Paid</span>',
        'partial' => '<span class="payment-badge partial"><i class="fas fa-adjust"></i> Partial</span>',
        'unpaid' => '<span class="payment-badge unpaid"><i class="fas fa-exclamation-circle"></i> Unpaid</span>'
    ];
    
    return $badges[$status] ?? $badges['unpaid'];
}

/**
 * Format currency
 * @param float $amount
 * @return string Formatted amount
 */
function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}


