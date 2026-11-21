<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
requireLogin();

if (getUserRole() !== 'portal') {
    header('Location: ../index.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$csrf_token = generateCSRFToken();
$compose_receiver_id = isset($_GET['compose']) ? (int) $_GET['compose'] : 0;

// Match the account deletion records used by Manage Users.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS portal_account_trash (
    user_id INT NOT NULL PRIMARY KEY,
    deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// Handle send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        header('Location: messages.php?error=invalid_token');
        exit();
    }

    $receiver_id = (int) ($_POST['receiver_id'] ?? 0);
    $recipient_check = mysqli_query($conn, "SELECT u.id FROM users u
        WHERE u.id = $receiver_id AND u.id != '$user_id'
        AND NOT EXISTS (SELECT 1 FROM portal_account_trash t WHERE t.user_id = u.id)");
    if (!$recipient_check || !mysqli_fetch_assoc($recipient_check)) {
        header('Location: messages.php?error=invalid_recipient');
        exit();
    }
    $subject = mysqli_real_escape_string($conn, $_POST['subject']);
    $message = mysqli_real_escape_string($conn, $_POST['message']);
    
    // Check if this is a reply to an existing message
    $original_message_id = isset($_POST['original_message_id']) ? mysqli_real_escape_string($conn, $_POST['original_message_id']) : null;
    
    if ($original_message_id && mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM messages LIKE 'original_message_id'")) > 0) {
        $query = "INSERT INTO messages (sender_id, receiver_id, subject, message, original_message_id) 
                  VALUES ('$user_id', '$receiver_id', '$subject', '$message', '$original_message_id')";
    } else {
        $query = "INSERT INTO messages (sender_id, receiver_id, subject, message) 
                  VALUES ('$user_id', '$receiver_id', '$subject', '$message')";
    }
    
    if (mysqli_query($conn, $query)) {
        header('Location: messages.php' . (isset($_GET['view']) ? '?view=' . (int) $_GET['view'] : '?sent=1'));
        exit();
    }
}

// Handle delete/restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_action'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        header('Location: messages.php?error=invalid_token');
        exit();
    }

    $message_id = mysqli_real_escape_string($conn, $_POST['id']);
    $message_action = $_POST['message_action'];

    if ($message_action === 'delete') {
        mysqli_query($conn, "UPDATE messages SET is_deleted = TRUE WHERE id = '$message_id' AND (sender_id = '$user_id' OR receiver_id = '$user_id')");
    } elseif ($message_action === 'restore') {
        mysqli_query($conn, "UPDATE messages SET is_deleted = FALSE WHERE id = '$message_id' AND (sender_id = '$user_id' OR receiver_id = '$user_id')");
    }

    header('Location: messages.php');
    exit();
}

// Handle view message
if (isset($_GET['view'])) {
    $message_id = mysqli_real_escape_string($conn, $_GET['view']);
    
    // Get message details
    $view_query = "
        SELECT m.*, 
               u1.first_name as sender_first, u1.last_name as sender_last, u1.role as sender_role, u1.profile_image as sender_profile,
               u2.first_name as receiver_first, u2.last_name as receiver_last, u2.role as receiver_role, u2.profile_image as receiver_profile,
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
        $read_deleted = isset($_GET['from_trash']) ? 1 : 0;
        mysqli_query($conn, "UPDATE messages SET is_read = TRUE WHERE sender_id = '$other_user_id' AND receiver_id = '$user_id' AND is_read = FALSE AND is_deleted = $read_deleted");
    }
    
    if (!$viewed_message) { header('Location: messages.php'); exit(); }
    $other_user_id = ($viewed_message['sender_id'] == $user_id) ? $viewed_message['receiver_id'] : $viewed_message['sender_id'];
    $is_trash_view = isset($_GET['from_trash']) ? "m.is_deleted = TRUE" : "m.is_deleted = FALSE";
    $thread_query = "
        SELECT m.*, 
               u1.first_name as sender_first, u1.last_name as sender_last, u1.role as sender_role, u1.profile_image as sender_profile,
               u2.first_name as receiver_first, u2.last_name as receiver_last, u2.role as receiver_role, u2.profile_image as receiver_profile,
               sd1.business_name as sender_business,
               sd2.business_name as receiver_business
        FROM messages m 
        JOIN users u1 ON m.sender_id = u1.id 
        JOIN users u2 ON m.receiver_id = u2.id 
        LEFT JOIN store_details sd1 ON u1.id = sd1.user_id 
        LEFT JOIN store_details sd2 ON u2.id = sd2.user_id 
        WHERE ((m.sender_id = '$user_id' AND m.receiver_id = '$other_user_id') 
           OR (m.sender_id = '$other_user_id' AND m.receiver_id = '$user_id'))
        AND $is_trash_view
        ORDER BY m.created_at ASC
    ";
    
    $thread_result = mysqli_query($conn, $thread_query);
    $thread_messages = array();
    while ($thread_msg = mysqli_fetch_assoc($thread_result)) {
        $thread_messages[] = $thread_msg;
    }
}

// Count every unread incoming message, even when the latest message was sent by us.
$conversation_unread = [];
$unread_result = mysqli_query($conn, "SELECT sender_id, COUNT(*) AS unread_count FROM messages WHERE receiver_id = $user_id AND is_read = 0 AND is_deleted = 0 GROUP BY sender_id");
if ($unread_result) {
    while ($unread_row = mysqli_fetch_assoc($unread_result)) {
        $conversation_unread[(int) $unread_row['sender_id']] = (int) $unread_row['unread_count'];
    }
}

