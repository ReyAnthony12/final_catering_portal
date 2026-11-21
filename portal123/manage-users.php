<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
requireLogin();

if (getUserRole() !== 'portal') {
    header('Location: ../index.php');
    exit();
}

// Share the same protection rules between the account list and both delete handlers.
$today = date('Y-m-d');
$deletionChecks = "
    EXISTS (SELECT 1 FROM bookings b WHERE ((u.role = 'customer' AND b.customer_id = u.id)
            OR (u.role IN ('store', 'caterer') AND b.store_id = u.id))
        AND COALESCE(b.order_status, '') NOT IN ('completed', 'cancelled', 'rejected', 'declined')) AS ongoing_bookings,
    EXISTS (SELECT 1 FROM subscriptions sub WHERE sub.store_id = u.id AND sub.payment_status = 'paid'
        AND (sub.end_date >= '$today' OR (
            (sub.end_date IS NULL OR sub.end_date = '0000-00-00') AND EXISTS (
                SELECT 1 FROM store_details coverage WHERE coverage.user_id = u.id
                    AND coverage.subscription_status = 'active' AND coverage.subscription_expiry >= '$today'
            )
        ))) AS paid_coverage";

function accountDeletionReason(array $account): ?string {
    if ($account['role'] === 'customer') {
        return $account['ongoing_bookings'] ? 'Cannot delete this customer while bookings are ongoing.' : null;
    }
    if (!in_array($account['role'], ['store', 'caterer'], true)) return null;
    if ($account['ongoing_bookings'] && $account['paid_coverage']) {
        return 'Cannot delete this caterer while bookings are ongoing and paid subscription coverage is active.';
    }
    if ($account['ongoing_bookings']) return 'Cannot delete this caterer while bookings are ongoing.';
    if ($account['paid_coverage']) return 'Cannot delete this caterer while paid subscription coverage is active.';
    return null;
}

