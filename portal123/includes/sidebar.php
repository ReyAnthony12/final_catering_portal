<?php
require_once __DIR__ . '/logo_asset.php';

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
$sidebar_unread_count = isset($conn, $_SESSION['user_id'])
    ? getUnreadCount((int) $_SESSION['user_id']) : 0;
$sidebar_message_unread = 0;
if (isset($conn, $_SESSION['user_id'])) {
    $sidebar_message_result = mysqli_query($conn, 'SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ' . (int) $_SESSION['user_id'] . ' AND is_read = 0 AND is_deleted = 0');
    if ($sidebar_message_result) {
        $sidebar_message_unread = (int) mysqli_fetch_assoc($sidebar_message_result)['unread_count'];
    }
}

// Fetch site logo from settings
$sidebar_logo = portalLogoUrl('uploads/logo.png');
if (isset($conn)) {
    $logo_query = "SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo'";
    $logo_result = mysqli_query($conn, $logo_query);
    if ($logo_result && mysqli_num_rows($logo_result) > 0) {
        $logo_row = mysqli_fetch_assoc($logo_result);
        if (!empty($logo_row['setting_value'])) {
            $sidebar_logo = portalLogoUrl($logo_row['setting_value']);
        }
    }
}
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header dashboard-brand">
        <span class="logo-mark">
            <img src="<?php echo htmlspecialchars($sidebar_logo); ?>" alt="Logo" class="logo-img">
        </span>
        <h3>Portal Admin</h3>
    </div>

    <div class="portal-utility">
        <div class="portal-utility-card" id="portalNotifWrapper">
            <button type="button" class="portal-notification-btn" onclick="togglePortalNotifications()">
                <span class="portal-bell-wrap">
                    <i class="fas fa-bell" style="color: var(--accent-gold); font-size: 16px;"></i>
                    <span class="nav-alert-dot" id="portal-notif-dot"></span>
                </span>
                <span class="portal-notification-copy">
                    <strong>Notifications</strong>
                </span>
                <span class="portal-notification-badge" id="portal-notif-count" role="status" aria-live="polite" aria-label="<?php echo $sidebar_unread_count; ?> unread notifications" style="display: <?php echo $sidebar_unread_count > 0 ? 'inline-block' : 'none'; ?>;"><?php echo $sidebar_unread_count > 99 ? '99+' : $sidebar_unread_count; ?></span>
            </button>

            <div class="portal-notification-dropdown" id="portalNotifDropdown">
                <div class="portal-notification-header">
                    <h4>Recent Updates</h4>
                    <a href="#" onclick="markAllPortalAsRead(); return false;">Mark all read</a>
                </div>
                <div class="portal-notification-list" id="portal-notif-list">
                    <div class="portal-notification-empty">Loading notifications...</div>
                </div>
            </div>
        </div>
    </div>
    
    <ul class="sidebar-menu">
        <li>
            <a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>" data-nav="dashboard">
                <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                <span class="nav-alert-dot" data-nav-dot="dashboard"></span>
            </a>
        </li>
        <li>
            <a href="users.php" class="<?php echo ($current_page == 'users.php') ? 'active' : ''; ?>" data-nav="users">
                <i class="fas fa-users"></i> <span>Users</span>
                <span class="nav-alert-dot" data-nav-dot="users"></span>
            </a>
        </li>
        <li>
            <a href="messages.php" class="<?php echo ($current_page == 'messages.php') ? 'active' : ''; ?>" data-nav="messages">
                <i class="fas fa-envelope"></i> <span>Messages</span>
                <span class="nav-alert-dot<?php echo $sidebar_message_unread > 0 ? ' is-on' : ''; ?>" data-nav-dot="messages" aria-label="<?php echo $sidebar_message_unread; ?> unread messages"></span>
            </a>
        </li>
        <li>
            <a href="reports.php" class="<?php echo ($current_page == 'reports.php') ? 'active' : ''; ?>" data-nav="reports">
                <i class="fas fa-chart-bar"></i> <span>Reports</span>
            </a>
        </li>
        <li>
            <a href="payouts.php" class="<?php echo ($current_page == 'payouts.php') ? 'active' : ''; ?>" data-nav="payouts">
                <i class="fas fa-wallet"></i> <span>Payouts</span>
                <span class="nav-alert-dot" data-nav-dot="payouts"></span>
            </a>
        </li>
        <li>
            <a href="manage-users.php" class="<?php echo ($current_page == 'manage-users.php') ? 'active' : ''; ?>" data-nav="manage-users">
                <i class="fas fa-user-cog"></i> <span>Manage Users</span>
                <span class="nav-alert-dot" data-nav-dot="manage-users"></span>
            </a>
        </li>
        <li>
            <a href="user-reports.php" class="<?php echo ($current_page == 'user-reports.php') ? 'active' : ''; ?>" data-nav="user-reports">
                <i class="fas fa-flag"></i> <span>User Appeals</span>
                <span class="nav-alert-dot" data-nav-dot="user-reports"></span>
            </a>
        </li>
        <li>
            <a href="subscription.php" class="<?php echo ($current_page == 'subscription.php') ? 'active' : ''; ?>" data-nav="subscription">
                <i class="fas fa-credit-card"></i> <span>Subscription</span>
                <span class="nav-alert-dot" data-nav-dot="subscription"></span>
            </a>
        </li>
        <li>
            <a href="system-settings.php" class="<?php echo ($current_page == 'system-settings.php') ? 'active' : ''; ?>" data-nav="system-settings">
                <i class="fas fa-cog"></i> <span>System Settings</span>
                <span class="nav-alert-dot" data-nav-dot="system-settings"></span>
            </a>
        </li>
        <li style="margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 20px;">
            <a href="../index.php" target="_blank" style="color: var(--accent-gold);">
                <i class="fas fa-external-link-alt"></i> <span>View Website</span>
            </a>
        </li>
        <li>
            <a href="../logout.php">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </a>
        </li>
    </ul>
</div>
