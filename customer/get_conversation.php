<?php
require_once __DIR__ . "/../config.php";
/** @var mysqli $conn */
require_once __DIR__ . "/../includes/payment_functions.php";
require_once __DIR__ . '/../includes/order_reference_helper.php';
requireLogin();

if (getUserRole() !== "customer") {
    http_response_code(403);
    exit("Forbidden");
}

header("Content-Type: application/json");

$user_id = $_SESSION["user_id"];
$message_id = isset($_GET["view"]) ? mysqli_real_escape_string($conn, $_GET["view"]) : "";
$from_trash = isset($_GET["from_trash"]) ? true : false;

if (!$message_id) {
    echo json_encode(["error" => "No message ID provided"]);
    exit();
}

// Get message details
$view_query = "
    SELECT m.*,
           u1.first_name as sender_first, u1.last_name as sender_last, u1.role as sender_role,
           u2.first_name as receiver_first, u2.last_name as receiver_last, u2.role as receiver_role,
           sd1.business_name as sender_business,
           sd2.business_name as receiver_business
    FROM messages m
    JOIN users u1 ON m.sender_id = u1.id
    JOIN users u2 ON m.receiver_id = u2.id
    LEFT JOIN store_details sd1 ON u1.id = sd1.user_id
    LEFT JOIN store_details sd2 ON u2.id = sd2.user_id
    WHERE m.id = '$message_id'
    AND (m.sender_id = '$user_id' OR m.receiver_id = '$user_id')
";

$view_result = mysqli_query($conn, $view_query);
$viewed_message = mysqli_fetch_assoc($view_result);

if (!$viewed_message) {
    echo json_encode(["error" => "Message not found"]);
    exit();
}

// Mark all unread messages from this sender as read
$other_user_id = ($viewed_message["sender_id"] == $user_id) ? $viewed_message["receiver_id"] : $viewed_message["sender_id"];
mysqli_query($conn, "UPDATE messages SET is_read = TRUE WHERE sender_id = '$other_user_id' AND receiver_id = '$user_id' AND is_read = FALSE");

// Get order balance info
$order_balance = null;
preg_match("/ORD-[A-Z0-9]+/", $viewed_message["subject"] ?? '', $matches);
if (empty($matches)) {
    preg_match("/ORD-[A-Z0-9]+/", $viewed_message["message"] ?? '', $matches);
}

if (!empty($viewed_message['order_id']) || !empty($matches)) {
    $order_number = $matches[0] ?? '';
    $linked_order_id = (int) ($viewed_message['order_id'] ?? 0);
    $booking_lookup = $linked_order_id > 0 ? "id = $linked_order_id" : "order_number = '$order_number'";
    $other_user_id_for_order = ($viewed_message["sender_id"] == $user_id) ? $viewed_message["receiver_id"] : $viewed_message["sender_id"];
    $order_query = "SELECT * FROM bookings WHERE $booking_lookup AND ((customer_id = '$user_id' AND store_id = '$other_user_id_for_order') OR (customer_id = '$other_user_id_for_order' AND store_id = '$user_id'))";
    $order_result = mysqli_query($conn, $order_query);
    if ($order_row = mysqli_fetch_assoc($order_result)) {
        $payment_summary = getOrderPaymentSummary($order_row["id"]);
        $payment_history = getOrderPaymentHistory($order_row["id"]);
        $order_balance = [
            "order_id"              => $order_row["id"],
            "order_number"          => $order_row["order_number"],
            "total_amount"          => $payment_summary["total_amount"],
            "down_payment_required" => $payment_summary["down_payment_required"],
            "down_payment_paid"     => $payment_summary["down_payment_paid"],
            "down_payment_remaining"=> $payment_summary["down_payment_remaining"],
            "total_paid"            => $payment_summary["total_paid"],
            "balance"               => $payment_summary["remaining_balance"],
            "payment_status"        => $payment_summary["payment_status"],
            "progress_percentage"   => $payment_summary["progress_percentage"],
            "payment_history"       => $payment_history
        ];
    }
}

// Get thread messages
$thread_messages = [];
$is_trash_view = $from_trash ? "m.is_deleted = TRUE" : "m.is_deleted = FALSE";
$other_uid = ($viewed_message["sender_id"] == $user_id) ? $viewed_message["receiver_id"] : $viewed_message["sender_id"];
$where_clause = "((m.sender_id = '$user_id' AND m.receiver_id = '$other_uid') OR (m.sender_id = '$other_uid' AND m.receiver_id = '$user_id')) AND $is_trash_view";

