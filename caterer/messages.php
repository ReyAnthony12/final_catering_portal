<?php
// Start session first as header.php checks it
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/financial_helper.php';
require_once __DIR__ . '/../includes/order_reference_helper.php';
require_once __DIR__ . '/../includes/menu_change_helper.php';

if (!isLoggedIn() || !in_array(getUserRole(), ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$csrf_token = generateCSRFToken();

// Use the portal logo when the contact has no available profile photo.
function renderMessageContactAvatar($profile_image) {
    $path = str_replace('\\', '/', trim((string) $profile_image));
    $photo = strpos($path, 'uploads/') === 0 && strpos($path, '..') === false
        && is_file(__DIR__ . '/../' . $path) ? '../' . $path : '../uploads/logo.png';
    ?>
    <span class="conversation-avatar" aria-hidden="true">
        <img src="<?php echo htmlspecialchars($photo, ENT_QUOTES, 'UTF-8'); ?>" alt="" onerror="this.onerror=null;this.src='../uploads/logo.png';">
    </span>
    <?php
}

// Check dates on every request, including POSTs before the page header runs.
$access_query = mysqli_query($conn, "SELECT subscription_status, subscription_expiry, free_trial_start, free_trial_used, free_trial_expiry FROM store_details WHERE user_id = '" . intval($user_id) . "' LIMIT 1");
$message_access = $access_query ? mysqli_fetch_assoc($access_query) : [];
$message_today = date('Y-m-d');
$has_message_subscription = ($message_access['subscription_status'] ?? '') === 'active'
    && !empty($message_access['subscription_expiry'])
    && $message_access['subscription_expiry'] >= $message_today;
$has_message_trial = !empty($message_access['free_trial_start'])
    && empty($message_access['free_trial_used'])
    && !empty($message_access['free_trial_expiry'])
    && $message_access['free_trial_expiry'] >= $message_today;
$portal_only_messaging = !$has_message_subscription && !$has_message_trial;

// These actions also send customer messages through the order helpers.
if ($portal_only_messaging && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['menu_change_action']) || isset($_POST['refund_action']))) {
    header('Location: messages.php?error=portal_only');
    exit();
}

function parseMenuChangeRequestDetails($message_text) {
    $details = [
        'current_set' => '',
        'requested_set' => '',
        'current_total' => 0,
        'new_total' => 0,
        'already_paid' => 0,
        'difference' => 0,
        'additional_needed' => 0
    ];

    $patterns = [
        'current_set' => '/Current Set:\s*(.+)/i',
        'requested_set' => '/Requested Set:\s*(.+)/i',
        'current_total' => '/Current Set Total:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'new_total' => '/New Set Total:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'already_paid' => '/Already Paid:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'difference' => '/Price Difference:\s*([+-]?)PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'additional_needed' => '/Additional Amount Needed:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i'
    ];

    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $message_text, $matches)) {
            if (in_array($key, ['current_total', 'new_total', 'already_paid', 'additional_needed'], true)) {
                $details[$key] = floatval(str_replace(',', '', $matches[1]));
            } elseif ($key === 'difference') {
                $amount = floatval(str_replace(',', '', $matches[2]));
                $details[$key] = (($matches[1] ?? '') === '-') ? -$amount : $amount;
            } else {
                $details[$key] = trim($matches[1]);
            }
        }
    }

    return $details;
}

// Check if caterer is closed by portal
$store_status_query = "SELECT u.status as user_status FROM users u WHERE u.id = '$user_id'";
$store_status_result = mysqli_query($conn, $store_status_query);
$store_status = mysqli_fetch_assoc($store_status_result);
$is_store_closed_by_portal = ($store_status['user_status'] === 'closed');

// Handle send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        die('Invalid security token. Please refresh the page.');
    }
    
    $receiver_id = intval($_POST['receiver_id'] ?? 0);
    $recipient_query = mysqli_query($conn, "SELECT role FROM users WHERE id = '$receiver_id' AND id != '" . intval($user_id) . "' LIMIT 1");
    $recipient = $recipient_query ? mysqli_fetch_assoc($recipient_query) : null;
    if (!$recipient || !in_array($recipient['role'], $portal_only_messaging ? ['portal'] : ['portal', 'customer'], true)) {
        header('Location: messages.php?error=' . ($portal_only_messaging ? 'portal_only' : 'invalid_recipient'));
        exit();
    }
    $subject = mysqli_real_escape_string($conn, $_POST['subject']);
    
    $message_text = $_POST['message'];

    $message = mysqli_real_escape_string($conn, $message_text);
    
    // Check if this is a reply to an existing message
    $original_message_id = isset($_POST['original_message_id']) ? mysqli_real_escape_string($conn, $_POST['original_message_id']) : null;
    $order_id = isset($_POST['order_id']) && !empty($_POST['order_id']) ? mysqli_real_escape_string($conn, $_POST['order_id']) : null;
    
    // If replying and no order_id provided, try to find it from original message
    if ($original_message_id && !$order_id) {
        $orig_q = mysqli_query($conn, "SELECT order_id FROM messages WHERE id = '$original_message_id'");
        if ($orig_row = mysqli_fetch_assoc($orig_q)) {
            $order_id = $orig_row['order_id'];
        }
    }

    if ($original_message_id) {
        $query = "INSERT INTO messages (sender_id, receiver_id, order_id, subject, message, original_message_id) 
                  VALUES ('$user_id', '$receiver_id', " . ($order_id ? "'$order_id'" : "NULL") . ", '$subject', '$message', '$original_message_id')";
    } else {
        $query = "INSERT INTO messages (sender_id, receiver_id, order_id, subject, message) 
                  VALUES ('$user_id', '$receiver_id', " . ($order_id ? "'$order_id'" : "NULL") . ", '$subject', '$message')";
    }
    
    if (mysqli_query($conn, $query)) {
        // Keep the new message ID before notification inserts change insert_id.
        $sent_message_id = (int) mysqli_insert_id($conn);
        $_SESSION['message_sent_notice'] = $sent_message_id;
        if ($original_message_id) {
            $_SESSION['sent_reply_draft'] = [
                'thread' => (int) $original_message_id,
                'receiver' => $receiver_id,
                'message' => $message_text
            ];
        }
        // Get caterer name for notification
        $store_query = mysqli_query($conn, "SELECT sd.business_name FROM store_details sd WHERE sd.user_id = '$user_id'");
        $caterer = mysqli_fetch_assoc($store_query);
        $store_name = $caterer ? $caterer['business_name'] : 'Caterer';
        
        // Create notification for receiver (customer)
        createNotification(
            $receiver_id,
            'new_message',
            'New Message from ' . $store_name,
            $subject,
            'messages.php'
        );

        if (function_exists('notifyPortalAdmins')) {
            notifyPortalAdmins(
                'new_message',
                'Caterer Sent a Message',
                $store_name . ' sent a new message to a customer.',
                'messages.php'
            );
        }
    } else {
        header('Location: messages.php?error=send_failed');
        exit();
    }

    header('Location: messages.php?sent=1&view=' . $sent_message_id);
    exit();
}