// Get all messages
// Latest message for each conversation, including replies.
    $messages_query = "
        SELECT m.*, 
               u1.first_name as sender_first, u1.last_name as sender_last, u1.role as sender_role, u1.profile_image as sender_profile,
               u2.first_name as receiver_first, u2.last_name as receiver_last, u2.role as receiver_role, u2.profile_image as receiver_profile,
               sd1.business_name as sender_business,
               sd2.business_name as receiver_business,
               0 as reply_count
        FROM messages m 
        JOIN users u1 ON m.sender_id = u1.id 
        JOIN users u2 ON m.receiver_id = u2.id 
        LEFT JOIN store_details sd1 ON u1.id = sd1.user_id 
        LEFT JOIN store_details sd2 ON u2.id = sd2.user_id 
        WHERE (m.sender_id = '$user_id' OR m.receiver_id = '$user_id') 
        AND m.is_deleted = FALSE 
        AND m.id IN (
            SELECT MAX(id) FROM messages 
            WHERE (sender_id = '$user_id' OR receiver_id = '$user_id') AND is_deleted = FALSE 
            GROUP BY IF(sender_id = '$user_id', receiver_id, sender_id)
        )
        ORDER BY m.created_at DESC
    ";
$messages_result = mysqli_query($conn, $messages_query);

// Get deleted messages
$deleted_query = "
    SELECT m.*, 
           u1.first_name as sender_first, u1.last_name as sender_last, u1.role as sender_role, u1.profile_image as sender_profile,
           u2.first_name as receiver_first, u2.last_name as receiver_last, u2.role as receiver_role, u2.profile_image as receiver_profile,
           sd1.business_name as sender_business,
           sd2.business_name as receiver_business
    FROM messages m 
    JOIN users u1 ON m.sender_id = u1.id 
    JOIN users u2 ON m.receiver_id = u2.id 
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

// Get users for dropdown
$users_query = "
    SELECT u.id, 
           CASE 
               WHEN u.role IN ('store', 'caterer') THEN
                   CASE WHEN NULLIF(TRIM(sd.business_name), '') IS NOT NULL
                        THEN CONCAT(sd.business_name, ' - ', u.first_name, ' ', u.last_name)
                        ELSE CONCAT(u.first_name, ' ', u.last_name) END
               ELSE CONCAT(u.first_name, ' ', u.last_name)
           END as name,
           u.role 
    FROM users u 
    LEFT JOIN store_details sd ON u.id = sd.user_id 
    WHERE u.id != '$user_id'
      AND NOT EXISTS (SELECT 1 FROM portal_account_trash t WHERE t.user_id = u.id)
    ORDER BY CASE WHEN u.role IN ('store', 'caterer') THEN 0 WHEN u.role = 'customer' THEN 1 ELSE 2 END, name, u.id
";
$users_result_avail = mysqli_query($conn, $users_query);
$recipient_groups = ['Caterers' => [], 'Customers' => [], 'Administrators' => []];
if ($users_result_avail) {
    while ($row = mysqli_fetch_assoc($users_result_avail)) {
        $group = in_array($row['role'], ['store', 'caterer'], true) ? 'Caterers' : ($row['role'] === 'customer' ? 'Customers' : 'Administrators');
        $recipient_groups[$group][] = $row;
    }
}


function portalMessageContact($message, $user_id) {
    $side = $message['sender_id'] == $user_id ? 'receiver' : 'sender';
    $name = trim($message[$side . '_first'] . ' ' . $message[$side . '_last']);
    $business = trim($message[$side . '_business'] ?? '');
    $is_caterer = in_array($message[$side . '_role'], ['store', 'caterer'], true);
    return ['name' => ($is_caterer && $business !== '') ? $business : ($name ?: 'Member'), 'business' => $business,
        'role' => in_array($message[$side . '_role'], ['store', 'caterer'], true) ? 'Caterer' : ucfirst($message[$side . '_role']),
        'image' => $message[$side . '_profile'] ?? '',
        'unread_count' => empty($message['is_deleted']) ? ($GLOBALS['conversation_unread'][(int) $message[$side . '_id']] ?? 0) : 0];
}
function portalMessageAvatar($contact) {
    $name = htmlspecialchars($contact['name'], ENT_QUOTES, 'UTF-8');
    $path = str_replace('\\', '/', $contact['image']);
    $valid = strpos($path, 'uploads/') === 0 && strpos($path, '..') === false && is_file(dirname(__DIR__) . '/' . $path);
    echo '<div class="contact-person"><span class="contact-avatar">';
    echo '<span>' . htmlspecialchars(strtoupper(substr($contact['name'], 0, 1))) . '</span>';
    if ($valid) echo '<img src="../' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '" alt="' . $name . ' profile" onerror="this.remove()">';
    $unread_count = (int) ($contact['unread_count'] ?? 0);
    echo '</span><span class="contact-copy"><strong>' . $name;
    if ($unread_count > 0) {
        echo ' <span class="conversation-unread-badge" aria-label="' . $unread_count . ' unread messages">' . $unread_count . '</span>';
    }
    echo '</strong></span></div>';
}

