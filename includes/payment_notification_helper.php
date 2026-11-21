<?php
// Payment read state is separate from reservation and bell notifications.
function getPaymentNotificationGroups($conn, int $store): array {
    static $initialized = false;
    if (!$initialized) {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS payment_notification_reads (
        user_id INT NOT NULL, notification_id INT NOT NULL,
        PRIMARY KEY (user_id, notification_id)
        )");
        $initialized = true;
    }
    $orders = [];
    $result = mysqli_query($conn, "SELECT id, order_number FROM bookings WHERE store_id = $store AND is_archived_by_store = 0 AND (order_status IS NULL OR order_status <> 'cancelled')");
    while ($result && ($row = mysqli_fetch_assoc($result))) $orders[] = $row;
    $groups = [];
    $result = mysqli_query($conn, "SELECT n.id, n.title, n.message, n.link FROM notifications n
        LEFT JOIN payment_notification_reads r ON r.user_id = n.user_id AND r.notification_id = n.id
        WHERE n.user_id = $store AND r.notification_id IS NULL
        AND n.type IN ('new_order','payment_received','payment_confirmed','payment_verified')");
    while ($result && ($notice = mysqli_fetch_assoc($result))) {
        $query = [];
        parse_str(parse_url($notice['link'] ?? '', PHP_URL_QUERY) ?: '', $query);
        $linkedId = (int) ($query['order_id'] ?? $query['id'] ?? 0);
        foreach ($orders as $order) {
            $number = (string) $order['order_number'];
            $matches = $linkedId > 0 ? $linkedId === (int) $order['id'] :
                ($number !== '' && preg_match('/(?<![a-zA-Z0-9])' . preg_quote($number, '/') . '(?![a-zA-Z0-9])/', $notice['title'] . ' ' . $notice['message']));
            if ($matches) {
                $groups[(int) $order['id']][] = (int) $notice['id'];
                break;
            }
        }
    }
    return $groups;
}