// Handle Mark as Paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_as_paid'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        die('Invalid security token. Please refresh the page.');
    }
    
    $order_id = mysqli_real_escape_string($conn, $_POST['order_id']);
    
    // Update order payment status
    $update_query = "UPDATE bookings SET payment_status = 'paid' WHERE id = '$order_id' AND store_id = '$user_id'";
    
    if (mysqli_query($conn, $update_query)) {
        // Get customer ID from order
        $customer_query = mysqli_query($conn, "SELECT customer_id, total_amount FROM bookings WHERE id = '$order_id'");
        $order = mysqli_fetch_assoc($customer_query);
        $customer_id = $order['customer_id'];
        $total_amount = $order['total_amount'];
        
        // Get caterer name
        $store_query = mysqli_query($conn, "SELECT sd.business_name FROM store_details sd WHERE sd.user_id = '$user_id'");
        $caterer = mysqli_fetch_assoc($store_query);
        $store_name = $caterer ? $caterer['business_name'] : 'Caterer';
        
        // Create notification for customer
        createNotification(
            $customer_id,
            'payment_confirmed',
            'Payment Confirmed by ' . $store_name,
            'Your payment of ?' . number_format($total_amount, 2) . ' has been confirmed. Thank you!',
            'messages.php'
        );

        if (function_exists('notifyPortalAdmins')) {
            notifyPortalAdmins(
                'payment_confirmed',
                'Caterer Confirmed Customer Payment',
                $store_name . ' marked a customer payment as confirmed for Booking #' . $order_id . '.',
                'reports.php'
            );
        }
        
        // Redirect back to the viewed message or inbox
        $view_param = isset($_POST['view']) ? intval($_POST['view']) : 0;
        $redirect_url = $view_param > 0 ? 'messages.php?view=' . $view_param . '&paid=1' : 'messages.php?paid=1';
        header('Location: ' . $redirect_url);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['menu_change_action'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        header('Location: messages.php?error=invalid_token');
        exit();
    }

    $return_to = trim($_POST['return_to'] ?? '');
    $redirect_base = 'messages.php?view=' . intval($_POST['message_id'] ?? 0);
    if ($return_to !== '' && preg_match('/^(messages|order-details)\.php(\?.*)?$/', $return_to)) {
        $redirect_base = $return_to;
    }

    $action = $_POST['menu_change_action'];
    $message_id = intval($_POST['message_id'] ?? 0);
    $order_id = intval($_POST['order_id'] ?? 0);

    $message_q = mysqli_query($conn, "
        SELECT m.*, o.order_number, o.customer_id, o.product_id, o.additional_menu, o.addons_total, o.extra_fee, o.store_id, o.number_of_pax
        FROM messages m
        JOIN bookings o ON m.order_id = o.id
        WHERE m.id = '$message_id' AND m.order_id = '$order_id' AND o.store_id = '$user_id'
        LIMIT 1
    ");
    $message_row = $message_q ? mysqli_fetch_assoc($message_q) : null;

    if (!$message_row) {
        header('Location: ' . $redirect_base . (strpos($redirect_base, '?') !== false ? '&' : '?') . 'error=menu_request_not_found');
        exit();
    }

    $request_details = parseMenuChangeRequestDetails($message_row['message'] ?? '');
    $requested_set = trim($request_details['requested_set'] ?? '');
    $current_set = trim($request_details['current_set'] ?? '');

    if ($requested_set === '') {
        header('Location: ' . $redirect_base . (strpos($redirect_base, '?') !== false ? '&' : '?') . 'error=menu_request_invalid');
        exit();
    }

    if ($action === 'approve') {
        $result = applyApprovedMenuChange($conn, [
            'order_id' => $order_id,
            'store_user_id' => $user_id,
            'customer_id' => intval($message_row['customer_id']),
            'product_id' => intval($message_row['product_id']),
            'message_id' => $message_id,
            'order_number' => $message_row['order_number'],
            'requested_set' => $requested_set,
            'current_set' => $current_set,
            'additional_menu' => $message_row['additional_menu'] ?? '',
            'number_of_pax' => intval($message_row['number_of_pax'] ?? ($message_row['quantity'] ?? 1)),
            'addons_total' => floatval($message_row['addons_total'] ?? 0),
            'extra_fee' => floatval($message_row['extra_fee'] ?? 0)
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'menu_change_update_failed';
            header('Location: ' . $redirect_base . (strpos($redirect_base, '?') !== false ? '&' : '?') . 'error=' . $error);
            exit();
        }
        header('Location: ' . $redirect_base . (strpos($redirect_base, '?') !== false ? '&' : '?') . 'menu_change=approved');
        exit();
    }

    if ($action === 'reject') {
        $reject_reason = trim($_POST['reject_reason'] ?? '');
        if ($reject_reason === '') {
            header('Location: ' . $redirect_base . (strpos($redirect_base, '?') !== false ? '&' : '?') . 'error=reject_reason_required');
            exit();
        }
        $result = applyRejectedMenuChange($conn, [
            'order_id' => $order_id,
            'store_user_id' => $user_id,
            'customer_id' => intval($message_row['customer_id']),
            'message_id' => $message_id,
            'order_number' => $message_row['order_number'],
            'requested_set' => $requested_set,
            'current_set' => $current_set,
            'reject_reason' => $reject_reason
        ]);
        if (!$result['success']) {
            $error = $result['error'] ?? 'menu_change_reply_failed';
            header('Location: ' . $redirect_base . (strpos($redirect_base, '?') !== false ? '&' : '?') . 'error=' . $error);
            exit();
        }

        header('Location: ' . $redirect_base . (strpos($redirect_base, '?') !== false ? '&' : '?') . 'menu_change=rejected');
        exit();
    }
}

// Handle refund approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refund_action'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        die('Invalid security token. Please refresh the page.');
    }

    $action         = $_POST['refund_action']; // 'approve' or 'reject'
    $order_id       = intval($_POST['order_id']);
    $customer_id    = intval($_POST['customer_id']);
    $message_id     = intval($_POST['message_id']);
    $refund_amount  = floatval($_POST['refund_amount']);
    $view_param     = intval($_POST['view_param'] ?? 0);

    // Get caterer name
    $sname_q   = mysqli_query($conn, "SELECT sd.business_name FROM store_details sd WHERE sd.user_id = '$user_id'");
    $sname_row = mysqli_fetch_assoc($sname_q);
    $store_name = $sname_row ? $sname_row['business_name'] : 'Caterer';

    // Get order number
    $ord_q   = mysqli_query($conn, "SELECT order_number FROM bookings WHERE id = '$order_id' AND store_id = '$user_id'");
    $ord_row = mysqli_fetch_assoc($ord_q);
    $order_number = $ord_row ? $ord_row['order_number'] : '';

    $redirect_base = 'messages.php' . ($view_param > 0 ? '?view=' . $view_param : '');
    $sep = strpos($redirect_base, '?') !== false ? '&' : '?';

    if ($action === 'approve') {
        // Handle file upload
        $proof_image = '';
        if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/proofs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $file_ext = strtolower(pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            if (in_array($file_ext, $allowed_ext)) {
                $new_filename = 'refund_' . $order_id . '_' . time() . '.' . $file_ext;
                if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $upload_dir . $new_filename)) {
                    $proof_image = 'uploads/proofs/' . $new_filename;
                }
            }
        }

        // Insert refund record in payments table
        $proof_image_sql = $proof_image ? "'" . mysqli_real_escape_string($conn, $proof_image) . "'" : "NULL";
        $refund_q = "INSERT INTO payments (order_id, amount, payment_type, status, notes, proof_image, created_at)
                     VALUES ('$order_id', '$refund_amount', 'refund', 'verified', 'Refund approved by caterer via messages', $proof_image_sql, NOW())";
        if (mysqli_query($conn, $refund_q)) {
            // Mark the refund request message as handled (update message_type)
            mysqli_query($conn, "UPDATE messages SET message_type = 'refund_approved' WHERE id = '$message_id'");

            // Notify customer
            createNotification($customer_id, 'refund_approved',
                'Refund Approved by ' . $store_name,
                'Your refund of PHP ' . number_format($refund_amount, 2) . ' for ' . $order_number . ' has been approved.',
                'messages.php');

            // Send confirmation message to customer
            $confirm_msg = mysqli_real_escape_string($conn, "? REFUND APPROVED\n\nYour refund request for {$order_number} has been approved.\nRefund Amount: PHP " . number_format($refund_amount, 2) . "\n\nPlease allow a few days for the amount to be returned to you.");
            
            // If there's an image, attach it to the message or mention it
            if ($proof_image) {
                $confirm_msg .= "\n\nProof of return has been attached to the payment record.";
            }

            $confirm_subject = mysqli_real_escape_string($conn, 'Re: Refund Request: ' . $order_number);
            mysqli_query($conn, "INSERT INTO messages (sender_id, receiver_id, order_id, subject, message, message_type, original_message_id)
                VALUES ('$user_id', '$customer_id', '$order_id', '$confirm_subject', '$confirm_msg', 'refund_approved', '$message_id')");

            header('Location: ' . $redirect_base . $sep . 'refund_action=approved');
            exit();
        }
        header('Location: ' . $redirect_base . $sep . 'refund_action=error');
        exit();
    }

    if ($action === 'reject') {
        $reject_reason = mysqli_real_escape_string($conn, trim($_POST['reject_reason'] ?? ''));
        if (empty($reject_reason)) {
            header('Location: ' . $redirect_base . $sep . 'refund_action=reason_required');
            exit();
        }

        // Mark original message
        mysqli_query($conn, "UPDATE messages SET message_type = 'refund_rejected' WHERE id = '$message_id'");

        // Send rejection message to customer
        $reject_msg = mysqli_real_escape_string($conn, "? REFUND REQUEST DECLINED\n\nYour refund request for {$order_number} has been declined.\n\nReason:\n{$reject_reason}\n\nIf you have questions, please reply to this message.");
        $reject_subject = mysqli_real_escape_string($conn, 'Re: Refund Request: ' . $order_number);
        mysqli_query($conn, "INSERT INTO messages (sender_id, receiver_id, order_id, subject, message, message_type, original_message_id)
            VALUES ('$user_id', '$customer_id', '$order_id', '$reject_subject', '$reject_msg', 'refund_rejected', '$message_id')");

        createNotification($customer_id, 'refund_rejected',
            'Refund Declined by ' . $store_name,
            'Your refund request for ' . $order_number . ' was declined. Reason: ' . $reject_reason,
            'messages.php');

        header('Location: ' . $redirect_base . $sep . 'refund_action=rejected');
        exit();
    }
}

// Handle delete/restore
function updateMessageConversation($conn, $message_id, $user_id, $deleted) {
    $message_id = intval($message_id);
    $user_id = intval($user_id);
    $deleted = intval($deleted);
    $result = mysqli_query($conn, "SELECT sender_id, receiver_id FROM messages WHERE id = $message_id AND (sender_id = $user_id OR receiver_id = $user_id)");
    $message = $result ? mysqli_fetch_assoc($result) : null;
    if (!$message) return false;
    $contact_id = intval($message['sender_id'] == $user_id ? $message['receiver_id'] : $message['sender_id']);
    // Each inbox row represents a contact, so move their entire conversation.
    return mysqli_query($conn, "UPDATE messages SET is_deleted = $deleted WHERE is_deleted IN (0, 1) AND ((sender_id = $user_id AND receiver_id = $contact_id) OR (sender_id = $contact_id AND receiver_id = $user_id))");
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_list_action'])) {
    header('Content-Type: application/json');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false]);
        exit();
    }
    $action = $_POST['message_list_action'];
    if (in_array($action, ['delete_all', 'empty_trash'], true)) {
        $account_id = intval($user_id);
        $source = $action === 'delete_all' ? 0 : 1;
        // Retain message records and order/reply references after clearing Trash.
        $target = $action === 'delete_all' ? 1 : 2;
        $success = mysqli_query($conn, "UPDATE messages SET is_deleted = $target WHERE is_deleted = $source AND (sender_id = $account_id OR receiver_id = $account_id)");
        if (!$success) http_response_code(500);
        echo json_encode(['success' => (bool) $success]);
        exit();
    }
    $message_id = intval($_POST['id'] ?? 0);
    if (!in_array($action, ['delete', 'restore'], true) || $message_id < 1) {
        http_response_code(400);
        echo json_encode(['success' => false]);
        exit();
    }
    $deleted = $action === 'delete' ? 1 : 0;
    $success = updateMessageConversation($conn, $message_id, $user_id, $deleted);
    if (!$success) http_response_code(500);
    echo json_encode(['success' => (bool) $success]);
    exit();
}
if (isset($_GET['action'])) {
    $message_id = mysqli_real_escape_string($conn, $_GET['id']);
    
    if ($_GET['action'] === 'delete') {
        updateMessageConversation($conn, $message_id, $user_id, 1);
    } elseif ($_GET['action'] === 'restore') {
        updateMessageConversation($conn, $message_id, $user_id, 0);
    }
    
    header('Location: messages.php?list_updated=1');
    exit();
}

// Handle view message
$viewed_message = null;
$thread_messages = [];
$order_balance = null;
$menu_change_request = null;