$page_title = 'Messages - Portal Dashboard';
$extra_head = '
<style>
    .conversation-unread-badge {display:inline-flex;align-items:center;justify-content:center;min-width:18px;min-height:18px;padding:1px 5px;margin-left:5px;border-radius:10px;background:#dc3545;color:#fff;font-size:10px;font-weight:700;line-height:1.4;vertical-align:middle}
    .messages-layout {
        display: grid;
        grid-template-columns: 350px 1fr;
        gap: 25px;
        align-items: start;
    }
    
    .message-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        background: var(--secondary-bg);
        padding: 10px;
        border-radius: 10px;
        border: 1px solid var(--border-color);
    }
    
    .message-tab {
        padding: 8px 15px;
        background: transparent;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        border-radius: 6px;
        transition: all 0.3s;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .message-tab.active {
        background: var(--accent-gold);
        color: #000;
    }
    
    .message-thread {
        max-height: 400px;
        overflow-y: auto;
        padding-right: 10px;
        margin-bottom: 20px;
    }
    
    .thread-message {
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 15px;
        max-width: 85%;
    }
    
    .sent-message {
        background: rgba(212, 175, 55, 0.1);
        border-left: 4px solid var(--accent-gold);
        margin-left: auto;
    }
    
    .received-message {
        background: rgba(33, 150, 243, 0.1);
        border-left: 4px solid var(--info-color);
    }
    
    .message-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
        font-size: 12px;
    }
    
    .message-sender {
        font-weight: 700;
        color: var(--accent-gold);
    }
    
    .message-time {
        color: var(--text-muted);
    }
    
    .reply-form {
        background: var(--secondary-bg);
        padding: 20px;
        border-radius: 12px;
        border: 1px solid var(--border-color);
    }
    
    .unread {
        background: rgba(212, 175, 55, 0.05) !important;
    }
    
    .reply-indicator {
        background: var(--accent-gold);
        color: #000;
        padding: 2px 6px;
        border-radius: 10px;
        font-size: 10px;
        font-weight: 700;
        margin-left: 5px;
    }

    @media (max-width: 1200px) {
        .messages-layout {
            grid-template-columns: 1fr;
        }
    }

    .messages-page {min-width:0;width:calc(100% - 260px)}.messages-page .messages-layout {grid-template-columns:minmax(0,1fr);grid-template-areas:"inbox";gap:24px}
    .messages-page .content-body {border-radius:20px;padding:26px;border:1px solid #ffffff12;background:linear-gradient(145deg,#20211f,#171816);box-shadow:0 16px 44px #00000020;min-width:0}
    .messages-page .compose-card {grid-area:compose;position:sticky;top:24px;margin:0}
    .messages-page h3 {font-size:18px;letter-spacing:-.4px;margin-bottom:22px}
    .messages-page .form-group {margin-bottom:20px}
    .messages-page label {font-size:12px;font-weight:600;display:block;margin-bottom:9px}
    .messages-page .form-control {border-radius:12px;border-color:#ffffff16;background:#ffffff05;min-height:48px;width:100%;font-size:14px}
    .messages-page .form-control:focus {outline:2px solid #d4af3760;outline-offset:2px}
    .messages-page textarea {resize:vertical}
    .messages-page .btn-primary {border-radius:12px;min-height:45px}
    .messages-page .messages-list-container {grid-area:inbox;min-width:0}.messages-page .messages-list-container>.content-body {margin:0;min-height:420px}
    .messages-page .message-tabs {border-radius:15px;padding:6px;background:#ffffff04;border-color:#ffffff12}
    .messages-page .message-tab {min-height:44px;border-radius:10px;justify-content:center}
    .messages-page .message-tab.active {background:#d4af3720;color:#ebcc6c;box-shadow:inset 0 0 0 1px #d4af3730}
    .inbox-heading {display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:22px}
    .inbox-heading h3 {margin-bottom:6px}.inbox-heading p {color:var(--text-muted);font-size:12px}
    .sync-status {font-size:11px;white-space:nowrap;color:#b5c6ad;background:#91ae7a12;padding:7px 10px;border-radius:20px}
    .contact-person {display:flex;align-items:center;gap:12px;min-width:0}
    .contact-avatar {width:44px;height:44px;border-radius:15px;position:relative;flex-shrink:0;display:grid;place-items:center;background:linear-gradient(135deg,#766333,#3c3525);color:#f6df96;font-weight:700;overflow:hidden;border:1px solid #d4af3733}
    .contact-avatar img {position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
    .contact-copy {min-width:0}.contact-copy strong {display:block;font-size:13px}.contact-copy small {display:block;color:var(--text-muted);font-size:11px;margin-top:5px}
    .messages-page .data-table {table-layout:fixed}.messages-page .data-table th {font-size:10px;letter-spacing:1px;padding:12px 8px}
    .messages-page .data-table td {padding:20px 8px;overflow-wrap:anywhere;font-size:12px}
    .messages-page .data-table th:first-child {width:30%}.messages-page .data-table th:nth-child(3) {width:110px}.messages-page .data-table th:last-child {width:100px}
    .messages-page .message-preview {overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}
    .messages-page .data-table .btn {min-width:36px;min-height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center}
    .messages-page .btn-info {background:#ffffff09;color:#ddd;border:1px solid #ffffff12}.messages-page .btn-danger {background:#d9656512;color:#e99a91;border:1px solid #d9656520}
    .message-thread .thread-message {overflow-wrap:anywhere}
    #messageModal {z-index:1100!important}#messageModal>div {overscroll-behavior:contain}
    @media(max-width:992px){.main-content.messages-page {width:100%;padding-top:76px}}
    @media(max-width:1150px){.messages-page .messages-layout {grid-template-columns:minmax(0,1fr);grid-template-areas:"inbox"}.messages-page .compose-card {position:static}.messages-page .compose-card textarea {min-height:110px}}
    @media(max-width:640px){.messages-page .content-body {padding:18px;border-radius:16px}.messages-page .messages-layout {gap:20px}.messages-page .messages-list-container>.content-body {min-height:0}.messages-page .message-tabs {margin-bottom:12px}.messages-page .compose-card h3 {margin-bottom:18px}.messages-page .message-tab {flex:1;font-size:12px}.inbox-heading {align-items:flex-start}.inbox-heading p {max-width:180px;line-height:1.5}.messages-page .data-table thead {display:none}.messages-page .data-table,.messages-page .data-table tbody {display:block;width:100%}.messages-page .data-table tr {display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;padding:18px 0;border-bottom:1px solid #ffffff12}.messages-page .data-table td {display:block;border:0;padding:0}.messages-page .data-table td:first-child {grid-column:1}.messages-page .data-table td:nth-child(2) {grid-column:1/-1;grid-row:2;padding-left:56px}.messages-page .data-table td:nth-child(3) {grid-column:2;grid-row:1;font-size:10px;padding-top:4px}.messages-page .data-table td:last-child {grid-column:2;grid-row:3}.messages-page .table-container {overflow:visible}.contact-copy strong {font-size:12px}#messageModal>div {padding:18px!important;width:95%!important}.thread-message {max-width:94%}}


    .messages-page .compose-trigger {margin-left:auto;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;font-size:12px;white-space:nowrap}
    .compose-modal {position:fixed;inset:0;margin:auto;width:min(520px,calc(100% - 24px));max-height:calc(100dvh - 32px);padding:0;border:1px solid #ffffff20;border-radius:20px;background:var(--card-bg);color:var(--text-main);overflow:auto;box-shadow:0 28px 100px #0009}
    .compose-modal::backdrop {background:#000b;backdrop-filter:blur(6px)}
    .messages-page .compose-modal .compose-card {position:static;margin:0;border:0;width:100%;box-shadow:none}
    .compose-heading {display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px}
    .messages-page .compose-heading h3 {margin:0}
    .compose-close {width:40px;height:40px;border-radius:10px;border:1px solid #ffffff18;background:#ffffff08;color:var(--text-main);font-size:26px;cursor:pointer;flex-shrink:0}
    body:has(#composeModal[open]) {overflow:hidden}
    @media(max-width:640px){.messages-page .message-tabs {flex-wrap:wrap;gap:6px}.messages-page .message-tab {padding:8px}.messages-page .compose-trigger {flex:1 0 100%;margin:0}.compose-modal {max-height:calc(100dvh - 24px);border-radius:16px}.messages-page .compose-modal .form-control {font-size:16px}.messages-page .compose-modal textarea {min-height:120px}}


    .recipient-toggle {display:flex;align-items:center;justify-content:space-between;gap:12px;text-align:left;color:var(--text-main);cursor:pointer;height:auto;padding:12px 14px}
    #recipientLabel {white-space:normal;overflow-wrap:anywhere;line-height:1.5}
    .recipient-toggle i {font-size:11px;flex-shrink:0}
    .recipient-picker {position:relative}
    .recipient-menu {position:absolute;top:100%;left:0;right:0;z-index:20;box-shadow:0 16px 35px #0008;margin-top:6px;padding:10px;background:#171916;border:1px solid #d4af3745;border-radius:12px}
    .recipient-menu[hidden],.recipient-group[hidden],.recipient-option[hidden],#recipientEmpty[hidden],#recipientError[hidden] {display:none}
    .recipient-options {max-height:min(220px,30dvh);overflow-y:auto;overscroll-behavior:contain;margin-top:8px}
    .recipient-group-label {padding:12px 8px 6px;color:#d7bc69;font-size:11px;font-weight:700;letter-spacing:.5px}
    .recipient-option {display:block;width:100%;border:0;border-radius:8px;padding:12px 10px;background:transparent;color:#f6f6f3;text-align:left;font:inherit;font-size:13px;line-height:1.6;white-space:normal;overflow-wrap:anywhere;cursor:pointer}
    .recipient-option:hover,.recipient-option:focus-visible,.recipient-option[aria-pressed="true"] {background:#d4af3720;color:#ffe59b;outline-color:#d4af37}
    #recipientEmpty {padding:14px 8px;color:var(--text-muted);font-size:13px}#recipientError {color:#efaaa0;font-size:12px;margin-top:8px}


    .messages-page .conversation-detail {display:none!important}
    .conversation-link {display:block;text-decoration:none;color:inherit;border-radius:12px;padding:4px 0;min-width:0}
    .conversation-link:focus-visible {outline:2px solid var(--accent-gold);outline-offset:4px}
    .conversation-link:hover .contact-copy strong {color:#ebcc6c}
    .messages-page .data-table th:first-child {width:auto}
    .thread-subject {font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:10px;overflow-wrap:anywhere}
    @media(max-width:640px){
        .messages-page .data-table tr {grid-template-columns:minmax(0,1fr) auto;gap:8px 12px;padding:16px 0;align-items:center}
        .messages-page .data-table td:first-child {grid-column:1;grid-row:1;min-width:0}
        .messages-page .data-table td:nth-child(3) {grid-column:2;grid-row:1;padding:0;color:var(--text-muted);font-size:10px;white-space:nowrap;align-self:center;text-align:right}
        .messages-page .data-table td:last-child {grid-column:2;grid-row:2;justify-self:end}
        .messages-page .data-table td:last-child .btn-info {display:none}
        .messages-page .data-table .btn {min-width:40px;min-height:40px}
        .messages-page .contact-copy strong {font-size:13px;line-height:1.5;white-space:normal;overflow-wrap:anywhere}
        .messages-page .contact-copy small {font-size:12px;line-height:1.5}
        .messages-page .contact-avatar {width:44px;height:44px}
        .messages-page .inbox-heading {gap:8px;flex-wrap:wrap}
        .messages-page .inbox-heading p {max-width:none}
    }


    @media(max-width:640px){
        .messages-page .messages-list-container>.content-body {padding:14px;min-height:0}
        .messages-page .inbox-heading {margin-bottom:10px;align-items:center}
        .messages-page .inbox-heading h3 {font-size:15px;margin:0}
        .messages-page .sync-status {font-size:9px;padding:5px 7px}
        .messages-page .data-table tr {grid-template-columns:minmax(0,1fr) auto auto;gap:8px;padding:12px 0;align-items:center}
        .messages-page .data-table td:first-child {grid-column:1;grid-row:1}
        .messages-page .data-table td:nth-child(3) {grid-column:2;grid-row:1;font-size:9px}
        .messages-page .data-table td:last-child {grid-column:3;grid-row:1}
        .messages-page .contact-person {gap:8px}
        .messages-page .contact-avatar {width:34px;height:34px;border-radius:11px;font-size:12px}
        .messages-page .contact-copy strong {font-size:12px;line-height:1.4}
        .messages-page .conversation-link {padding:2px 0}
        .messages-page .data-table .btn {min-width:32px;min-height:36px;padding:6px 9px!important;font-size:11px!important}
        .messages-page .data-table tr:last-child {border-bottom:0}
    }


    #messageModal {padding:20px;background:rgba(0,0,0,.72)!important;backdrop-filter:blur(12px)!important}
    #messageModal>div {width:min(680px,100%)!important;height:min(800px,90dvh);max-height:90dvh!important;padding:0!important;display:flex;flex-direction:column;overflow:hidden!important;border-radius:24px!important;border:1px solid #ffffff18!important;background:linear-gradient(150deg,#20221f,#151714)!important;box-shadow:0 32px 120px #0009!important}
    #messageModal>div>div:first-child {flex-shrink:0;padding:18px 22px!important;margin:0!important;border-bottom:1px solid #ffffff0d!important;background:#ffffff02}
    #messageModal>div>div:first-child>a {width:36px;height:36px;display:grid;place-items:center;background:#ffffff07!important;border:1px solid #ffffff14!important;border-radius:11px;font-size:24px!important;line-height:1}
    #messageModal .contact-avatar {width:40px;height:40px;border-radius:13px}
    #messageModal .contact-copy strong {font-size:14px}
    #messageModal .message-thread {flex:1;min-height:100px;max-height:none;padding:22px;margin:0!important;overflow:auto;scrollbar-width:thin;scrollbar-color:#ffffff20 transparent;display:flex;flex-direction:column;gap:14px}
    #messageModal .thread-message {width:fit-content;max-width:85%;padding:13px 15px;margin:0;flex-shrink:0;box-shadow:0 4px 14px #00000012}
    #messageModal .received-message {background:#ffffff07;border:1px solid #ffffff0c;border-radius:16px 16px 16px 4px;align-self:flex-start}
    #messageModal .sent-message {background:linear-gradient(130deg,#d4af3723,#d4af3713);border:1px solid #d4af3725;border-radius:16px 16px 4px 16px;align-self:flex-end}
    #messageModal .thread-subject {font-size:10px;font-weight:500;color:#a7aa9f;margin-bottom:8px;line-height:1.5}
    #messageModal .message-header {gap:18px;align-items:center;margin-bottom:8px;font-size:10px}
    #messageModal .message-sender {font-weight:600;color:#e1c872}
    #messageModal .message-time {font-size:9px;white-space:nowrap;color:#93988c}
    #messageModal .chat-body {white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.65;font-size:14px;color:#f0f1ec;text-align:left}
    #messageModal .reply-form {flex-shrink:0;padding:16px 22px;border:0;border-top:1px solid #ffffff0e;border-radius:0;background:#11130f}
    #messageModal .reply-form h4 {font-size:12px;margin-bottom:12px!important;color:#dfca85!important}
    #messageModal .reply-form .form-group {margin-bottom:10px}
    #messageModal .reply-form label {font-size:10px;margin-bottom:5px;color:#a7aa9f}
    #messageModal .reply-form input {min-height:36px;padding:8px 11px;font-size:12px;background:#ffffff04;border-color:#ffffff10}
    #messageModal .reply-form textarea {min-height:68px;max-height:120px;padding:10px 12px;font-size:14px;background:#ffffff04;border-color:#ffffff10}
    #messageModal .reply-form form>div:last-child {margin-top:10px!important;gap:10px!important}
    #messageModal .reply-form .btn {min-height:40px;font-size:12px;border-radius:11px}
    #messageModal .reply-form .btn-secondary {display:none}
    @media(max-width:640px){
        #messageModal {padding:10px}
        #messageModal>div {width:100%!important;height:calc(100dvh - 32px);max-height:calc(100dvh - 32px)!important;border-radius:20px!important;padding:0!important}
        #messageModal>div>div:first-child {padding:14px 16px!important}
        #messageModal .message-thread {padding:16px;gap:12px}
        #messageModal .thread-message {max-width:94%;padding:11px 13px}
        #messageModal .reply-form {padding:12px 16px max(12px,env(safe-area-inset-bottom))}
        #messageModal .reply-form .form-control {font-size:16px}
        #messageModal .reply-form textarea {min-height:64px}
        #messageModal .chat-body {font-size:13px}
    }

    /* Keep the existing mobile layout; compact only the desktop conversation list. */
    @media (min-width: 993px) {
        .messages-page .messages-list-container > .content-body {min-height:0}
        .messages-page .data-table td {padding:8px;font-size:11px}
        .messages-page .conversation-link {padding:2px 0}
        .messages-page .contact-person {gap:10px}
        .messages-page .contact-avatar {width:32px;height:32px;border-radius:10px;font-size:12px}
        .messages-page .contact-copy strong {font-size:12px;line-height:1.4}
        .messages-page .data-table .btn {min-width:30px;min-height:30px;padding:5px 8px!important;font-size:11px!important;border-radius:8px}
    }
</style>
';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="main-content messages-page">

    <!-- Database Update Notice -->
    <?php 
    $check_column = mysqli_query($conn, "SHOW COLUMNS FROM messages LIKE 'original_message_id'");
    if (mysqli_num_rows($check_column) == 0): ?>
    <div class="alert" style="background: rgba(244, 67, 54, 0.1); color: var(--error-color); padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid rgba(244, 67, 54, 0.2);">
        <strong><i class="fas fa-exclamation-triangle"></i> Notice:</strong> Reply feature requires database update. 
        Please run this SQL in your database:
        <pre style="background: var(--input-bg); color: var(--text-main); border: 1px solid var(--border-color); padding: 10px; border-radius: 5px; margin: 10px 0; font-size: 12px;">
ALTER TABLE messages ADD COLUMN original_message_id INT DEFAULT NULL AFTER message;
CREATE INDEX idx_original_message_id ON messages(original_message_id);</pre>
        <a href="?update_db=1" class="btn btn-primary" style="padding: 5px 15px; text-decoration: none; border-radius: 4px;">
            <i class="fas fa-database"></i> Try to Update Database
        </a>
    </div>
    <?php 
    // Handle database update request
    if (isset($_GET['update_db'])) {
        $sql1 = "ALTER TABLE messages ADD COLUMN original_message_id INT DEFAULT NULL AFTER message";
        $sql2 = "CREATE INDEX idx_original_message_id ON messages(original_message_id)";
        
        if (mysqli_query($conn, $sql1)) {
            echo "<div class='alert' style='background: rgba(76, 175, 80, 0.1); color: var(--success-color); padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid rgba(76, 175, 80, 0.2);'>
                <i class='fas fa-check-circle'></i> Database updated successfully! Please refresh the page.
            </div>";
            mysqli_query($conn, $sql2);
        } else {
            appLogError('Portal messages update_db failed', mysqli_error($conn));
            echo "<div class='alert' style='background: rgba(244, 67, 54, 0.1); color: var(--error-color); padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid rgba(244, 67, 54, 0.2);'>
                <i class='fas fa-exclamation-circle'></i> Unable to update the message schema automatically. Please try again or contact support.
            </div>";
        }
    }
    endif; ?>

    <?php if (isset($_GET['view']) && isset($viewed_message) && $viewed_message): ?>
    <!-- Message View Modal -->
    <div id="messageModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(5px);">
        <div style="background: var(--card-bg); padding: 30px; border-radius: 15px; max-width: 800px; width: 90%; max-height: 90vh; overflow-y: auto; color: var(--text-main); border: 1px solid var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
                <div><?php portalMessageAvatar(portalMessageContact($viewed_message, $user_id)); ?></div>
                <a href="messages.php" style="background: none; border: none; font-size: 28px; cursor: pointer; color: var(--text-muted); text-decoration: none;">&times;</a>
            </div>
            
            <!-- Conversation Thread -->
            <?php if (!empty($thread_messages)): ?>
            <div class="message-thread" style="margin-bottom: 30px;">
                <?php foreach ($thread_messages as $thread_msg): ?>
                    <details class="thread-message <?php echo ($thread_msg['sender_id'] == $user_id) ? 'sent-message' : 'received-message'; ?>" data-message-id="<?php echo (int) $thread_msg['id']; ?>"><summary class="chat-body" aria-label="Show message details"><?php echo htmlspecialchars($thread_msg['message']); ?></summary>
                        <div class="thread-subject"><span>Subject:</span> <?php echo htmlspecialchars(preg_replace('/^(?:Re:\s*)+/i', '', $viewed_message['subject']), ENT_QUOTES, 'UTF-8'); ?></div><div class="message-header"><?php if ((int) $thread_msg['sender_id'] === $user_id): ?><span class="message-sender">You</span><?php endif; ?><span class="message-time"><?php echo date('M j, g:i a', strtotime($thread_msg['created_at'])); ?></span></div>
                    </details>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Reply Form -->
            <div class="reply-form">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="receiver_id" value="<?php echo (int) $other_user_id; ?>">
                    <input type="hidden" name="original_message_id" value="<?php echo !empty($viewed_message['original_message_id']) ? $viewed_message['original_message_id'] : $viewed_message['id']; ?>">
                    <input type="hidden" name="send_message" value="1">
                    
                    <input type="hidden" name="subject" value="<?php echo htmlspecialchars('Re: ' . preg_replace('/^(?:Re:\s*)+/i', '', $viewed_message['subject']), ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <div class="form-group">
                        
                        <textarea name="message" rows="3" required class="form-control" aria-label="Your reply" placeholder="Type your reply here..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 15px; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-paper-plane"></i> Send Reply
                        </button>
                        <a href="messages.php" class="btn btn-secondary" style="padding: 10px 25px;">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="messages-layout">
        <div class="messages-list-container" style="flex-grow: 1;">
            <!-- Message Tabs -->
            <div class="message-tabs">
                <button class="message-tab active" onclick="showTab('all')" data-tab="all"><i class="fas fa-inbox"></i> All Messages</button>
                <button class="message-tab" onclick="showTab('trash')" data-tab="trash"><i class="fas fa-trash"></i> Trash</button>
                <button type="button" class="btn btn-primary compose-trigger" onclick="openCompose()"><i class="fas fa-plus"></i><span>New Message</span></button>
            </div>
            
            <div class="content-body">
                <!-- All Messages -->
                <div id="all-tab">
                    <div class="inbox-heading"><div><h3>All Messages</h3></div></div>
                    <?php if (mysqli_num_rows($messages_result) > 0): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Conversation</th>
                                        <th class="conversation-detail">Subject</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php mysqli_data_seek($messages_result, 0); while ($message = mysqli_fetch_assoc($messages_result)): $contact = portalMessageContact($message, $user_id); ?>
                                        <tr class="<?php echo $contact['unread_count'] > 0 ? 'unread' : ''; ?>">
                                            <td>
                                                <a class="conversation-link" href="?view=<?php echo (int) $message['id']; ?><?php echo $message['is_deleted'] ? '&amp;from_trash=1' : ''; ?>" aria-label="<?php echo htmlspecialchars('Open conversation with ' . $contact['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php portalMessageAvatar($contact); ?></a>
                                            </td>
                                            <td class="conversation-detail">
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($message['subject']); ?></div>
                                                <div class="message-preview" style="color: var(--text-muted); font-size: 12px; margin-top: 4px;">
                                                    <?php echo ($message['sender_id'] == $user_id ? 'You: ' : '') . htmlspecialchars($message['message']); ?>
                                                </div>
                                            </td>
                                            <td style="white-space: nowrap;"><?php echo date('M d, Y', strtotime($message['created_at'])); ?></td>
                                            <td>
                                                <div style="display: flex; gap: 8px;">
                                                    <form method="POST" action="" style="display:inline-block;" onsubmit="return portalConfirmForm(event, this, 'Are you sure you want to delete this message?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                        <input type="hidden" name="message_action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $message['id']; ?>">
                                                        <button type="submit" class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 60px; color: var(--text-muted);">
                            <i class="fas fa-envelope fa-4x" style="margin-bottom: 25px; color: var(--border-color); opacity: 0.5;"></i>
                            <h3>No Messages</h3>
                            <p>Your inbox is empty.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Trash -->
                <div id="trash-tab" style="display: none;">
                    <h3><i class="fas fa-trash"></i> Deleted Messages</h3>
                    <?php if (mysqli_num_rows($deleted_result) > 0): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Conversation</th>
                                        <th class="conversation-detail">Subject</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php mysqli_data_seek($deleted_result, 0); while ($message = mysqli_fetch_assoc($deleted_result)): $contact = portalMessageContact($message, $user_id); ?>
                                        <tr style="cursor: pointer; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='rgba(255,255,255,0.05)';" onmouseout="this.style.backgroundColor='transparent';" onclick="if(!event.target.closest('form') && !event.target.closest('a.btn')) window.location.href='?view=<?php echo $message['id']; ?>&from_trash=1'">
                                            <td>
                                                <a class="conversation-link" href="?view=<?php echo (int) $message['id']; ?><?php echo $message['is_deleted'] ? '&amp;from_trash=1' : ''; ?>" aria-label="<?php echo htmlspecialchars('Open conversation with ' . $contact['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php portalMessageAvatar($contact); ?></a>
                                            </td>
                                            <td class="conversation-detail"><?php echo htmlspecialchars($message['subject']); ?></td>
                                            <td style="white-space: nowrap;"><?php echo date('M d, Y', strtotime($message['created_at'])); ?></td>
                                            <td>
                                                <div style="display: flex; gap: 8px;">
                                                    <form method="POST" action="" style="display:inline-block;" onsubmit="return portalConfirmForm(event, this, 'Are you sure you want to restore this message?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                        <input type="hidden" name="message_action" value="restore">
                                                        <input type="hidden" name="id" value="<?php echo (int) $message['id']; ?>">
                                                        <button type="submit" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;" title="Restore">
                                                            <i class="fas fa-redo"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 60px; color: var(--text-muted);">
                            <i class="fas fa-trash fa-4x" style="margin-bottom: 25px; color: var(--border-color); opacity: 0.5;"></i>
                            <h3>Trash is Empty</h3>
                            <p>No deleted messages found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <dialog id="composeModal" class="compose-modal" aria-labelledby="composeTitle">
        <div class="content-body compose-card">
            <div class="compose-heading"><h3 id="composeTitle"><i class="fas fa-paper-plane"></i> New Message</h3><button type="button" class="compose-close" onclick="closeCompose()" aria-label="Close new message">&times;</button></div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="send_message" value="1">
                <div class="form-group">
                    <label for="receiver_id">Send To</label>
                    <div class="recipient-picker">
                        <input type="hidden" id="receiver_id" name="receiver_id" value="<?php echo $compose_receiver_id ?: ''; ?>">
                        <button type="button" id="recipientToggle" class="form-control recipient-toggle" aria-expanded="false" aria-controls="recipientMenu"><span id="recipientLabel">Select a recipient</span><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
                        <div id="recipientMenu" class="recipient-menu" hidden>
                            <div class="recipient-options">
                            <?php foreach ($recipient_groups as $group_label => $recipients): ?>
                                <?php if (empty($recipients)) continue; ?>
                                <div class="recipient-group">
                                    <div class="recipient-group-label"><?php echo htmlspecialchars($group_label); ?></div>
                                    <?php foreach ($recipients as $recipient): ?>
                                    <button type="button" class="recipient-option" data-recipient-id="<?php echo (int) $recipient['id']; ?>" aria-pressed="<?php echo $compose_receiver_id === (int) $recipient['id'] ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($recipient['name']); ?></button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            <p id="recipientEmpty" hidden>No matching recipients.</p>
                            </div>
                        </div>
                        <p id="recipientError" hidden>Please select a recipient.</p>
                    </div>
                </div>
                <div class="form-group">
                    <label for="new_subject">Subject</label>
                    <input type="text" id="new_subject" name="subject" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="new_message">Message</label>
                    <textarea id="new_message" name="message" rows="6" class="form-control" required placeholder="Type your message here..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-paper-plane"></i> Send Message
                </button>
            </form>
        </div>
        </dialog>

    </div>
</div>

    <script>


        const recipientToggle = document.getElementById('recipientToggle');
        const recipientMenu = document.getElementById('recipientMenu');
        const recipientOptions = [...document.querySelectorAll('.recipient-option')];
        function setRecipientMenu(open) {
            recipientMenu.hidden = !open;
            recipientToggle.setAttribute('aria-expanded', String(open));
            if (open) (recipientOptions.find(option => option.getAttribute('aria-pressed') === 'true') || recipientOptions[0])?.focus();
        }
        recipientToggle.addEventListener('click', () => setRecipientMenu(recipientMenu.hidden));
        document.addEventListener('click', event => {
            if (!event.target.closest('.recipient-picker')) setRecipientMenu(false);
        });
        recipientOptions.forEach(option => option.addEventListener('click', () => {
            document.getElementById('receiver_id').value = option.dataset.recipientId;
            document.getElementById('recipientLabel').textContent = option.textContent;
            recipientOptions.forEach(item => item.setAttribute('aria-pressed', String(item === option)));
            document.getElementById('recipientError').hidden = true;
            setRecipientMenu(false);
            recipientToggle.focus();
        }));
        const selectedRecipient = recipientOptions.find(option => option.getAttribute('aria-pressed') === 'true');
        if (selectedRecipient) document.getElementById('recipientLabel').textContent = selectedRecipient.textContent;
        document.getElementById('receiver_id').closest('form').addEventListener('submit', event => {
            if (!document.getElementById('receiver_id').value) {
                event.preventDefault();
                document.getElementById('recipientError').hidden = false;
                setRecipientMenu(true);
            }
        });
        recipientMenu.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); setRecipientMenu(false); recipientToggle.focus(); }
        });

        const composeModal = document.getElementById('composeModal');
        function openCompose() { if (!composeModal.open) composeModal.showModal(); }
        function closeCompose() { composeModal.close(); }
        composeModal.addEventListener('cancel', (event) => event.preventDefault());
        <?php if ($compose_receiver_id > 0): ?>openCompose();<?php endif; ?>

        function showTab(tabName) {
            tabName = tabName === 'trash' ? 'trash' : 'all';
            const url = new URL(location.href);
            url.searchParams.set('tab', tabName);
            history.replaceState(null, '', url);
            // Update tab buttons
            document.querySelectorAll('.message-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector('[data-tab="' + tabName + '"]').classList.add('active');
            
            // Hide all tabs
            document.getElementById('all-tab').style.display = 'none';
            document.getElementById('trash-tab').style.display = 'none';
            
            // Show selected tab
            document.getElementById(tabName + '-tab').style.display = 'block';
        }
        
        const messageStateKey = 'portal-messages:<?php echo (int) $user_id; ?>:' + (new URLSearchParams(location.search).get('view') || 'inbox');
        function saveMessageView() {
            const thread = document.querySelector('.message-thread');
            const workspace = document.querySelector('.messages-page');
            try {
                sessionStorage.setItem(messageStateKey, JSON.stringify({
                    expanded: [...document.querySelectorAll('details.thread-message[open]')].map(item => item.dataset.messageId),
                    threadScroll: thread ? thread.scrollTop : 0,
                    pageScroll: workspace ? workspace.scrollTop : 0
                }));
            } catch (_) { /* Storage may be disabled; navigation still works. */ }
        }
        window.addEventListener('pagehide', saveMessageView);
        document.addEventListener('visibilitychange', () => { if (document.hidden) saveMessageView(); });
        document.addEventListener('DOMContentLoaded', function() {
            const params = new URLSearchParams(location.search);
            showTab(params.get('tab') || (params.has('from_trash') ? 'trash' : 'all'));
            try {
                const saved = JSON.parse(sessionStorage.getItem(messageStateKey) || 'null');
                if (!saved) return;
                const expanded = new Set(saved.expanded || []);
                document.querySelectorAll('details.thread-message').forEach(item => { item.open = expanded.has(item.dataset.messageId); });
                requestAnimationFrame(() => {
                    const thread = document.querySelector('.message-thread');
                    const workspace = document.querySelector('.messages-page');
                    if (thread) thread.scrollTop = saved.threadScroll || 0;
                    if (workspace) workspace.scrollTop = saved.pageScroll || 0;
                });
            } catch (_) { /* Ignore an unavailable or outdated saved view. */ }
        });

        let refreshBusy = false;
        async function refreshMessages() {
            if (refreshBusy || document.hidden) return;
            refreshBusy = true;
            try {
                const response = await fetch(window.location.href, {cache: 'no-store', headers: {'X-Requested-With': 'XMLHttpRequest'}});
                if (!response.ok || response.redirected) throw new Error('Unavailable');
                const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                for (const id of ['all-tab', 'trash-tab']) {
                    const current = document.getElementById(id), next = doc.getElementById(id);
                    if (!current || !next) throw new Error('Unavailable');
                    if (current.innerHTML !== next.innerHTML) current.innerHTML = next.innerHTML;
                }
                const currentThread = document.querySelector('.message-thread');
                const nextThread = doc.querySelector('.message-thread');
                if (currentThread && nextThread) {
                    const expanded = new Set([...currentThread.querySelectorAll('details[open][data-message-id]')].map(item => item.dataset.messageId));
                    nextThread.querySelectorAll('details[data-message-id]').forEach(item => { item.open = expanded.has(item.dataset.messageId); });
                    if (currentThread.innerHTML !== nextThread.innerHTML) {
                    const previousScroll = currentThread.scrollTop;
                    const nearBottom = currentThread.scrollHeight - currentThread.scrollTop - currentThread.clientHeight < 60;
                    currentThread.innerHTML = nextThread.innerHTML;
                    if (nearBottom) currentThread.scrollTop = currentThread.scrollHeight;
                    else currentThread.scrollTop = previousScroll;
                    }
                }
                if (typeof updatePortalNotifCount === 'function') updatePortalNotifCount();

            } catch (error) {
                const status = document.querySelector('.sync-status');
                if (status) status.textContent = 'Reconnecting…';
            } finally { refreshBusy = false; }
        }
        refreshMessages();
        setInterval(refreshMessages, 5000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshMessages(); });

    </script>
    </div>
<?php require __DIR__ . '/includes/footer-bar.php'; ?>
</body>
</html>
