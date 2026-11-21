<?php
// API endpoint for notifications
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/order_reference_helper.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

header('Content-Type: application/json');
header('Cache-Control: no-store, private');

function customerNotificationTargets($conn, $userId) {
    $targets = [];
    $uid = (int) $userId;
    $rows = mysqli_query($conn, "SELECT id, link FROM notifications WHERE user_id = $uid AND is_read = 0");
    while ($rows && ($row = mysqli_fetch_assoc($rows))) {
        $link = html_entity_decode((string) $row['link']);
        $page = basename(parse_url($link, PHP_URL_PATH) ?: '');
        if ($page === '') continue;
        parse_str(parse_url($link, PHP_URL_QUERY) ?: '', $query);
        $targets[] = ['notification_id' => (int) $row['id'], 'page' => $page,
            'id' => (int) ($query['id'] ?? $query['order_id'] ?? $query['view'] ?? 0)];
    }
    return $targets;
}

// Message badges and bell alerts share the same underlying read state.
if (in_array(getUserRole(), ['customer', 'portal', 'store', 'caterer'], true)) {
    $notification_user_id = (int) $user_id;
    $message_alerts = mysqli_query($conn, "SELECT id, link FROM notifications WHERE user_id = $notification_user_id AND is_read = 0 AND type = 'new_message'");
    while ($message_alerts && ($alert = mysqli_fetch_assoc($message_alerts))) {
        parse_str(parse_url(html_entity_decode((string) $alert['link']), PHP_URL_QUERY) ?: '', $alert_query);
        $message_id = (int) ($alert_query['view'] ?? 0);
        if ($message_id > 0) {
            $message_state = mysqli_query($conn, "SELECT is_read FROM messages WHERE id = $message_id AND receiver_id = $notification_user_id LIMIT 1");
            $message_row = $message_state ? mysqli_fetch_assoc($message_state) : null;
            if ($message_row && (int) $message_row['is_read'] === 1) markAsRead((int) $alert['id'], $notification_user_id);
        } else {
            $unread_messages = mysqli_query($conn, "SELECT id FROM messages WHERE receiver_id = $notification_user_id AND is_read = 0 AND is_deleted = 0 LIMIT 1");
            if ($unread_messages && mysqli_num_rows($unread_messages) === 0) markAsRead((int) $alert['id'], $notification_user_id);
        }
    }
}

