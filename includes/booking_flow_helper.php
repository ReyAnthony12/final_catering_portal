<?php

/** Event types are unique per customer and day; different types may share a time. */
function customerHasSameDayEvent(mysqli $conn, int $customerId, string $date, string $eventType): bool {
    $statement = mysqli_prepare($conn, "SELECT id FROM bookings WHERE customer_id = ? AND DATE(order_date) = ? AND LOWER(TRIM(event_type)) = LOWER(?) AND order_status != 'cancelled' LIMIT 1");
    if (!$statement) throw new RuntimeException('Unable to check existing bookings.');
    try {
        $eventType = trim($eventType);
        mysqli_stmt_bind_param($statement, 'iss', $customerId, $date, $eventType);
        if (!mysqli_stmt_execute($statement) || !mysqli_stmt_store_result($statement)) {
            throw new RuntimeException('Unable to check existing bookings.');
        }
        return mysqli_stmt_num_rows($statement) > 0;
    } finally {
        mysqli_stmt_close($statement);
    }
}

if (!function_exists('normalizeOrderWorkflowStatus')) {
    function normalizeOrderWorkflowStatus($status): string
    {
        $normalized = strtolower(trim((string) $status));

        if ($normalized === 'approved') {
            return 'confirmed';
        }

        if ($normalized === 'ready') {
            return 'delivering';
        }

        if (in_array($normalized, ['rejected', 'declined'], true)) {
            return 'cancelled';
        }

        return $normalized !== '' ? $normalized : 'pending';
    }
}

if (!function_exists('normalizeCustomerBookingStatus')) {
    function normalizeCustomerBookingStatus($status): string
    {
        return normalizeOrderWorkflowStatus($status);
    }
}

if (!function_exists('normalizePaymentChannel')) {
    function normalizePaymentChannel($paymentMethod, array $order = []): string
    {
        $method = strtolower(trim((string) $paymentMethod));

        if ($method === 'gcash') {
            return 'manual_gcash';
        }

        return $method !== '' ? $method : 'unknown';
    }
}

if (!function_exists('getCustomerBookingStatusLabel')) {
    function getCustomerBookingStatusLabel($status): string
    {
        switch (normalizeOrderWorkflowStatus($status)) {
            case 'pending_payment':
                return 'Pending Payment';
            case 'awaiting_store_confirmation':
                return 'Paid - Waiting for Caterer';
            case 'confirmed':
                return 'Confirmed';
            case 'completed':
                return 'Completed';
            case 'cancelled':
                return 'Cancelled';
            case 'preparing':
                return 'Confirmed';
            case 'delivering':
                return 'Confirmed';
            default:
                return 'Pending Payment';
        }
    }
}

if (!function_exists('getCustomerBookingStatusClass')) {
    function getCustomerBookingStatusClass($status): string
    {
        switch (normalizeOrderWorkflowStatus($status)) {
            case 'awaiting_store_confirmation':
                return 'status-confirmed';
            case 'confirmed':
            case 'preparing':
            case 'delivering':
            case 'completed':
                return 'status-completed';
            case 'cancelled':
                return 'status-cancelled';
            case 'pending':
            case 'pending_payment':
            default:
                return 'status-pending';
        }
    }
}

if (!function_exists('getStoreReservationStatusLabel')) {
    function getStoreReservationStatusLabel($status): string
    {
        switch (normalizeOrderWorkflowStatus($status)) {
            case 'pending_payment':
                return 'Pending Payment';
            case 'awaiting_store_confirmation':
                return 'Paid - Waiting for Caterer';
            case 'confirmed':
                return 'Confirmed';
            case 'completed':
                return 'Completed';
            case 'cancelled':
                return 'Cancelled';
            case 'preparing':
                return 'Confirmed';
            case 'delivering':
                return 'Confirmed';
            default:
                return ucfirst(normalizeOrderWorkflowStatus($status));
        }
    }
}

if (!function_exists('getPortalWebhookSyncStatus')) {
    function getPortalWebhookSyncStatus(array $order, array $payment = []): array
    {
        $hasPaymentRecord = !empty($payment['payment_id']) || !empty($payment['payment_reference_number']) || !empty($payment['payment_amount']);
        $hasOrderReference = !empty($order['reference_number']) || !empty($order['order_reference_number']);
        $hasPaidAt = !empty($order['paid_at']) || !empty($order['payment_created_at']);

        if ($hasPaymentRecord) {
            return [
                'key' => 'webhook_confirmed',
                'label' => 'Webhook Confirmed'
            ];
        }

        if ($hasOrderReference || $hasPaidAt) {
            return [
                'key' => 'fallback_synced',
                'label' => 'Fallback Synced'
            ];
        }

        return [
            'key' => 'needs_attention',
            'label' => 'Needs Attention'
        ];
    }
}

function customerBookingDownpaymentIsOpen(string $status, array $financials): bool {
    if (!in_array(strtolower(trim($status)), ['confirmed', 'approved'], true)
        || ($financials['totals']['balance'] ?? 0) <= 0
        || ($financials['down_payment_required'] ?? 0) <= ($financials['totals']['total_paid'] ?? 0)) {
        return false;
    }
    foreach ($financials['history'] ?? [] as $payment) {
        if (strtolower((string) ($payment['status'] ?? '')) === 'pending') return false;
    }
    return true;
}