function loadAccountForDeletion(mysqli $conn, int $userId, string $checks): array {
    $result = $conn->query("SELECT u.id, u.role, $checks FROM users u
        WHERE u.id = $userId AND u.role IN ('store', 'caterer', 'customer') FOR UPDATE");
    $account = $result->fetch_assoc();
    if (!$account) throw new RuntimeException('Account not found.');
    return $account;
}

$deletionNotice = $_SESSION['manage_users_deletion_notice'] ?? null;
unset($_SESSION['manage_users_deletion_notice']);
$csrf_token = generateCSRFToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'view_account') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false]);
        exit();
    }
    $accountId = (int) ($_POST['user_id'] ?? 0);
    $accountResult = mysqli_query($conn, "SELECT first_name, last_name FROM users WHERE id = $accountId AND role IN ('customer', 'store', 'caterer')");
    $account = $accountResult ? mysqli_fetch_assoc($accountResult) : null;
    $success = false;
    if ($account) {
        $adminId = (int) $_SESSION['user_id'];
        $notices = mysqli_query($conn, "SELECT id, link, message FROM notifications WHERE user_id = $adminId AND is_read = 0 AND (type = 'customer_registration' OR link LIKE '%manage-users.php%')");
        $ids = [];
        while ($notices && ($notice = mysqli_fetch_assoc($notices))) {
            $query = [];
            parse_str(parse_url($notice['link'] ?? '', PHP_URL_QUERY) ?: '', $query);
            $matches = isset($query['account_id'])
                ? (int) $query['account_id'] === $accountId
                : $notice['message'] === $account['first_name'] . ' ' . $account['last_name'] . ' created a new customer account.';
            if ($matches) $ids[] = (int) $notice['id'];
        }
        $success = !$ids || mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = $adminId AND id IN (" . implode(',', $ids) . ')');
    }
    echo json_encode(['success' => (bool) $success]);
    exit();
}
$trash_view = ($_GET['view'] ?? '') === 'trash';
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS portal_account_trash (
    user_id INT NOT NULL PRIMARY KEY,
    deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'reset_password') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        header('Location: manage-users.php?error=invalid_token');
        exit();
    }

    $user_id = mysqli_real_escape_string($conn, $_POST['user_id'] ?? '');
    $action = mysqli_real_escape_string($conn, $_POST['action']);
    $redirect_type = isset($_POST['redirect_type']) ? urlencode($_POST['redirect_type']) : 'caterer';

    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        header('Location: manage-users.php?type=' . $redirect_type . '&error=invalid_user');
        exit();
    }

    // Never allow deleting/closing the current portal admin session user by mistake
    if ((int) ($_SESSION['user_id'] ?? 0) === $user_id) {
        header('Location: manage-users.php?type=' . $redirect_type . '&error=cannot_modify_self');
        exit();
    }

    // Update ordinary account actions without navigating away from the selected row.
    if (($_POST['confirm_hard_delete'] ?? '') !== '1' || $action !== 'delete') {
        $json_response = ($_POST['response_format'] ?? '') === 'json';
        if ($json_response) header('Content-Type: application/json');
        if (!in_array($action, ['delete', 'close', 'restore', 'activate'], true)
            || !empty($_POST['confirm_hard_delete'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid account action.']);
            exit();
        }
        mysqli_begin_transaction($conn);
        try {
            $target = mysqli_query($conn, "SELECT id FROM users WHERE id = $user_id AND role IN ('store', 'caterer', 'customer') FOR UPDATE");
            if (!$target || !mysqli_fetch_assoc($target)) {
                throw new Exception('Account not found.');
            }
            if ($action === 'delete') {
                $reason = accountDeletionReason(loadAccountForDeletion($conn, $user_id, $deletionChecks));
                if ($reason !== null) {
                    $conn->rollback();
                    if ($json_response) {
                        http_response_code(409);
                        echo json_encode(['success' => false, 'message' => $reason]);
                    } else {
                        $_SESSION['manage_users_deletion_notice'] = $reason;
                        header('Location: manage-users.php?type=' . $redirect_type);
                    }
                    exit();
                }
                if (!mysqli_query($conn, "INSERT INTO portal_account_trash (user_id) VALUES ($user_id) ON DUPLICATE KEY UPDATE deleted_at = CURRENT_TIMESTAMP")) {
                    throw new Exception('Unable to move account to Trash.');
                }
            } elseif (in_array($action, ['restore', 'activate'], true)) {
                if (!mysqli_query($conn, "DELETE FROM portal_account_trash WHERE user_id = $user_id")) {
                    throw new Exception('Unable to restore account.');
                }
            } else {
                $trashed = mysqli_query($conn, "SELECT user_id FROM portal_account_trash WHERE user_id = $user_id");
                if (!$trashed || mysqli_num_rows($trashed)) throw new Exception('Restore the account from Trash first.');
            }
            $status = in_array($action, ['restore', 'activate'], true) ? 'active' : 'closed';
            $is_open = $status === 'active' ? 1 : 0;
            if (!mysqli_query($conn, "UPDATE users SET status = '$status' WHERE id = $user_id")
                || !mysqli_query($conn, "UPDATE store_details SET is_open = $is_open WHERE user_id = $user_id")) {
                throw new Exception('Account update failed.');
            }
            $details_result = mysqli_query($conn, "SELECT is_open, business_open_time, business_close_time, auto_schedule_enabled FROM store_details WHERE user_id = $user_id");
            $details = $details_result ? mysqli_fetch_assoc($details_result) : null;
            $available = $details && isStoreAvailableNow($details['is_open'], $details['business_open_time'], $details['business_close_time'], $details['auto_schedule_enabled']);
            mysqli_commit($conn);
            if ($json_response) {
                echo json_encode(['success' => true, 'status' => $status, 'is_available' => (bool) $available]);
            } else {
                header('Location: manage-users.php?type=' . $redirect_type . ($trash_view ? '&view=trash' : ''));
            }
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to update this account. Please try again.']);
        }
        exit();
    }

    switch ($action) {
        case 'activate':
        case 'restore':
            mysqli_query($conn, "UPDATE users SET status = 'active' WHERE id = $user_id AND role IN ('store', 'caterer', 'customer')");
            mysqli_query($conn, "UPDATE store_details SET is_open = 1 WHERE user_id = $user_id");
            break;
        case 'close':
            mysqli_query($conn, "UPDATE users SET status = 'closed' WHERE id = $user_id AND role IN ('store', 'caterer', 'customer')");
            mysqli_query($conn, "UPDATE store_details SET is_open = 0 WHERE user_id = $user_id");
            break;
        case 'delete':
            // Soft-delete preferred: mark closed instead of hard-delete to avoid FK / data loss issues
            // Hard delete only if explicitly forced via confirm_hard_delete=1
            $force_hard = isset($_POST['confirm_hard_delete']) && $_POST['confirm_hard_delete'] === '1';
            if ($force_hard) {
                mysqli_begin_transaction($conn);
                try {
                    $reason = accountDeletionReason(loadAccountForDeletion($conn, $user_id, $deletionChecks));
                    if ($reason !== null) {
                        $conn->rollback();
                        $_SESSION['manage_users_deletion_notice'] = $reason;
                        header('Location: manage-users.php?type=' . $redirect_type);
                        exit();
                    }
                    mysqli_query($conn, "DELETE FROM messages WHERE sender_id = $user_id OR receiver_id = $user_id");
                    mysqli_query($conn, "DELETE FROM notifications WHERE user_id = $user_id");
                    mysqli_query($conn, "DELETE FROM bookings WHERE customer_id = $user_id OR store_id = $user_id");
                    mysqli_query($conn, "DELETE FROM products WHERE store_id = $user_id");
                    mysqli_query($conn, "DELETE FROM store_details WHERE user_id = $user_id");
                    mysqli_query($conn, "DELETE FROM subscriptions WHERE store_id = $user_id");
                    $ok = mysqli_query($conn, "DELETE FROM users WHERE id = $user_id AND role IN ('store', 'caterer', 'customer')");
                    if (!$ok) {
                        throw new Exception(mysqli_error($conn));
                    }
                    mysqli_commit($conn);
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    header('Location: manage-users.php?type=' . $redirect_type . '&error=delete_failed');
                    exit();
                }
            } else {
                mysqli_query($conn, "UPDATE users SET status = 'closed' WHERE id = $user_id AND role IN ('store', 'caterer', 'customer')");
                mysqli_query($conn, "UPDATE store_details SET is_open = 0 WHERE user_id = $user_id");
            }
            break;
    }

    header('Location: manage-users.php?type=' . $redirect_type);
    exit();
}

// Handle POST actions (Password Reset)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['portal_feedback'] = 'Invalid security token. Please try again.'; header('Location: manage-users.php');
        exit();
    }

    $user_id = mysqli_real_escape_string($conn, $_POST['user_id']);
    $new_password = $_POST['new_password'];
    
    // Hash the new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Update the password in the database
    $update_query = "UPDATE users SET password = '$hashed_password' WHERE id = '$user_id'";
    if (mysqli_query($conn, $update_query)) {
        $_SESSION['portal_feedback'] = 'Password has been reset successfully.'; header('Location: manage-users.php');
    } else {
        appLogError('Portal manage-users password reset failed', mysqli_error($conn));
        $_SESSION['portal_feedback'] = 'Unable to reset the password right now. Please try again.'; header('Location: manage-users.php');
    }
    exit();
}

// Get filter and search parameters
$filter = isset($_GET['filter']) && in_array($_GET['filter'], ['active', 'pending', 'rejected', 'closed'], true) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$user_type = ($_GET['type'] ?? '') === 'customer' ? 'customer' : 'caterer';


// Build where clause
$approvedCaterer = "EXISTS (SELECT 1 FROM store_details approval
    WHERE approval.user_id = u.id AND approval.approval_status = 'approved')";
$where_clause = "WHERE u.role IN ('caterer', 'store', 'customer')";
if (!$trash_view) {
    $where_clause .= " AND (u.role = 'customer' OR $approvedCaterer)";
}
$where_clause .= $trash_view
    ? " AND EXISTS (SELECT 1 FROM portal_account_trash t WHERE t.user_id = u.id)"
    : " AND NOT EXISTS (SELECT 1 FROM portal_account_trash t WHERE t.user_id = u.id)";
