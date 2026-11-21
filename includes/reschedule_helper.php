<?php

require_once __DIR__ . '/notification_helper.php';

function submitRescheduleRequest($conn, $params) {
    $order_id = intval($params['order_id'] ?? 0);
    $customer_id = intval($params['customer_id'] ?? 0);
    $store_id = intval($params['store_id'] ?? 0);
    $order_number = trim((string) ($params['order_number'] ?? ''));
    $current_date = trim((string) ($params['current_date'] ?? ''));
    $current_time = trim((string) ($params['current_time'] ?? ''));
    $requested_date = trim((string) ($params['requested_date'] ?? ''));
    $requested_time = trim((string) ($params['requested_time'] ?? ''));
    $reason = trim((string) ($params['reason'] ?? ''));

    if ($order_id <= 0 || $customer_id <= 0 || $store_id <= 0 || $requested_date === '' || $requested_time === '' || $reason === '') {
        return ['success' => false, 'error' => 'missing_data'];
    }

    mysqli_begin_transaction($conn);

    try {
        $stmt = $conn->prepare("
            UPDATE bookings
            SET reschedule_status = 'pending',
                requested_order_date = ?,
                requested_order_time = ?,
                reschedule_reason = ?,
                reschedule_response = NULL,
                reschedule_requested_at = NOW(),
                reschedule_resolved_at = NULL
            WHERE id = ? AND customer_id = ?
              AND reschedule_requested_at IS NULL
              AND (reschedule_status IS NULL OR reschedule_status = '' OR reschedule_status = 'none')
        ");
        $stmt->bind_param("sssii", $requested_date, $requested_time, $reason, $order_id, $customer_id);
        if (!$stmt->execute()) {
            throw new Exception('order_update_failed: ' . $stmt->error);
        }
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            mysqli_rollback($conn);
            return ['success' => false, 'error' => 'reschedule_already_used'];
        }
        $stmt->close();

        $log_details = "Customer requested to reschedule the event.\n";
        $log_details .= "Current Schedule: " . $current_date . ' ' . $current_time . "\n";
        $log_details .= "Requested Schedule: " . $requested_date . ' ' . $requested_time . "\n";
        $log_details .= "Reason: " . $reason;

        $stmt = $conn->prepare("INSERT INTO booking_logs (order_id, user_id, user_role, action, details, created_at) VALUES (?, ?, 'customer', 'Reschedule Requested', ?, NOW())");
        $stmt->bind_param("iis", $order_id, $customer_id, $log_details);
        if (!$stmt->execute()) {
            throw new Exception('order_log_failed: ' . $stmt->error);
        }
        $stmt->close();

        $subject = 'Reschedule Request for Booking #' . $order_number;
        $message = "The customer requested to move the event schedule.\n";
        $message .= "Current Schedule: " . $current_date . ' ' . $current_time . "\n";
        $message .= "Requested Schedule: " . $requested_date . ' ' . $requested_time . "\n";
        $message .= "Reason: " . $reason;

        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, order_id, subject, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiss", $customer_id, $store_id, $order_id, $subject, $message);
        if (!$stmt->execute()) {
            throw new Exception('message_insert_failed: ' . $stmt->error);
        }
        $stmt->close();

        mysqli_commit($conn);

        if (function_exists('createNotification')) {
            createNotification(
                $store_id,
                'reschedule_request',
                'Reschedule Request',
                'Customer requested to reschedule booking #' . $order_number . '.',
                'order-details.php?id=' . $order_id
            );
        }

        if (function_exists('notifyPortalAdmins')) {
            notifyPortalAdmins(
                'reschedule_request',
                'Reschedule Request Submitted',
                'A customer requested to reschedule booking #' . $order_number . '.',
                'reports.php'
            );
        }

        return ['success' => true];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
    }
}

