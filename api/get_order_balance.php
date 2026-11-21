<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/../includes/financial_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$oid = $_GET['id'] ?? $_GET['order_id'] ?? 0;
$order_id = intval($oid);

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing order_id']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$role = getUserRole();
if ($role === 'customer') {
    $owner_filter = "customer_id = $user_id";
} elseif (in_array($role, ['store', 'caterer'], true)) {
    $owner_filter = "store_id = $user_id";
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// Check ownership before reading or synchronizing financial data.
$allowed = mysqli_query($conn, "SELECT id, reschedule_status FROM bookings WHERE id = $order_id AND $owner_filter LIMIT 1");
if (!$allowed || mysqli_num_rows($allowed) === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}

$booking_state = mysqli_fetch_assoc($allowed);
$financials = getOrderFinancials($order_id);

if ($financials) {
    // Merge success flag
    $response = array_merge(['success' => true], $financials);
    $response['reschedule_pending_count'] = strtolower((string) $booking_state['reschedule_status']) === 'pending' ? 1 : 0;
    echo json_encode($response);
} else {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
}
?>