if ($filter !== 'all') {
    $where_clause .= " AND u.status = '$filter'";
}
if (!empty($search)) {
    $where_clause .= " AND (u.first_name LIKE '%$search%' OR u.last_name LIKE '%$search%' OR u.username LIKE '%$search%' OR u.email LIKE '%$search%')";
}
if ($user_type !== 'all') {
    if ($user_type === 'caterer') {
        $where_clause .= " AND u.role IN ('caterer', 'store')";
    } else {
        $where_clause .= " AND u.role = '$user_type'";
    }
}

// Get users query
$users_query = "
    SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.phone_number, u.profile_image, u.role, u.status, u.created_at,
           sd.business_name, sd.subscription_status, sd.is_open, sd.business_open_time, sd.business_close_time, sd.auto_schedule_enabled,
           (SELECT COUNT(*) FROM bookings WHERE customer_id = u.id) as customer_orders,
           (SELECT COUNT(*) FROM bookings WHERE store_id = u.id) as store_orders,
           $deletionChecks
    FROM users u 
    LEFT JOIN store_details sd ON u.id = sd.user_id 
    $where_clause
    ORDER BY u.role,
        CASE WHEN u.role = 'customer' THEN LOWER(TRIM(CONCAT_WS(' ', u.first_name, u.last_name))) END ASC,
        u.created_at DESC, u.id ASC
";
$users_result = mysqli_query($conn, $users_query);

// Get statistics
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users u WHERE u.role IN ('caterer', 'store') AND $approvedCaterer AND u.id NOT IN (SELECT user_id FROM portal_account_trash)) as total_stores,
        (SELECT COUNT(*) FROM users WHERE role = 'customer' AND id NOT IN (SELECT user_id FROM portal_account_trash)) as total_customers,
        (SELECT COUNT(*) FROM users WHERE status = 'active') as active_users,
        (SELECT COUNT(*) FROM users WHERE status = 'inactive') as inactive_users,
        (SELECT COUNT(*) FROM users WHERE status = 'closed') as closed_users
";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Separate stores and customers
$stores = [];
$customers = [];
mysqli_data_seek($users_result, 0); // Reset pointer
while ($user = mysqli_fetch_assoc($users_result)) {
    if ($user['role'] === 'caterer' || $user['role'] === 'store') {
        $stores[] = $user;
    } elseif ($user['role'] === 'customer') {
        $customers[] = $user;
    }
}


// Match the name indicator to the same unread alerts acknowledged on expansion.
$new_account_ids = [];
$account_notices = mysqli_query($conn, 'SELECT link, message FROM notifications WHERE user_id = ' . (int) $_SESSION['user_id']
    . " AND is_read = 0 AND (type = 'customer_registration' OR link LIKE '%manage-users.php%')");
while ($account_notices && ($notice = mysqli_fetch_assoc($account_notices))) {
    $notice_query = [];
    parse_str(parse_url($notice['link'] ?? '', PHP_URL_QUERY) ?: '', $notice_query);
    if (!empty($notice_query['account_id'])) {
        $new_account_ids[(int) $notice_query['account_id']] = true;
    } else {
        foreach ($customers as $customer) {
            if ($notice['message'] === $customer['first_name'] . ' ' . $customer['last_name'] . ' created a new customer account.') {
                $new_account_ids[(int) $customer['id']] = true;
            }
        }
    }
}

function portalActionForm($action, $userId, $label, $className, $icon, $csrfToken, $userType, $confirmMessage = '') {
    global $trash_view;
    if ($trash_view && $action !== 'restore') return '';
    if ($action === 'delete') $confirmMessage = 'Move this account to Trash? It will be hidden from public listings. You can restore it from Trash later.';
    $confirmAttr = '';
    if ($confirmMessage !== '') {
        $escapedConfirm = htmlspecialchars(json_encode($confirmMessage), ENT_QUOTES);
        $confirmAttr = ' onsubmit="return portalConfirmForm(event, this, ' . $escapedConfirm . ');"';
    }
    return '<form method="POST" action="" data-account-action style="display:inline-block;"' . $confirmAttr . '>'
        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">'
        . '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">'
        . '<input type="hidden" name="user_id" value="' . (int)$userId . '">'
        . '<input type="hidden" name="redirect_type" value="' . htmlspecialchars($userType) . '">'
        . '<button type="submit" class="btn ' . htmlspecialchars($className) . '"><i class="fas ' . htmlspecialchars($icon) . '"></i> ' . htmlspecialchars($label) . '</button>'
        . '</form>';
}

$page_title = 'Manage Users - Portal Dashboard';
$extra_head = '
<style>
.user-type-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    background: var(--secondary-bg);
    padding: 8px 10px;
    border-radius: 10px;
    border: 1px solid var(--border-color);
}

.user-type-tab {
    padding: 8px 16px;
    background: transparent;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    border-radius: 6px;
    transition: all 0.3s;
    font-weight: 500;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.user-type-tab:hover, .user-type-tab.active {
    background: var(--accent-gold);
    color: #000;
}

.user-section {
    margin-bottom: 25px;
    background: transparent;
    border-radius: 0;
    padding: 0;
    border: none;
}

.user-section h3 {
    margin-top: 0;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-main);
    font-size: 18px;
}

.no-users {
    text-align: center;
    padding: 30px;
    color: var(--text-muted);
    background: rgba(255,255,255,0.02);
    border-radius: 8px;
}

.data-table th, 
.data-table td {
    padding: 12px 16px;
    font-size: 13px;
}

.action-buttons-cell {
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-start;
    align-items: center;
}

.action-buttons-cell .btn {
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 500;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.caterer-status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: bold;
    margin-left: 6px;
}

.caterer-open {
    background: rgba(76, 175, 80, 0.15);
    color: #4caf50;
    border: 1px solid rgba(76, 175, 80, 0.3);
}

.caterer-closed {
    background: rgba(244, 67, 54, 0.15);
    color: #f44336;
    border: 1px solid rgba(244, 67, 54, 0.3);
}

.user-avatar {
    width: 40px !important;
    height: 40px !important;
}