function approveRescheduleRequest($conn, $params) {
    $order_id = intval($params['order_id'] ?? 0);
    $store_user_id = intval($params['store_user_id'] ?? 0);
    $customer_id = intval($params['customer_id'] ?? 0);
    $order_number = trim((string) ($params['order_number'] ?? ''));
    $old_date = trim((string) ($params['old_date'] ?? ''));
    $old_time = trim((string) ($params['old_time'] ?? ''));
    $new_date = trim((string) ($params['new_date'] ?? ''));
    $new_time = trim((string) ($params['new_time'] ?? ''));

    if ($order_id <= 0 || $store_user_id <= 0 || $customer_id <= 0 || $new_date === '' || $new_time === '') {
        return ['success' => false, 'error' => 'missing_data'];
    }

    mysqli_begin_transaction($conn);

    try {
        $stmt = $conn->prepare("
            UPDATE bookings
            SET order_date = ?,
                order_time = ?,
                requested_order_date = NULL,
                requested_order_time = NULL,
                reschedule_status = 'approved',
                reschedule_response = 'Approved by caterer',
                reschedule_resolved_at = NOW()
            WHERE id = ? AND store_id = ?
        ");
        $stmt->bind_param("ssii", $new_date, $new_time, $order_id, $store_user_id);
        if (!$stmt->execute()) {
            throw new Exception('order_update_failed: ' . $stmt->error);
        }
        $stmt->close();

        $log_details = "Caterer approved the reschedule request.\n";
        $log_details .= "Previous Schedule: " . $old_date . ' ' . $old_time . "\n";
        $log_details .= "Approved Schedule: " . $new_date . ' ' . $new_time;

        $stmt = $conn->prepare("INSERT INTO booking_logs (order_id, user_id, user_role, action, details, created_at) VALUES (?, ?, 'caterer', 'Reschedule Approved', ?, NOW())");
        $stmt->bind_param("iis", $order_id, $store_user_id, $log_details);
        if (!$stmt->execute()) {
            throw new Exception('order_log_failed: ' . $stmt->error);
        }
        $stmt->close();

        $subject = 'Reschedule Approved for Booking #' . $order_number;
        $message = "Your reschedule request has been approved by the caterer.\n";
        $message .= "Previous Schedule: " . $old_date . ' ' . $old_time . "\n";
        $message .= "New Schedule: " . $new_date . ' ' . $new_time;

        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, order_id, subject, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiss", $store_user_id, $customer_id, $order_id, $subject, $message);
        if (!$stmt->execute()) {
            throw new Exception('message_insert_failed: ' . $stmt->error);
        }
        $stmt->close();

        mysqli_commit($conn);

        if (function_exists('createNotification')) {
            createNotification(
                $customer_id,
                'reschedule_approved',
                'Reschedule Approved',
                'Your reschedule request for booking #' . $order_number . ' was approved.',
                'booking-details.php?id=' . $order_id
            );
        }

        if (function_exists('notifyPortalAdmins')) {
            notifyPortalAdmins(
                'reschedule_approved',
                'Reschedule Request Approved',
                'The caterer approved the reschedule request for booking #' . $order_number . '.',
                'reports.php'
            );
        }

        return ['success' => true];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
    }
}

function rejectRescheduleRequest($conn, $params) {
    $order_id = intval($params['order_id'] ?? 0);
    $store_user_id = intval($params['store_user_id'] ?? 0);
    $customer_id = intval($params['customer_id'] ?? 0);
    $order_number = trim((string) ($params['order_number'] ?? ''));
    $requested_date = trim((string) ($params['requested_date'] ?? ''));
    $requested_time = trim((string) ($params['requested_time'] ?? ''));
    $reject_reason = trim((string) ($params['reject_reason'] ?? ''));

    if ($order_id <= 0 || $store_user_id <= 0 || $customer_id <= 0 || $requested_date === '' || $requested_time === '' || $reject_reason === '') {
        return ['success' => false, 'error' => 'missing_data'];
    }

    mysqli_begin_transaction($conn);

    try {
        $stmt = $conn->prepare("
            UPDATE bookings
            SET reschedule_status = 'rejected',
                reschedule_response = ?,
                reschedule_resolved_at = NOW()
            WHERE id = ? AND store_id = ?
        ");
        $stmt->bind_param("sii", $reject_reason, $order_id, $store_user_id);
        if (!$stmt->execute()) {
            throw new Exception('order_update_failed: ' . $stmt->error);
        }
        $stmt->close();

        $log_details = "Caterer rejected the reschedule request for " . $requested_date . ' ' . $requested_time . ".\n";
        $log_details .= "Reason: " . $reject_reason;

        $stmt = $conn->prepare("INSERT INTO booking_logs (order_id, user_id, user_role, action, details, created_at) VALUES (?, ?, 'caterer', 'Reschedule Rejected', ?, NOW())");
        $stmt->bind_param("iis", $order_id, $store_user_id, $log_details);
        if (!$stmt->execute()) {
            throw new Exception('order_log_failed: ' . $stmt->error);
        }
        $stmt->close();

        $subject = 'Reschedule Rejected for Booking #' . $order_number;
        $message = "Your reschedule request was not approved by the caterer.\n";
        $message .= "Requested Schedule: " . $requested_date . ' ' . $requested_time . "\n";
        $message .= "Reason: " . $reject_reason;

        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, order_id, subject, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiss", $store_user_id, $customer_id, $order_id, $subject, $message);
        if (!$stmt->execute()) {
            throw new Exception('message_insert_failed: ' . $stmt->error);
        }
        $stmt->close();

        mysqli_commit($conn);

        if (function_exists('createNotification')) {
            createNotification(
                $customer_id,
                'reschedule_rejected',
                'Reschedule Rejected',
                'Your reschedule request for booking #' . $order_number . ' was rejected.',
                'booking-details.php?id=' . $order_id
            );
        }

        if (function_exists('notifyPortalAdmins')) {
            notifyPortalAdmins(
                'reschedule_rejected',
                'Reschedule Request Rejected',
                'The caterer rejected the reschedule request for booking #' . $order_number . '.',
                'reports.php'
            );
        }

        return ['success' => true];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
    }
}
