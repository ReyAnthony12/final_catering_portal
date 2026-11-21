<?php
function reservationStatusIsLocked(string $status): bool {
    return in_array(strtolower(trim($status)), ['cancelled', 'rejected', 'declined'], true);
}

/** Claim the status change atomically before inventory or notification work. */
function catererChangeReservationStatus(mysqli $conn, int $orderId, int $storeId, string $status, ?string $expectedStatus = null): bool {
    $status = strtolower(trim($status));
    if (!in_array($status, ['pending','confirmed','preparing','ready','delivering','completed','cancelled','rejected','declined'], true)) return false;
    if ($expectedStatus !== null && reservationStatusIsLocked($expectedStatus)) return false;
    $sql = "UPDATE bookings SET order_status = ? WHERE id = ? AND store_id = ? AND LOWER(TRIM(order_status)) NOT IN ('cancelled','rejected','declined')";
    if ($expectedStatus !== null) $sql .= ' AND LOWER(TRIM(order_status)) = ?';
    $statement = mysqli_prepare($conn, $sql);
    if (!$statement) return false;
    try {
        if ($expectedStatus !== null) {
            $expectedStatus = strtolower(trim($expectedStatus));
            mysqli_stmt_bind_param($statement, 'siis', $status, $orderId, $storeId, $expectedStatus);
        } else {
            mysqli_stmt_bind_param($statement, 'sii', $status, $orderId, $storeId);
        }
        $changed = mysqli_stmt_execute($statement) && mysqli_stmt_affected_rows($statement) === 1;
    } finally { mysqli_stmt_close($statement); }

    if ($changed && $expectedStatus === 'pending' && $status === 'confirmed') {
        sendReservationConfirmationNotice($conn, $orderId);
    }
    return $changed;
}

function lockCancelledReservationControls(string $actions, string $status): string {
    if (!reservationStatusIsLocked($status)) return $actions;
    $label = htmlspecialchars(ucfirst(strtolower(trim($status))), ENT_QUOTES, 'UTF-8');
    $badge = '<span class="reservation-status-locked" role="status" title="This booking is closed and its status cannot be changed." style="display:inline-flex;align-items:center;min-height:38px;padding:7px 12px;border:1px solid var(--border-color,#96a38e);border-radius:8px;background:var(--secondary-bg,#c9d2c1);color:var(--text-main,#20372b);font-size:12px;white-space:nowrap;">' . $label . ' &middot; Locked</span>';
    $actions = preg_replace('~<select\b[^>]*class="status-select"[^>]*>.*?</select>~s', $badge, $actions);
    // A cancelled booking has no preparation work to schedule.
    return preg_replace('~<a\b[^>]*href="event_preparation\.php\?order_id=[^"]*"[^>]*>.*?</a>~s', '', $actions);
}

function sendReservationConfirmationNotice(mysqli $conn, int $orderId): void {
    try {
        require_once __DIR__ . '/mail_helper.php';
        $result = mysqli_query($conn, "SELECT u.email, u.first_name, b.order_number, sd.business_name
            FROM bookings b
            JOIN users u ON u.id = b.customer_id
            LEFT JOIN store_details sd ON sd.user_id = b.store_id
            WHERE b.id = $orderId LIMIT 1");
        $booking = $result ? mysqli_fetch_assoc($result) : null;
        if (!$booking || !filter_var($booking['email'], FILTER_VALIDATE_EMAIL)) {
            error_log("Booking confirmation email skipped for booking ID $orderId: missing valid customer email.");
            return;
        }

        $name = trim((string) $booking['first_name']) ?: 'Customer';
        $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $reference = $escape($booking['order_number'] ?: $orderId);
        $caterer = $escape($booking['business_name'] ?: 'Your caterer');
        $url = $escape(rtrim(BASE_URL, '/') . '/customer/booking-details.php?id=' . $orderId);
        $body = '<h2>Booking confirmed</h2><p>Hello ' . $escape($name) . ',</p>'
            . '<p>' . $caterer . ' confirmed booking #' . $reference . '.</p>'
            . '<p>Open your booking in Catering Portal to see the amount due and proceed with your downpayment.</p>'
            . '<p><a href="' . $url . '">View booking details</a></p>';
        if (!sendEmailViaSmtp($booking['email'], $name, 'Your catering booking is confirmed', $body)) {
            error_log("Booking confirmation email could not be sent for booking ID $orderId.");
        }
    } catch (Throwable $error) {
        error_log("Booking confirmation email failed for booking ID $orderId.");
    }
}