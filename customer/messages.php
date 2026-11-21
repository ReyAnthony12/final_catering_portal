<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/payment_functions.php';
require_once __DIR__ . '/../includes/order_reference_helper.php';
requireLogin();
// Fetch the latest conversation layout when accessed through a proxy or tunnel.
header('Cache-Control: no-store, private, max-age=0');

if (getUserRole() !== 'customer') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$booking_chat = null;
$booking_chat_id = (int) ($_GET['order_id'] ?? $_GET['return_booking'] ?? 0);
if ($booking_chat_id > 0) {
    $chat_booking_result = mysqli_query($conn, "SELECT id, store_id, order_number FROM bookings WHERE id = $booking_chat_id AND customer_id = '$user_id' LIMIT 1");
    $booking_chat = $chat_booking_result ? mysqli_fetch_assoc($chat_booking_result) : null;
}
$return_caterer_id = max(0, (int) ($_GET['return_caterer'] ?? 0));
$return_caterer_query = $return_caterer_id > 0 ? '&return_caterer=' . $return_caterer_id : '';
$csrf_token = generateCSRFToken();

// Handle send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        die('Invalid security token. Please refresh the page.');
    }
    
    $receiver_id = mysqli_real_escape_string($conn, $_POST['receiver_id']);
    $subject = mysqli_real_escape_string($conn, $_POST['subject']);
    $message = mysqli_real_escape_string($conn, $_POST['message']);
    
    // Handle payment receipt upload with validation
    $payment_receipt = '';
    if (isset($_FILES['payment_receipt']) && $_FILES['payment_receipt']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/payments/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES['payment_receipt']['type'];
        if (!in_array($file_type, $allowed_types)) {
            die('Invalid file type. Only JPG, PNG, and GIF are allowed.');
        }
        
        // Validate file size (2MB max)
        if ($_FILES['payment_receipt']['size'] > 2097152) {
            die('File too large. Maximum size is 2MB.');
        }
        
        $filename = 'receipt_' . time() . '_' . basename($_FILES['payment_receipt']['name']);
        $target_file = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['payment_receipt']['tmp_name'], $target_file)) {
            $payment_receipt = 'uploads/payments/' . $filename;
            $message .= mysqli_real_escape_string($conn, "\n\n[attachment: " . $payment_receipt . "]");
        }
    }
    
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
    
    $new_message_id = null;
    if (mysqli_query($conn, $query)) {
        $new_message_id = mysqli_insert_id($conn);
        // Get sender name for notification
        $sender_query = mysqli_query($conn, "SELECT first_name, last_name FROM users WHERE id = '$user_id'");
        $sender = mysqli_fetch_assoc($sender_query);
        $sender_name = $sender['first_name'] . ' ' . $sender['last_name'];
        
        // Create notification for receiver (caterer)
        $notif_title = $payment_receipt ? 'Payment Received from ' . $sender_name : 'New Message from ' . $sender_name;
        createNotification(
            $receiver_id,
            $payment_receipt ? 'payment_received' : 'new_message',
            $notif_title,
            $subject,
            'messages.php'
        );

        if (function_exists('notifyPortalAdmins')) {
            notifyPortalAdmins(
                $payment_receipt ? 'payment_received' : 'new_message',
                $payment_receipt ? 'Customer Sent Payment Receipt' : 'Customer Sent a Message',
                $payment_receipt
                    ? $sender_name . ' sent a payment receipt through messages.'
                    : $sender_name . ' sent a new message to a caterer.',
                'messages.php'
            );
        }
    }
    
    // Redirect back to the same conversation, not the main messages list
    $redirect_view_id = $new_message_id ?: intval($original_message_id);
    header('Location: messages.php?view=' . $redirect_view_id . ($booking_chat ? '&return_booking=' . (int) $booking_chat['id'] : '') . $return_caterer_query);
    exit();
}

// Handle refund request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_refund_request'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        die('Invalid security token. Please refresh the page.');
    }

    $receiver_id   = mysqli_real_escape_string($conn, $_POST['receiver_id']);
    $order_id      = mysqli_real_escape_string($conn, $_POST['order_id']);
    // Refund requests always use the verified amount actually paid for this booking.
    $refund_booking_id = (int) ($_POST['order_id'] ?? 0);
    $refund_receiver_id = (int) ($_POST['receiver_id'] ?? 0);
    $refund_owned_result = mysqli_query($conn, "SELECT id FROM bookings WHERE id = $refund_booking_id AND customer_id = '$user_id' AND store_id = $refund_receiver_id LIMIT 1");
    if (!$refund_owned_result || !mysqli_fetch_assoc($refund_owned_result)) {
        http_response_code(403);
        exit('Booking not found.');
    }
    $refund_summary = getOrderPaymentSummary($refund_booking_id);
    $refund_amount = round((float) ($refund_summary['total_paid'] ?? 0), 2);
    if (!is_finite($refund_amount) || $refund_amount <= 0) {
        header('Location: messages.php?refund_error=1');
        exit();
    }
    $refund_reason = mysqli_real_escape_string($conn, trim($_POST['refund_reason']));
    $orig_msg_id   = !empty($_POST['original_message_id']) ? intval($_POST['original_message_id']) : null;

    // Get customer name
    $cname_q       = mysqli_query($conn, "SELECT first_name, last_name FROM users WHERE id = '$user_id'");
    $cname         = mysqli_fetch_assoc($cname_q);
    $customer_name = $cname['first_name'] . ' ' . $cname['last_name'];

    // Get order number for subject
    $ord_q      = mysqli_query($conn, "SELECT order_number FROM bookings WHERE id = '$order_id' AND customer_id = '$user_id'");
    $ord_row    = mysqli_fetch_assoc($ord_q);
    $order_number = $ord_row ? $ord_row['order_number'] : '';

    $subject      = 'Refund Request: ' . $order_number;
    $message_body = "REFUND REQUEST\n\n"
        . "Customer: " . $customer_name . "\n"
        . "Order: " . $order_number . "\n"
        . "Requested Refund Amount: PHP " . number_format($refund_amount, 2) . "\n\n"
        . "Reason:\n" . $refund_reason;

    $orig_clause = $orig_msg_id ? "'$orig_msg_id'" : "NULL";
    $q = "INSERT INTO messages (sender_id, receiver_id, order_id, subject, message, message_type, original_message_id)
          VALUES ('$user_id', '$receiver_id', '$order_id', '$subject', '$message_body', 'refund_request', $orig_clause)";

    if (mysqli_query($conn, $q)) {
        $new_msg_id = mysqli_insert_id($conn);

        createNotification(
            $receiver_id,
            'refund_request',
            'Refund Request from ' . $customer_name,
            $customer_name . ' requested a refund of PHP ' . number_format($refund_amount, 2) . ' for ' . $order_number,
            'messages.php'
        );
        if (function_exists('notifyPortalAdmins')) {
            notifyPortalAdmins('refund_request', 'Customer Refund Request',
                $customer_name . ' submitted a refund request for ' . $order_number . '.', 'messages.php');
        }
        
        header('Location: messages.php?view=' . $new_msg_id . '&refund_sent=1');
        exit();
    }
    header('Location: messages.php?view=' . ($orig_msg_id ?? '') . '&refund_error=1');
    exit();
}

// Handle delete/restore
if (isset($_GET['action'])) {
    $message_id = isset($_GET['id']) ? mysqli_real_escape_string($conn, $_GET['id']) : '';
    
    if ($_GET['action'] === 'delete' && isset($_GET['id'])) {
        mysqli_query($conn, "UPDATE messages SET is_deleted = TRUE WHERE id = '$message_id' AND (sender_id = '$user_id' OR receiver_id = '$user_id')");
    } elseif ($_GET['action'] === 'restore' && isset($_GET['id'])) {
        mysqli_query($conn, "UPDATE messages SET is_deleted = FALSE WHERE id = '$message_id' AND (sender_id = '$user_id' OR receiver_id = '$user_id')");
    } elseif ($_GET['action'] === 'mark_all_read') {
        mysqli_query($conn, "UPDATE messages SET is_read = TRUE WHERE receiver_id = '$user_id' AND is_read = FALSE");
    } elseif ($_GET['action'] === 'delete_all_messages') {
        mysqli_query($conn, "UPDATE messages SET is_deleted = TRUE WHERE (sender_id = '$user_id' OR receiver_id = '$user_id')");
    }
    
    header('Location: messages.php');
    exit();
}

// Handle view message
$viewed_message = null;
$thread_messages = [];
$order_balance = null; // Caterer order balance info

