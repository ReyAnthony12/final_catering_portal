<?php
require_once __DIR__ . '/../includes/order_reference_helper.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/payment_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !in_array(getUserRole(), ['store', 'caterer'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$date = trim($_GET['date'] ?? '');

if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing date parameter.']);
    exit();
}

$date_safe = mysqli_real_escape_string($conn, $date);
$store_id_safe = mysqli_real_escape_string($conn, $user_id);

// Check if this date is a holiday
$holiday_res = mysqli_query($conn, "SELECT id FROM store_holidays WHERE store_id = '$store_id_safe' AND holiday_date = '$date_safe' LIMIT 1");
$is_holiday = ($holiday_res && mysqli_num_rows($holiday_res) > 0);

// Get capacity settings
$capacity = getStoreBookingCapacity($user_id);

// Fetch all bookings (non-cancelled) for this caterer on this date
$bookings_res = mysqli_query($conn, "
    SELECT
        o.id,
        o.order_number,
        o.full_name,
        o.order_time,
        o.number_of_pax,
        o.order_status,
        o.payment_status,
        o.total_amount,
        o.package_name,
        o.event_type,
        u.email,
        u.phone_number
    FROM bookings o
    LEFT JOIN users u ON u.id = o.customer_id
    WHERE o.store_id = '$store_id_safe'
      AND DATE(o.order_date) = '$date_safe'
      AND LOWER(TRIM(o.order_status)) NOT IN ('cancelled', 'rejected', 'declined')
    ORDER BY o.order_time ASC, o.id ASC
");

$bookings = [];
if ($bookings_res) {
    while ($row = mysqli_fetch_assoc($bookings_res)) {
        // Format time to 12-hour
        $time_formatted = '';
        if (!empty($row['order_time'])) {
            $t = DateTime::createFromFormat('H:i:s', $row['order_time'])
                ?: DateTime::createFromFormat('H:i', $row['order_time']);
            $time_formatted = $t ? $t->format('h:i A') : $row['order_time'];
        }

        $bookings[] = [
            'id'             => intval($row['id']),
            'order_number'   => formatCatererBookingNumber($row['id'], $row['order_number']),
            'customer_name'  => htmlspecialchars($row['full_name'] ?? ''),
            'email'          => htmlspecialchars($row['email'] ?? ''),
            'phone'          => htmlspecialchars($row['phone_number'] ?? ''),
            'time'           => $time_formatted,
            'pax'            => intval($row['number_of_pax'] ?? 0),
            'order_status'   => $row['order_status'],
            'payment_status' => $row['payment_status'],
            'total_amount'   => floatval($row['total_amount'] ?? 0),
            'package_name'   => htmlspecialchars($row['package_name'] ?? ''),
            'event_type'     => htmlspecialchars($row['event_type'] ?? ''),
        ];
    }
}

// Also count cancelled bookings for reference
$cancelled_res = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM bookings
    WHERE store_id = '$store_id_safe'
      AND DATE(order_date) = '$date_safe'
      AND order_status = 'cancelled'
");
$cancelled_count = 0;
if ($cancelled_res && ($crow = mysqli_fetch_assoc($cancelled_res))) {
    $cancelled_count = intval($crow['cnt']);
}

$active_count = count($bookings);
$slots_remaining = max(0, $capacity - $active_count);

echo json_encode([
    'success'          => true,
    'date'             => $date,
    'is_holiday'       => $is_holiday,
    'capacity'         => $capacity,
    'active_count'     => $active_count,
    'cancelled_count'  => $cancelled_count,
    'slots_remaining'  => $slots_remaining,
    'bookings'         => $bookings,
]);
