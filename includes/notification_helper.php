<?php

if (!function_exists('createNotification')) {
    /**
     * Create a new notification for a user.
     */
    function createNotification($user_id, $type, $title, $message, $link = null) {
        global $conn;
        $user_id = intval($user_id);
        $type = mysqli_real_escape_string($conn, $type);
        $title = mysqli_real_escape_string($conn, $title);
        $message = mysqli_real_escape_string($conn, $message);
        $link = $link ? "'" . mysqli_real_escape_string($conn, $link) . "'" : "NULL";

        $sql = "INSERT INTO notifications (user_id, type, title, message, link)
                VALUES ($user_id, '$type', '$title', '$message', $link)";
        return mysqli_query($conn, $sql);
    }
}

if (!function_exists('notifyUsersByRole')) {
    /**
     * Create the same notification for all users with the given role.
     */
    function notifyUsersByRole($role, $type, $title, $message, $link = null) {
        global $conn;

        $role = mysqli_real_escape_string($conn, trim((string) $role));
        if ($role === '') {
            return false;
        }

        $users_result = mysqli_query($conn, "SELECT id FROM users WHERE role = '$role' AND status = 'active'");
        if (!$users_result) {
            return false;
        }

        $sent = false;
        while ($user = mysqli_fetch_assoc($users_result)) {
            if (createNotification((int) $user['id'], $type, $title, $message, $link)) {
                $sent = true;
            }
        }

        return $sent;
    }
}

if (!function_exists('getPortalSidebarDots')) {
    /**
     * Which portal sidebar items should show a red alert dot.
     */
    function getPortalSidebarDots(int $user_id): array
    {
        global $conn;

        $dots = [
            'notifications' => false,
            'dashboard' => false,
            'users' => false,
            'messages' => false,
            'reports' => false,
            'payouts' => false,
            'user-reports' => false,
            'manage-users' => false,
            'subscription' => false,
            'system-settings' => false,
        ];

        $pending_count = 0;
        $pending_result = mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'store' AND status = 'pending'");
        if ($pending_result) {
            $pending_count = (int) (mysqli_fetch_assoc($pending_result)['c'] ?? 0);
        }

        $unread = getUnreadCount($user_id);
        if ($unread > 0) {
            $dots['notifications'] = true;
        }

        $notif_sql = "SELECT type, link FROM notifications WHERE user_id = " . intval($user_id) . " AND is_read = 0";
        $notif_result = mysqli_query($conn, $notif_sql);
        if ($notif_result) {
            while ($row = mysqli_fetch_assoc($notif_result)) {
                $type = (string) ($row['type'] ?? '');
                $link = strtolower((string) ($row['link'] ?? ''));
                $page = basename(parse_url($link, PHP_URL_PATH) ?: '');

                if ($page === 'users.php' || $type === 'store_registration') {
                    // Only show red dot on users/approvals if there are actually pending stores to approve
                    if ($pending_count > 0) {
                        $dots['users'] = true;
                    }
                }
                if ($page === 'manage-users.php' || $type === 'customer_registration') {
                    $dots['manage-users'] = true;
                }
                if ($page === 'reports.php' || in_array($type, ['new_order', 'order_status', 'payment_received', 'payment_confirmed', 'payment_verified', 'reschedule_request', 'menu_change_request'], true)) {
                    $dots['reports'] = true;
                }
                if ($page === 'system-settings.php') {
                    $dots['system-settings'] = true;
                }
                if ($page === 'payouts.php') {
                    $dots['payouts'] = true;
                }
                if ($page === 'user-reports.php') {
                    $dots['user-reports'] = true;
                }
                if ($page === 'dashboard.php') {
                    $dots['dashboard'] = true;
                }
            }
        }

        // Payment alerts follow actionable records, so processed payments cannot
        // leave a stale sidebar dot through an unread notification.
        $subscription_result = mysqli_query($conn, "SELECT 1 FROM subscriptions WHERE payment_status = 'pending' LIMIT 1");
        if ($subscription_result) {
            $dots['subscription'] = mysqli_num_rows($subscription_result) > 0;
        }

        $msg_result = mysqli_query($conn, "SELECT COUNT(*) AS c FROM messages WHERE receiver_id = " . intval($user_id) . " AND is_read = 0 AND is_deleted = 0");
        if ($msg_result) {
            $msg_row = mysqli_fetch_assoc($msg_result);
            $dots['messages'] = intval($msg_row['c'] ?? 0) > 0;
            if ($dots['messages']) {
                $dots['notifications'] = true;
            }
        }

        if ($pending_count > 0) {
            $dots['users'] = true;
            $dots['notifications'] = true;
        }

        return [
            'count' => $unread,
            'dots' => $dots,
        ];
    }
}

if (!function_exists('notifyPortalAdmins')) {
    /**
     * Notify all active portal admins.
     */
    function notifyPortalAdmins($type, $title, $message, $link = null) {
        return notifyUsersByRole('portal', $type, $title, $message, $link);
    }
}