$thread_query = "
    SELECT m.*,
           u1.first_name as sender_first, u1.last_name as sender_last, u1.role as sender_role,
           u2.first_name as receiver_first, u2.last_name as receiver_last, u2.role as receiver_role,
           sd1.business_name as sender_business,
           sd2.business_name as receiver_business
    FROM messages m
    JOIN users u1 ON m.sender_id = u1.id
    JOIN users u2 ON m.receiver_id = u2.id
    LEFT JOIN store_details sd1 ON u1.id = sd1.user_id
    LEFT JOIN store_details sd2 ON u2.id = sd2.user_id
    WHERE $where_clause
    ORDER BY m.created_at ASC, m.id ASC
";

$thread_result = mysqli_query($conn, $thread_query);
while ($thread_msg = mysqli_fetch_assoc($thread_result)) {
    $thread_messages[] = $thread_msg;
}

// Get caterer QR if applicable
$store_qr_code = null;
$other_store_id = ($viewed_message["sender_id"] == $user_id) ? $viewed_message["receiver_id"] : $viewed_message["sender_id"];
{
    $store_qr_query = mysqli_query($conn, "SELECT gcash_qr_image FROM store_details WHERE user_id = '$other_store_id'");
    $store_qr = mysqli_fetch_assoc($store_qr_query);
    if ($store_qr && $store_qr["gcash_qr_image"]) {
        $store_qr_code = $store_qr["gcash_qr_image"];
    }
}

// Build other party name
$other_party_name = ($viewed_message["sender_id"] == $user_id)
    ? ($viewed_message["receiver_business"] ?? ($viewed_message["receiver_first"] . " " . $viewed_message["receiver_last"]))
    : ($viewed_message["sender_business"] ?? ($viewed_message["sender_first"] . " " . $viewed_message["sender_last"]));

$csrf_token = generateCSRFToken();

$logo_result = mysqli_query($conn, "SELECT profile_image FROM users WHERE id = '" . (int) $other_user_id . "' LIMIT 1");
$logo_row = $logo_result ? mysqli_fetch_assoc($logo_result) : null;
$party_logo = ltrim((string) ($logo_row['profile_image'] ?? ''), '/');
if ($party_logo === '' || !is_file(dirname(__DIR__) . '/' . $party_logo)) $party_logo = 'uploads/logo.png';

$response = [
    "other_party_logo" => $party_logo,
    "message_id"       => $viewed_message["id"],
    "subject"          => formatCustomerMessageBookingReferences($viewed_message["subject"]),
    "other_party_name" => $other_party_name,
    "receiver_id"      => ($viewed_message["sender_id"] == $user_id) ? $viewed_message["receiver_id"] : $viewed_message["sender_id"],
    "original_msg_id"  => isset($viewed_message["original_message_id"]) ? ($viewed_message["original_message_id"] ?: $viewed_message["id"]) : $viewed_message["id"],
    "csrf_token"       => $csrf_token,
    "from_trash"       => $from_trash,
    "order_balance"    => $order_balance,
    "store_qr_code"    => $store_qr_code,
    "other_store_id"   => $other_store_id,
    "thread_messages"  => array_map(function($msg) use ($user_id) {
        $msg_text = htmlspecialchars(formatCustomerMessageBookingReferences($msg["message"] ?? ""));
        $msg_text = preg_replace(
            "/\[attachment:\s*(.+?)\]/",
            "<br><a href=\"../$1\" target=\"_blank\" style=\"display:inline-block; margin-top:10px;\"><img src=\"../$1\" style=\"max-width:200px; border-radius:8px; border:1px solid rgba(255,255,255,0.1);\"/></a>",
            $msg_text
        );
        return [
            "id"         => $msg["id"],
            "is_me"      => ($msg["sender_id"] == $user_id),
            "message"    => nl2br($msg_text),
            "created_at" => date("M d, h:i A", strtotime($msg["created_at"])),
        ];
    }, $thread_messages),
];

$unread_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM messages WHERE receiver_id = '$user_id' AND is_read = 0 AND is_deleted = 0");
$response['unread_count'] = (int) (mysqli_fetch_assoc($unread_result)['count'] ?? 0);
echo json_encode($response);
?>
