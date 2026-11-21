<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

// Check login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = (int) $_SESSION['user_id'];
    $order_id = (int) ($_POST['order_id'] ?? 0);
    
    // Validate inputs
    $overall = floatval($_POST['overall_rating']);
    $service = intval($_POST['service_rating']);
    $food = intval($_POST['food_rating']);
    $delivery = intval($_POST['delivery_rating']);
    $comment = mysqli_real_escape_string($conn, $_POST['comment']);
    
    // Check if order is completed
    $check_order = mysqli_query($conn, "SELECT order_status, store_id FROM bookings WHERE id = '$order_id' AND customer_id = '$user_id'");
    $order = mysqli_fetch_assoc($check_order);
    
    if (!$order || strtolower($order['order_status']) !== 'completed') {
        echo json_encode(['success' => false, 'message' => 'Order is not completed or does not exist.']);
        exit();
    }
    
    // Use the caterer from the booking rather than the submitted store_id.
    $store_id = (int) $order['store_id'];

    // Check if already reviewed
    $check_review = mysqli_query($conn, "SELECT id FROM reviews WHERE order_id = '$order_id'");
    if (mysqli_num_rows($check_review) > 0) {
        echo json_encode(['success' => false, 'message' => 'You have already reviewed this order.']);
        exit();
    }
    
    // Insert review
    $query = "INSERT INTO reviews (order_id, store_id, customer_id, overall_rating, service_rating, food_rating, delivery_rating, comment, created_at) 
              VALUES ('$order_id', '$store_id', '$user_id', '$overall', '$service', '$food', '$delivery', '$comment', NOW())";
              
    if (mysqli_query($conn, $query)) {
        echo json_encode(['success' => true, 'message' => 'Review submitted successfully!']);
    } else {
        appLogError('Submit review failed', mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Unable to save your review right now. Please try again.']);
    }
    exit();
}
?>