switch ($action) {
    case 'sync_view':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array(getUserRole(), ['customer', 'portal', 'store', 'caterer'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false]);
            break;
        }
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $page = basename((string) ($data['page'] ?? ''));
        $view_id = (int) ($data['id'] ?? 0);
        if (getUserRole() === 'portal') {
            // Section activity notices are read when that section is viewed.
            // Targeted account/conversation notices retain their own read action.
            $pages = ['dashboard.php', 'users.php', 'messages.php', 'reports.php', 'payouts.php', 'manage-users.php', 'user-reports.php', 'subscription.php', 'system-settings.php'];
            if (in_array($page, $pages, true)) {
                $uid = (int) $user_id;
                $rows = mysqli_query($conn, "SELECT id, link FROM notifications WHERE user_id = $uid AND is_read = 0");
                while ($rows && ($row = mysqli_fetch_assoc($rows))) {
                    $link = html_entity_decode((string) $row['link']);
                    if (basename(parse_url($link, PHP_URL_PATH) ?: '') !== $page) continue;
                    parse_str(parse_url($link, PHP_URL_QUERY) ?: '', $target);
                    if (array_intersect(['id', 'order_id', 'view', 'account_id', 'caterer_id', 'caterer'], array_keys($target))) continue;
                    markAsRead((int) $row['id'], $uid);
                }
            }
            echo json_encode(['success' => true, 'count' => getUnreadCount($user_id)]);
            break;
        }
        $view_pages = in_array(getUserRole(), ['store', 'caterer'], true)
            ? ['dashboard.php', 'reservations.php', 'booking-details.php', 'order-details.php', 'payments.php', 'messages.php', 'reviews.php', 'reports.php', 'subscription.php', 'profile.php']
            : ['payments.php', 'booking-details.php', 'messages.php', 'booking.php'];
        if (!in_array($page, $view_pages, true)) {
            echo json_encode(['success' => true, 'count' => getUnreadCount($user_id)]);
            break;
        }
        $uid = (int) $user_id;
        $rows = mysqli_query($conn, "SELECT id, type, link FROM notifications WHERE user_id = $uid AND is_read = 0");
        while ($rows && ($row = mysqli_fetch_assoc($rows))) {
            $link = html_entity_decode((string) $row['link']);
            if (basename(parse_url($link, PHP_URL_PATH) ?: '') !== $page) continue;
            parse_str(parse_url($link, PHP_URL_QUERY) ?: '', $query);
            $target_id = (int) ($query['id'] ?? $query['order_id'] ?? $query['view'] ?? 0);
            if ($target_id > 0 && $target_id !== $view_id) continue;
            // Conversation notifications are cleared only after the messages are actually read.
            if ($page === 'messages.php') {
                $unread = mysqli_query($conn, "SELECT id FROM messages WHERE receiver_id = $uid AND is_read = 0 AND is_deleted = 0 LIMIT 1");
                if (!$unread || mysqli_num_rows($unread) > 0) continue;
            }
            markAsRead((int) $row['id'], $uid);
        }
        $approved_count_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $uid AND is_read = 0 AND type = 'reschedule_approved'");
        $approved_count = $approved_count_result ? (int) mysqli_fetch_assoc($approved_count_result)['count'] : 0;
        $payload = ['success' => true, 'count' => getUnreadCount($user_id), 'booking_reschedule_count' => $approved_count];
        if (getUserRole() === 'customer') $payload['targets'] = customerNotificationTargets($conn, $uid);
        echo json_encode($payload);
        break;
    case 'get':
        // Get notifications for user
        $limit = intval($_GET['limit'] ?? 10);
        $notifications = getNotifications($user_id, $limit);
        if (in_array(getUserRole(), ['customer', 'store', 'caterer', 'portal'], true)) {
            foreach ($notifications as &$notification) {
                $notification['title'] = formatNotificationBookingReferences($notification['title'] ?? '');
                $notification['message'] = formatNotificationBookingReferences($notification['message'] ?? '');
            }
            unset($notification);
        }
        echo json_encode(['success' => true, 'notifications' => $notifications]);
        break;
        
    case 'count':
        $count = getUnreadCount($user_id);
        $payload = ['success' => true, 'count' => $count];
        if (in_array(getUserRole(), ['store', 'caterer'], true)) {
            $sections = [];
            $rows = mysqli_query($conn, 'SELECT link FROM notifications WHERE user_id = ' . (int) $user_id . ' AND is_read = 0');
            while ($rows && ($row = mysqli_fetch_assoc($rows))) {
                $page = basename(parse_url(html_entity_decode((string) $row['link']), PHP_URL_PATH) ?: '');
                if ($page !== '') $sections[$page] = ($sections[$page] ?? 0) + 1;
            }
            $payload['sections'] = $sections;
        }
        if (function_exists('getUserRole') && getUserRole() === 'portal' && function_exists('getPortalSidebarDots')) {
            $alerts = getPortalSidebarDots((int) $user_id);
            $payload['count'] = $alerts['count'];
            $payload['dots'] = $alerts['dots'];
        }
        echo json_encode($payload);
        break;
        
    case 'mark_read':
        // Mark single notification as read
        $data = json_decode(file_get_contents('php://input'), true);
        $notification_id = intval($data['id'] ?? 0);
        
        if ($notification_id > 0) {
            $success = markAsRead($notification_id, $user_id);
            echo json_encode(['success' => $success]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid notification ID']);
        }
        break;
        
    case 'mark_all_read':
        // Mark all as read
        $success = markAllAsRead($user_id);
        echo json_encode(['success' => $success]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
?>