// Open the selected caterer, including a conversation with no messages yet.
$compose_caterer = null;
if ($booking_chat && !isset($_GET['view'])) {
    $chat_store_id = (int) $booking_chat['store_id'];
    $existing_chat = mysqli_query($conn, "SELECT id FROM messages WHERE order_id = $booking_chat_id AND ((sender_id = '$user_id' AND receiver_id = $chat_store_id) OR (receiver_id = '$user_id' AND sender_id = $chat_store_id)) AND is_deleted = FALSE ORDER BY created_at DESC, id DESC LIMIT 1");
    $chat_message = $existing_chat ? mysqli_fetch_assoc($existing_chat) : null;
    if ($chat_message) { $_GET['view'] = $chat_message['id']; }
    else { $_GET['store_id'] = $chat_store_id; }
}
if (!isset($_GET['view']) && isset($_GET['store_id'])) {
    $contact_id = (int) $_GET['store_id'];
    $contact_result = mysqli_query($conn, "SELECT u.id, u.first_name, sd.business_name
        FROM users u JOIN store_details sd ON sd.user_id = u.id
        WHERE u.id = $contact_id AND u.role IN ('caterer', 'store')");
    $compose_caterer = mysqli_fetch_assoc($contact_result) ?: null;
    if ($compose_caterer && !$booking_chat) {
        $customer_id = (int) $user_id;
        $existing_result = mysqli_query($conn, "SELECT id FROM messages
            WHERE ((sender_id = $customer_id AND receiver_id = $contact_id)
                OR (sender_id = $contact_id AND receiver_id = $customer_id))
                AND is_deleted = FALSE ORDER BY created_at DESC, id DESC LIMIT 1");
        if ($existing = mysqli_fetch_assoc($existing_result)) {
            header('Location: messages.php?view=' . (int) $existing['id'] . $return_caterer_query);
            exit();
        }
    }
}

if (isset($_GET['view'])) {
    $message_id = mysqli_real_escape_string($conn, $_GET['view']);
    
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
    
    // Mark all unread messages from this sender as read
    if ($viewed_message) {
        $other_user_id = ($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_id'] : $viewed_message['sender_id'];
        mysqli_query($conn, "UPDATE messages SET is_read = TRUE WHERE sender_id = '$other_user_id' AND receiver_id = '$user_id' AND is_read = FALSE");
    }
    
    // Get order balance info if message is related to an order
    if ($viewed_message) {
        // Check if subject contains order number (e.g., "Regarding Order ORD-XXX")
        preg_match('/ORD-[A-Z0-9]+/', $viewed_message['subject'] ?? '', $matches);
        if (empty($matches)) {
            // Also check message content
            preg_match('/ORD-[A-Z0-9]+/', $viewed_message['message'] ?? '', $matches);
        }
        
        if (!empty($viewed_message['order_id']) || !empty($matches)) {
            $order_number = mysqli_real_escape_string($conn, $matches[0] ?? '');
            $linked_order_id = (int) ($viewed_message['order_id'] ?? 0);
            $booking_lookup = $linked_order_id > 0 ? "id = $linked_order_id" : "order_number = '$order_number'";
            $other_user_id = ($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_id'] : $viewed_message['sender_id'];
            $order_query = "SELECT * FROM bookings WHERE $booking_lookup AND ((customer_id = '$user_id' AND store_id = '$other_user_id') OR (customer_id = '$other_user_id' AND store_id = '$user_id'))";
            $order_result = mysqli_query($conn, $order_query);
            if ($order_row = mysqli_fetch_assoc($order_result)) {
                // Use enhanced payment summary
                $payment_summary = getOrderPaymentSummary($order_row['id']);
                $payment_history = getOrderPaymentHistory($order_row['id']);
                
                $order_balance = [
                    'order_id' => $order_row['id'],
                    'order_number' => $order_row['order_number'],
                    'total_amount' => $payment_summary['total_amount'],
                    'down_payment_required' => $payment_summary['down_payment_required'],
                    'down_payment_paid' => $payment_summary['down_payment_paid'],
                    'down_payment_remaining' => $payment_summary['down_payment_remaining'],
                    'total_paid' => $payment_summary['total_paid'],
                    'balance' => $payment_summary['remaining_balance'],
                    'payment_status' => $payment_summary['payment_status'],
                    'progress_percentage' => $payment_summary['progress_percentage'],
                    'payment_history' => $payment_history
                ];
            }
        }
    }
    
    if ($viewed_message) {
        $other_user_id = ($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_id'] : $viewed_message['sender_id'];
        $is_trash_view = isset($_GET['from_trash']) ? "m.is_deleted = TRUE" : "m.is_deleted = FALSE";
        $where_clause = "((m.sender_id = '$user_id' AND m.receiver_id = '$other_user_id') OR (m.sender_id = '$other_user_id' AND m.receiver_id = '$user_id')) AND $is_trash_view";

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
    }
}

if ($compose_caterer && !$viewed_message) {
    $viewed_message = [
        'id' => '', 'sender_id' => $user_id,
        'receiver_id' => $compose_caterer['id'],
        'receiver_business' => $compose_caterer['business_name'],
        'receiver_first' => $compose_caterer['first_name'],
        'order_id' => $booking_chat['id'] ?? null,
        'subject' => $booking_chat ? 'Regarding Booking #' . formatCustomerBookingNumber($booking_chat['id'], $booking_chat['order_number']) : 'Catering inquiry',
    ];
}

// Get received messages - GROUPED BY CONVERSATION (Caterer + Subject/Order)
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM messages LIKE 'original_message_id'");
$has_reply_column = mysqli_num_rows($check_column) > 0;

// Get only the latest message per conversation (caterer + subject combination)
// This ensures each order from the same caterer appears as a separate conversation
if ($has_reply_column) {
    $received_query = "
        SELECT m.*, u.first_name, u.last_name, u.role, u.profile_image, sd.business_name,
               (SELECT COUNT(*) FROM messages m2 
                WHERE m2.sender_id = m.sender_id AND m2.receiver_id = '$user_id' AND m2.is_deleted = FALSE) as reply_count,
               (SELECT COUNT(*) FROM messages m3 
                WHERE m3.sender_id = m.sender_id AND m3.receiver_id = '$user_id'
                AND m3.is_read = 0 AND m3.is_deleted = FALSE) as unread_count,
               (SELECT MAX(created_at) FROM messages m4 WHERE m4.sender_id = m.sender_id AND m4.receiver_id = '$user_id' AND m4.is_deleted = FALSE) as last_activity
        FROM messages m 
        JOIN users u ON m.sender_id = u.id 
        LEFT JOIN store_details sd ON u.id = sd.user_id 
        WHERE m.receiver_id = '$user_id' 
        AND m.is_deleted = FALSE 
        AND m.id IN (
            SELECT MAX(id) FROM messages 
            WHERE receiver_id = '$user_id' AND is_deleted = FALSE 
            GROUP BY sender_id
        )
        ORDER BY last_activity DESC
    ";
    $sent_query = "
        SELECT m.*, u.first_name, u.last_name, u.role, u.profile_image, sd.business_name,
               0 as reply_count,
               (SELECT MAX(created_at) FROM messages m3 
                WHERE m3.sender_id = '$user_id' AND m3.receiver_id = m.receiver_id AND m3.is_deleted = FALSE) as last_activity
        FROM messages m 
        JOIN users u ON m.receiver_id = u.id 
        LEFT JOIN store_details sd ON u.id = sd.user_id 
        WHERE m.sender_id = '$user_id' 
        AND m.is_deleted = FALSE 
        AND m.id IN (
            SELECT MAX(id) FROM messages 
            WHERE sender_id = '$user_id' AND is_deleted = FALSE 
            GROUP BY receiver_id
        )
        ORDER BY last_activity DESC
    ";
} else {
    $received_query = "
        SELECT m.*, u.first_name, u.last_name, u.role, u.profile_image, sd.business_name,
               0 as reply_count,
               (SELECT COUNT(*) FROM messages m2 
                WHERE m2.sender_id = m.sender_id AND m2.receiver_id = '$user_id' 
                AND m2.is_read = 0 AND m2.is_deleted = FALSE) as unread_count
        FROM messages m 
        JOIN users u ON m.sender_id = u.id 
        LEFT JOIN store_details sd ON u.id = sd.user_id 
        WHERE m.receiver_id = '$user_id' 
        AND m.is_deleted = FALSE
        AND m.id IN (
            SELECT MAX(id) FROM messages 
            WHERE receiver_id = '$user_id' AND is_deleted = FALSE 
            GROUP BY sender_id
        )
        ORDER BY m.created_at DESC
    ";
    $sent_query = "
        SELECT m.*, u.first_name, u.last_name, u.role, u.profile_image, sd.business_name,
               0 as reply_count,
               m.created_at as last_activity
        FROM messages m 
        JOIN users u ON m.receiver_id = u.id 
        LEFT JOIN store_details sd ON u.id = sd.user_id 
        WHERE m.sender_id = '$user_id' 
        AND m.is_deleted = FALSE
        AND m.id IN (
            SELECT MAX(id) FROM messages 
            WHERE sender_id = '$user_id' AND is_deleted = FALSE 
            GROUP BY receiver_id
        )
        ORDER BY m.created_at DESC
    ";
}
$received_result = mysqli_query($conn, $received_query);
$sent_result = mysqli_query($conn, $sent_query);

// Get deleted messages - Grouped by Conversation
$deleted_query = "
    SELECT m.*, 
           CASE 
               WHEN m.sender_id = '$user_id' THEN CONCAT(u2.first_name, ' ', u2.last_name)
               ELSE CONCAT(u1.first_name, ' ', u1.last_name)
           END as contact_name,
           CASE 
               WHEN m.sender_id = '$user_id' THEN u2.role
               ELSE u1.role
           END as contact_role,
           CASE 
               WHEN m.sender_id = '$user_id' THEN sd2.business_name
               ELSE sd1.business_name
           END as business_name,
           CASE WHEN m.sender_id = '$user_id' THEN u2.profile_image ELSE u1.profile_image END as profile_image
    FROM messages m 
    LEFT JOIN users u1 ON m.sender_id = u1.id 
    LEFT JOIN users u2 ON m.receiver_id = u2.id 
    LEFT JOIN store_details sd1 ON u1.id = sd1.user_id 
    LEFT JOIN store_details sd2 ON u2.id = sd2.user_id 
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

$page_title = 'Messages';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/customer-messages-theme.css?v=<?php echo substr(hash_file('sha256', __DIR__ . '/../assets/css/customer-messages-theme.css'), 0, 12); ?>">

<div class="customer-messages-page">
    <?php if (isset($_GET['sent'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Message sent successfully!
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['refund_sent'])): ?>
        <dialog id="refundResultNotice" class="sage-refund-dialog" aria-labelledby="refundResultTitle">
            <h2 id="refundResultTitle">Refund request sent</h2>
            <p>The caterer will review your request.</p>
            <div class="refund-dialog-actions"><button type="button" onclick="this.closest('dialog').close()">OK</button></div>
        </dialog>
    <?php endif; ?>
    <?php if (isset($_GET['refund_error'])): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> Failed to send refund request. Please try again.
        </div>
    <?php endif; ?>

    <div class="card messages-split-layout<?php echo $viewed_message ? ' has-conversation' : ''; ?>" style="display: flex; height: calc(100vh - 200px); min-height: 500px; padding: 0; overflow: hidden; border-radius: 12px; background: #1a1a1a; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
    
    <!-- LEFT SIDEBAR: Tabs & List -->
    <div class="messages-sidebar" style="width: 320px; flex-shrink: 0; border-right: 1px solid rgba(255, 255, 255, 0.05); display: flex; flex-direction: column; background: #1f1f1f;">
        
        <!-- Tabs -->
        <div class="section-tabs" style="display: flex; padding: 15px; border-bottom: 1px solid rgba(255, 255, 255, 0.05); gap: 5px; margin-bottom: 0;">
            <button class="section-tab active" onclick="openTab('inbox')" style="flex: 1; padding: 10px 5px; font-size: 13px; border-radius: 8px;"><i class="fas fa-inbox"></i> Inbox</button>
            <button class="section-tab" onclick="openTab('sent')" style="flex: 1; padding: 10px 5px; font-size: 13px; border-radius: 8px;"><i class="fas fa-paper-plane"></i> Sent</button>
            <button class="section-tab" onclick="openTab('trash')" style="flex: 1; padding: 10px 5px; font-size: 13px; border-radius: 8px;"><i class="fas fa-trash"></i> Trash</button>
        </div>
        
        <style>
            .messages-list-container { flex: 1; overflow-y: auto; }
            .sidebar-message-card { padding: 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.03); cursor: pointer; display: flex; gap: 12px; transition: all 0.2s; position: relative; }
            .sidebar-message-card:hover { background: rgba(255, 255, 255, 0.02); }
            .sidebar-message-card.active { background: rgba(212, 175, 55, 0.08); }
            .sidebar-message-card.active::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--accent-gold); }
            .sidebar-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--accent-gold); color: #111; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; flex-shrink: 0; }
            .sidebar-info { flex: 1; min-width: 0; }
            .sidebar-name { font-size: 14px; font-weight: 600; color: #eee; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .sidebar-preview { font-size: 12px; color: #888; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .sidebar-meta { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; }
            .sidebar-time { font-size: 11px; color: #666; }
            .sidebar-unread-dot { width: 8px; height: 8px; background: #ff4444; border-radius: 50%; display: inline-block; }
            .tab-content { display: none; }
            .tab-content.active { display: block; }
            .messages-list-container::-webkit-scrollbar, .modern-chat-area::-webkit-scrollbar, .modern-left-col::-webkit-scrollbar { width: 6px; }
            .messages-list-container::-webkit-scrollbar-track, .modern-chat-area::-webkit-scrollbar-track, .modern-left-col::-webkit-scrollbar-track { background: transparent; }
            .messages-list-container::-webkit-scrollbar-thumb, .modern-chat-area::-webkit-scrollbar-thumb, .modern-left-col::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: 10px; }
        </style>
        
        <!-- List Container -->
        <div class="messages-list-container">
            <!-- Inbox Tab -->
            <div id="inbox-tab" class="tab-content active">
                <?php if (mysqli_num_rows($received_result) > 0): ?>
                    <?php while ($message = mysqli_fetch_assoc($received_result)): ?>
                        <?php $is_active = (isset($_GET['view']) && $_GET['view'] == $message['id']) ? 'active' : ''; ?>
                        <div class="sidebar-message-card <?php echo $is_active; ?>" data-msg-id="<?php echo $message['id']; ?>" onclick="loadConversation(<?php echo $message['id']; ?>)">
                            <div class="sidebar-avatar">
                                <img src="../<?php echo htmlspecialchars(!empty($message['profile_image']) && is_file(dirname(__DIR__) . '/' . $message['profile_image']) ? $message['profile_image'] : 'uploads/logo.png', ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" width="40" height="40" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                            </div>
                            <div class="sidebar-info">
                                <div class="sidebar-name"><?php echo htmlspecialchars($message['business_name'] ?? $message['first_name'] . ' ' . $message['last_name']); ?></div>
                                <div class="sidebar-preview"><?php echo htmlspecialchars(formatCustomerMessageBookingReferences($message['subject'])); ?></div>
                                <div class="sidebar-meta">
                                    <div class="sidebar-time"><?php echo date('M d', strtotime($message['last_activity'])); ?></div>
                                    <?php if ($message['unread_count'] > 0): ?>
                                        <div class="sidebar-unread-dot" id="unread-dot-<?php echo $message['id']; ?>"></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="padding: 30px 20px; text-align: center; color: #666;">
                        <i class="fas fa-inbox" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i>
                        <p style="font-size: 13px;">No messages in inbox.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sent Tab -->
            <div id="sent-tab" class="tab-content">
                <?php if (mysqli_num_rows($sent_result) > 0): ?>
                    <?php while ($message = mysqli_fetch_assoc($sent_result)): ?>
                        <?php $is_active = (isset($_GET['view']) && $_GET['view'] == $message['id']) ? 'active' : ''; ?>
                        <div class="sidebar-message-card <?php echo $is_active; ?>" data-msg-id="<?php echo $message['id']; ?>" onclick="loadConversation(<?php echo $message['id']; ?>)">
                            <div class="sidebar-avatar" style="background: #444; color: #fff;">
                                <img src="../<?php echo htmlspecialchars(!empty($message['profile_image']) && is_file(dirname(__DIR__) . '/' . $message['profile_image']) ? $message['profile_image'] : 'uploads/logo.png', ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" width="40" height="40" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                            </div>
                            <div class="sidebar-info">
                                <div class="sidebar-name">To: <?php echo htmlspecialchars($message['business_name'] ?? $message['first_name'] . ' ' . $message['last_name']); ?></div>
                                <div class="sidebar-preview"><?php echo htmlspecialchars(formatCustomerMessageBookingReferences($message['subject'])); ?></div>
                                <div class="sidebar-meta">
                                    <div class="sidebar-time"><?php echo date('M d', strtotime($message['last_activity'])); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="padding: 30px 20px; text-align: center; color: #666;">
                        <i class="fas fa-paper-plane" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i>
                        <p style="font-size: 13px;">No sent messages.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Trash Tab -->
            <div id="trash-tab" class="tab-content">
                <?php if (mysqli_num_rows($deleted_result) > 0): ?>
                    <?php while ($message = mysqli_fetch_assoc($deleted_result)): ?>
                        <?php $is_active = (isset($_GET['view']) && $_GET['view'] == $message['id']) ? 'active' : ''; ?>
                        <div class="sidebar-message-card <?php echo $is_active; ?>" data-msg-id="<?php echo $message['id']; ?>" onclick="loadConversation(<?php echo $message['id']; ?>, true)">
                            <div class="sidebar-avatar" style="background: #333; color: #888;">
                                <i class="fas fa-trash"></i>
                            </div>
                            <div class="sidebar-info">
                                <div class="sidebar-name"><?php echo htmlspecialchars($message['business_name'] ?? $message['contact_name']); ?></div>
                                <div class="sidebar-preview"><?php echo htmlspecialchars(formatCustomerMessageBookingReferences($message['subject'])); ?></div>
                                <div class="sidebar-meta">
                                    <div class="sidebar-time"><?php echo date('M d', strtotime($message['created_at'])); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="padding: 30px 20px; text-align: center; color: #666;">
                        <i class="fas fa-trash" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i>
                        <p style="font-size: 13px;">Trash is empty.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT MAIN: Conversation & Order Details -->
    <style>
    .conversation-body, .conversation-chat-column, .messages-main { min-height: 0; }
    .modern-chat-area { flex: 1; min-height: 0; overflow-y: auto; padding: 25px; display: flex; flex-direction: column; gap: 20px; }
    .modern-message { flex-shrink: 0; }
    .conversation-header, .modern-reply-area { flex-shrink: 0; }
    .modern-message-bubble { padding: 14px 18px; border-radius: 16px; position: relative; max-width: 85%; width: fit-content; font-size: 14px; line-height: 1.5; }
    .modern-message { display: flex; flex-direction: column; }
    .modern-message.customer { align-self: flex-end; align-items: flex-end; }
    .modern-message.caterer { align-self: flex-start; align-items: flex-start; }
    .modern-message.customer .modern-message-bubble { background: var(--accent-gold); color: #111; border-bottom-right-radius: 4px; }
    .modern-message.caterer .modern-message-bubble { background: #2a2a2a; color: #eee; border-bottom-left-radius: 4px; border: 1px solid rgba(255,255,255,0.05); }
    .modern-message-time { font-size: 11px; color: #777; margin-top: 6px; }
    .modern-message.customer .modern-message-time { color: rgba(0,0,0,0.5); }

    .modern-left-col { width: 300px; flex-shrink: 0; background: #1a1a1a; border-left: 1px solid rgba(255,255,255,0.05); overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 15px; }
    .modern-card { background: #222; border-radius: 12px; padding: 18px; border: 1px solid rgba(255,255,255,0.04); }
    .modern-card-title { font-size: 13px; font-weight: 600; color: #888; margin-bottom: 14px; text-transform: uppercase; display: flex; align-items: center; gap: 8px; }
    .modern-order-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.03); }
    .modern-order-item:last-child { border-bottom: none; }
    .modern-order-label { font-size: 12px; color: #777; }
    .modern-order-value { font-size: 13px; font-weight: 600; color: #ddd; }

    .modern-reply-area { padding: 20px 25px; border-top: 1px solid rgba(216,181,84,0.12); background: linear-gradient(180deg,#1c1c1c,#171717); }
    .modern-form-control { box-sizing:border-box; width: 100%; background: #202020; border: 1px solid rgba(255,255,255,0.09); border-radius: 14px; padding: 14px 16px; color: #f5f2eb; resize: none; font-family: inherit; font-size:14px; line-height:1.5; transition: border-color 0.2s, box-shadow 0.2s; }
    .modern-form-control::placeholder { color:#96928a; }
    .modern-form-control:focus { outline: none; border-color: var(--accent-gold); box-shadow:0 0 0 3px rgba(216,181,84,0.09); }
    .modern-btn-primary { background: var(--accent-gold); color: #111; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
    .modern-btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
    .modern-upload-btn { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #aaa; padding: 10px 16px; border-radius: 8px; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
    .reply-actions { display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:nowrap; }
    .reply-tools { display:flex; gap:8px; flex-wrap:nowrap; align-items:center; min-width:0; }
    .reply-actions .modern-btn-primary { flex-shrink:0; white-space:nowrap; }
    .reply-tools .modern-upload-btn { padding:8px 10px; font-size:12px; white-space:nowrap; }
    .reply-actions .modern-upload-btn, .reply-actions .modern-btn-primary { box-sizing:border-box; min-height:44px; justify-content:center; border-radius:11px; font-family:inherit; font-weight:600; line-height:1.2; text-decoration:none; }
    .reply-actions .modern-upload-btn { background:#262521; border-color:rgba(216,181,84,0.18); color:#e0d9c9; }
    .reply-actions .modern-btn-primary { background:linear-gradient(135deg,#e4c56e,#cdaa4b); box-shadow:0 3px 10px rgba(0,0,0,0.18); }
    .reply-actions .modern-upload-btn:focus-visible, .reply-actions .modern-btn-primary:focus-visible { outline:2px solid var(--accent-gold); outline-offset:3px; }
    .qr-dialog { position:fixed; inset:0; margin:auto; box-sizing:border-box; width:360px; max-width:calc(100vw - 32px); max-height:calc(100dvh - 40px); overflow-y:auto; border:1px solid rgba(216,181,84,.28); border-radius:22px; padding:20px; background:linear-gradient(145deg,#25231e,#171717); color:#f5f2eb; box-shadow:0 24px 80px rgba(0,0,0,.55); }
    .qr-dialog::backdrop { background:rgba(0,0,0,.72); backdrop-filter:blur(5px); }
    .qr-dialog-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px; }
    .qr-dialog-title { margin:0; font-size:17px; font-weight:600; letter-spacing:.2px; }
    .qr-dialog-close { min-height:44px; padding:10px 14px; border:1px solid rgba(216,181,84,.25); border-radius:10px; background:rgba(216,181,84,.08); color:#e4c56e; font-family:inherit; font-size:12px; font-weight:600; cursor:pointer; }
    .qr-dialog-close:focus-visible { outline:2px solid var(--accent-gold); outline-offset:3px; }
    .qr-image-frame { background:#fff; padding:16px; border-radius:14px; }
    .qr-dialog img { display:block; width:100%; height:auto; max-height:calc(100dvh - 190px); object-fit:contain; margin:0; }
    @media (max-width:360px) { .qr-dialog { padding:16px; border-radius:18px; } .qr-dialog-title { font-size:15px; } .qr-image-frame { padding:12px; } }
    .modern-upload-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }
    </style>

    <style>
        /* The split view becomes a single-pane conversation on small screens. */
        .messages-split-layout {
            position: relative;
            width: 100%;
        }

        .messages-sidebar,
        .messages-main,
        .conversation-body,
        .conversation-chat-column {
            min-width: 0;
        }

        .conversation-back-btn {
            display: none;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
            padding: 8px 10px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.05);
            color: #eee;
            font: inherit;
            font-size: 13px;
            cursor: pointer;
        }

        .conversation-back-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        @media (max-width: 768px) {
            .reply-actions { display:grid; grid-template-columns:1fr 1fr .65fr; gap:6px; }
            .reply-tools { display:contents; }
            .reply-tools > div:empty { display:none; }
            .reply-tools .modern-upload-btn { padding:10px 4px; font-size:11px; }
            .reply-actions .modern-btn-primary { grid-column:3; padding:10px 6px; font-size:12px; }
            .modern-form-control { font-size:16px; }
            .messages-split-layout {
                flex-direction: column !important;
                height: calc(100vh - 180px) !important;
                height: calc(100dvh - 180px) !important;
                min-height: 0 !important;
            }

            .messages-sidebar {
                width: 100% !important;
                flex: 1 1 auto !important;
                border-right: 0 !important;
                border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            }

            .messages-main {
                display: none !important;
                width: 100% !important;
                flex: 1 1 auto !important;
            }

            /* A selected conversation takes over the available mobile width. */
            .messages-split-layout.has-conversation .messages-main,
            .messages-split-layout.mobile-conversation-open .messages-main {
                display: flex !important;
            }

            .messages-split-layout.has-conversation .messages-sidebar,
            .messages-split-layout.mobile-conversation-open .messages-sidebar {
                display: none !important;
            }

            /* The list state has priority after pressing the Back button. */
            .messages-split-layout.mobile-conversation-list .messages-main {
                display: none !important;
            }

            .messages-split-layout.mobile-conversation-list .messages-sidebar {
                display: flex !important;
            }

            .conversation-back-btn {
                display: inline-flex !important;
            }

            .conversation-header {
                padding: 12px 14px !important;
                gap: 8px !important;
            }

            .conversation-header-info {
                flex: 1 1 auto;
                min-width: 0;
            }

            .conversation-header-info h2,
            .conversation-header-info > div {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .conversation-header-actions {
                flex: 0 0 auto;
                gap: 6px !important;
            }

            .conversation-header-actions a {
                padding: 8px !important;
                font-size: 0 !important;
            }

            .conversation-header-actions a i {
                font-size: 14px;
            }

            .conversation-body {
                flex-direction: column !important;
                overflow: hidden !important;
            }

            .conversation-chat-column {
                flex: 1 1 0 !important;
                min-height: 0;
            }

            .modern-chat-area {
                flex: 1 1 0 !important;
                min-height: 0;
                max-height: none !important;
                overflow-y: auto !important;
                padding: 16px !important;
                gap: 14px;
            }

            .modern-reply-area {
                padding: 14px 16px !important;
            }

            .modern-left-col {
                width: 100% !important;
                flex: 0 1 auto !important;
                max-height: 25% !important;
                overflow-y: auto !important;
                border-left: 0 !important;
                border-top: 1px solid rgba(255, 255, 255, 0.05);
                padding: 16px !important;
            }

            .modern-message-bubble {
                max-width: 92% !important;
                overflow-wrap: anywhere;
                word-break: break-word;
            }
        }

        @media (min-width: 769px) {
            .messages-split-layout {
                flex-direction: row !important;
            }

            .messages-sidebar {
                width: 320px !important;
                flex: 0 0 320px !important;
            }

            .messages-main {
                display: flex !important;
            }
        }

        @media (max-width: 360px) {
            .conversation-back-btn span {
                display: none;
            }

            .conversation-back-btn {
                padding: 8px;
            }
        }
    </style>

    <style>
        /* Customer messaging: warm gold accents and comfortable touch targets. */
        .messages-split-layout { border:1px solid rgba(220,185,99,.16); border-radius:22px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.25); }
        #messages-main .conversation-header { background:linear-gradient(120deg,#25231e,#191919) !important; border-bottom:1px solid rgba(220,185,99,.15) !important; }
        .conversation-header-info > div:first-child { flex-shrink:0; box-shadow:0 0 0 4px rgba(220,185,99,.08); background:linear-gradient(135deg,#f0d78d,#c6a04b) !important; }
        .conversation-header-info h2 { letter-spacing:.1px; }
        .conversation-header-info > div:last-child > div { color:#b3ada0 !important; margin-top:4px; }
        .modern-chat-area { background:radial-gradient(ellipse at top right,rgba(204,169,77,.055),transparent 65%),#141515; padding:28px; gap:18px; overscroll-behavior:contain; }
        .modern-message { max-width:82%; }
        .modern-message-bubble { max-width:100%; box-sizing:border-box; padding:14px 17px; border-radius:18px; overflow-wrap:anywhere; box-shadow:0 4px 14px rgba(0,0,0,.12); }
        .modern-message.customer .modern-message-bubble { background:linear-gradient(135deg,#e9cf83,#d1ae55); color:#211c10; }
        .modern-message.caterer .modern-message-bubble { background:linear-gradient(145deg,#292a29,#222322); border-color:rgba(255,255,255,.065); color:#f0eee8; }
        .modern-message-time, .modern-message.customer .modern-message-time { color:#aaa59a; font-size:11px; margin:7px 4px 0; }
        .modern-message-text img { max-width:100% !important; height:auto; }
        .modern-reply-area { background:linear-gradient(180deg,#1e1f1d,#191a18); padding:18px 22px; }
        .modern-form-control { background:#141513; border-color:rgba(220,185,99,.16); border-radius:16px; min-height:76px; }
        .reply-tools .modern-upload-btn { gap:7px; padding:12px 14px; font-size:12px; }
        .reply-actions .modern-upload-btn, .reply-actions .modern-btn-primary { min-height:46px; border-radius:13px; }
        .reply-tools label::before { content:'\f0c6'; font-family:'Font Awesome 6 Free'; font-weight:900; }
        .reply-tools button::before { content:'\f029'; font-family:'Font Awesome 6 Free'; font-weight:900; }
        .reply-actions .modern-btn-primary::after { content:'\f1d8'; font-family:'Font Awesome 6 Free'; font-weight:900; }
        .proof-selection { display:flex; align-items:center; gap:10px; background:rgba(220,185,99,.06); border:1px solid rgba(220,185,99,.16); border-radius:12px; padding:9px 12px; color:#e2d3a9; font-size:12px; }
        .proof-selection[hidden] { display:none; }
        .proof-selection span { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .proof-selection button { border:0; background:transparent; color:#e2d3a9; min-width:44px; min-height:36px; cursor:pointer; font:inherit; }
        @media (max-width:768px) {
            .messages-split-layout { border-radius:18px; }
            #messages-main .conversation-header { padding:14px 12px !important; gap:10px !important; }
            .conversation-back-btn { width:44px; height:44px; justify-content:center; padding:0 !important; border-radius:13px; }
            .conversation-back-btn span { display:none; }
            .conversation-header-info { gap:10px !important; }
            .conversation-header-info > div:first-child { width:36px !important; height:36px !important; font-size:14px !important; }
            .conversation-header-info h2 { font-size:15px !important; }
            .conversation-header-actions a { width:44px; height:44px; box-sizing:border-box; justify-content:center; border-radius:13px !important; }
            .modern-chat-area { padding:20px 14px !important; gap:18px; }
            .modern-message { max-width:88%; }
            .modern-message-bubble { max-width:100% !important; font-size:14px; line-height:1.65; padding:12px 15px; }
            .modern-reply-area { padding:14px 12px max(14px,env(safe-area-inset-bottom)) !important; }
            .reply-actions { grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
            .reply-tools .modern-upload-btn { padding:12px 8px; font-size:12px; white-space:normal; min-height:46px; }
            .reply-actions .modern-btn-primary { grid-column:1 / -1; width:100%; min-height:46px; font-size:14px; justify-content:center; }
            .modern-form-control { font-size:16px; padding:12px 14px; min-height:72px; }
            .modern-left-col { box-sizing:border-box; }
        }
        @media (prefers-reduced-motion:reduce) { .modern-btn-primary, .modern-upload-btn, .modern-form-control { transition:none; } .modern-btn-primary:hover { transform:none; } }
    </style>
    <div id="messages-main" class="messages-main" style="flex: 1; display: flex; flex-direction: column; background: #151515; min-width: 0; overflow: hidden;">
        <?php if ($viewed_message): ?>

            <!-- Main Header -->
            <div class="conversation-header" style="padding: 15px 25px; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; background: #1c1c1c;">
                <button type="button" class="conversation-back-btn" onclick="showConversationList()" aria-label="Back to messages">
                    <i class="fas fa-arrow-left"></i><span>Back</span>
                </button>
                <div class="conversation-header-info" style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--accent-gold); color: #111; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 16px;">
                        <?php 
                            $other_party_name = ($viewed_message['sender_id'] == $user_id) ? 
                                ($viewed_message['receiver_business'] ?? $viewed_message['receiver_first']) : 
                                ($viewed_message['sender_business'] ?? $viewed_message['sender_first']);
                            $chat_logo_user = (int) (($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_id'] : $viewed_message['sender_id']);
                            $chat_logo_result = mysqli_query($conn, "SELECT profile_image FROM users WHERE id = $chat_logo_user LIMIT 1");
                            $chat_logo_row = $chat_logo_result ? mysqli_fetch_assoc($chat_logo_result) : null;
                            $chat_logo = ltrim((string) ($chat_logo_row['profile_image'] ?? ''), '/');
                            if ($chat_logo === '' || !is_file(dirname(__DIR__) . '/' . $chat_logo)) $chat_logo = 'uploads/logo.png';
                            echo '<img class="conversation-logo" src="../' . htmlspecialchars($chat_logo, ENT_QUOTES, 'UTF-8') . '" alt="Caterer logo">';
                        ?>
                    </div>
                    <div>
                        <h2 style="margin: 0; font-size: 16px; color: #fff;"><?php echo htmlspecialchars($other_party_name); ?></h2>
                        
                    </div>
                </div>
                
                <!-- Actions -->
                <?php if (!empty($viewed_message['id'])): ?>
                <div class="conversation-header-actions" style="display: flex; gap: 10px;">
                    <a href="?action=delete&id=<?php echo $viewed_message['id']; ?>" onclick="return confirm('Move this conversation to trash?');" style="color: #ff4444; background: rgba(255,68,68,0.1); padding: 8px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; display: flex; align-items: center; gap: 6px; transition: 0.2s;"><i class="fas fa-trash"></i> Delete</a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Body Area -->
            <div class="conversation-body" style="flex: 1; display: flex; overflow: hidden;">
                <!-- Chat Area -->
                <div class="conversation-chat-column" style="flex: 1; display: flex; flex-direction: column; min-width: 0;">
                    <div class="modern-chat-area">
                        <?php if (!empty($thread_messages)): ?>
                            <?php foreach ($thread_messages as $msg): ?>
                                <?php $is_me = $msg['sender_id'] == $user_id; ?>
                                <div class="modern-message <?php echo $is_me ? 'customer' : 'caterer'; ?>">
                                    <div class="modern-message-bubble">
                                        <div class="modern-message-text">
                                            <?php 
                                                $msg_text = htmlspecialchars(formatCustomerMessageBookingReferences($msg['message'] ?? ''));
                                                $msg_text = preg_replace('/\[attachment:\s*(.+?)\]/', '<br><a href="../$1" target="_blank" style="display:inline-block; margin-top:10px;"><img src="../$1" style="max-width:200px; border-radius:8px; border:1px solid rgba(255,255,255,0.1);"/></a>', $msg_text);
                                                echo nl2br($msg_text);
                                            ?>
                                        </div>
                                    </div>
                                    <div class="modern-message-time">
                                        <?php echo date('M d, h:i A', strtotime($msg['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; color: #666; padding: 40px; margin-top: auto; margin-bottom: auto;">
                                <i class="fas fa-comments" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
                                <p>No messages yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php
                    $store_qr = null;
                    {
                        $qr_user_id = ($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_id'] : $viewed_message['sender_id'];
                        $qr_result = mysqli_query($conn, "SELECT gcash_qr_image FROM store_details WHERE user_id = '$qr_user_id'");
                        $store_qr = mysqli_fetch_assoc($qr_result);
                    }
                    ?>
                    <!-- Reply Area -->
                    <div class="modern-reply-area">
                        <form method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="receiver_id" value="<?php echo ($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_id'] : $viewed_message['sender_id']; ?>">
                            <input type="hidden" name="original_message_id" value="<?php echo isset($viewed_message['original_message_id']) ? ($viewed_message['original_message_id'] ?: $viewed_message['id']) : $viewed_message['id']; ?>">
                            <input type="hidden" name="subject" value="<?php echo empty($viewed_message['id']) ? '' : 'Re: '; ?><?php echo htmlspecialchars($viewed_message['subject']); ?>">
                            
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <input type="hidden" name="order_id" value="<?php echo (int) ($booking_chat['id'] ?? $viewed_message['order_id'] ?? 0); ?>">
                                <textarea <?php echo $booking_chat ? 'autofocus' : ''; ?> name="message" class="modern-form-control" rows="2" aria-label="Message to caterer" placeholder="Type your message here..." required></textarea>
                                
                                <div class="reply-actions">
<div class="reply-tools">
                                    <label class="modern-upload-btn" for="paymentReceipt" tabindex="0" onkeydown="if(event.key === 'Enter' || event.key === ' ') { event.preventDefault(); document.getElementById('paymentReceipt').click(); }">
                                        <span>Attach Proof</span>
                                        <input type="file" id="paymentReceipt" name="payment_receipt" accept="image/*" style="display: none;">
                                    </label>
                                    
                                    <?php if (!empty($store_qr['gcash_qr_image'])): ?>
                                    <button type="button" class="modern-upload-btn" onclick="toggleCustomerQR()"><span>Show QR Code</span></button>
                                    <?php endif; ?>
                                    </div>
                                    <button type="submit" name="send_message" class="modern-btn-primary">
                                        <span>Send</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!empty($store_qr['gcash_qr_image'])): ?>
                <dialog id="customerQRDisplay" class="qr-dialog" aria-labelledby="customerQRTitle">
                    <div class="qr-dialog-header"><h3 id="customerQRTitle" class="qr-dialog-title">Caterer QR Code</h3><button type="button" onclick="toggleCustomerQR()" class="qr-dialog-close">Close</button></div>
                    <div class="qr-image-frame"><img src="../<?php echo htmlspecialchars($store_qr['gcash_qr_image']); ?>" alt="Caterer payment QR code"></div>
                </dialog>
                <?php endif; ?>
                <!-- Order Info Column (Rightmost) -->
                <?php if ($order_balance && $order_balance['total_paid'] > 0): ?>
                <div class="modern-left-col">
                    <?php
                    $other_store_id = ($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_id'] : $viewed_message['sender_id'];
                    if ($order_balance && $order_balance['total_paid'] > 0):
                    ?>
                    <div class="modern-card" style="margin-top:15px; background: rgba(231,76,60,0.04); border-color: rgba(231,76,60,0.15);">
                        <button type="button" onclick="toggleRefundForm()" id="refundToggleBtn" style="width:100%; padding:10px; background:transparent; border:1px solid rgba(231,76,60,0.4); color:#e74c3c; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; transition:0.2s;">
                            Request a Refund
                        </button>
                        <div id="refundForm" style="display:none; margin-top:15px;">
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="receiver_id" value="<?php echo $other_store_id; ?>">
                                <input type="hidden" name="order_id"    value="<?php echo $order_balance['order_id']; ?>">
                                <input type="hidden" name="original_message_id" value="<?php echo isset($viewed_message['original_message_id']) ? ($viewed_message['original_message_id'] ?: $viewed_message['id']) : $viewed_message['id']; ?>">

                                <div style="margin-bottom:10px;">
                                    <input type="number" name="refund_amount" value="<?php echo number_format((float) $order_balance['total_paid'], 2, '.', ''); ?>" readonly aria-label="Amount paid to refund" step="0.01" required placeholder="Amount (Max: &#8369;<?php echo number_format($order_balance['total_paid'], 2); ?>)" style="width:100%; padding:8px 10px; background:#1a1a1a; border:1px solid rgba(255,255,255,0.1); border-radius:6px; color:#fff; font-size:13px; box-sizing:border-box;">
                                </div>
                                <div style="margin-bottom:10px;">
                                    <textarea name="refund_reason" required rows="2" placeholder="Reason for refund..." style="width:100%; padding:8px 10px; background:#1a1a1a; border:1px solid rgba(255,255,255,0.1); border-radius:6px; color:#fff; font-size:13px; resize:none; box-sizing:border-box;"></textarea>
                                </div>
                                <button type="submit" name="send_refund_request"  style="width:100%; padding:8px; background:#e74c3c; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px;">
                                    Submit
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <!-- Empty state when no conversation is selected -->
            <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #151515;">
                <div style="width: 80px; height: 80px; border-radius: 50%; background: rgba(255,255,255,0.03); display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                    <i class="fas fa-paper-plane" style="font-size: 32px; color: var(--accent-gold); opacity: 0.5;"></i>
                </div>
                <h3 style="color: #ddd; font-weight: 500; margin-bottom: 10px;">Your Messages</h3>
                <p style="color: #777; font-size: 14px; margin-bottom: 30px;">Select a conversation from the sidebar to view it.</p>
                
                <div style="display: flex; gap: 15px;">
                    <a href="?action=mark_all_read" style="background: rgba(255,255,255,0.05); color: #fff; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-size: 13px; display: flex; align-items: center; gap: 8px; transition: 0.2s;"><i class="fas fa-check-double" style="color: var(--accent-gold);"></i> Mark all as read</a>
                    <a href="?action=delete_all_messages" onclick="return confirm('Delete ALL messages? This cannot be undone.');" style="background: rgba(255,68,68,0.1); color: #ff4444; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-size: 13px; display: flex; align-items: center; gap: 8px; transition: 0.2s;"><i class="fas fa-trash"></i> Empty all</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
</div> <!-- End of messages page content; the shared footer closes .container. -->

<script>
    // Delegated events also support conversations loaded without a page refresh.
    document.addEventListener('change', function(event) {
        const input = event.target;
        if (!input.matches('input[name="payment_receipt"]')) return;
        const form = input.closest('form');
        let selection = form.querySelector('.proof-selection');
        if (!selection) {
            selection = document.createElement('div');
            selection.className = 'proof-selection';
            selection.setAttribute('role', 'status');
            const filename = document.createElement('span');
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.textContent = 'Remove';
            remove.addEventListener('click', function() {
                input.value = '';
                selection.hidden = true;
            });
            selection.append(filename, remove);
            form.querySelector('.reply-actions').before(selection);
        }
        selection.hidden = !input.files.length;
        selection.querySelector('span').textContent = input.files[0]?.name || '';
    });
    // ── Tab switching ─────────────────────────────────────────────────────────
    function openTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.section-tab').forEach(btn => btn.classList.remove('active'));
        document.getElementById(tabName + '-tab').classList.add('active');
        document.querySelectorAll('.section-tab').forEach(btn => {
            if (btn.getAttribute('onclick').includes(tabName)) btn.classList.add('active');
        });
    }

    // ── Toggle QR Code display ────────────────────────────────────────────────
    function toggleCustomerQR() {
        const qrDisplay = document.getElementById('customerQRDisplay');
        if (!qrDisplay) return;
        if (qrDisplay.open) qrDisplay.close();
        else qrDisplay.showModal();
    }

    // ── Toggle refund form ────────────────────────────────────────────────────
    function toggleRefundForm() {
        const mobileDialog = document.getElementById('mobileRefundDialog');
        if (window.matchMedia('(max-width: 768px)').matches && mobileDialog) {
            mobileDialog.showModal();
            return;
        }
        const form = document.getElementById('refundForm');
        const btn  = document.getElementById('refundToggleBtn');
        if (form.style.display === 'none' || form.style.display === '') {
            form.style.display = 'block';
            btn.innerHTML = '<i class="fas fa-times"></i> Cancel Refund Request';
            btn.style.background = 'rgba(255,255,255,0.06)';
            btn.style.color = '#aaa';
            btn.style.borderColor = 'rgba(255,255,255,0.1)';
        } else {
            form.style.display = 'none';
            btn.innerHTML = '<i class="fas fa-undo-alt"></i> Request a Refund';
            btn.style.background = 'rgba(231,76,60,0.15)';
            btn.style.color = '#e74c3c';
            btn.style.borderColor = 'rgba(231,76,60,0.4)';
        }
    }

    function getMessagesLayout() {
        return document.querySelector('.messages-split-layout');
    }

    let conversationVersion = 0;
    let refreshingConversation = false;
    let messageSignature = '';

    function chatScroller() {
        return document.querySelector('#messages-main .modern-chat-area');
    }

    function scrollToLatest() {
        requestAnimationFrame(() => {
            const area = document.querySelector('#messages-main .modern-chat-area');
            const scroller = chatScroller();
            if (!area || !scroller) return;
            scroller.scrollTop = scroller === area ? area.scrollHeight
                : area.offsetTop - scroller.offsetTop + area.offsetHeight - scroller.clientHeight;
        });
    }

    async function refreshConversation() {
        const params = new URLSearchParams(window.location.search);
        if (refreshingConversation || document.hidden || !params.has('view') || params.has('from_trash') ||
            !getMessagesLayout()?.classList.contains('mobile-conversation-open')) return;
        const version = conversationVersion;
        refreshingConversation = true;
        try {
            const response = await fetch('get_conversation.php?view=' + encodeURIComponent(params.get('view')), { cache: 'no-store' });
            if (!response.ok) return;
            const data = await response.json();
            if (version !== conversationVersion || data.error) return;
            syncMessageUnreadBadge(data.unread_count);
            const area = document.querySelector('#messages-main .modern-chat-area');
            const scroller = chatScroller();
            if (!area || !scroller) return;
            const paid = Number(data.order_balance?.total_paid || 0);
            if (!document.getElementById('refundToggleBtn') && paid > 0) {
                document.querySelector('#messages-main .conversation-body')?.insertAdjacentHTML('beforeend', buildRefundPanel(data));
                arrangeMobileRefund();
            }
            const amount = document.querySelector('#refundForm [name="refund_amount"]');
            if (amount && document.activeElement !== amount) amount.value = paid.toFixed(2);
            const signature = JSON.stringify(data.thread_messages);
            if (signature === messageSignature) return;
            const atLatest = scroller === area
                ? scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 100
                : area.getBoundingClientRect().bottom - scroller.getBoundingClientRect().bottom < 100;
            const previousTop = scroller.scrollTop;
            area.innerHTML = data.thread_messages.map(msg =>
                '<div class="modern-message ' + (msg.is_me ? 'customer' : 'caterer') + '">' +
                '<div class="modern-message-bubble"><div class="modern-message-text">' + msg.message + '</div></div>' +
                '<div class="modern-message-time">' + escHtml(msg.created_at) + '</div></div>').join('');
            messageSignature = signature;
            if (atLatest) scrollToLatest();
            else scroller.scrollTop = previousTop;
        } catch (error) {
            // Retry on the next interval after a temporary connection failure.
        } finally {
            refreshingConversation = false;
        }
    }

    // On mobile, switch back to the list without reloading the page.
    function showConversationList(updateHistory = true) {
        const bookingReturn = <?php echo json_encode($booking_chat ? 'booking-details.php?id=' . (int) $booking_chat['id'] : null); ?>;
        const catererReturn = <?php echo json_encode($return_caterer_id > 0 ? 'browse-caterer.php?store_id=' . $return_caterer_id : null); ?>;
        if (catererReturn) {
            let target = catererReturn;
            try {
                const saved = JSON.parse(sessionStorage.getItem('catererMessageOrigin') || 'null');
                if (saved) {
                    const url = new URL(saved.url);
                    if (url.origin === location.origin && url.pathname.endsWith('/customer/browse-caterer.php') && url.searchParams.get('store_id') === String(<?php echo (int) $return_caterer_id; ?>)) target = url.href;
                }
            } catch (_) {}
            location.href = target; return;
        }
        if (bookingReturn) { window.location.href = bookingReturn; return; }
        conversationVersion++;
        const layout = getMessagesLayout();
        if (!layout) return;

        layout.classList.remove('mobile-conversation-open');
        layout.classList.add('mobile-conversation-list');

        if (updateHistory) {
            const url = new URL(window.location.href);
            url.searchParams.delete('store_id');
            url.searchParams.delete('view');
            url.searchParams.delete('from_trash');
            history.pushState({ messagesList: true }, '', url.pathname + url.search);
        }
    }

    // ── AJAX: Load conversation instantly without page reload ─────────────────
    function loadConversation(msgId, fromTrash, updateHistory = true) {
        const version = ++conversationVersion;
        fromTrash = fromTrash || false;

        const layout = getMessagesLayout();
        if (layout) {
            layout.classList.remove('mobile-conversation-list');
            layout.classList.add('mobile-conversation-open');
        }

        // Mark active in sidebar
        document.querySelectorAll('.sidebar-message-card').forEach(c => c.classList.remove('active'));
        const card = document.querySelector('.sidebar-message-card[data-msg-id="' + msgId + '"]');
        if (card) card.classList.add('active');

        // Hide unread dot instantly
        const dot = document.getElementById('unread-dot-' + msgId);
        if (dot) dot.style.display = 'none';

        // Update URL without reload
        if (updateHistory) {
            const newUrl = '?view=' + encodeURIComponent(msgId) + (fromTrash ? '&from_trash=1' : '');
            history.pushState({ msgId: msgId, fromTrash: fromTrash }, '', newUrl);
        }

        // Show skeleton loading
        const mainEl = document.getElementById('messages-main');
        mainEl.innerHTML = '<div style="flex:1; display:flex; align-items:center; justify-content:center; color:#555; font-size:14px; gap:10px;">' +
            '<i class="fas fa-circle-notch fa-spin" style="color:var(--accent-gold);"></i> Loading...</div>';

        // Fetch conversation data
        const url = 'get_conversation.php?view=' + encodeURIComponent(msgId) + (fromTrash ? '&from_trash=1' : '');
        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (version !== conversationVersion) return;
                if (data.error) {
                    mainEl.innerHTML = '<div style="flex:1; display:flex; align-items:center; justify-content:center; color:#f55;">Error: ' + data.error + '</div>';
                    return;
                }
                renderConversation(data, mainEl);
            })
            .catch(() => {
                mainEl.innerHTML = '<div style="flex:1; display:flex; align-items:center; justify-content:center; color:#f55;">Failed to load conversation.</div>';
            });
    }

    // ── Render conversation HTML from JSON data ───────────────────────────────
    function syncMessageUnreadBadge(count) {
        if (!Number.isFinite(Number(count))) return;
        document.querySelectorAll('a[href="messages.php"]').forEach(link => {
            let badge = link.querySelector('span');
            if (Number(count) <= 0) {
                if (badge) badge.remove();
            } else {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.style.cssText = 'background:#ff4444;color:white;font-size:10px;padding:2px 6px;border-radius:10px;margin-left:5px;font-weight:bold;line-height:1;';
                    link.append(badge);
                }
                badge.textContent = count;
            }
        });
    }
    function buildRefundPanel(d) {
        // Build order summary panel
        let orderHtml = '';
        if (d.order_balance) {
            const ob = d.order_balance;



            let refundHtml = '';
            if (ob.total_paid > 0) {
                const maxRefund = parseFloat(ob.total_paid).toFixed(2);
                refundHtml = '<div class="modern-card" style="margin-top:15px; background:rgba(231,76,60,0.04); border-color:rgba(231,76,60,0.15);">' +
                    '<button type="button" onclick="toggleRefundForm()" id="refundToggleBtn" style="width:100%; padding:10px; background:transparent; border:1px solid rgba(231,76,60,0.4); color:#e74c3c; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; transition:0.2s;">Request a Refund</button>' +
                    '<div id="refundForm" style="display:none; margin-top:15px;">' +
                    '<form method="POST" action="">' +
                    '<input type="hidden" name="csrf_token" value="' + escHtml(d.csrf_token) + '">' +
                    '<input type="hidden" name="receiver_id" value="' + escHtml(d.other_store_id) + '">' +
                    '<input type="hidden" name="order_id" value="' + escHtml(ob.order_id) + '">' +
                    '<input type="hidden" name="original_message_id" value="' + escHtml(d.original_msg_id) + '">' +
                    '<div style="margin-bottom:10px;"><input type="number" name="refund_amount" value="' + maxRefund + '" readonly aria-label="Amount paid to refund" step="0.01" required placeholder="Amount (Max: &#8369;' + parseFloat(ob.total_paid).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ')" style="width:100%; padding:8px 10px; background:#1a1a1a; border:1px solid rgba(255,255,255,0.1); border-radius:6px; color:#fff; font-size:13px; box-sizing:border-box;"></div>' +
                    '<div style="margin-bottom:10px;"><textarea name="refund_reason" required rows="2" placeholder="Reason for refund..." style="width:100%; padding:8px 10px; background:#1a1a1a; border:1px solid rgba(255,255,255,0.1); border-radius:6px; color:#fff; font-size:13px; resize:none; box-sizing:border-box;"></textarea></div>' +
                    '<button type="submit" name="send_refund_request"  style="width:100%; padding:8px; background:#e74c3c; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px;">Submit Refund Request</button>' +
                    '</form></div></div>';
            }

            if (refundHtml) {
                orderHtml = '<div class="modern-left-col">' + refundHtml + '</div>';
            }
        }
        return orderHtml;
    }

    function renderConversation(d, container) {
        syncMessageUnreadBadge(d.unread_count);
        messageSignature = JSON.stringify(d.thread_messages);
        const initials = d.other_party_name ? d.other_party_name.charAt(0).toUpperCase() : '?';
        const escapedName = escHtml(d.other_party_name);
        const escapedSubject = escHtml(d.subject);

        // Build chat bubbles
        let bubblesHtml = '';
        if (d.thread_messages && d.thread_messages.length > 0) {
            d.thread_messages.forEach(msg => {
                const side = msg.is_me ? 'customer' : 'caterer';
                bubblesHtml += '<div class="modern-message ' + side + '">' +
                    '<div class="modern-message-bubble"><div class="modern-message-text">' + msg.message + '</div></div>' +
                    '<div class="modern-message-time">' + escHtml(msg.created_at) + '</div>' +
                    '</div>';
            });
        } else {
            bubblesHtml = '<div style="text-align:center; color:#666; padding:40px; margin:auto;">' +
                '<i class="fas fa-comments" style="font-size:48px; margin-bottom:16px; opacity:0.3;"></i><p>No messages yet.</p></div>';
        }

        const orderHtml = buildRefundPanel(d);
        // Build reply area
        const replyHtml = d.from_trash ? '' :
            '<div class="modern-reply-area">' +
            '<form method="POST" action="" enctype="multipart/form-data">' +
            '<input type="hidden" name="csrf_token" value="' + escHtml(d.csrf_token) + '">' +
            '<input type="hidden" name="receiver_id" value="' + escHtml(d.receiver_id) + '">' +
            '<input type="hidden" name="original_message_id" value="' + escHtml(d.original_msg_id) + '">' +
            '<input type="hidden" name="subject" value="Re: ' + escHtml(d.subject) + '">' +
            '<div style="display:flex; flex-direction:column; gap:12px;">' +
            '<textarea name="message" class="modern-form-control" rows="2" placeholder="Type your reply here..." required></textarea>' +
            '<div class="reply-actions"><div class="reply-tools">' +
                '<label class="modern-upload-btn" for="paymentReceipt" tabindex="0" onkeydown="if(event.key === \'Enter\' || event.key === \' \') { event.preventDefault(); document.getElementById(\'paymentReceipt\').click(); }">' +
                '<span>Attach Proof</span>' +
                '<input type="file" id="paymentReceipt" name="payment_receipt" accept="image/*" style="display:none;">' +
                '</label>' +
            (d.store_qr_code ? '<button type="button" class="modern-upload-btn" onclick="toggleCustomerQR()"><span>Show QR Code</span></button>' : '') +
            '</div>' +
            '<button type="submit" name="send_message" class="modern-btn-primary"><span>Send</span></button>' +
            '</div></div></form>' +
            (d.store_qr_code ? '<dialog id="customerQRDisplay" class="qr-dialog" aria-labelledby="customerQRTitle"><div class="qr-dialog-header"><h3 id="customerQRTitle" class="qr-dialog-title">Caterer QR Code</h3><button type="button" onclick="toggleCustomerQR()" class="qr-dialog-close">Close</button></div><div class="qr-image-frame"><img src="../' + escHtml(d.store_qr_code) + '" alt="Caterer payment QR code"></div></dialog>' : '') + '</div>';

        // Assemble full HTML
        container.innerHTML =
            // Header
            '<div class="conversation-header" style="padding:15px 25px; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center; background:#1c1c1c;">' +
            '<button type="button" class="conversation-back-btn" onclick="showConversationList()" aria-label="Back to messages"><i class="fas fa-arrow-left"></i><span>Back</span></button>' +
            '<div class="conversation-header-info" style="display:flex; align-items:center; gap:12px; min-width:0;">' +
            '<div style="width:40px; height:40px; border-radius:50%; background:var(--accent-gold); color:#111; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:16px; flex-shrink:0;">' + '<img class="conversation-logo" src="../' + escHtml(d.other_party_logo || 'uploads/logo.png') + '" alt="Caterer logo">' + '</div>' +
            '<div style="min-width:0;"><h2 style="margin:0; font-size:16px; color:#fff; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + escapedName + '</h2>' +
            '</div>' +
            '</div>' +
            '<div class="conversation-header-actions" style="display:flex; gap:10px;">' +
            '<a href="?action=delete&id=' + escHtml(d.message_id) + '" onclick="return confirm(\'Move this conversation to trash?\')" style="color:#ff4444; background:rgba(255,68,68,0.1); padding:8px 12px; border-radius:6px; text-decoration:none; font-size:13px; display:flex; align-items:center; gap:6px; transition:0.2s;"><i class="fas fa-trash"></i> Delete</a>' +
            '</div></div>' +
            // Body
            '<div class="conversation-body" style="flex:1; display:flex; overflow:hidden;">' +
            '<div class="conversation-chat-column" style="flex:1; display:flex; flex-direction:column; min-width:0;">' +
            '<div class="modern-chat-area" id="chat-scroll-area">' + bubblesHtml + '</div>' +
            replyHtml +
            '</div>' +
            orderHtml +
            '</div>';

        arrangeMobileRefund();
        // Scroll to bottom of chat
        scrollToLatest();
    }

    // ── HTML escape helper ────────────────────────────────────────────────────
    let mobileRefundState = null;
    function arrangeMobileRefund() {
        if (mobileRefundState) {
            const {button, card, marker, dialog} = mobileRefundState;
            if (marker.isConnected) {
                marker.parentElement.classList.remove('mobile-refund-column-empty');
                marker.replaceWith(card);
                card.prepend(button);
                card.classList.remove('mobile-refund-card');
                button.classList.remove('mobile-refund-action');
                button.textContent = 'Request a Refund';
                card.querySelector('#refundForm').style.display = 'none';
            }
            dialog.remove();
            mobileRefundState = null;
        }
        if (!window.matchMedia('(max-width: 768px)').matches) return;
        const button = document.getElementById('refundToggleBtn');
        const form = document.getElementById('refundForm');
        const actions = document.querySelector('.conversation-header-actions');
        if (!button || !form || !actions) return;
        const card = button.parentElement;
        const marker = document.createComment('Refund form desktop position');
        card.before(marker);
        card.parentElement.classList.add('mobile-refund-column-empty');
        const dialog = document.createElement('dialog');
        dialog.id = 'mobileRefundDialog';
        dialog.setAttribute('aria-labelledby', 'mobileRefundTitle');
        const header = document.createElement('div');
        header.className = 'mobile-refund-heading';
        const title = document.createElement('h3');
        title.id = 'mobileRefundTitle';
        title.textContent = 'Request a Refund';
        const close = document.createElement('button');
        close.type = 'button';
        close.textContent = '×';
        close.setAttribute('aria-label', 'Close refund request');
        close.addEventListener('click', () => dialog.close());
        header.append(title, close);
        button.classList.add('mobile-refund-action');
        button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10h11a6 6 0 0 1 0 12" transform="translate(0 -3)"/><path d="m7 3-4 4 4 4"/><path d="M14 11v6m2-5h-3a1 1 0 0 0 0 2h2a1 1 0 0 1 0 2h-3"/></svg>';
        button.setAttribute('aria-label', 'Request a Refund');
        button.title = 'Request a Refund';
        actions.prepend(button);
        card.classList.add('mobile-refund-card');
        form.style.display = 'block';
        dialog.append(header, card);
        document.body.append(dialog);
        mobileRefundState = {button, card, marker, dialog};
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrangeMobileRefund);
    else arrangeMobileRefund();
    window.matchMedia('(max-width: 768px)').addEventListener('change', arrangeMobileRefund);

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ── Handle browser back/forward ───────────────────────────────────────────
    window.addEventListener('popstate', function(e) {
        const params = new URLSearchParams(window.location.search);
        if (params.has('view')) {
            loadConversation(params.get('view'), params.has('from_trash'), false);
        } else {
            showConversationList(false);
        }
    });

    // Keep list preferences across refreshes; conversations open at the latest message.
    const messageStatePrefix = 'customerMessages:' + <?php echo json_encode((string) $user_id); ?> + ':';
    let restoringMessagePosition = true;
    function saveMessagePosition() {
        if (restoringMessagePosition) return;
        const selectedTab = document.querySelector('.tab-content.active')?.id?.replace(/-tab$/, '') || 'inbox';
        const area = chatScroller();
        const sidebar = document.querySelector('.messages-list-container');
        const layout = getMessagesLayout();
        const state = { tab: selectedTab, chatTop: area?.scrollTop || 0,
            sidebarTop: sidebar?.scrollTop || 0, pageTop: window.scrollY,
            listMode: layout?.classList.contains('mobile-conversation-list') || false };
        sessionStorage.setItem(messageStatePrefix + location.search, JSON.stringify(state));
        sessionStorage.setItem(messageStatePrefix + 'tab', selectedTab);
    }
    function restoreMessagePosition() {
        let state;
        try { state = JSON.parse(sessionStorage.getItem(messageStatePrefix + location.search) || 'null'); } catch (_) {}
        const tab = state?.tab || sessionStorage.getItem(messageStatePrefix + 'tab');
        if (['inbox', 'sent', 'trash'].includes(tab)) openTab(tab);
        requestAnimationFrame(() => requestAnimationFrame(() => {
            if (state) {
                const area = chatScroller();
                if (area) area.scrollTop = area.scrollHeight;
                const sidebar = document.querySelector('.messages-list-container');
                if (sidebar) sidebar.scrollTop = state.sidebarTop;
                const layout = getMessagesLayout();
                if (layout && state.listMode) {
                    layout.classList.add('mobile-conversation-list');
                    layout.classList.remove('mobile-conversation-open');
                }
                window.scrollTo(0, state.pageTop);
            }
            restoringMessagePosition = false;
        }));
    }
    document.addEventListener('scroll', saveMessagePosition, true);
    document.addEventListener('click', event => {
        if (event.target.closest('.section-tab,.sidebar-message-card,.conversation-back-btn')) {
            requestAnimationFrame(saveMessagePosition);
        }
    });
    window.addEventListener('pagehide', saveMessagePosition);
    window.addEventListener('pageshow', restoreMessagePosition);

    // ── Auto-scroll to bottom on page load ───────────────────────────────────
    document.addEventListener('DOMContentLoaded', function() {
        const layout = getMessagesLayout();
        if (layout && layout.classList.contains('has-conversation')) {
            layout.classList.remove('mobile-conversation-list');
            layout.classList.add('mobile-conversation-open');
        }

        scrollToLatest();
    });
    window.addEventListener('load', scrollToLatest);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshConversation();
    });
    setInterval(refreshConversation, 3000);
</script>


<dialog id="refundConfirmNotice" class="sage-refund-dialog" aria-labelledby="refundConfirmTitle">
    <h2 id="refundConfirmTitle">Submit refund request?</h2>
    <p>Your request will be sent to the caterer for review.</p>
    <div class="refund-dialog-actions"><button type="button" data-refund-cancel class="refund-dialog-secondary">Cancel</button><button type="button" data-refund-confirm>Submit request</button></div>
</dialog>
<script src="../assets/js/customer-refund-dialogs.js?v=20261027-1"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>


<?php if ($booking_chat || $return_caterer_id > 0): ?>
<style>
.conversation-back-btn { display: inline-flex !important; align-items: center; justify-content: center; width: 40px !important; height: 40px !important; flex: 0 0 40px; padding: 0 !important; }
.conversation-back-btn span { display: none !important; }
</style>
<?php endif; ?>
