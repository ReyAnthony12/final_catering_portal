<?php
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, private');
if (!isLoggedIn() || !in_array(getUserRole(), ['store', 'caterer'], true)) {
    http_response_code(403); echo json_encode(['success' => false]); exit;
}
$store = (int) $_SESSION['user_id'];
if (empty($_SESSION['reservation_badge_token'])) $_SESSION['reservation_badge_token'] = bin2hex(random_bytes(24));
$token = $_SESSION['reservation_badge_token'];
require_once __DIR__ . '/../includes/reservation_notification_helper.php';
$groups = getReservationNotificationGroups($conn, $store);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($token, (string) ($body['token'] ?? ''))) {
        http_response_code(403); echo json_encode(['success' => false]); exit;
    }
    $customer = (int) ($body['customer_id'] ?? 0);
    $seen = array_map('intval', (array) ($body['ids'] ?? []));
    $ids = array_intersect($groups[$customer] ?? [], $seen);
    if ($ids) {
        $list = implode(',', $ids);
        mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = $store AND id IN ($list)");
    }
    echo json_encode(['success' => true]); exit;
}
echo json_encode(['success' => true, 'groups' => (object) $groups, 'token' => $token]);