if (isset($_GET['view'])) {
    $message_id = mysqli_real_escape_string($conn, $_GET['view']);
    
    // Get message details
    $view_query = "
        SELECT m.*, 
               u1.first_name as sender_first, u1.last_name as sender_last, u1.role as sender_role, u1.profile_image as sender_profile_image,
               u2.first_name as receiver_first, u2.last_name as receiver_last, u2.profile_image as receiver_profile_image, u2.role as receiver_role
        FROM messages m 
        JOIN users u1 ON m.sender_id = u1.id 
        JOIN users u2 ON m.receiver_id = u2.id 
        WHERE m.id = '$message_id' 
        AND (m.sender_id = '$user_id' OR m.receiver_id = '$user_id')
    ";
    
    $view_result = mysqli_query($conn, $view_query);
    $viewed_message = mysqli_fetch_assoc($view_result);
    
    // Mark as read if receiver is viewing
    if ($viewed_message && $viewed_message['receiver_id'] == $user_id && !$viewed_message['is_read']) {
        mysqli_query($conn, "UPDATE messages SET is_read = TRUE WHERE id = '$message_id'");
    }
    
    // Get order balance info if message is related to an order
    if ($viewed_message) {
        if (
            stripos($viewed_message['subject'] ?? '', 'Menu Change Request') !== false ||
            stripos($viewed_message['message'] ?? '', 'Requested Set:') !== false
        ) {
            $parsed_request = parseMenuChangeRequestDetails($viewed_message['message'] ?? '');
            if (!empty($parsed_request['requested_set'])) {
                $menu_change_request = $parsed_request;
                $menu_change_request['message_id'] = intval($viewed_message['id']);
            }
        }

        // Check order_id first
        if (!empty($viewed_message['order_id'])) {
            $order_query = "SELECT * FROM bookings WHERE id = '{$viewed_message['order_id']}' AND store_id = '$user_id'";
            $order_result = mysqli_query($conn, $order_query);
            if ($order_row = mysqli_fetch_assoc($order_result)) {
                $fin = getOrderFinancials($order_row['id']);
                $order_balance = [
                    'order_id' => $order_row['id'],
                    'order_number' => $order_row['order_number'],
                    'total_amount' => $fin['totals']['grand_total'] ?? 0,
                    'downpayment' => $fin['down_payment_required'],
                    'balance' => $fin['totals']['balance'] ?? 0,
                    'payment_status' => $fin['status']
                ];
            }
        } else {
            // Fallback to regex match
            preg_match('/ORD-[A-Z0-9]+/', $viewed_message['subject'], $matches);
            if (empty($matches)) {
                preg_match('/ORD-[A-Z0-9]+/', $viewed_message['message'], $matches);
            }
            
            if (!empty($matches)) {
                $order_number = $matches[0];
                $order_query = "SELECT * FROM bookings WHERE order_number = '$order_number' AND store_id = '$user_id'";
                $order_result = mysqli_query($conn, $order_query);
                if ($order_row = mysqli_fetch_assoc($order_result)) {
                    $fin = getOrderFinancials($order_row['id']);
                    $order_balance = [
                        'order_id' => $order_row['id'],
                        'order_number' => $order_row['order_number'],
                        'total_amount' => $fin['totals']['grand_total'] ?? 0,
                        'downpayment' => $fin['down_payment_required'],
                        'balance' => $fin['totals']['balance'] ?? 0,
                        'payment_status' => $fin['status']
                    ];
                }
            }
        }
    }
    
    $other_user_id = ($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_id'] : $viewed_message['sender_id'];
    $other_user_id = ($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_id'] : $viewed_message['sender_id'];
    $where_clause = "((m.sender_id = '$user_id' AND m.receiver_id = '$other_user_id') OR (m.sender_id = '$other_user_id' AND m.receiver_id = '$user_id'))";

    $is_trash_view = isset($_GET['from_trash']) ? "AND m.is_deleted = TRUE" : "AND m.is_deleted = FALSE";

    // Mark the whole visible thread as read for the caterer so the sidebar dot clears immediately.
    mysqli_query(
        $conn,
        "UPDATE messages m
         SET m.is_read = 1
         WHERE m.sender_id = '$other_user_id'
           AND m.receiver_id = '$user_id'
           AND COALESCE(m.is_read, 0) = 0"
    );

    $thread_query = "
        SELECT m.*, 
               u1.first_name as sender_first, u1.last_name as sender_last, u1.role as sender_role, u1.profile_image as sender_profile_image,
               u2.first_name as receiver_first, u2.last_name as receiver_last, u2.profile_image as receiver_profile_image, u2.role as receiver_role
        FROM messages m 
        JOIN users u1 ON m.sender_id = u1.id 
        JOIN users u2 ON m.receiver_id = u2.id 
        WHERE $where_clause $is_trash_view
        AND (m.sender_id = '$user_id' OR m.receiver_id = '$user_id')
        ORDER BY m.created_at ASC, m.id ASC
    ";
    
    $thread_result = mysqli_query($conn, $thread_query);
    while ($thread_msg = mysqli_fetch_assoc($thread_result)) {
        $thread_messages[] = $thread_msg;
    }
}

// Detect pending refund request in thread (for the caterer action card)
$refund_request_msg = null;
if (!empty($thread_messages)) {
    foreach (array_reverse($thread_messages) as $tm) {
        if (($tm['message_type'] ?? '') === 'refund_request' && $tm['receiver_id'] == $user_id) {
            $refund_request_msg = $tm;
            break;
        }
    }
}


// Get messages list (Inbox)
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM messages LIKE 'original_message_id'");
$has_reply_column = mysqli_num_rows($check_column) > 0;

    $messages_query = "
        SELECT m.*, 
               u1.first_name as sender_first, u1.last_name as sender_last, u1.role as sender_role, u1.profile_image as sender_profile_image,
               u2.first_name as receiver_first, u2.last_name as receiver_last, u2.profile_image as receiver_profile_image, u2.role as receiver_role,
               CASE 
                   WHEN m.sender_id = '$user_id' THEN u2.first_name
                   ELSE u1.first_name
               END as contact_first,
               CASE 
                   WHEN m.sender_id = '$user_id' THEN u2.last_name
                   ELSE u1.last_name
               END as contact_last,
               CASE 
                   WHEN m.sender_id = '$user_id' THEN u2.role
                   ELSE u1.role
               END as contact_role,
               EXISTS (SELECT 1 FROM bookings b WHERE b.store_id = '$user_id'
                   AND b.customer_id = CASE WHEN m.sender_id = '$user_id' THEN m.receiver_id ELSE m.sender_id END
                   AND DATE(b.order_date) = CURDATE() AND b.order_status != 'cancelled') as has_booking_today,
               0 as reply_count,
               (SELECT COUNT(*) FROM messages m3 
                WHERE m3.sender_id = CASE WHEN m.sender_id = '$user_id' THEN m.receiver_id ELSE m.sender_id END 
                AND m3.receiver_id = '$user_id' 
                AND m3.is_read = 0 AND m3.is_deleted = FALSE) as unread_count,
               m.created_at as last_activity
        FROM messages m 
        JOIN users u1 ON m.sender_id = u1.id 
        JOIN users u2 ON m.receiver_id = u2.id 
        WHERE (m.sender_id = '$user_id' OR m.receiver_id = '$user_id') 
        AND m.is_deleted = FALSE 
        AND m.id IN (
            SELECT MAX(id) FROM messages 
            WHERE (sender_id = '$user_id' OR receiver_id = '$user_id') AND is_deleted = FALSE 
            GROUP BY IF(sender_id = '$user_id', receiver_id, sender_id)
        )
        ORDER BY last_activity DESC, m.id DESC
    ";
$messages_result = mysqli_query($conn, $messages_query);

// Get deleted messages
$deleted_query = "
    SELECT m.*, 
           CONCAT(u1.first_name, ' ', u1.last_name) as sender_name,
           CONCAT(u2.first_name, ' ', u2.last_name) as receiver_name,
           u1.role as sender_role, u1.profile_image as sender_profile_image,
           u2.profile_image as receiver_profile_image, u2.role as receiver_role
    FROM messages m 
    JOIN users u1 ON m.sender_id = u1.id 
    JOIN users u2 ON m.receiver_id = u2.id 
    WHERE (m.sender_id = '$user_id' OR m.receiver_id = '$user_id') 
    AND m.is_deleted = TRUE 
    AND m.id IN (
        SELECT MAX(id) FROM messages 
        WHERE (sender_id = '$user_id' OR receiver_id = '$user_id') AND is_deleted = TRUE 
        GROUP BY IF(sender_id = '$user_id', receiver_id, sender_id)
    )
    ORDER BY m.created_at DESC
";
$deleted_result = mysqli_query($conn, $deleted_query);

// Get users for dropdown
$message_recipient_roles = $portal_only_messaging ? "role = 'portal'" : "(role = 'portal' OR role = 'customer')";
$users_query = "
    SELECT id, CONCAT(first_name, ' ', last_name) as name, role 
    FROM users 
    WHERE $message_recipient_roles
    AND id != '$user_id'
    ORDER BY name ASC
";
$users_result = mysqli_query($conn, $users_query);

$page_title = 'Caterer Messages';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Reuse Styles from Customer Messages for Consistency -->
<?php if (($_GET['error'] ?? '') === 'send_failed'): ?>
<div class="alert alert-error" role="alert" style="margin-bottom: 20px;">Your message could not be sent. Please try again.</div>
<?php endif; ?>
<?php if ($portal_only_messaging): ?>
<div class="alert alert-error" style="margin-bottom: 20px;">
    Your free trial or subscription has ended. You can only message Portal Admin until your subscription is active.
</div>
<?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalid_recipient'): ?>
<div class="alert alert-error" style="margin-bottom: 20px;">Please select a valid recipient.</div>
<?php endif; ?>
<style>
    .btn-delete-all { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; margin-bottom: 14px; padding: 10px 16px; border: 1px solid #c0392b; border-radius: 10px; background: rgba(192,57,43,.15); color: #ff8074; font: inherit; font-weight: 600; cursor: pointer; }
    .btn-delete-all:hover { background: rgba(192,57,43,.3); }
    .btn-delete-all:focus-visible { outline: 2px solid #ff8074; outline-offset: 3px; }
    .btn-delete-all[aria-disabled="true"] { opacity: .5; cursor: wait; }
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    
    .message-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;
    }

    .message-tabs .compose-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        margin-left: auto;
        white-space: nowrap;
    }
    
    .message-tab {
        padding: 10px 20px;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        font-weight: 500;
        color: var(--text-muted);
    }
    
    .message-tab.active {
        background: var(--accent-gold);
        color: #000;
        border-color: var(--accent-gold);
    }

    .message-toolbar {
        display: flex;
        gap: 15px;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .search-box {
        position: relative;
        flex: 1;
        min-width: 250px;
    }

    .search-box input {
        width: 100%;
        padding: 12px 14px 12px 40px;
        background: var(--primary-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        color: var(--text-main);
        font-size: 14px;
        box-sizing: border-box;
    }

    .search-box i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
    }

    .filter-buttons {
        display: flex;
        gap: 8px;
    }

    .filter-btn {
        padding: 8px 16px;
        border-radius: 20px;
        border: 1px solid var(--border-color);
        background: var(--card-bg);
        color: var(--text-main);
        cursor: pointer;
        transition: all 0.2s;
    }

    .filter-btn:hover {
        border-color: var(--accent-gold);
    }

    .filter-btn.active {
        background: var(--accent-gold);
        color: #000;
        border-color: var(--accent-gold);
        font-weight: 600;
    }

    .message-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .message-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-color);
        transition: all 0.2s;
        border-radius: 8px;
        margin-bottom: 4px;
    }

    .message-item:hover {
        background-color: rgba(255,255,255,0.05);
    }

    .message-item.unread {
        background-color: rgba(212, 175, 55, 0.08);
        border-left: 4px solid var(--accent-gold);
    }

    .message-content {
        flex: 1;
        min-width: 0;
        margin-right: 20px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .message-sender {
        font-weight: 700;
        font-size: 15px;
        color: var(--text-main);
    }

    .message-subject {
        font-weight: 600;
        font-size: 14px;
        color: var(--text-main);
    }

    .message-preview {
        color: var(--text-muted);
        font-size: 13px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .message-meta {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
        min-width: 110px;
    }
    
    .message-time {
        font-size: 12px;
        color: var(--text-muted);
    }

    .message-actions {
        display: flex;
        gap: 8px;
    }

    .btn-view, .btn-delete {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 6px;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .btn-view { background: var(--accent-gold); color: #000; border: none; font-weight: 500; }
    .btn-view:hover { background: #e5b532; }
    
    .btn-delete { background: transparent; color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.5); }
    .btn-delete:hover { background: rgba(231, 76, 60, 0.1); border-color: #e74c3c; }

    /* Modern Modal Styles (Copied from Customer) */
    .modern-modal-overlay {
        display: flex;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.88);
        backdrop-filter: blur(12px);
        z-index: 1100;
        align-items: center;
        justify-content: center;
        padding: 24px;
        font-family: 'Inter', sans-serif;
    }
    
    .modern-modal {
        background: #0d0d0d;
        border-radius: 20px;
        width: 100%;
        max-width: 1200px;
        max-height: 92vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.05), 0 25px 50px -12px rgba(0, 0, 0, 0.7);
    }
    
    .modern-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 24px 32px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        background: linear-gradient(180deg, #141414 0%, #0f0f0f 100%);
    }
    
    .modern-modal-body {
        display: flex;
        flex: 1;
        overflow: hidden;
        gap: 0;
        padding: 0;
    }
    
    .modern-left-col { flex: 0 0 40%; display: flex; flex-direction: column; gap: 24px; overflow-y: auto; padding: 32px; border-right: 1px solid rgba(255, 255, 255, 0.04); }
    .modern-right-col { flex: 1; display: flex; flex-direction: column; background: #141414; border-radius: 0; overflow: hidden; border: none; }
    
    .modern-card {
        background: #141414;
        border-radius: 16px;
        padding: 28px;
        border: 1px solid rgba(255, 255, 255, 0.04);
    }
    
    .modern-chat-area {
        flex: 1;
        overflow-y: auto;
        padding: 28px;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    
    .modern-message {
        display: flex;
        flex-direction: column;
        max-width: 80%;
    }
    
    .modern-message.customer { align-self: flex-start; }
    .modern-message.caterer { align-self: flex-end; }
    
    .modern-message-bubble {
        padding: 16px 20px;
        border-radius: 20px;
        position: relative;
    }
    
    .modern-message.customer .modern-message-bubble {
        background: #1e1e1e;
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-bottom-left-radius: 6px;
    }
    
    .modern-message.caterer .modern-message-bubble {
        background: linear-gradient(135deg, rgba(245, 197, 66, 0.15) 0%, rgba(245, 197, 66, 0.08) 100%);
        border: 1px solid rgba(245, 197, 66, 0.15);
        border-bottom-right-radius: 6px;
    }
    
    .modern-reply-area {
        padding: 24px 28px;
        border-top: 1px solid rgba(255, 255, 255, 0.04);
        background: #0f0f0f;
    }
    
    .modern-form-control {
        width: 100%;
        background: #1a1a1a;
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 12px;
        padding: 14px 18px;
        color: #fff;
    }
    
    .modern-btn-primary {
        background: linear-gradient(135deg, #f5c542 0%, #e5b532 100%);
        color: #111;
        border: none;
        padding: 14px 32px;
        border-radius: 12px;
        cursor: pointer;
        font-weight: 600;
        width: 100%;
    }
    
    /* Compose Modal specific */
    .modern-close-btn {
        color: #666;
        font-size: 20px;
        text-decoration: none;
        transition: all 0.2s ease;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.05);
    }
    
    .modern-close-btn:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.1);
    }
    
    .compose-modal {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.8);
        z-index: 1200;
        align-items: center;
        justify-content: center;
    }
    .compose-content {
        background: var(--card-bg);
        width: 90%; max-width: 500px;
        padding: 30px;
        border-radius: 16px;
    }
    /* Page-specific responsive inbox and conversation layout. */
    .messages-header {
        background: radial-gradient(ellipse at top right, rgba(212,175,55,.16), transparent 65%), var(--card-bg);
        border: 1px solid rgba(212,175,55,.22);
        border-radius: 20px;
        padding: 28px;
        gap: 20px;
    }
    .messages-header h1 { font-size: clamp(26px, 4vw, 36px); letter-spacing: -.8px; }
    .messages-header p { margin-top: 8px; line-height: 1.6; }
    .messages-header .btn { min-height: 48px; border-radius: 12px; }
    .messages-inbox { border-radius: 20px; padding: 24px; }
    .message-tab { min-height: 44px; display: inline-flex; align-items: center; gap: 8px; }
    .search-box { min-width: 0; flex-basis: 280px; }
    .search-box input { min-height: 48px; border-radius: 12px; }
    .filter-btn { min-height: 44px; font-size: 13px; white-space: nowrap; }
    .message-item { gap: 16px; border: 1px solid var(--border-color); background: var(--primary-bg); margin-bottom: 12px; border-radius: 14px; padding: 20px; }
    .message-content { gap: 7px; margin-right: 0; }
    #messageList .message-item { position: relative; flex-direction: row; align-items: center; }
    #messageList .message-meta { flex: 0 0 auto; min-width: 0; padding-top: 0; border-top: 0; }
    #messageList .message-sender { overflow-wrap: anywhere; }
    .message-open { color: inherit; text-decoration: none; }
    .message-open, .message-contact { display: flex; flex-direction: row; align-items: center; gap: 12px; min-width: 0; }
    .message-open .message-sender, .message-contact .message-sender { min-width: 0; flex: 1; }
    .conversation-avatar { overflow: hidden; }
    .conversation-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .conversation-avatar [hidden] { display: none; }
    .message-open::after { content: ''; position: absolute; inset: 0; border-radius: 14px; }
    .message-open:focus-visible { outline: none; }
    .message-open:focus-visible::after { outline: 2px solid var(--accent-gold); outline-offset: 3px; }
    #messageList .btn-delete { position: relative; z-index: 1; }
    .message-sender { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 16px; }
    .message-subject { overflow-wrap: anywhere; line-height: 1.5; }
    .message-preview { line-height: 1.6; }
    .btn-view, .btn-delete { min-height: 44px; min-width: 44px; box-sizing: border-box; gap: 5px; font-size: 13px; border-radius: 10px; }
    .message-tab:focus-visible, .filter-btn:focus-visible, .btn-view:focus-visible, .btn-delete:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 3px; }
    .compose-content { box-sizing: border-box; max-height: 90dvh; overflow-y: auto; border: 1px solid var(--border-color); }
    .compose-content .form-control, .modern-form-control { box-sizing: border-box; font-size: 16px; }
    .modern-right-col, .modern-left-col { min-width: 0; }
    .modern-message-bubble { overflow-wrap: anywhere; line-height: 1.7; }
    .modern-message-bubble img { max-width: 100% !important; height: auto; }
    .modern-close-btn { flex-shrink: 0; width: 44px; height: 44px; }
    .modern-reply-area form > div { flex-wrap: wrap; }
    #attachment_filename { overflow-wrap: anywhere; }
    @media (max-width: 768px) {
        .messages-header { padding: 22px; align-items: flex-start; }
        .messages-inbox { padding: 18px; }
        .message-toolbar { align-items: stretch; gap: 12px; }
        .search-box { flex-basis: 100%; }
        .filter-buttons { flex-wrap: wrap; }
        .modern-modal-body { flex-direction: column; height: auto !important; max-height: none !important; overflow: visible; }
        .modern-left-col { flex: auto; overflow: visible; padding: 18px; gap: 16px; border-right: 0; border-bottom: 1px solid var(--border-color); }
        .modern-right-col { flex: auto; overflow: visible; }
        .modern-modal-header { padding: 18px; gap: 12px; }
        .modern-modal-header h2 { font-size: 20px; }
        .modern-card { padding: 18px; }
        .modern-chat-area { flex: auto; min-height: 180px; max-height: 55dvh; padding: 18px; gap: 14px; }
        .modern-message { max-width: 92%; }
        .modern-message-bubble { padding: 12px 16px; }
        .modern-reply-area { padding: 18px; }
    }
    @media (max-width: 480px) {
        .messages-header { flex-direction: column; padding: 20px; border-radius: 16px; }
        .messages-header .btn { width: 100%; justify-content: center; }
        .messages-inbox { padding: 14px; border-radius: 16px; }
        .message-tabs { gap: 8px; }
        .message-tab { flex: 1; justify-content: center; padding: 10px; }
        .message-tabs .compose-button { padding: 10px; font-size: 12px; }
        .filter-buttons { width: 100%; gap: 6px; }
        .filter-btn { flex: 1; padding: 8px; font-size: 12px; }
        .message-item { flex-direction: column; align-items: stretch; padding: 16px; gap: 14px; }
        .message-meta { flex-direction: row; align-items: center; justify-content: space-between; min-width: 0; gap: 8px; border-top: 1px solid var(--border-color); padding-top: 12px; }
        .message-time { font-size: 12px; }
        .compose-content { width: calc(100% - 24px); padding: 22px; border-radius: 18px; }
        .modern-reply-area form > div > button, .modern-reply-area label { flex: 1; justify-content: center; padding: 12px !important; font-size: 13px; }
    }
    .conversation-view { margin-top: 0 !important; border-radius: 20px !important; border: 1px solid rgba(212,175,55,.18) !important; box-shadow: 0 16px 48px rgba(0,0,0,.18) !important; }
    .conversation-view .modern-modal-header { gap: 14px; padding: 22px 26px; background: linear-gradient(120deg, #242015, #151515 75%); }
    .conversation-avatar { width: 46px; height: 46px; flex-shrink: 0; display: grid; place-items: center; border-radius: 15px; background: rgba(212,175,55,.14); color: var(--accent-gold); border: 1px solid rgba(212,175,55,.24); font-size: 20px; }
    /* Compact inbox rows on desktop without changing the mobile layout. */
    @media (min-width: 769px) {
        .messages-inbox .message-item { padding: 10px 16px; margin-bottom: 8px; border-radius: 12px; }
        .messages-inbox .conversation-avatar { width: 36px; height: 36px; border-radius: 11px; font-size: 16px; }
        .messages-inbox .btn-delete { min-width: 36px; min-height: 36px; padding: 8px; }
        .messages-inbox .message-open::after { border-radius: 12px; }
    }
    .conversation-identity { flex: 1; min-width: 0; }
    .conversation-identity h2 { margin: 0; font-size: 18px; overflow-wrap: anywhere; line-height: 1.4; }
    .conversation-identity p { margin: 4px 0 0; color: #aaa; font-size: 12px; }
    .conversation-view .modern-chat-area { background: radial-gradient(ellipse at top right, rgba(212,175,55,.04), transparent 65%), #111; }
    .conversation-view .modern-message { min-width: 70px; }
    .conversation-view .modern-message-bubble { font-size: 15px; border-radius: 18px; }
    .conversation-view .modern-message.caterer .modern-message-bubble { background: linear-gradient(135deg, #38301b, #292417); border-color: rgba(212,175,55,.24); border-bottom-right-radius: 5px; }
    .conversation-view .modern-message.customer .modern-message-bubble { background: #252525; border-bottom-left-radius: 5px; }
    .conversation-time { margin-top: 6px; padding: 0 5px; color: #939393; font-size: 11px; }
    .caterer .conversation-time { text-align: right; }
    .conversation-composer { display: flex; flex-direction: column; gap: 12px; }
    .conversation-composer .modern-form-control { order: -1; min-height: 90px; resize: vertical; line-height: 1.6; border-color: #333; }
    .conversation-composer .modern-form-control:focus { outline: none; border-color: var(--accent-gold); box-shadow: 0 0 0 3px rgba(212,175,55,.1); }
    .conversation-composer > div { margin-bottom: 0 !important; }
    .conversation-composer button, .conversation-composer label { min-height: 44px; box-sizing: border-box; }
    @media (max-width: 768px) {
        .conversation-view .modern-modal-header { padding: 16px; }
        .conversation-view .modern-right-col { min-height: 58dvh; }
        .conversation-view .modern-chat-area { flex: 1; max-height: 52dvh; padding: 20px 16px; }
        .conversation-view .modern-reply-area { padding: 16px; padding-bottom: max(16px, env(safe-area-inset-bottom)); }
        .conversation-view .modern-message { max-width: 88%; }
        .conversation-view .modern-message-bubble { padding: 12px 15px; }
        .conversation-avatar { width: 40px; height: 40px; border-radius: 12px; }
        .conversation-identity h2 { font-size: 16px; }
        .conversation-view .modern-close-btn { background: rgba(255,255,255,.08); color: #ddd; }
    }
    .conversation-view .modern-reply-area { padding: 12px 16px; }
    .conversation-composer { display: grid; grid-template-columns: minmax(0, 1fr) 44px; align-items: end; gap: 8px; }
    .conversation-composer .modern-form-control { order: initial; min-height: 44px; height: 44px; max-height: 120px; resize: none; padding: 11px 14px; border-radius: 22px; line-height: 22px; overflow-y: auto; }
    .conversation-composer .chat-attach, .conversation-composer .chat-send { width: 44px; height: 44px; min-height: 44px; padding: 0 !important; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
    .conversation-composer .chat-attach { color: #bbb; background: #252525; border: 1px solid #353535; }
    .conversation-composer .chat-attach:focus-visible, .conversation-composer .chat-send:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 3px; }
    .conversation-composer #attachment_filename { grid-column: 1 / -1; font-size: 12px; color: #aaa; }
    .conversation-composer #attachment_filename:empty { display: none; }
    @media (max-width: 768px) {
        .conversation-view .modern-reply-area { padding: 10px; padding-bottom: max(10px, env(safe-area-inset-bottom)); }
        .conversation-view .modern-right-col { min-height: 0; }
        /* Let the mobile conversation fill the viewport down to the composer. */
        .main-content:has(.conversation-view) {
            position: fixed;
            inset: 0;
            margin: 0;
            width: 100%;
            display: flex;
            flex-direction: column;
            height: var(--mobile-chat-height, 100dvh);
            top: var(--mobile-chat-top, 0px);
            bottom: auto;
            flex: none;
            min-height: 0;
            box-sizing: border-box;
            padding-bottom: 0;
            overflow: hidden;
        }
        .conversation-view {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
        }
        .conversation-view .modern-modal-header { flex-shrink: 0; }
        .conversation-view .modern-modal-body {
            flex: 1;
            min-height: 0;
            height: 0 !important;
            overflow: hidden;
        }
        .conversation-view .modern-left-col {
            flex: 0 1 auto;
            max-height: 35%;
            overflow-y: auto;
        }
        .conversation-view .modern-right-col { flex: 1; overflow: hidden; }
        .conversation-view .modern-chat-area {
            flex: 1;
            min-height: 0;
            max-height: none;
            overflow-y: auto;
        }
        .conversation-view .modern-reply-area { flex-shrink: 0; }
        .conversation-view .modern-message { flex-shrink: 0; }
        .conversation-view .modern-chat-area > :first-child { margin-top: auto; }
    }
    .message-toolbar .filter-buttons { display: flex; flex-wrap: nowrap; align-items: center; gap: 6px; min-width: 0; }
    .message-toolbar .filter-btn, .message-toolbar .btn-delete-all { flex: 0 0 auto; white-space: nowrap; }
    .message-toolbar .btn-delete-all { margin: 0; min-height: 44px; }
    @media (max-width: 480px) {
        .message-toolbar .filter-buttons { gap: 4px; }
        .message-toolbar .filter-btn, .message-toolbar .btn-delete-all { flex: 1 1 auto; min-width: 0; padding: 8px 5px; font-size: 10px; }
        .message-toolbar .btn-delete-all { gap: 3px; justify-content: center; }
    }
    /* Scoped premium message composer. */
    #composeModal { padding: 16px; box-sizing: border-box; background: rgba(5,7,9,.74); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
    #composeModal .compose-content { width: 100%; max-width: 520px; padding: 30px; border: 1px solid #d9b96a45; border-radius: 24px; background: radial-gradient(ellipse at top left,#d9b96a12,transparent 55%),#191b1e; color: #f6f3ed; box-shadow: 0 30px 100px #0009; max-height: calc(100dvh - 32px); overflow-y: auto; overscroll-behavior: contain; }
    .compose-intro { margin-bottom: 24px; padding-bottom: 22px; border-bottom: 1px solid #ffffff10; }
    .compose-emblem { display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; margin-bottom: 18px; border: 1px solid #d9b96a40; border-radius: 14px; background: #d9b96a12; color: #e6c477; font-size: 18px; }
    .compose-eyebrow { color: #c6ad73; font-size: 9px; font-weight: 700; letter-spacing: 2px; }
    #composeModal #composeTitle { color: #f6f3ed !important; font-size: 29px; letter-spacing: -.9px; line-height: 1.2; }
    .compose-description { margin: 10px 0 0; color: #a2a4a8; font-size: 13px; line-height: 1.6; }
    #composeModal .modern-close-btn { min-width: 38px; min-height: 38px; border: 1px solid #ffffff14 !important; color: #babdc2; background: #ffffff06; border-radius: 50%; }
    #composeModal form > div { margin-bottom: 20px !important; }
    #composeModal label { margin-bottom: 9px !important; color: #d7d7d9 !important; font-size: 12px; font-weight: 600; letter-spacing: .2px; }
    #composeModal .form-control { display: block; width: 100%; min-height: 49px; padding: 12px 14px; border: 1px solid #ffffff18; border-radius: 11px; background: #232529 !important; color: #f3f1ed !important; font: inherit; font-size: 16px; line-height: 1.5; transition: border-color .2s, box-shadow .2s; }
    #composeModal .form-control::placeholder { color: #85888f; font-size: 13px; }
    #composeModal textarea.form-control { min-height: 138px; resize: vertical; }
    #composeModal .form-control:focus { outline: none; border-color: #d9b96a; box-shadow: 0 0 0 3px #d9b96a18; }
    #composeModal .modern-btn-primary { display: flex; align-items: center; justify-content: center; gap: 14px; width: 100%; min-height: 51px; border: 1px solid #f1d58b; border-radius: 12px; background: linear-gradient(115deg,#f1d58b,#d9b45c); color: #262115; font-size: 14px; font-weight: 700; box-shadow: 0 6px 22px #d9b45c18; cursor: pointer; }
    #composeModal .modern-btn-primary:hover { filter: brightness(1.08); }
    #composeModal button:focus-visible { outline: 2px solid #efd18b; outline-offset: 3px; }
    @media (max-width: 540px) {
        #composeModal { padding: 12px; }
        #composeModal .compose-content { padding: 23px 20px; border-radius: 22px; max-height: calc(100dvh - 24px); }
        #composeModal #composeTitle { font-size: 26px; }
        #composeModal textarea.form-control { min-height: 115px; }
        .compose-intro { margin-bottom: 20px; padding-bottom: 18px; }
        .compose-eyebrow { letter-spacing: 1.5px; }
    }
    #filter-all.active {
        background: var(--card-bg);
        color: var(--text-main);
        border-color: var(--border-color);
        font-weight: 600;
    }
</style>

<?php if (isset($_GET['refund_action'])): ?>
    <?php if ($_GET['refund_action'] === 'approved'): ?>
    <dialog class="sage-notice-dialog" data-auto-notice data-clear-parameter="refund_action" aria-labelledby="refundApprovedTitle">
        <h2 id="refundApprovedTitle">Refund approved</h2>
        <p>Refund approved and recorded successfully.</p>
        <div class="sage-notice-actions"><button type="button" data-notice-close>OK</button></div>
    </dialog>
    <?php elseif ($_GET['refund_action'] === 'rejected'): ?>
    <div class="alert alert-info" style="background:rgba(52,152,219,0.12); border:1px solid rgba(52,152,219,0.3); color:#74b9ff; padding:12px 18px; border-radius:8px; margin-bottom:15px;"><i class="fas fa-info-circle"></i> Refund request declined. Customer has been notified.</div>
    <?php elseif ($_GET['refund_action'] === 'error'): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Failed to process refund. Please try again.</div>
    <?php elseif ($_GET['refund_action'] === 'reason_required'): ?>
    <div class="alert alert-warning" style="background:rgba(241,196,15,0.12); border:1px solid rgba(241,196,15,0.3); color:#f1c40f; padding:12px 18px; border-radius:8px; margin-bottom:15px;"><i class="fas fa-exclamation-triangle"></i> Please provide a reason for rejecting the refund.</div>
    <?php endif; ?>
<?php endif; ?>

<link rel="stylesheet" href="../assets/css/caterer-messages.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-messages.css'); ?>">
<?php if (!$viewed_message): ?>
<div class="card messages-inbox">
    <div class="message-toolbar">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="search" id="messageSearch" aria-label="Search messages" placeholder="Search messages..." oninput="filterMessages()">
        </div>
        <div class="filter-buttons">
<button type="button" onclick="openComposeModal()" class="message-tab compose-button" aria-label="New message" title="New message"><i class="fas fa-pen" aria-hidden="true"></i></button>

            <button class="filter-btn active" onclick="filterBy('all')" id="filter-all">All</button>
            <button class="filter-btn" onclick="filterBy('unread')" id="filter-unread">Unread</button>
            <button class="filter-btn" onclick="filterBy('today')" id="filter-today">Today's Bookings</button>
            <button type="button" id="trashTabButton" class="message-tab" onclick="showTab(document.getElementById('trash-tab').style.display === 'none' ? 'trash' : 'all')"><i class="fas fa-trash"></i> Trash</button>
            <button type="button" id="deleteAllMessages" class="btn-delete-all" onclick="updateMessageList(event, this, document.getElementById('trash-tab').style.display === 'none' ? 'delete_all' : 'empty_trash')"><i class="fas fa-trash" aria-hidden="true"></i> Delete All</button>
        </div>
    </div>

    <div id="all-tab">
        <?php if (mysqli_num_rows($messages_result) > 0): ?>
            <ul class="message-list" id="messageList">
                <?php while ($msg = mysqli_fetch_assoc($messages_result)): 
                    $is_unread = (int) $msg['unread_count'] > 0;
                    $has_order = !empty($msg['has_booking_today']);
                    $is_today = $has_order;
                ?>
                    <li class="message-item <?php echo $is_unread ? 'unread' : ''; ?> <?php echo $has_order ? 'has-order' : ''; ?> <?php echo $is_today ? 'today' : ''; ?>" 
                        data-sender="<?php echo strtolower(htmlspecialchars($msg['contact_first'] . ' ' . $msg['contact_last'])); ?>"
                        data-subject="<?php echo strtolower(htmlspecialchars(formatCatererMessageBookingReferences($msg['subject']))); ?>"
                        data-message="<?php echo strtolower(htmlspecialchars(substr(formatCatererMessageBookingReferences($msg['message']), 0, 100))); ?>"
                        data-unread="<?php echo $is_unread ? '1' : '0'; ?>"
                        data-today="<?php echo $is_today ? '1' : '0'; ?>">
                        <a href="?view=<?php echo $msg['id']; ?>" class="message-content message-open">
                            <?php renderMessageContactAvatar($msg[$msg['sender_id'] == $user_id ? 'receiver_profile_image' : 'sender_profile_image'] ?? ''); ?>
                            <div class="message-sender">
                                <?php echo htmlspecialchars($msg['contact_first'] . ' ' . $msg['contact_last']); ?>
                                <?php if (stripos($msg['message_type'] ?? '', 'refund_request') !== false || stripos($msg['subject'] ?? '', 'Refund Request') !== false): ?>
                                    <span style="background:rgba(231,76,60,0.18); color:#e74c3c; font-size:11px; font-weight:700; padding:2px 8px; border-radius:10px; border:1px solid rgba(231,76,60,0.35); white-space:nowrap;">
                                        <i class="fas fa-undo-alt"></i> Refund Request
                                    </span>
                                <?php endif; ?>

                                <?php if (isset($msg['unread_count']) && $msg['unread_count'] > 0): ?>
                                    <span class="unread-badge" aria-label="<?php echo (int) $msg['unread_count']; ?> unread messages" title="<?php echo (int) $msg['unread_count']; ?> unread messages"><?php echo (int) $msg['unread_count'] > 99 ? '99+' : (int) $msg['unread_count']; ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <div class="message-meta">
                            <div class="message-actions">
                                <a href="?action=delete&id=<?php echo $msg['id']; ?>" class="btn-delete" aria-label="Move message to trash" onclick="updateMessageList(event, this, 'delete')"><i class="fas fa-trash"></i></a>
                            </div>
                        </div>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php else: ?>
            <p style="text-align: center; color: var(--text-muted); padding: 20px;">No messages found.</p>
        <?php endif; ?>
        <p id="messageSearchEmpty" hidden style="text-align:center; color:var(--text-muted); padding:24px;" role="status">No messages match your search.</p>
    </div>


    <div id="trash-tab" style="display: none;">
        <?php if (mysqli_num_rows($deleted_result) > 0): ?>
            <ul class="message-list">
                <?php while ($msg = mysqli_fetch_assoc($deleted_result)): ?>
                    <li class="message-item">
                        <div class="message-content message-contact">
                            <?php renderMessageContactAvatar($msg[$msg['sender_id'] == $user_id ? 'receiver_profile_image' : 'sender_profile_image'] ?? ''); ?>
                            <div class="message-sender">
                                <?php echo htmlspecialchars($msg['sender_id'] == $user_id ? 'To: ' . $msg['receiver_name'] : 'From: ' . $msg['sender_name']); ?>
                            </div>
                        </div>
                        <div class="message-meta">
                            <a href="?action=restore&id=<?php echo $msg['id']; ?>" class="btn-view" style="background: var(--success);" onclick="updateMessageList(event, this, 'restore')">Restore</a>
                        </div>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php else: ?>
            <p style="text-align: center; color: var(--text-muted); padding: 20px;">Trash is empty.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<!-- Modern View Message Inline -->
<?php if ($viewed_message): ?>
<div class="modern-modal conversation-view" style="position: relative; max-width: none; max-height: none; box-shadow: none; border-radius: 12px; margin-top: 20px; border: 1px solid rgba(255, 255, 255, 0.05);">
    <div class="modern-modal-header">
        <?php $contact_prefix = $viewed_message['sender_id'] == $user_id ? 'receiver' : 'sender'; ?>
        <?php renderMessageContactAvatar($viewed_message[$contact_prefix . '_profile_image'] ?? ''); ?>
        <div class="conversation-identity">
            <h2><?php echo htmlspecialchars(trim($viewed_message[$contact_prefix . '_first'] . ' ' . $viewed_message[$contact_prefix . '_last'])); ?></h2>
            <?php if (!empty($_SESSION['message_sent_notice']) && (int) ($_GET['view'] ?? 0) === (int) $_SESSION['message_sent_notice']): ?>
                <span id="messageSentNotice" class="message-sent-notice" role="status" aria-live="polite"><i class="fas fa-check-circle" aria-hidden="true"></i> Message sent successfully</span>
                <?php unset($_SESSION['message_sent_notice']); ?>
            <?php endif; ?>
        </div>
        <a href="messages.php" class="modern-close-btn" aria-label="Back to inbox"><i class="fas fa-arrow-left"></i></a>
    </div>

    
    <div class="modern-modal-body" style="height: 700px; max-height: 70vh;">
        <!-- Left Column: Order Payment Details -->
        <?php if (!$portal_only_messaging && $order_balance && ($menu_change_request || $refund_request_msg)): ?>
        <div class="modern-left-col">
            <?php if (!$portal_only_messaging && $menu_change_request && $order_balance): ?>
            <div class="modern-card">
                <h4 style="color: var(--accent-gold); margin-bottom: 15px;"><i class="fas fa-random"></i> Menu Change Request</h4>
                <div style="display: grid; gap: 10px;">
                    <div>
                        <div style="font-size: 12px; color: #888;">Current Set</div>
                        <div style="font-weight: 600; color: #fff;"><?php echo htmlspecialchars($menu_change_request['current_set'] ?: 'Not specified'); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #888;">Requested Set</div>
                        <div style="font-weight: 700; color: var(--accent-gold);"><?php echo htmlspecialchars($menu_change_request['requested_set']); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #888;">Current Set Total</div>
                        <div style="font-weight: 600; color: #fff;">PHP <?php echo number_format($menu_change_request['current_total'], 2); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #888;">New Set Total</div>
                        <div style="font-weight: 600; color: #fff;">PHP <?php echo number_format($menu_change_request['new_total'], 2); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #888;">Already Paid</div>
                        <div style="font-weight: 600; color: #fff;">PHP <?php echo number_format($menu_change_request['already_paid'], 2); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #888;">Additional Needed</div>
                        <div style="font-weight: 700; color: var(--accent-gold);">PHP <?php echo number_format($menu_change_request['additional_needed'], 2); ?></div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 18px;">
                    <form method="POST" action="" onsubmit="return confirm('Approve this menu change request?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="menu_change_action" value="approve">
                        <input type="hidden" name="message_id" value="<?php echo intval($menu_change_request['message_id']); ?>">
                        <input type="hidden" name="order_id" value="<?php echo intval($order_balance['order_id']); ?>">
                        <button type="submit" style="width: 100%; padding: 12px; background: #2ecc71; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-weight: 700;">
                            <i class="fas fa-check-circle"></i> Approve
                        </button>
                    </form>
                    <form method="POST" action="" onsubmit="return confirm('Reject this menu change request?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="menu_change_action" value="reject">
                        <input type="hidden" name="message_id" value="<?php echo intval($menu_change_request['message_id']); ?>">
                        <input type="hidden" name="order_id" value="<?php echo intval($order_balance['order_id']); ?>">
                        <textarea name="reject_reason" rows="3" required class="modern-form-control" style="margin-bottom: 10px; min-height: 86px;" placeholder="Reason for rejection..."></textarea>
                        <button type="submit" style="width: 100%; padding: 12px; background: #c0392b; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-weight: 700;">
                            <i class="fas fa-times-circle"></i> Reject
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$portal_only_messaging && $refund_request_msg && $order_balance): ?>
            <div class="modern-card caterer-refund-card" style="background:rgba(231,76,60,0.06); border:1px solid rgba(231,76,60,0.25); margin-top:0;">
                <h4 style="color:#e74c3c; margin-bottom:15px; font-size:15px;">
                    <i class="fas fa-undo-alt"></i> Refund Request
                </h4>
                <?php
                // Parse refund amount from the message body
                $refund_msg_body = $refund_request_msg['message'] ?? '';
                $parsed_refund_amount = 0;
                if (preg_match('/Requested Refund Amount:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i', $refund_msg_body, $rm)) {
                    $parsed_refund_amount = floatval(str_replace(',', '', $rm[1]));
                }
                $refund_reason_text = '';
                if (preg_match('/Reason:\s*\n(.+)/si', $refund_msg_body, $rr)) {
                    $refund_reason_text = trim($rr[1]);
                }
                ?>
                <div style="display:grid; gap:10px; margin-bottom:16px;">
                    <div>
                        <div style="font-size:11px; color:#888;">Customer</div>
                        <div style="font-weight:600; color:#fff;">
                            <?php echo htmlspecialchars($refund_request_msg['sender_first'] . ' ' . $refund_request_msg['sender_last']); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:11px; color:#888;">Requested Refund Amount</div>
                        <div style="font-weight:700; color:#e74c3c; font-size:18px;">
                            &#8369;<?php echo number_format($parsed_refund_amount, 2); ?>
                        </div>
                    </div>
                    <?php if ($refund_reason_text): ?>
                    <div>
                        <div style="font-size:11px; color:#888;">Reason</div>
                        <div style="font-size:13px; color:#ddd; background:#111; padding:8px 12px; border-radius:8px; line-height:1.5;">
                            <?php echo nl2br(htmlspecialchars(formatCatererMessageBookingReferences($refund_reason_text))); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div style="display:grid; gap:10px;">
                    <!-- Approve -->
                    <form method="POST" action="" enctype="multipart/form-data" data-confirm-title="Approve refund?" data-confirm-message="Approve this refund of &#8369;<?php echo number_format($parsed_refund_amount, 2); ?>?" data-confirm-button="Approve refund">
                        <input type="hidden" name="csrf_token"    value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="refund_action" value="approve">
                        <input type="hidden" name="message_id"   value="<?php echo intval($refund_request_msg['id']); ?>">
                        <input type="hidden" name="order_id"     value="<?php echo intval($order_balance['order_id']); ?>">
                        <input type="hidden" name="customer_id"  value="<?php echo intval($refund_request_msg['sender_id']); ?>">
                        <input type="hidden" name="refund_amount" value="<?php echo $parsed_refund_amount; ?>">
                        <input type="hidden" name="view_param"   value="<?php echo isset($_GET['view']) ? intval($_GET['view']) : 0; ?>">
                        
                        <div style="margin-bottom: 12px;">
                            <label style="font-size: 11px; color: #888; display: block; margin-bottom: 5px;">Proof of Return (Required Image):</label>
                            <input type="file" name="proof_image" accept="image/*" required class="modern-form-control" style="padding: 8px;">
                        </div>

                        <button type="submit" style="width:100%; padding:12px; background:#2ecc71; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:700; font-size:14px;">
                            <i class="fas fa-check-circle"></i> Approve Refund
                        </button>
                    </form>

                    <!-- Reject -->
                    <div>
                        <button type="button" onclick="toggleRefundRejectForm()" id="refundRejectToggle"
                            style="width:100%; padding:12px; background:rgba(192,57,43,0.15); color:#e74c3c; border:1px solid rgba(192,57,43,0.4); border-radius:8px; cursor:pointer; font-weight:700; font-size:14px;">
                            <i class="fas fa-times-circle"></i> Decline Refund
                        </button>
                        <div id="refundRejectForm" style="display:none; margin-top:10px;">
                            <form method="POST" action="" data-confirm-title="Decline refund?" data-confirm-message="Decline this refund request?" data-confirm-button="Decline refund">
                                <input type="hidden" name="csrf_token"    value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="refund_action" value="reject">
                                <input type="hidden" name="message_id"   value="<?php echo intval($refund_request_msg['id']); ?>">
                                <input type="hidden" name="order_id"     value="<?php echo intval($order_balance['order_id']); ?>">
                                <input type="hidden" name="customer_id"  value="<?php echo intval($refund_request_msg['sender_id']); ?>">
                                <input type="hidden" name="refund_amount" value="<?php echo $parsed_refund_amount; ?>">
                                <input type="hidden" name="view_param"   value="<?php echo isset($_GET['view']) ? intval($_GET['view']) : 0; ?>">
                                <textarea name="reject_reason" rows="3" required class="modern-form-control"
                                    style="margin-bottom:8px; resize:none;"
                                    placeholder="Reason for declining..."></textarea>
                                <button type="submit" style="width:100%; padding:10px; background:#c0392b; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:700;">
                                    <i class="fas fa-paper-plane"></i> Send Decline Notice
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>
        
        <!-- Right Column: Chat -->
        <div class="modern-right-col">
            <div class="modern-chat-area">
                <?php if (!empty($thread_messages)): ?>
                    <?php foreach ($thread_messages as $msg): 
                        $is_me = ($msg['sender_id'] == $user_id);
                    ?>
                        <div class="modern-message <?php echo $is_me ? 'caterer' : 'customer'; ?>">
                            <div class="modern-message-bubble" role="button" tabindex="0" aria-expanded="false" aria-controls="message-time-<?php echo (int) $msg['id']; ?>" aria-label="Show or hide message time">
                                <div style="color: #ddd;">
                                    <?php 
                                        $msg_text = htmlspecialchars(formatCatererMessageBookingReferences($msg['message']));
                                        $msg_text = preg_replace('/\[attachment:\s*(.+?)\]/', '<br><a href="../$1" target="_blank" style="display:inline-block; margin-top:10px;"><img src="../$1" style="max-width:200px; border-radius:8px; border:1px solid rgba(255,255,255,0.1);"/></a>', $msg_text);
                                        echo nl2br($msg_text); 
                                    ?>
                                </div>
                            </div>
                            <div class="conversation-time" id="message-time-<?php echo (int) $msg['id']; ?>" hidden><?php echo date('M j Â· g:i A', strtotime($msg['created_at'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="modern-reply-area">
                <?php $reply_role = ($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_role'] : $viewed_message['sender_role']; ?>
                <?php if (!$portal_only_messaging || $reply_role === 'portal'): ?>
                <form method="POST" action="" class="conversation-composer" data-draft-user="<?php echo (int) $user_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="receiver_id" value="<?php echo ($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_id'] : $viewed_message['sender_id']; ?>">
                    <input type="hidden" name="original_message_id" value="<?php echo $viewed_message['original_message_id'] ?: $viewed_message['id']; ?>">
                    <input type="hidden" name="order_id" value="<?php echo isset($order_balance['order_id']) ? $order_balance['order_id'] : (isset($viewed_message['order_id']) ? $viewed_message['order_id'] : ''); ?>">
                    
                    <input type="hidden" name="subject" value="Re: <?php echo htmlspecialchars($viewed_message['subject']); ?>">
                    
                    <textarea name="message" class="modern-form-control" rows="1" aria-label="Your reply" placeholder="Message..." required oninput="this.style.height='44px'; this.style.height=Math.min(this.scrollHeight,120)+'px';"></textarea>
                    <button type="submit" name="send_message" class="modern-btn-primary chat-send" aria-label="Send reply" title="Send reply"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
                </form>
                <?php else: ?>
                <p>Your free trial or subscription has ended. You can only message Portal Admin until your subscription is active.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Compose Modal -->
<dialog id="message-action-modal" aria-labelledby="message-action-title" aria-describedby="message-action-description">
    <div class="message-action-heading">
        <h3 id="message-action-title">Move conversations to Trash?</h3>
        <button type="button" class="message-action-close" aria-label="Close confirmation">&times;</button>
    </div>
    <p id="message-action-description"></p>
    <div class="message-action-footer">
        <button type="button" class="message-action-cancel">Cancel</button>
        <button type="button" class="message-action-confirm">Move to Trash</button>
    </div>
</dialog>
<div id="composeModal" class="compose-modal" role="dialog" aria-modal="true" aria-labelledby="composeTitle">
    <div class="compose-content"><div class="compose-intro" style="margin-bottom:18px; padding-bottom:0; border-bottom:0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0;">
            <h3 id="composeTitle" style="margin: 0; color: var(--text-main);">New Message</h3>
            <button type="button" onclick="closeComposeModal()" class="modern-close-btn" aria-label="Close new message" style="border:0; cursor:pointer;">&times;</button>
        </div>
        
        </div><form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; color: var(--text-main);" for="composeRecipient">Recipient</label>
                <select id="composeRecipient" name="receiver_id" class="form-control" required style="background: var(--input-bg); color: var(--text-main);">
                    <option value="" disabled selected hidden><?php echo $portal_only_messaging ? 'Select Portal Admin' : 'Select Recipient'; ?></option>
                    <?php
                    $recipient_groups = ['portal' => [], 'customer' => []];
                    if ($users_result) {
                        mysqli_data_seek($users_result, 0);
                        while ($recipient_user = mysqli_fetch_assoc($users_result)) {
                            $recipient_groups[$recipient_user['role']][] = $recipient_user;
                        }
                    }
                    foreach (['portal' => 'Portal Admin', 'customer' => 'Customers'] as $recipient_role => $group_label):
                        if (empty($recipient_groups[$recipient_role])) continue;
                    ?>
                        <optgroup label="<?php echo $group_label; ?>">
                            <?php foreach ($recipient_groups[$recipient_role] as $recipient_user): ?>
                                <option value="<?php echo intval($recipient_user['id']); ?>"><?php echo htmlspecialchars($recipient_user['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <div id="recipientPicker" hidden>
                    <button type="button" id="recipientToggle" aria-expanded="false" aria-controls="recipientPanel"><span>Select Recipient</span><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
                    <div id="recipientPanel" hidden>
                        <label for="recipientSearch" class="recipient-search-label">Find a recipient</label>
                        <input type="search" id="recipientSearch" placeholder="Search name..." autocomplete="off">
                        <div id="recipientOptions" aria-label="Recipients"></div>
                        <p id="recipientEmpty" hidden>No recipients found.</p>
                    </div>
                    <p id="recipientError" role="alert" hidden>Please select a recipient.</p>
                </div>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; color: var(--text-main);" for="composeSubject">Subject</label>
                <input id="composeSubject" placeholder="What would you like to discuss?" type="text" name="subject" class="form-control" required style="background: var(--input-bg); color: var(--text-main);">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; color: var(--text-main);" for="composeMessage">Your message</label>
                <textarea id="composeMessage" placeholder="Write your message here..." name="message" class="form-control" rows="5" required style="background: var(--input-bg); color: var(--text-main);"></textarea>
            </div>
            
            <button type="submit" name="send_message" class="modern-btn-primary">Send message <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
        </form>
    </div>
</div>

<script>
    // Open the conversation at its newest message, after images finish loading too.
    const sentNotice = document.getElementById('messageSentNotice');
    if (sentNotice) window.setTimeout(() => sentNotice.remove(), 1000);
    const chatArea = document.querySelector('.modern-chat-area');
    if (chatArea) {
        function toggleMessageTime(event) {
            if (event.target.closest('a, input, textarea, button')) return;
            const bubble = event.target.closest('.modern-message-bubble');
            if (!bubble || !chatArea.contains(bubble)) return;
            if (event.type === 'keydown') {
                if (event.target !== bubble || !['Enter', ' '].includes(event.key)) return;
                event.preventDefault();
            }
            const time = bubble.parentElement.querySelector('.conversation-time');
            if (!time) return;
            time.hidden = !time.hidden;
            bubble.setAttribute('aria-expanded', String(!time.hidden));
        }
        chatArea.addEventListener('click', toggleMessageTime);
        chatArea.addEventListener('keydown', toggleMessageTime);
        const scrollToLatest = () => { chatArea.scrollTop = chatArea.scrollHeight; };
        scrollToLatest();
        window.addEventListener('load', scrollToLatest, { once: true });
        if (window.matchMedia('(max-width: 768px)').matches) {
            const chatMain = chatArea.closest('.main-content');
            const fitVisibleScreen = () => {
                const viewport = window.visualViewport;
                chatMain.style.setProperty('--mobile-chat-height', `${viewport ? viewport.height : window.innerHeight}px`);
                chatMain.style.setProperty('--mobile-chat-top', `${viewport ? viewport.offsetTop : 0}px`);
            };
            fitVisibleScreen();
            window.addEventListener('resize', fitVisibleScreen);
            window.visualViewport?.addEventListener('resize', fitVisibleScreen);
            window.visualViewport?.addEventListener('scroll', fitVisibleScreen);
            let followLatest = true;
            chatArea.addEventListener('scroll', () => {
                followLatest = chatArea.scrollHeight - chatArea.clientHeight - chatArea.scrollTop < 48;
            }, { passive: true });
            // Keep the newest message visible as images, fonts or the composer resize.
            const chatResizeObserver = new ResizeObserver(() => {
                if (followLatest) scrollToLatest();
            });
            chatResizeObserver.observe(chatArea);
            chatArea.querySelectorAll('.modern-message').forEach(message => chatResizeObserver.observe(message));
            requestAnimationFrame(scrollToLatest);
        }
    }

    let activeMessageFilter = 'all';
    let messageListBusy = false;
    function confirmMessageAction(action, description) {
        const modal = document.getElementById('message-action-modal');
        const confirm = modal.querySelector('.message-action-confirm');
        const previousFocus = document.activeElement;
        document.getElementById('message-action-title').textContent = action === 'empty_trash'
            ? 'Delete conversations permanently?' : action === 'delete_all'
                ? 'Move all conversations to Trash?' : 'Move conversation to Trash?';
        document.getElementById('message-action-description').textContent = description;
        confirm.textContent = action === 'empty_trash' ? 'Delete permanently' : 'Move to Trash';
        return new Promise(resolve => {
            let accepted = false;
            confirm.onclick = () => { accepted = true; modal.close(); };
            modal.querySelector('.message-action-cancel').onclick = () => modal.close();
            modal.querySelector('.message-action-close').onclick = () => modal.close();
            modal.addEventListener('close', () => {
                previousFocus?.focus();
                resolve(accepted);
            }, { once: true });
            modal.showModal();
            modal.querySelector('.message-action-cancel').focus();
        });
    }
    async function updateMessageList(event, link, action) {
        event.preventDefault();
        if (messageListBusy) return;
        const confirmation = { delete: 'Move to trash?', delete_all: 'Move ALL inbox conversations to Trash? This includes conversations hidden by search or filters.', empty_trash: 'Delete ALL conversations in Trash? You will no longer be able to restore them.' }[action];
        messageListBusy = true;
        if (confirmation && !await confirmMessageAction(action, confirmation)) {
            messageListBusy = false;
            return;
        }
        link.setAttribute('aria-disabled', 'true');
        const row = link.closest('.message-item');
        try {
            const response = await fetch('messages.php', {
                method: 'POST',
                body: new URLSearchParams({
                    message_list_action: action,
                    id: link.href ? new URL(link.href).searchParams.get('id') : '',
                    csrf_token: <?php echo json_encode($csrf_token); ?>
                })
            });
            if (!response.ok || !(await response.json()).success) throw new Error('update');
            if (row) row.remove();
            // Refresh only the two lists so server grouping and Trash stay accurate.
            const listsResponse = await fetch('messages.php', { cache: 'no-store' });
            if (!listsResponse.ok) throw new Error('refresh');
            const page = new DOMParser().parseFromString(await listsResponse.text(), 'text/html');
            if (!page.getElementById('all-tab') || !page.getElementById('trash-tab')) throw new Error('refresh');
            const y = window.scrollY;
            const main = document.querySelector('.main-content');
            const mainY = main?.scrollTop || 0;
            for (const id of ['all-tab', 'trash-tab']) {
                document.getElementById(id).replaceChildren(...page.getElementById(id).childNodes);
            }
            filterMessages();
            if (main) main.scrollTop = mainY;
            window.scrollTo(0, y);
        } catch (error) {
            alert('Unable to update the message list. Please try again.');
        } finally {
            messageListBusy = false;
            link.removeAttribute('aria-disabled');
        }
    }
    const messageListPositionKey = 'caterer-message-list-position';
    function keepMessageListPosition(confirmDelete) {
        if (confirmDelete && !confirm('Move to trash?')) return false;
        try {
            sessionStorage.setItem(messageListPositionKey, JSON.stringify({
                time: Date.now(),
                y: window.scrollY,
                mainY: document.querySelector('.main-content')?.scrollTop || 0,
                tab: document.getElementById('trash-tab').style.display === 'none' ? 'all' : 'trash',
                filter: activeMessageFilter,
                search: document.getElementById('messageSearch').value
            }));
        } catch (error) { /* Navigation still works if storage is unavailable. */ }
        return true;
    }
    if (new URLSearchParams(window.location.search).has('list_updated')) {
        try {
            const saved = JSON.parse(sessionStorage.getItem(messageListPositionKey));
            sessionStorage.removeItem(messageListPositionKey);
            if (saved && Date.now() - saved.time < 60000) {
                showTab(saved.tab);
                document.getElementById('messageSearch').value = saved.search;
                filterBy(saved.filter);
                const restorePosition = () => {
                    const main = document.querySelector('.main-content');
                    if (main) main.scrollTop = saved.mainY;
                    window.scrollTo(0, saved.y);
                };
                requestAnimationFrame(restorePosition);
                window.addEventListener('load', restorePosition, { once: true });
            }
        } catch (error) { /* A missing saved position leaves the default view. */ }
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('list_updated');
        history.replaceState(history.state, '', cleanUrl);
    }
    function filterBy(filter) {
        activeMessageFilter = filter;
        showTab('all');
        document.querySelectorAll('.filter-btn').forEach(button => {
            const active = button.id === 'filter-' + filter;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', String(active));
        });
        filterMessages();
    }
    function filterMessages() {
        const search = document.getElementById('messageSearch');
        if (!search) return;
        const query = search.value.toLowerCase().trim();
        const items = document.querySelectorAll('#messageList .message-item');
        let visible = 0;
        items.forEach(item => {
            const matchesSearch = [item.dataset.sender, item.dataset.subject, item.dataset.message].join(' ').toLowerCase().includes(query);
            const matchesFilter = activeMessageFilter === 'all' || (activeMessageFilter === 'unread' && item.dataset.unread === '1') || (activeMessageFilter === 'today' && item.dataset.today === '1' && item.classList.contains('has-order'));
            item.style.display = matchesSearch && matchesFilter ? '' : 'none';
            if (matchesSearch && matchesFilter) visible++;
        });
        document.getElementById('messageSearchEmpty').hidden = items.length === 0 || visible > 0;
        document.querySelectorAll('#trash-tab .message-item').forEach(item => {
            item.style.display = item.querySelector('.message-sender').textContent.toLowerCase().includes(query) ? '' : 'none';
        });
    }
    function showTab(tabName) {
        if (tabName === 'all') {
            document.getElementById('all-tab').style.display = 'block';
            document.getElementById('trash-tab').style.display = 'none';
            document.getElementById('trashTabButton').classList.remove('active');
        } else {
            document.getElementById('all-tab').style.display = 'none';
            document.getElementById('trash-tab').style.display = 'block';
            document.getElementById('trashTabButton').classList.add('active');
        }
        const trash = tabName === 'trash';
        document.getElementById('trashTabButton').setAttribute('aria-pressed', String(trash));
        document.querySelectorAll('.filter-btn').forEach(button => {
            const selected = !trash && button.id === 'filter-' + activeMessageFilter;
            button.classList.toggle('active', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
        document.getElementById('deleteAllMessages').innerHTML = '<i class="fas fa-trash" aria-hidden="true"></i> ' + (trash ? 'Empty Trash' : 'Delete All');
        filterMessages();
    }

    let inboxRefreshing = false;
    async function refreshInbox() {
        if (!document.getElementById('all-tab') || document.hidden || messageListBusy || inboxRefreshing) return;
        inboxRefreshing = true;
        try {
            const response = await fetch('messages.php', { cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok || response.redirected) return;
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (messageListBusy) return;
            for (const id of ['all-tab', 'trash-tab']) {
                const current = document.getElementById(id);
                const incoming = page.getElementById(id);
                if (!incoming) return;
                const position = current.scrollTop;
                current.replaceChildren(...incoming.childNodes);
                current.scrollTop = position;
            }
            filterMessages();
        } catch (error) { /* Retain the current inbox during connection failures. */ }
        finally { inboxRefreshing = false; }
    }
    window.setInterval(refreshInbox, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshInbox(); });
    const recipientSelect = document.getElementById('composeRecipient');
    const recipientToggle = document.getElementById('recipientToggle');
    const recipientPanel = document.getElementById('recipientPanel');
    const recipientSearch = document.getElementById('recipientSearch');
    function setRecipientPanel(open) {
        recipientPanel.hidden = !open;
        recipientToggle.setAttribute('aria-expanded', String(open));
        if (open) {
            recipientSearch.value = '';
            renderRecipients();
            recipientSearch.focus();
        }
    }
    function renderRecipients() {
        const list = document.getElementById('recipientOptions');
        list.replaceChildren();
        const query = recipientSearch.value.trim().toLowerCase();
        let count = 0;
        for (const group of recipientSelect.querySelectorAll('optgroup')) {
            const options = [...group.querySelectorAll('option')].filter(option => option.textContent.toLowerCase().includes(query));
            if (!options.length) continue;
            const heading = document.createElement('h4');
            heading.textContent = group.label;
            list.append(heading);
            for (const option of options) {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = option.textContent;
                button.classList.toggle('selected', recipientSelect.value === option.value);
                button.setAttribute('aria-pressed', String(recipientSelect.value === option.value));
                button.addEventListener('click', () => {
                    recipientSelect.value = option.value;
                    recipientSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    recipientToggle.querySelector('span').textContent = option.textContent;
                    document.getElementById('recipientError').hidden = true;
                    setRecipientPanel(false);
                    recipientToggle.focus();
                });
                list.append(button);
                count++;
            }
        }
        document.getElementById('recipientEmpty').hidden = count > 0;
    }
    if (recipientSelect) {
        recipientSelect.hidden = true;
        recipientSelect.required = false;
        document.getElementById('recipientPicker').hidden = false;
        document.querySelector('label[for="composeRecipient"]').htmlFor = 'recipientToggle';
        recipientToggle.addEventListener('click', () => setRecipientPanel(recipientPanel.hidden));
        recipientSearch.addEventListener('input', renderRecipients);
        recipientPanel.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                setRecipientPanel(false);
                recipientToggle.focus();
            }
        });
        recipientSelect.form.addEventListener('submit', event => {
            if (recipientSelect.value) return;
            event.preventDefault();
            document.getElementById('recipientError').hidden = false;
            setRecipientPanel(true);
        });
    }
    let composeReturnFocus; let composeBodyOverflow;
    function openComposeModal() {
        composeReturnFocus = document.activeElement;
        composeBodyOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden';
        document.getElementById('composeModal').style.display = 'flex';
        document.getElementById('recipientToggle').focus();
    }
    function closeComposeModal() {
        setRecipientPanel(false);
        document.getElementById('composeModal').style.display = 'none';
        document.body.style.overflow = composeBodyOverflow || '';
        if (composeReturnFocus) composeReturnFocus.focus();
    }
    document.addEventListener('keydown', function(event) {
        const modal = document.getElementById('composeModal');
        if (modal.style.display !== 'flex') return;

        if (event.key === 'Tab') {
            const controls = [...modal.querySelectorAll('button, select, input:not([type="hidden"]), textarea')].filter(control => control.getClientRects().length && !control.disabled);
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    // Keep the compose dialog open on backdrop clicks to protect the draft.

    function toggleRefundRejectForm() {
        const form   = document.getElementById('refundRejectForm');
        const toggle = document.getElementById('refundRejectToggle');
        if (!form) return;
        if (form.style.display === 'none' || form.style.display === '') {
            form.style.display = 'block';
            toggle.innerHTML = '<i class="fas fa-chevron-up"></i> Cancel';
            toggle.style.background = 'rgba(255,255,255,0.05)';
            toggle.style.color = '#aaa';
            toggle.style.borderColor = 'rgba(255,255,255,0.1)';
        } else {
            form.style.display = 'none';
            toggle.innerHTML = '<i class="fas fa-times-circle"></i> Decline Refund';
            toggle.style.background = 'rgba(192,57,43,0.15)';
            toggle.style.color = '#e74c3c';
            toggle.style.borderColor = 'rgba(192,57,43,0.4)';
        }
    }
</script>

<?php
$sent_reply_draft = $_SESSION['sent_reply_draft'] ?? null;
unset($_SESSION['sent_reply_draft']);
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.conversation-composer');
    if (!form) return;
    const input = form.querySelector('textarea[name="message"]');
    const thread = form.elements.original_message_id.value;
    const receiver = form.elements.receiver_id.value;
    const key = `caterer-reply:${form.dataset.draftUser}:${receiver}:${thread}`;
    const sent = <?php echo json_encode($sent_reply_draft, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    try {
        const draft = localStorage.getItem(key);
        if (sent && String(sent.thread) === thread && String(sent.receiver) === receiver && draft === sent.message) {
            localStorage.removeItem(key);
        } else if (draft !== null) {
            input.value = draft;
            input.style.height = '44px';
            input.style.height = Math.min(input.scrollHeight, 120) + 'px';
        }
    } catch (error) { /* Messaging remains usable if storage is unavailable. */ }
    const save = () => {
        try {
            if (input.value) localStorage.setItem(key, input.value);
            else localStorage.removeItem(key);
        } catch (error) { /* Storage may be unavailable or full. */ }
    };
    input.addEventListener('input', save);
    form.addEventListener('submit', save);
});
</script>
<link rel="stylesheet" href="../assets/css/sage-notices.css?v=20261027-2">
<script src="../assets/js/sage-notices.js?v=20261027-2"></script>
<script src="../assets/js/caterer-refund-panel.js?v=20261027-2"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

