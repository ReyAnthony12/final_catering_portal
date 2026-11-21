<?php
// Match unread booking alerts to customers using only this store's orders.
function getReservationNotificationGroups($conn, $store) {
    $store = (int) $store;
$orders = [];
$result = mysqli_query($conn, "SELECT id, customer_id, order_number FROM bookings WHERE store_id = $store");
while ($row = mysqli_fetch_assoc($result)) $orders[] = $row;
$groups = [];
$result = mysqli_query($conn, "SELECT id, type, title, message, link FROM notifications WHERE user_id = $store AND is_read = 0 AND type IN ('new_order','order_status','payment_received','payment_confirmed','payment_verified','reschedule_request','menu_change_request')");
while ($notification = mysqli_fetch_assoc($result)) {
    $query = []; parse_str(parse_url($notification['link'] ?? '', PHP_URL_QUERY) ?: '', $query);
    foreach ($orders as $order) {
        $number = (string) $order['order_number'];
        $linked = (int) ($query['order_id'] ?? $query['id'] ?? 0) === (int) $order['id'];
        $mentioned = $number !== '' && preg_match('/(?<![a-zA-Z0-9])' . preg_quote($number, '/') . '(?![a-zA-Z0-9])/', ($notification['title'] ?? '') . ' ' . ($notification['message'] ?? ''));
        if ($linked || $mentioned) {
            $groups[(int) $order['customer_id']][] = (int) $notification['id'];
            break;
        }
    }
}

    return $groups;
}