.data-table p {
    margin: 4px 0;
    font-size: 13px;
}
.manage-users-page { min-width: 0; }
.manage-users-page .user-filters { min-width: 0; }
.manage-users-page .user-search { min-width: 0; }
.manage-users-page .user-avatar { flex-shrink: 0; object-fit: cover; border-radius: 12px; }
.manage-users-page .data-table td { overflow-wrap: anywhere; }
@media (max-width: 1100px) {
    .manage-users-page .data-table,
    .manage-users-page .data-table tbody { display: block; width: 100%; }
    .manage-users-page .data-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
    .manage-users-page .data-table tbody { display: grid; gap: 16px; }
    .manage-users-page .data-table tbody tr {
        display: grid; grid-template-columns: minmax(0, 1fr) auto;
        padding: 18px; gap: 14px 12px; border: 1px solid var(--border-color);
        border-radius: 18px; background: linear-gradient(145deg, #24231f, var(--secondary-bg) 65%);
        box-shadow: 0 8px 24px rgba(0,0,0,.12);
    }
    .manage-users-page .data-table td { display: block; padding: 0; border: 0; min-width: 0; white-space: normal; }
    .manage-users-page .data-table td:first-child { grid-column: 1; grid-row: 1; align-self: center; }
    .manage-users-page .data-table td:first-child small,
    .manage-users-page .data-table td:first-child strong { font-size: 15px; line-height: 1.5; }
    .manage-users-page .data-table td:nth-child(4) { grid-column: 2; grid-row: 1; align-self: start; }
    .manage-users-page .data-table td:nth-child(2),
    .manage-users-page .data-table td:nth-child(3),
    .manage-users-page .data-table td:last-child { grid-column: 1 / -1; }
    .manage-users-page .data-table td:nth-child(2) { border-top: 1px solid var(--border-color); padding-top: 12px; }
    .manage-users-page .data-table td[data-label]::before {
        content: attr(data-label); display: block; margin-bottom: 7px;
        color: var(--accent-gold); font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase;
    }
    .manage-users-page .data-table p { font-size: 14px; line-height: 1.6; }
    .manage-users-page .data-table p i { width: 18px; color: var(--text-muted); }
    .manage-users-page .action-buttons-cell { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    .manage-users-page .action-buttons-cell form { min-width: 0; }
    .manage-users-page .action-buttons-cell .btn { width: 100%; min-height: 44px; justify-content: center; white-space: normal; line-height: 1.4; border-radius: 10px; }
}
@media (max-width: 600px) {
    .manage-users-page .content-header { margin-bottom: 22px; }
    .manage-users-page .content-header h1 { font-size: 26px; letter-spacing: -.7px; }
    .manage-users-page .content-header p { font-size: 13px; line-height: 1.6; }
    .manage-users-page .content-body { padding: 0; border: 0; background: transparent; }
    .manage-users-page .user-type-tabs { gap: 4px; padding: 6px; border-radius: 14px; }
    .manage-users-page .user-type-tab { flex: 1; min-width: 0; justify-content: center; min-height: 46px; padding: 8px 5px; font-size: 13px; border-radius: 10px; }
    .manage-users-page .user-filters { align-items: stretch !important; }
    .manage-users-page .user-filters > div { width: 100%; min-width: 0; }
    .manage-users-page .user-filters h2 { font-size: 19px; margin-bottom: 8px; gap: 8px; }
    .manage-users-page .user-search { display: grid !important; width: 100%; grid-template-columns: minmax(0, 1fr) 46px 46px; gap: 8px !important; }
    .manage-users-page .user-search .form-control { width: 100% !important; min-width: 0; min-height: 46px; font-size: 16px; border-radius: 10px; }
    .manage-users-page .user-search select { grid-column: 1 / -1; }
    .manage-users-page .user-search .btn { min-height: 46px; padding: 10px; border-radius: 10px; }
    .manage-users-page .user-section h3 { font-size: 14px; color: var(--text-muted); }
    .manage-users-page .data-table tbody tr { padding: 16px; }
    .manage-users-page .data-table td:first-child > div { gap: 10px !important; }
    .manage-users-page .data-table td:first-child > div > div:first-child { flex-shrink: 0; }
    .manage-users-page .status-badge { font-size: 11px; padding: 5px 8px; }
    .manage-users-page #passwordResetModal { padding: 16px; overflow-y: auto; }
    .manage-users-page #passwordResetModal > div { width: 100% !important; max-width: 400px !important; max-height: calc(100dvh - 32px); overflow-y: auto; }
    .manage-users-page #new_password { font-size: 16px; min-height: 46px; }
}
/* Compact, name-first account list on mobile and desktop. */
.manage-users-page .account-name-toggle .account-new-badge {
    flex-shrink: 0; padding: 3px 6px; border-radius: 5px;
    background: #dc3545; color: #fff; font-size: 10px; font-weight: 700;
    line-height: 1.2; letter-spacing: .4px;
}
.manage-users-page .user-filters { gap: 8px !important; margin-bottom: 10px !important; padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; }
.manage-users-page .user-filters h2 { margin: 0; font-size: 15px; }
.manage-users-page .user-search .form-control { min-height: 36px; padding: 6px 9px; font-size: 13px; border-radius: 6px; }
.manage-users-page .user-search .btn { min-height: 36px; padding: 6px 10px; border-radius: 6px; }
.manage-users-page .user-search { gap: 6px !important; }
@media (max-width: 600px) {
    .manage-users-page .user-filters h2 { margin: 0; font-size: 15px; }
    .manage-users-page .user-search { grid-template-columns: minmax(0, 1fr) 36px 36px; }
    .manage-users-page .user-search .form-control { min-height: 36px; font-size: 16px; padding: 5px 8px; border-radius: 6px; }
    .manage-users-page .user-search .btn { min-height: 36px; padding: 6px; border-radius: 6px; }
}
html { scrollbar-gutter: stable; }
.manage-users-page { overflow-anchor: none; }
.manage-users-page .data-table,
.manage-users-page .data-table tbody { display: block; width: 100%; min-width: 0; }
.manage-users-page .data-table thead { display: none; }
.manage-users-page .data-table tbody { display: grid; gap: 8px; }
.manage-users-page .data-table tbody tr {
    display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
    padding: 0; gap: 0; border: 1px solid var(--border-color);
    border-radius: 10px; background: var(--secondary-bg); box-shadow: none;
}
.manage-users-page .data-table tbody tr > td {
    display: block; grid-column: auto; grid-row: auto; min-width: 0;
    padding: 8px 12px; border: 0; white-space: normal;
}
.manage-users-page .data-table tbody tr > td:first-child { display: flex; align-items: center; grid-column: 1 / -1; padding: 0; }
.manage-users-page .data-table tbody tr > td:last-child { grid-column: 1 / -1; }
.manage-users-page .data-table tbody tr:not(.is-expanded) > td:not(:first-child),
.manage-users-page .data-table tbody tr:not(.is-expanded) > td:first-child > div { display: none; }
.manage-users-page .account-name-toggle {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    width: 100%; min-height: 44px; padding: 10px 12px; border: 0; border-radius: 10px;
    background: transparent; color: var(--text-main); text-align: left;
    font: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
}
.manage-users-page .account-name-toggle { flex: 1; min-width: 0; }
.manage-users-page .account-heading-avatar { width: 28px !important; height: 28px !important; flex-shrink: 0; margin-left: 12px; object-fit: cover; border-radius: 6px; }
.manage-users-page .data-table tbody tr > td:first-child > .account-heading-avatar { display: flex !important; padding: 0 !important; }
.manage-users-page .account-name-toggle span { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.manage-users-page .account-name-toggle i { color: var(--accent-gold); flex-shrink: 0; }
.manage-users-page .account-name-toggle:hover { background: rgba(255,255,255,.04); }
.manage-users-page .account-name-toggle:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: -2px; }
.manage-users-page .is-expanded .account-name-toggle i { transform: rotate(180deg); }
.manage-users-page .data-table tbody tr.is-expanded > td:first-child > div:not(.account-heading-avatar) { padding: 6px 10px 6px 0; gap: 6px !important; flex-shrink: 1; min-width: 0; max-width: 40%; overflow-wrap: anywhere; }
.manage-users-page .data-table tbody tr.is-expanded > td:first-child .user-avatar { width: 28px !important; height: 28px !important; }
.manage-users-page .data-table td[data-label]::before {
    content: attr(data-label); display: block; margin-bottom: 5px;
    color: var(--accent-gold); font-size: 10px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase;
}
.manage-users-page .data-table p { margin: 3px 0; font-size: 13px; line-height: 1.45; }
.manage-users-page .data-table .action-buttons-cell { display: flex; flex-wrap: wrap; gap: 6px; }
.manage-users-page .data-table .action-buttons-cell .btn { width: auto; min-height: 38px; padding: 7px 10px; font-size: 12px; border-radius: 6px; }
.manage-users-page .content-body,
.manage-users-page .user-section,
.manage-users-page .data-table tbody tr,
.manage-users-page .data-table td,
.manage-users-page .action-buttons-cell,
.manage-users-page .action-buttons-cell form { min-width: 0; max-width: 100%; box-sizing: border-box; }
.manage-users-page .data-table tbody tr { width: 100%; }
.manage-users-page .data-table td p,
.manage-users-page .data-table td small { overflow-wrap: anywhere; }
.manage-users-page .data-table .action-buttons-cell .btn { max-width: 100%; white-space: normal; overflow-wrap: anywhere; box-sizing: border-box; }
.manage-users-page .data-table tbody tr.is-expanded { padding-bottom: 6px; }
.manage-users-page .data-table tbody tr.is-expanded > td:first-child { border-bottom: 1px solid var(--border-color); margin-bottom: 4px; }
.manage-users-page .data-table tbody tr > td[data-label] { padding: 12px 14px; }
.manage-users-page .data-table td[data-label]::before { margin-bottom: 8px; }
.manage-users-page .data-table .action-buttons-cell {
    display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: stretch; gap: 8px; width: 100%;
}
.manage-users-page .data-table .action-buttons-cell > form { display: flex; width: 100%; margin: 0; }
.manage-users-page .data-table .action-buttons-cell .btn {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    width: 100%; min-width: 0 !important; min-height: 40px;
    margin: 0; padding: 8px 10px; line-height: 1.35;
}
@media (max-width: 600px) {
    .manage-users-page .data-table tbody tr { grid-template-columns: minmax(0, 1fr); }
    .manage-users-page .data-table tbody tr > td:nth-child(n) { grid-column: 1 / -1; grid-row: auto; width: 100%; justify-self: stretch; }
    .manage-users-page .data-table .action-buttons-cell { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .manage-users-page .data-table .action-buttons-cell .btn { width: 100%; min-width: 0 !important; }
    .manage-users-page .user-type-tabs { margin-bottom: 12px; }
    .manage-users-page .content-header { margin-bottom: 14px; }
}
</style>
';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

    <div class="main-content manage-users-page">
        <?php if ($deletionNotice): ?>
            <p class="alert alert-warning" role="alert"><?php echo htmlspecialchars($deletionNotice); ?></p>
        <?php endif; ?>
        
        <div class="content-body">
            <!-- Filter and Search -->
            <div class="user-filters" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                
                <div style="display: flex; gap: 10px;">
                    <form class="user-search" method="GET" action="" style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php if ($trash_view): ?><input type="hidden" name="view" value="trash"><?php endif; ?>
                        <input type="hidden" name="type" value="<?php echo $user_type; ?>">
                        <select name="filter" aria-label="Filter by account status" onchange="this.form.submit()" class="form-control" style="width: auto;">
                            <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="rejected" <?php echo $filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="closed" <?php echo $filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                        
                        <input type="text" name="search" aria-label="Search accounts" placeholder="Search accounts..." value="<?php echo htmlspecialchars($search); ?>" 
                               class="form-control" style="width: 200px;">
                        
                        <button type="submit" class="btn btn-primary" aria-label="Search">
                            <i class="fas fa-search"></i>
                        </button>
                        
                        <button type="button" aria-label="Clear filters" onclick="location.href='manage-users.php?type=<?php echo $user_type; ?><?php echo $trash_view ? '&amp;view=trash' : ''; ?>'" class="btn btn-secondary">
                            <i class="fas fa-sync"></i>
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- User Type Tabs - Only Caterers and Customers -->
            <div class="user-type-tabs">
                <button class="user-type-tab <?php echo $user_type === 'caterer' ? 'active' : ''; ?>" 
                        onclick="window.location.href='manage-users.php?type=caterer<?php echo $trash_view ? '&amp;view=trash' : ''; ?>'">
                    <i class="fas fa-caterer"></i> Caterers (<?php echo $stats['total_stores']; ?>)
                </button>
                <button class="user-type-tab <?php echo $user_type === 'customer' ? 'active' : ''; ?>" 
                        onclick="window.location.href='manage-users.php?type=customer<?php echo $trash_view ? '&amp;view=trash' : ''; ?>'">
                    <i class="fas fa-user"></i> Customers (<?php echo $stats['total_customers']; ?>)
                </button>
            </div>

            <div style="display:flex; gap:10px; margin:16px 0; flex-wrap:wrap;">
                <a class="btn <?php echo !$trash_view ? 'btn-primary' : 'btn-secondary'; ?>" href="?type=<?php echo $user_type; ?>">Accounts</a>
                <a class="btn <?php echo $trash_view ? 'btn-primary' : 'btn-secondary'; ?>" href="?type=<?php echo $user_type; ?>&amp;view=trash"><i class="fas fa-trash"></i> Trash (<span id="trash-count"><?php
                    $trash_roles = $user_type === 'customer' ? "'customer'" : "'store','caterer'";
                    $trash_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM portal_account_trash t JOIN users u ON u.id = t.user_id WHERE u.role IN ($trash_roles)"));
                    echo (int) $trash_count['total'];
                ?></span>)</a>
            </div>
            <?php if ($trash_view): ?>
                <style>.action-buttons-cell > form:not([data-account-action]), .action-buttons-cell > button, .action-buttons-cell > a { display:none !important; }</style>
            <?php endif; ?>

            <!-- Caterers Section -->
            <?php if ($user_type === 'caterer'): ?>
                <?php if (!empty($stores)): ?>
                <div class="user-section">
                    <h3><i class="fas fa-caterer"></i> Caterers (<?php echo count($stores); ?>)</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Caterer Information</th>
                                <th>Business Details</th>
                                <th>Activity</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stores as $caterer): ?>
                                <tr data-account-id="<?php echo (int) $caterer['id']; ?>">
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 15px;">
                                            <?php if ($caterer['profile_image']): ?>
                                                <img src="../<?php echo $caterer['profile_image']; ?>" 
                                                     alt="Profile" class="user-avatar">
                                            <?php else: ?>
                                                <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--accent-gold); display: flex; align-items: center; justify-content: center; color: black; font-weight: bold;">
                                                    <?php echo strtoupper(substr($caterer['first_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <small style="color: var(--text-main); font-weight: 600;"><i class="fas fa-caterer"></i> <?php echo $caterer['business_name']; ?></small>
                                                <div style="margin-top: 5px;">
                                                <?php $store_is_available = isStoreAvailableNow($caterer['is_open'] ?? 0, $caterer['business_open_time'] ?? null, $caterer['business_close_time'] ?? null, $caterer['auto_schedule_enabled'] ?? 0); ?>
                                                <?php if ($store_is_available): ?>
                                                    <span class="caterer-status caterer-open">Open</span>
                                                <?php else: ?>
                                                    <span class="caterer-status caterer-closed">Closed</span>
                                                <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Contact details">
                                        <p><i class="fas fa-phone"></i> <?php echo $caterer['phone_number']; ?></p>
                                        <p><i class="fas fa-envelope"></i> <?php echo $caterer['email'] ?: 'N/A'; ?></p>
                                        <p><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($caterer['created_at'])); ?></p>
                                    </td>
                                    <td data-label="Activity">
                                        <p><i class="fas fa-shopping-cart"></i> Orders: <?php echo $caterer['store_orders']; ?></p>
                                        <p><i class="fas fa-credit-card"></i> Subscription: <?php echo ucfirst($caterer['subscription_status']); ?></p>
                                    </td>
                                    <td>
                                        <span class="status-badge 
                                            <?php 
                                            if ($caterer['status'] === 'active') echo 'status-active';
                                            elseif ($caterer['status'] === 'pending') echo 'status-pending';
                                            else echo 'status-closed'; 
                                            ?>">
                                            <?php echo ucfirst($caterer['status']); ?>
                                        </span>
                                    </td>
                                    <td data-label="Manage account">
                                        <div class="action-buttons-cell">
                                            <?php if ($caterer['status'] === 'closed'): ?>
                                                <?php echo portalActionForm('restore', $caterer['id'], 'Restore', 'btn-info', 'fa-redo', $csrf_token, $user_type); ?>
                                            <?php elseif ($caterer['status'] === 'active'): ?>
                                                <?php echo portalActionForm('close', $caterer['id'], 'Close Caterer', 'btn-warning', 'fa-lock', $csrf_token, $user_type, 'Are you sure you want to close this caterer?'); ?>
                                            <?php endif; ?>
                                            <form method="POST" action="login_as_user.php" style="display:inline-block;" onsubmit="return portalConfirmForm(event, this, 'Login as this caterer account?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int)$caterer['id']; ?>">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-sign-in-alt"></i> Login
                                                </button>
                                            </form>
                                            <button type="button" onclick="openPasswordResetModal(<?php echo $caterer['id']; ?>)" 
                                                    class="btn btn-secondary">
                                                <i class="fas fa-key"></i> Reset Pass
                                            </button>
                                            <?php $deletionReason = accountDeletionReason($caterer); ?>
                                            <?php if ($deletionReason !== null): ?>
                                                <div>
                                                    <button type="button" class="btn btn-danger" disabled>Delete</button>
                                                    <p style="margin:6px 0 0;font-size:12px;"><?php echo htmlspecialchars($deletionReason); ?></p>
                                                </div>
                                            <?php else: ?>
                                            <?php echo portalActionForm('delete', $caterer['id'], 'Delete', 'btn-danger', 'fa-trash', $csrf_token, $user_type, 'Delete this caterer account? It will be closed and hidden from the landing page and Browse Caterers. Existing records will be kept, and you can restore the account later.'); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="user-section">
                        <h3><i class="fas fa-caterer"></i> Caterers</h3>
                        <div class="no-users">
                            <i class="fas fa-caterer fa-2x" style="margin-bottom: 10px; opacity: 0.5;"></i>
                            <p>No caterers found.</p>
                            <?php if ($filter !== 'all' || !empty($search)): ?>
                                <p><a href="manage-users.php?type=caterer" class="btn btn-primary" style="margin-top: 15px;">
                                    <i class="fas fa-times"></i> Clear filters
                                </a></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- Customers Section -->
            <?php if ($user_type === 'customer'): ?>
                <?php if (!empty($customers)): ?>
                <div class="user-section">
                    <h3><i class="fas fa-user"></i> Customers (<?php echo count($customers); ?>)</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Customer Information</th>
                                <th>Account Details</th>
                                <th>Activity</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $customer): ?>
                                <tr data-account-id="<?php echo (int) $customer['id']; ?>">
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 15px;">
                                            <?php if ($customer['profile_image']): ?>
                                                <img src="../<?php echo $customer['profile_image']; ?>" 
                                                     alt="Profile" class="user-avatar">
                                            <?php else: ?>
                                                <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--accent-gold); display: flex; align-items: center; justify-content: center; color: black; font-weight: bold;">
                                                    <?php echo strtoupper(substr($customer['first_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <strong><?php echo $customer['first_name'] . ' ' . $customer['last_name']; ?></strong><br>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Contact details">
                                        <p><i class="fas fa-phone"></i> <?php echo $customer['phone_number']; ?></p>
                                        <p><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($customer['created_at'])); ?></p>
                                    </td>
                                    <td data-label="Activity">
                                        <p><i class="fas fa-shopping-cart"></i> Orders: <?php echo $customer['customer_orders']; ?></p>
                                     
                                    </td>
                                    <td>
                                        <span class="status-badge 
                                            <?php 
                                            if ($customer['status'] === 'active') echo 'status-active';
                                            elseif ($customer['status'] === 'pending') echo 'status-pending';
                                            else echo 'status-closed'; 
                                            ?>">
                                            <?php echo ucfirst($customer['status']); ?>
                                        </span>
                                    </td>
                                    <td data-label="Manage account">
                                        <div class="action-buttons-cell">
                                            <?php if ($customer['status'] === 'closed'): ?>
                                                <?php echo portalActionForm('restore', $customer['id'], 'Restore', 'btn-info', 'fa-redo', $csrf_token, $user_type); ?>
                                            <?php elseif ($customer['status'] === 'active'): ?>
                                                <?php echo portalActionForm('close', $customer['id'], 'Close Account', 'btn-warning', 'fa-lock', $csrf_token, $user_type, 'Are you sure you want to close this customer account?'); ?>
                                            <?php endif; ?>
                                            <a href="messages.php?compose=<?php echo (int)$customer['id']; ?>" class="btn btn-primary">
                                                <i class="fas fa-envelope"></i> Message
                                            </a>
                                            <?php $deletionReason = accountDeletionReason($customer); ?>
                                            <?php if ($deletionReason !== null): ?>
                                                <div>
                                                    <button type="button" class="btn btn-danger" disabled>Delete</button>
                                                    <p style="margin:6px 0 0;font-size:12px;"><?php echo htmlspecialchars($deletionReason); ?></p>
                                                </div>
                                            <?php else: ?>
                                                <?php echo portalActionForm('delete', $customer['id'], 'Delete', 'btn-danger', 'fa-trash', $csrf_token, $user_type, 'Are you sure you want to DELETE this user? This action cannot be undone!'); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="user-section">
                        <h3><i class="fas fa-user"></i> Customers</h3>
                        <div class="no-users">
                            <i class="fas fa-user fa-2x" style="margin-bottom: 10px; opacity: 0.5;"></i>
                            <p>No customers found.</p>
                            <?php if ($filter !== 'all' || !empty($search)): ?>
                                <p><a href="manage-users.php?type=customer" class="btn btn-primary" style="margin-top: 15px;">
                                    <i class="fas fa-times"></i> Clear filters
                                </a></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

<?php require __DIR__ . '/includes/footer-bar.php'; ?>

    <!-- Password Reset Modal -->
    <div id="passwordResetModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000;">
        <div style="background: var(--card-bg); padding: 25px; border-radius: 12px; border: 1px solid var(--border-color); width: 400px; max-width: 90%;">
            <h3 style="margin-bottom: 15px; color: var(--text-main);">Reset Password</h3>
            <p style="margin-bottom: 20px; color: var(--text-muted);">Enter new password for the user.</p>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" id="reset_user_id" name="user_id" value="">
                
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div style="position: relative;">
                        <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6" style="padding-right: 46px;">
                        <button type="button" class="password-toggle" data-toggle-password="new_password" aria-label="Show password" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-muted); cursor: pointer;">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="closePasswordResetModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const newAccountIds = new Set(<?php echo json_encode(array_keys($new_account_ids)); ?>.map(String));
        document.querySelectorAll('.user-section .data-table tbody tr').forEach((row, index) => {
            const identity = row.cells[0];
            const name = identity.querySelector('strong, small');
            if (!name) return;
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'account-name-toggle';
            toggle.setAttribute('aria-expanded', 'false');
            const label = document.createElement('span');
            label.textContent = name.textContent.trim();
            // The disclosure heading already displays the account name.
            name.remove();
            const identityContent = identity.querySelector('div');
            const avatar = identityContent.querySelector('.user-avatar') || identityContent.firstElementChild;
            if (avatar) {
                avatar.classList.add('account-heading-avatar');
                identity.prepend(avatar);
            }
            toggle.append(label);
            // Keep availability in the heading so it stays aligned in both states.
            const availability = identityContent.querySelector('.caterer-status');
            if (availability) toggle.append(availability);
            if (newAccountIds.has(row.dataset.accountId)) {
                const indicator = document.createElement('span');
                indicator.className = 'account-new-badge';
                indicator.textContent = 'NEW';
                indicator.setAttribute('aria-label', 'New account, not yet viewed');
                toggle.append(indicator);
            }
            if (avatar) avatar.after(toggle);
            else identity.prepend(toggle);
            const details = [identityContent, ...Array.from(row.cells).slice(1)];
            details.forEach((detail, part) => {
                detail.id = 'account-detail-' + index + '-' + part;
                detail.setAttribute('aria-hidden', 'true');
            });
            toggle.setAttribute('aria-controls', details.map(detail => detail.id).join(' '));
            toggle.addEventListener('click', () => {
                const scrollX = window.scrollX;
                const scrollY = window.scrollY;
                const expanded = toggle.getAttribute('aria-expanded') !== 'true';
                // Keep enough page below the viewport when collapsing a card near the bottom.
                if (!expanded) {
                    document.body.style.minHeight = Math.max(document.body.getBoundingClientRect().height, scrollY + window.innerHeight) + 'px';
                }
                toggle.setAttribute('aria-expanded', String(expanded));
                row.style.minHeight = '';
                row.classList.toggle('is-expanded', expanded);
                details.forEach(detail => detail.setAttribute('aria-hidden', String(!expanded)));
                window.scrollTo({ left: scrollX, top: scrollY, behavior: 'instant' });
                if (expanded && row.dataset.accountId) {
                    const viewed = new FormData();
                    viewed.set('action', 'view_account');
                    viewed.set('user_id', row.dataset.accountId);
                    viewed.set('csrf_token', <?php echo json_encode($csrf_token); ?>);
                    fetch(window.location.href, { method: 'POST', body: viewed, credentials: 'same-origin' })
                        .then(response => response.json())
                        .then(result => {
                            if (result.success) {
                                toggle.querySelector('.account-new-badge')?.remove();
                                newAccountIds.delete(row.dataset.accountId);
                                if (typeof updatePortalNotifCount === 'function') updatePortalNotifCount();
                            }
                        }).catch(() => {});
                }
            });
        });

        document.addEventListener('submit', async function(event) {
            const form = event.target;
            if (!form.matches('form[data-account-action]') || event.defaultPrevented) return;
            event.preventDefault();
            const row = form.closest('tr');
            if (!row || row.dataset.updating === '1') return;
            const data = new FormData(form);
            data.set('response_format', 'json');
            const buttons = row.querySelectorAll('button');
            row.dataset.updating = '1';
            buttons.forEach(button => button.disabled = true);
            try {
                    const response = await fetch(window.location.href, {
                    method: 'POST', body: data, credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                if (response.redirected) throw new Error('Your session or security token has expired. Refresh the page and try again.');
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'Unable to update the account.');
                const inTrash = new URL(window.location.href).searchParams.get('view') === 'trash';
                if (data.get('action') === 'delete' || (inTrash && data.get('action') === 'restore')) {
                    const scrollY = window.scrollY;
                    const scrollX = window.scrollX;
                    const bodyHeight = document.body.getBoundingClientRect().height;
                    document.body.style.minHeight = bodyHeight + 'px';
                    const tbody = row.parentElement;
                    row.remove();
                    const trashCount = document.getElementById('trash-count');
                    if (trashCount) trashCount.textContent = Math.max(0, Number(trashCount.textContent) + (inTrash ? -1 : 1));
                    document.querySelectorAll('.user-type-tab, .user-section h3').forEach(label => {
                        const isCustomer = data.get('redirect_type') === 'customer';
                        if ((isCustomer && label.textContent.includes('Customers')) || (!isCustomer && label.textContent.includes('Caterers'))) {
                            label.innerHTML = label.innerHTML.replace(/\((\d+)\)/, (_, count) => '(' + Math.max(0, Number(count) + (inTrash && label.matches('.user-type-tab') ? 1 : -1)) + ')');
                        }
                    });
                    if (!tbody.children.length) {
                        const emptyRow = tbody.insertRow();
                        const cell = emptyRow.insertCell();
                        cell.colSpan = 5;
                        cell.textContent = inTrash ? 'Trash is empty.' : 'No accounts found.';
                    }
                    window.scrollTo({left: scrollX, top: scrollY, behavior: 'instant'});
                    return;
                }
                const updateScrollX = window.scrollX;
                const updateScrollY = window.scrollY;
                // Status and action labels must not resize the selected account.
                row.style.minHeight = row.getBoundingClientRect().height + 'px';
                const closed = result.status === 'closed';
                const badge = row.querySelector('.status-badge');
                if (badge) {
                    badge.textContent = closed ? 'Closed' : 'Active';
                    badge.classList.remove('status-active', 'status-pending', 'status-closed');
                    badge.classList.add(closed ? 'status-closed' : 'status-active');
                }
                const availability = row.querySelector('.caterer-status');
                if (availability) {
                    availability.textContent = result.is_available ? 'Open' : 'Closed';
                    availability.classList.toggle('caterer-open', result.is_available);
                    availability.classList.toggle('caterer-closed', !result.is_available);
                }
                row.querySelectorAll('form[data-account-action]').forEach(actionForm => {
                    const actionInput = actionForm.querySelector('[name="action"]');
                    if (actionInput.value === 'delete') return;
                    actionInput.value = closed ? 'restore' : 'close';
                    actionForm.removeAttribute('onsubmit');
                    const button = actionForm.querySelector('button');
                    button.style.minWidth = button.getBoundingClientRect().width + 'px';
                    button.classList.remove('btn-info', 'btn-warning');
                    button.classList.add(closed ? 'btn-info' : 'btn-warning');
                    button.innerHTML = closed ? '<i class="fas fa-redo"></i> Restore' : '<i class="fas fa-lock"></i> Close Account';
                });
                window.scrollTo({ left: updateScrollX, top: updateScrollY, behavior: 'instant' });
            } catch (error) {
                alert(error.message || 'Unable to update the account. Please try again.');
            } finally {
                delete row.dataset.updating;
                buttons.forEach(button => button.disabled = false);
            }
        });

        document.querySelectorAll('[data-toggle-password]').forEach(function(toggleButton) {
            toggleButton.addEventListener('click', function() {
                const input = document.getElementById(this.getAttribute('data-toggle-password'));
                if (!input) {
                    return;
                }

                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                this.setAttribute('aria-label', type === 'password' ? 'Show password' : 'Hide password');
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });
        });

        function openPasswordResetModal(userId) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('passwordResetModal').style.display = 'flex';
        }

        function closePasswordResetModal() {
            document.getElementById('passwordResetModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            var modal = document.getElementById('passwordResetModal');
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
    </script>
</body>
</html>
