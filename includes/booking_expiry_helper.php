<?php

/** Cancel only unaccepted bookings after their scheduled start (Philippine time).
 * Called before request handlers and by the minute scheduler. The conditional
 * update claims each booking once, including when workers run concurrently.
 */
function cancelExpiredUnacceptedBookings(mysqli $conn, ?DateTimeImmutable $now = null): int
{
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
        ->setTimezone(new DateTimeZone('Asia/Manila'));
    $cutoff = mysqli_real_escape_string($conn, $now->format('Y-m-d H:i:s'));
    $pending = "order_status IN ('pending', 'pending_payment', 'awaiting_store_confirmation')";
    $expired = "TIMESTAMP(order_date, COALESCE(order_time, '00:00:00')) <= '$cutoff'";
    $result = mysqli_query($conn, "SELECT id FROM bookings WHERE $pending AND $expired ORDER BY id");
    if (!$result) {
        throw new RuntimeException(mysqli_error($conn));
    }
    $count = 0;
    while ($candidate = mysqli_fetch_assoc($result)) {
        $id = (int) $candidate['id'];
        mysqli_begin_transaction($conn);
        try {
            $booking = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT id, customer_id, store_id, order_number FROM bookings WHERE id = $id AND $pending AND $expired FOR UPDATE"));
            if (!$booking) {
                mysqli_rollback($conn);
                continue;
            }
            mysqli_query($conn, "UPDATE bookings SET order_status = 'cancelled', timeline_status = 'cancelled', timeline_updated_at = '$cutoff' WHERE id = $id AND $pending AND $expired");
            if (mysqli_affected_rows($conn) !== 1) {
                mysqli_rollback($conn);
                continue;
            }
            // Pending bookings normally have no allocation; release any reserved stock once.
            $usage = mysqli_query($conn, "SELECT id, inventory_item_id, quantity_used FROM inventory_usage WHERE order_id = $id AND status = 'reserved' FOR UPDATE");
            while ($item = mysqli_fetch_assoc($usage)) {
                $itemId = (int) $item['inventory_item_id'];
                $quantity = (int) $item['quantity_used'];
                $usageId = (int) $item['id'];
                mysqli_query($conn, "UPDATE inventory_items SET available_stock = available_stock + $quantity WHERE id = $itemId");
                mysqli_query($conn, "DELETE FROM inventory_usage WHERE id = $usageId");
            }
            // Events has no Cancelled enum; remove the unfulfilled calendar reservation.
            mysqli_query($conn, "DELETE FROM events WHERE order_id = $id AND status IN ('Booked', 'Reserved')");
            $message = 'Booking #' . $booking['order_number'] . ' was automatically cancelled because the caterer did not accept it before the scheduled event date and time.';
            if (!createNotification((int) $booking['customer_id'], 'order_status', 'Booking Automatically Cancelled', $message, "booking-details.php?id=$id")
                || !createNotification((int) $booking['store_id'], 'order_status', 'Booking Automatically Cancelled', $message, "order-details.php?id=$id")) {
                throw new RuntimeException('Unable to save cancellation notifications.');
            }
            mysqli_commit($conn);
            $count++;
        } catch (Throwable $error) {
            mysqli_rollback($conn);
            throw $error;
        }
    }
    return $count;
}
