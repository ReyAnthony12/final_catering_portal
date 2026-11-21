<?php
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, private');
if (!isLoggedIn() || !in_array(getUserRole(), ['store', 'caterer'], true)) {
    http_response_code(403); echo json_encode(['success' => false]); exit;
}
require_once __DIR__ . '/../includes/payment_notification_helper.php';
$store = (int) $_SESSION['user_id'];
if (empty($_SESSION['payment_badge_token'])) $_SESSION['payment_badge_token'] = bin2hex(random_bytes(24));
$groups = getPaymentNotificationGroups($conn, $store);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['payment_badge_token'], (string) ($body['token'] ?? ''))) {
        http_response_code(403); echo json_encode(['success' => false]); exit;
    }
    $orders = array_map('intval', (array) ($body['order_ids'] ?? [$body['order_id'] ?? 0]));
    $allowed = [];
    foreach ($orders as $order) $allowed = array_merge($allowed, $groups[$order] ?? []);
    $ids = array_intersect($allowed, array_map('intval', (array) ($body['ids'] ?? [])));
    foreach ($ids as $id) {
        mysqli_query($conn, "INSERT IGNORE INTO payment_notification_reads (user_id, notification_id) VALUES ($store, $id)");
    }
    $groups = getPaymentNotificationGroups($conn, $store);
}
$order_customers = [];
$result = mysqli_query($conn, "SELECT id, customer_id FROM bookings WHERE store_id = $store");
while ($result && ($row = mysqli_fetch_assoc($result))) $order_customers[(int) $row['id']] = (int) $row['customer_id'];
echo json_encode(['success' => true, 'groups' => (object) $groups, 'order_customers' => (object) $order_customers, 'token' => $_SESSION['payment_badge_token']]);
