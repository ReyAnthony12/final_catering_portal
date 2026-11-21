<?php

require_once __DIR__ . '/financial_helper.php';
require_once __DIR__ . '/booking_pricing_helper.php';
require_once __DIR__ . '/notification_helper.php';

function applyApprovedMenuChange($conn, $params) {
    $order_id = intval($params['order_id'] ?? 0);
    $store_user_id = intval($params['store_user_id'] ?? 0);
    $customer_id = intval($params['customer_id'] ?? 0);
    $product_id = intval($params['product_id'] ?? 0);
    $message_id = intval($params['message_id'] ?? 0);
    $order_number = trim((string) ($params['order_number'] ?? ''));
    $requested_set = trim((string) ($params['requested_set'] ?? ''));
    $current_set = trim((string) ($params['current_set'] ?? ''));
    $additional_menu = trim((string) ($params['additional_menu'] ?? ''));
    $addons_total = floatval($params['addons_total'] ?? 0);
    $extra_fee = floatval($params['extra_fee'] ?? 0);

    if ($order_id <= 0 || $store_user_id <= 0 || $customer_id <= 0 || $product_id <= 0 || $message_id <= 0 || $requested_set === '') {
        return ['success' => false, 'error' => 'missing_data'];
    }

    mysqli_begin_transaction($conn);

    try {
        $stmt = $conn->prepare("SELECT set_name FROM product_sets WHERE product_id = ? AND set_name = ? LIMIT 1");
        $stmt->bind_param("is", $product_id, $requested_set);
        if (!$stmt->execute()) {
            throw new Exception('set_lookup_failed: ' . $stmt->error);
        }
        $set_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$set_row) {
            mysqli_rollback($conn);
            return ['success' => false, 'error' => 'requested_set_missing'];
        }

        $menu_parts = preg_split('/\s*\|\s*/', $additional_menu, -1, PREG_SPLIT_NO_EMPTY);
        if (!empty($menu_parts)) {
            $first_part = trim($menu_parts[0]);
            if (
                stripos($first_part, 'Added Items:') !== 0 &&
                stripos($first_part, 'Menu Swaps:') !== 0 &&
                stripos($first_part, 'Custom Add-on Request:') !== 0
            ) {
                $menu_parts[0] = $requested_set;
            } else {
                array_unshift($menu_parts, $requested_set);
            }
        } else {
            $menu_parts = [$requested_set];
        }

        $updated_menu = implode(' | ', $menu_parts);
        $pricing = bookingPricingCalculateStoredOrder($conn, [
            'product_id' => $product_id,
            'number_of_pax' => intval($params['number_of_pax'] ?? ($params['quantity'] ?? 1)),
            'addons_total' => $addons_total,
            'extra_fee' => $extra_fee,
            'additional_menu' => $updated_menu
        ]);

        if (!$pricing) {
            throw new Exception('order_repricing_failed');
        }

        if (!bookingPricingUpdateOrderFields($conn, $order_id, $pricing, ['additional_menu' => $updated_menu])) {
            throw new Exception('order_update_failed: ' . mysqli_error($conn));
        }

        $new_base_price = bookingPricingRound($pricing['base_price'] ?? 0);
        $new_total_amount = bookingPricingRound($pricing['total_amount'] ?? 0);

        $log_details = 'Caterer approved menu change from ' . ($current_set !== '' ? $current_set : 'current set') . ' to ' . $requested_set;
        $stmt = $conn->prepare("INSERT INTO booking_logs (order_id, user_id, user_role, action, details, created_at) VALUES (?, ?, 'caterer', 'Menu Change Approved', ?, NOW())");
        $stmt->bind_param("iis", $order_id, $store_user_id, $log_details);
        if (!$stmt->execute()) {
            throw new Exception('order_log_failed: ' . $stmt->error);
        }
        $stmt->close();

        $reply_subject = 'Menu Change Approved for Booking #' . $order_number;
        $reply_message = "Your menu change request has been approved.\n";
        $reply_message .= "Previous Set: " . ($current_set !== '' ? $current_set : 'Not specified') . "\n";
        $reply_message .= "Approved Set: " . $requested_set . "\n";
        $reply_message .= "New Set Total: PHP " . number_format($new_base_price, 2) . "\n";
        $reply_message .= "Please review your updated balance in the payment page.";

        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, order_id, subject, message, original_message_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiissi", $store_user_id, $customer_id, $order_id, $reply_subject, $reply_message, $message_id);
        if (!$stmt->execute()) {
            throw new Exception('reply_insert_failed: ' . $stmt->error);
        }
        $stmt->close();

        mysqli_commit($conn);

        if (function_exists('createNotification')) {
            createNotification(
                $customer_id,
                'menu_change_approved',
                'Menu Change Approved',
                'Your request to change booking #' . $order_number . ' to ' . $requested_set . ' was approved.',
                'booking.php'
            );
        }

        if (function_exists('notifyPortalAdmins')) {
            notifyPortalAdmins(
                'menu_change_approved',
                'Menu Change Approved',
                'The caterer approved a menu change request for booking #' . $order_number . '.',
                'reports.php'
            );
        }

        if (function_exists('getOrderFinancials')) {
            getOrderFinancials($order_id);
        }

        return [
            'success' => true,
            'new_base_price' => $new_base_price,
            'new_total_amount' => $new_total_amount,
            'updated_menu' => $updated_menu
        ];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
    }
}

