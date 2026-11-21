<?php
/** Toggle a caterer's holiday without blocking a date already booked. */
function toggleCatererHoliday(mysqli $conn, int $storeId, string $date): array {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        return ['success' => false, 'error' => 'Please choose a valid date.'];
    }
    $check = $conn->prepare('SELECT id FROM store_holidays WHERE store_id = ? AND holiday_date = ? LIMIT 1');
    $check->bind_param('is', $storeId, $date);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    if ($exists) {
        $delete = $conn->prepare('DELETE FROM store_holidays WHERE store_id = ? AND holiday_date = ?');
        $delete->bind_param('is', $storeId, $date);
        $delete->execute();
        $delete->close();
        return ['success' => true, 'is_holiday' => false];
    }
    $insert = $conn->prepare("INSERT INTO store_holidays (store_id, holiday_date)
        SELECT ?, ? FROM DUAL WHERE NOT EXISTS (
            SELECT 1 FROM bookings WHERE store_id = ? AND DATE(order_date) = ?
            AND LOWER(TRIM(order_status)) NOT IN ('cancelled', 'rejected', 'declined')
        ) AND NOT EXISTS (SELECT 1 FROM store_holidays WHERE store_id = ? AND holiday_date = ?)");
    $insert->bind_param('isisis', $storeId, $date, $storeId, $date, $storeId, $date);
    $insert->execute();
    $saved = $insert->affected_rows === 1;
    $insert->close();
    return $saved ? ['success' => true, 'is_holiday' => true]
        : ['success' => false, 'error' => 'This date already has bookings and cannot be marked as a holiday.'];
}