function applyRejectedMenuChange($conn, $params) {
    $order_id = intval($params['order_id'] ?? 0);
    $store_user_id = intval($params['store_user_id'] ?? 0);
    $customer_id = intval($params['customer_id'] ?? 0);
    $message_id = intval($params['message_id'] ?? 0);
    $order_number = trim((string) ($params['order_number'] ?? ''));
    $requested_set = trim((string) ($params['requested_set'] ?? ''));
    $current_set = trim((string) ($params['current_set'] ?? ''));
    $reject_reason = trim((string) ($params['reject_reason'] ?? ''));

    if ($order_id <= 0 || $store_user_id <= 0 || $customer_id <= 0 || $message_id <= 0 || $requested_set === '' || $reject_reason === '') {
        return ['success' => false, 'error' => 'missing_data'];
    }

    mysqli_begin_transaction($conn);

    try {
        $reject_subject = 'Menu Change Rejected for Booking #' . $order_number;
        $reject_message = "Your menu change request was not approved by the caterer at this time.\n";
        $reject_message .= "Current Set: " . ($current_set !== '' ? $current_set : 'Not specified') . "\n";
        $reject_message .= "Requested Set: " . $requested_set . "\n";
        $reject_message .= "Reason: " . $reject_reason;

        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, order_id, subject, message, original_message_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiissi", $store_user_id, $customer_id, $order_id, $reject_subject, $reject_message, $message_id);
        if (!$stmt->execute()) {
            throw new Exception('reject_reply_failed: ' . $stmt->error);
        }
        $stmt->close();

        $log_details = 'Caterer rejected menu change request for ' . $requested_set . '. Reason: ' . $reject_reason;
        $stmt = $conn->prepare("INSERT INTO booking_logs (order_id, user_id, user_role, action, details, created_at) VALUES (?, ?, 'caterer', 'Menu Change Rejected', ?, NOW())");
        $stmt->bind_param("iis", $order_id, $store_user_id, $log_details);
        if (!$stmt->execute()) {
            throw new Exception('reject_log_failed: ' . $stmt->error);
        }
        $stmt->close();

        mysqli_commit($conn);

        if (function_exists('createNotification')) {
            createNotification(
                $customer_id,
                'menu_change_rejected',
                'Menu Change Rejected',
                'Your request to change booking #' . $order_number . ' to ' . $requested_set . ' was rejected.',
                'booking.php'
            );
        }

        if (function_exists('notifyPortalAdmins')) {
            notifyPortalAdmins(
                'menu_change_rejected',
                'Menu Change Rejected',
                'The caterer rejected a menu change request for booking #' . $order_number . '.',
                'reports.php'
            );
        }

        return ['success' => true];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
    }
}
