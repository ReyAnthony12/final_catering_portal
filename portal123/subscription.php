<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/subscription_helper.php';
requireLogin();

if (getUserRole() !== 'portal') {
    header('Location: ../index.php');
    exit();
}

// Preserve the existing payment management flow opened from Payouts.
if (!(max(0, (int) ($_GET['caterer_id'] ?? 0)) > 0 && ($_GET['from'] ?? '') === 'history')) {
    require __DIR__ . '/includes/subscription_pricing.php';
    exit();
}
$current_date = date('Y-m-d');
$warning_days = getSubscriptionWarningDays();
$warning_date = date('Y-m-d', strtotime('+' . $warning_days . ' days'));
syncStoreSubscriptions($conn);
$csrf_token = generateCSRFToken();
// Use the same Trash records as Manage Users, including on fresh installations.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS portal_account_trash (
    user_id INT NOT NULL PRIMARY KEY,
    deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// ==========================================
// HANDLE ACTIONS
// ==========================================

// Handle GCash QR upload
$upload_success = '';
$upload_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_gcash'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $upload_error = 'Invalid security token. Please try again.';
    } elseif (isset($_FILES['gcash_qr']) && $_FILES['gcash_qr']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
        $file_type = $_FILES['gcash_qr']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            $upload_error = 'Only JPEG, PNG, JPG, and GIF files are allowed.';
        } else {
            $file_extension = pathinfo($_FILES['gcash_qr']['name'], PATHINFO_EXTENSION);
            $file_name = 'gcash_qr_' . time() . '.' . strtolower($file_extension);
            $target_dir = '../uploads/qr_codes/';
            
            if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
            
            $target_file = $target_dir . $file_name;
            
            if (move_uploaded_file($_FILES['gcash_qr']['tmp_name'], $target_file)) {
                $relative_path = 'uploads/qr_codes/' . $file_name;
                
                $check_query = "SELECT COUNT(*) as count FROM system_settings WHERE setting_key = 'portal_gcash_qr'";
                $check_result = mysqli_query($conn, $check_query);
                $check_data = mysqli_fetch_assoc($check_result);
                
                if ($check_data['count'] > 0) {
                    $update_query = "UPDATE system_settings SET setting_value = '$relative_path' WHERE setting_key = 'portal_gcash_qr'";
                } else {
                    $update_query = "INSERT INTO system_settings (setting_key, setting_value) VALUES ('portal_gcash_qr', '$relative_path')";
                }
                
                if (mysqli_query($conn, $update_query)) {
                    $upload_success = 'GCash QR code uploaded successfully!';
                } else {
                    $upload_error = 'Failed to update database.';
                    unlink($target_file);
                }
            } else {
                $upload_error = 'Failed to upload file.';
            }
        }
    } else {
        $upload_error = 'Please select a valid image file.';
    }
}

// Handle payment verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_payment'])) {
    $payment_id = (int) ($_POST['payment_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $reject_reason = trim((string) ($_POST['reject_reason'] ?? ''));
    $admin_label = trim((string) ($_SESSION['username'] ?? 'Portal Admin'));

    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $upload_error = 'Invalid security token. Please try again.';
    } elseif ($payment_id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $upload_error = 'Invalid subscription payment action.';
    } elseif ($action === 'reject' && $reject_reason === '') {
        $upload_error = 'Please provide a rejection reason.';
    } else {
        mysqli_begin_transaction($conn);

        try {
            $payment_query = mysqli_query(
                $conn,
                "SELECT s.*, sd.business_name
                 FROM subscriptions s
                 LEFT JOIN store_details sd ON sd.user_id = s.store_id
                 WHERE s.id = '$payment_id'
                   AND NOT EXISTS (SELECT 1 FROM portal_account_trash WHERE user_id = s.store_id)
                 LIMIT 1"
            );
            $payment = $payment_query ? mysqli_fetch_assoc($payment_query) : null;

            if (!$payment) {
                throw new Exception('Subscription payment record not found.');
            }

            if (($payment['payment_status'] ?? '') !== 'pending') {
                throw new Exception('This subscription payment has already been processed.');
            }

            $store_id = (int) $payment['store_id'];
            mysqli_query($conn, "SELECT user_id FROM store_details WHERE user_id = '$store_id' FOR UPDATE");
            $safe_admin_label = mysqli_real_escape_string($conn, $admin_label);

            if ($action === 'approve') {
                $safe_reference = mysqli_real_escape_string($conn, preg_replace('/\\s+/', '', (string) ($payment['reference_number'] ?? '')));
                $duplicate = mysqli_query($conn, "SELECT id FROM subscriptions WHERE store_id = '$store_id' AND id <> '$payment_id' AND payment_status = 'paid' AND REPLACE(REPLACE(REPLACE(reference_number, ' ', ''), CHAR(9), ''), CHAR(10), '') = '$safe_reference' LIMIT 1");
                if ($safe_reference !== '' && $duplicate && mysqli_num_rows($duplicate) > 0) {
                    throw new Exception('This payment reference has already been approved. Reject this duplicate submission instead.');
                }
                $updated = mysqli_query(
                    $conn,
                    "UPDATE subscriptions
                     SET payment_status = 'paid',
                         verified_at = NOW(),
                         verified_by = '$safe_admin_label',
                         reject_reason = NULL
                     WHERE id = '$payment_id'
                       AND payment_status = 'pending'"
                );

                if (!$updated || mysqli_affected_rows($conn) !== 1) {
                    if (!$updated) {
                        throw new Exception('Failed to approve the subscription payment. ' . mysqli_error($conn));
                    }

                    throw new Exception('This subscription payment may have already been processed. Please refresh the page and check the latest status.');
                }

                $new_expiry = activateStoreSubscription($conn, $store_id, date('Y-m-d H:i:s'));
                if (!$new_expiry) {
                    throw new Exception('Failed to activate the caterer subscription.');
                }

                if (!createNotification(
                    $store_id,
                    'payment_verified',
                    'Subscription Payment Approved',
                    "Your subscription payment has been approved. Your caterer subscription is active until $new_expiry.",
                    'subscription.php'
                )) {
                    throw new Exception('Failed to send the approval notification.');
                }

                mysqli_commit($conn);
                $upload_success = "Subscription payment approved. Caterer active until $new_expiry.";
            } else {
                $safe_reject_reason = mysqli_real_escape_string($conn, $reject_reason);
                $updated = mysqli_query(
                    $conn,
                    "UPDATE subscriptions
                     SET payment_status = 'rejected',
                         verified_at = NOW(),
                         verified_by = '$safe_admin_label',
                         reject_reason = '$safe_reject_reason'
                     WHERE id = '$payment_id'
                       AND payment_status = 'pending'"
                );

                if (!$updated || mysqli_affected_rows($conn) !== 1) {
                    if (!$updated) {
                        throw new Exception('Failed to reject the subscription payment. ' . mysqli_error($conn));
                    }

                    throw new Exception('This subscription payment may have already been processed. Please refresh the page and check the latest status.');
                }

                if (!createNotification(
                    $store_id,
                    'account_rejected',
                    'Subscription Payment Rejected',
                    "Your subscription payment was rejected. Reason: $reject_reason",
                    'subscription.php'
                )) {
                    throw new Exception('Failed to send the rejection notification.');
                }

                mysqli_commit($conn);
                $upload_success = 'Subscription payment rejected successfully.';
            }
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $upload_error = $e->getMessage();
        }
    }
}

// Handle send reminder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reminder'])) {
    $store_id = (int) ($_POST['store_id'] ?? 0);
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $upload_error = 'Invalid security token. Please try again.';
    } elseif ($store_id > 0) {
        $reminder_target = mysqli_query($conn, "SELECT id FROM users WHERE id = '$store_id' AND role = 'store' AND NOT EXISTS (SELECT 1 FROM portal_account_trash WHERE user_id = users.id)");
        if (!$reminder_target || !mysqli_num_rows($reminder_target)) {
            $upload_error = 'Caterer account is deleted or unavailable.';
        } else {
        $notified = createNotification(
            $store_id,
            'subscription_reminder',
            'Subscription Reminder',
            'This is a reminder from the Portal Admin to process your subscription payment to maintain access to your caterer features.',
            'subscription.php'
        );
        if ($notified) {
            $upload_success = 'Subscription reminder sent successfully.';
        } else {
            $upload_error = 'Failed to send reminder.';
        }
        }
    }
}

// Get current GCash QR
$portal_gcash_query = "SELECT setting_value FROM system_settings WHERE setting_key = 'portal_gcash_qr'";
$portal_gcash_result = mysqli_query($conn, $portal_gcash_query);
$portal_gcash = $portal_gcash_result ? mysqli_fetch_assoc($portal_gcash_result) : null;

// ==========================================
// DATA FETCHING FOR DASHBOARD
// ==========================================

// Filter Logic
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Base Query
$query = "
    SELECT 
        sd.*,
        u.email,
        u.first_name,
        u.last_name,
        (SELECT COUNT(*) FROM subscriptions WHERE store_id = sd.user_id AND payment_status = 'pending') as pending_count,
        (SELECT created_at FROM subscriptions WHERE store_id = sd.user_id AND payment_status = 'paid' ORDER BY created_at DESC LIMIT 1) as last_payment_date,
        (SELECT amount FROM subscriptions WHERE store_id = sd.user_id AND payment_status = 'paid' ORDER BY created_at DESC LIMIT 1) as last_payment_amount,
        -- Get Pending Payment Details for Verification
        p.id as pending_payment_id,
        p.amount as pending_amount,
        p.reference_number as pending_ref,
        p.payment_screenshot as pending_proof,
        p.created_at as pending_date
    FROM store_details sd
    JOIN users u ON sd.user_id = u.id
    LEFT JOIN subscriptions p ON p.id = (SELECT MAX(id) FROM subscriptions WHERE store_id = sd.user_id AND payment_status = 'pending')
    WHERE u.role = 'store'
      AND NOT EXISTS (SELECT 1 FROM portal_account_trash t WHERE t.user_id = u.id)
";

// Open the caterer selected from subscription payment history.
$selected_caterer_id = max(0, (int) ($_GET['caterer_id'] ?? 0));
$history_manage_flow = $selected_caterer_id > 0 && ($_GET['from'] ?? '') === 'history';
$return_coverage = (string) ($_GET['coverage'] ?? 'all');
if (!in_array($return_coverage, ['all', 'expiring', 'expired', 'active'], true)) $return_coverage = 'all';

if ($selected_caterer_id > 0) $query .= " AND sd.user_id = $selected_caterer_id";

// Apply Filters
if ($filter === 'active') {
    $query .= " AND sd.subscription_status = 'active' AND sd.subscription_expiry >= '$current_date'";
} elseif ($filter === 'expiring') {
    $query .= " AND sd.subscription_status = 'active' AND sd.subscription_expiry <= '$warning_date' AND sd.subscription_expiry >= '$current_date'";
} elseif ($filter === 'expired') {
    $query .= " AND (sd.subscription_status = 'expired' OR (sd.subscription_status = 'active' AND sd.subscription_expiry < '$current_date'))";
} elseif ($filter === 'pending') {
    $query .= " HAVING pending_count > 0";
}

$query .= " ORDER BY pending_count DESC, sd.subscription_expiry ASC";

$stores_result = mysqli_query($conn, $query);

// Statistics
$visible_stores = "FROM store_details sd JOIN users u ON u.id = sd.user_id
    WHERE u.role = 'store' AND NOT EXISTS (SELECT 1 FROM portal_account_trash t WHERE t.user_id = u.id)";
$stats_query = "
    SELECT 
        (SELECT COUNT(*) $visible_stores AND sd.subscription_status = 'active' AND sd.subscription_expiry >= '$current_date') as active,
        (SELECT COUNT(*) FROM subscriptions s JOIN users u ON u.id = s.store_id WHERE u.role = 'store' AND s.payment_status = 'pending' AND NOT EXISTS (SELECT 1 FROM portal_account_trash t WHERE t.user_id = u.id)) as pending,
        (SELECT COUNT(*) $visible_stores AND sd.subscription_status = 'active' AND sd.subscription_expiry <= '$warning_date' AND sd.subscription_expiry >= '$current_date') as expiring,
        (SELECT COUNT(*) $visible_stores AND (sd.subscription_status = 'expired' OR (sd.subscription_status = 'active' AND sd.subscription_expiry < '$current_date'))) as expired
";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

$page_title = 'Subscription Management';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
    /* Dashboard Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        background: var(--card-bg);
        padding: 20px;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        gap: 15px;
        transition: transform 0.2s;
    }
    .stat-card:hover { transform: translateY(-5px); }
    .stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }
    .stat-info h3 { margin: 0; font-size: 24px; color: var(--text-main); }
    .stat-info p { margin: 0; color: var(--text-muted); font-size: 14px; }
    
    .bg-active { background: rgba(46, 204, 113, 0.1); color: #2ecc71; }
    .bg-pending { background: rgba(241, 196, 15, 0.1); color: #f1c40f; }
    .bg-expiring { background: rgba(230, 126, 34, 0.1); color: #e67e22; }
    .bg-expired { background: rgba(231, 76, 60, 0.1); color: #e74c3c; }

    /* Filter Tabs */
    .filter-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        overflow-x: auto;
        padding-bottom: 5px;
    }
    .filter-btn {
        padding: 10px 20px;
        border-radius: 20px;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        color: var(--text-muted);
        text-decoration: none;
        font-size: 14px;
        white-space: nowrap;
        transition: all 0.3s;
    }
    .filter-btn:hover, .filter-btn.active {
        background: var(--accent-gold);
        color: #000;
        border-color: var(--accent-gold);
        font-weight: 600;
    }

    /* Table Styles */
    .table-container {
        background: var(--card-bg);
        border-radius: 12px;
        border: 1px solid var(--border-color);
        overflow: hidden;
    }
    .data-table {
        width: 100%;
        border-collapse: collapse;
    }
    .data-table th {
        background: rgba(0,0,0,0.2);
        padding: 15px;
        text-align: left;
        color: var(--text-muted);
        font-weight: 600;
        font-size: 13px;
        text-transform: uppercase;
    }
    .data-table td {
        padding: 15px;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-main);
        font-size: 14px;
    }
    .data-table tr:last-child td { border-bottom: none; }
    
    .caterer-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .caterer-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--secondary-bg);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--accent-gold);
        font-weight: bold;
    }
    
    .status-badge {
        padding: 5px 10px;
        border-radius: 15px;
        font-size: 12px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .status-active { background: rgba(46, 204, 113, 0.15); color: #2ecc71; }
    .status-pending { background: rgba(241, 196, 15, 0.15); color: #f1c40f; }
    .status-expiring { background: rgba(230, 126, 34, 0.15); color: #e67e22; }
    .status-expired { background: rgba(231, 76, 60, 0.15); color: #e74c3c; }

    /* Modal */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.8);
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(5px);
    }
    .modal-content {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        width: 90%;
        max-width: 600px;
        padding: 30px;
        position: relative;
        animation: slideIn 0.3s ease;
    }
    @keyframes slideIn {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    .close-modal {
        position: absolute;
        right: 20px;
        top: 20px;
        color: var(--text-muted);
        cursor: pointer;
        font-size: 24px;
    }
    
    .proof-img {
        width: 100%;
        max-height: 400px;
        object-fit: contain;
        border-radius: 8px;
        margin: 15px 0;
        border: 1px solid var(--border-color);
        background: #000;
    }
    
    .verify-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 20px;
        background: rgba(255,255,255,0.05);
        padding: 15px;
        border-radius: 8px;
    }
    .verify-item label { display: block; color: var(--text-muted); font-size: 12px; margin-bottom: 5px; }
    .verify-item div { color: var(--text-main); font-weight: 600; font-size: 16px; }

    .btn {
        padding: 8px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        border: none;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 13px;
    }
    .btn-verify { background: #f1c40f; color: #000; }
    .btn-approve { background: #2ecc71; color: #fff; width: 100%; justify-content: center; padding: 12px; font-size: 16px; }
    .btn-reject { background: #e74c3c; color: #fff; width: 100%; justify-content: center; padding: 12px; font-size: 16px; }
    .btn-outline { background: transparent; border: 1px solid var(--border-color); color: var(--text-muted); }
    .btn-upload { background: var(--accent-gold); color: #000; }
</style>

<link rel="stylesheet" href="subscription-responsive.css?v=20261015-1">
<div class="main-content subscription-page">
    <?php if ($history_manage_flow): ?>
        <a class="btn btn-outline" style="margin-bottom: 16px;" href="payouts.php?caterer=<?php echo $selected_caterer_id; ?>&amp;coverage=<?php echo htmlspecialchars($return_coverage, ENT_QUOTES, 'UTF-8'); ?>#manage-subscription">&larr; Back</a>
    <?php endif; ?>
    <?php if (!$history_manage_flow): ?>
    <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
        <button onclick="openQrModal()" class="btn btn-upload">
            <i class="fas fa-qrcode"></i> Update GCash QR
        </button>
    </div>
    <?php endif; ?>

    <?php if ($upload_success): ?>
        <div class="alert alert-success" style="padding: 15px; background: rgba(46, 204, 113, 0.2); color: #2ecc71; border-radius: 8px; margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo $upload_success; ?>
        </div>
    <?php endif; ?>
    <?php if ($upload_error): ?>
        <div class="alert alert-error" style="padding: 15px; background: rgba(231, 76, 60, 0.2); color: #ff8f8f; border-radius: 8px; margin-bottom: 20px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($upload_error); ?>
        </div>
    <?php endif; ?>

    <?php if (!$history_manage_flow): ?>
    <div class="subscription-section-heading"><div><h2>Caterer overview</h2></div></div>
    <!-- 2. Filters -->
    <nav class="filter-tabs" aria-label="Filter subscriptions">
        <a href="?<?php echo $selected_caterer_id > 0 ? 'caterer_id=' . $selected_caterer_id . '&amp;' : ''; ?>filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All Caterers</a>
        <a href="?<?php echo $selected_caterer_id > 0 ? 'caterer_id=' . $selected_caterer_id . '&amp;' : ''; ?>filter=active" class="filter-btn <?php echo $filter === 'active' ? 'active' : ''; ?>">Active</a>
        <a href="?<?php echo $selected_caterer_id > 0 ? 'caterer_id=' . $selected_caterer_id . '&amp;' : ''; ?>filter=pending" class="filter-btn <?php echo $filter === 'pending' ? 'active' : ''; ?>">Pending Payment</a>
        <a href="?<?php echo $selected_caterer_id > 0 ? 'caterer_id=' . $selected_caterer_id . '&amp;' : ''; ?>filter=expiring" class="filter-btn <?php echo $filter === 'expiring' ? 'active' : ''; ?>">Expiring Soon</a>
        <a href="?<?php echo $selected_caterer_id > 0 ? 'caterer_id=' . $selected_caterer_id . '&amp;' : ''; ?>filter=expired" class="filter-btn <?php echo $filter === 'expired' ? 'active' : ''; ?>">Expired</a>
    </nav>
    <?php endif; ?>

    <!-- 3. Stores Table -->
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Caterer Name</th>
                    <th>Plan</th>
                    <th>Payment Status</th>
                    <th>Expiration</th>
                    <th>Last Payment</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($stores_result) > 0): ?>
                    <?php while ($caterer = mysqli_fetch_assoc($stores_result)): ?>
                        <?php
                            // Determine Display Status
                            $display_status = '';
                            $status_class = '';
                            $icon = '';
                            
                            $days_until_expiry = (strtotime($caterer['subscription_expiry']) - strtotime($current_date)) / (60 * 60 * 24);
                            
                            if ($caterer['pending_count'] > 0) {
                                $display_status = 'Pending Verification';
                                $status_class = 'status-pending';
                                $icon = 'fa-clock';
                            } elseif ($caterer['subscription_status'] === 'expired' || ($caterer['subscription_status'] === 'active' && $days_until_expiry < 0)) {
                                $display_status = 'Expired';
                                $status_class = 'status-expired';
                                $icon = 'fa-times-circle';
                            } elseif ($caterer['subscription_status'] === 'active' && $days_until_expiry <= $warning_days) {
                                $display_status = 'Expiring Soon (' . ceil($days_until_expiry) . ' days)';
                                $status_class = 'status-expiring';
                                $icon = 'fa-exclamation-triangle';
                            } elseif ($caterer['subscription_status'] === 'active') {
                                $display_status = 'Active';
                                $status_class = 'status-active';
                                $icon = 'fa-check-circle';
                            } else {
                                $display_status = 'Inactive';
                                $status_class = 'status-expired';
                                $icon = 'fa-ban';
                            }
                        ?>
                        <tr class="subscription-row <?php echo $status_class; ?>">
                            <td data-label="Caterer Name">
                                <div class="caterer-info">
                                    <div class="caterer-avatar"><?php echo strtoupper(substr($caterer['business_name'], 0, 1)); ?></div>
                                    <div>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($caterer['business_name']); ?></div>
                                        <div style="font-size: 12px; color: var(--text-muted);">ID: <?php echo $caterer['user_id']; ?></div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Plan">
                                <?php echo htmlspecialchars($caterer['subscription_plan'] ?? 'Standard'); ?> 
                                (₱500)
                            </td>
                            <td data-label="Payment Status">
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <i class="fas <?php echo $icon; ?>"></i> <?php echo $display_status; ?>
                                </span>
                            </td>
                            <td data-label="Expiration">
                                <?php if ($caterer['subscription_expiry']): ?>
                                    <div style="font-weight: 600;"><?php echo date('M d, Y', strtotime($caterer['subscription_expiry'])); ?></div>
                                    <div style="font-size: 12px; color: var(--text-muted);">
                                        <?php echo ($days_until_expiry > 0) ? ceil($days_until_expiry) . ' days left' : 'Expired'; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Last Payment">
                                <?php if ($caterer['last_payment_date']): ?>
                                    <div>₱<?php echo number_format($caterer['last_payment_amount'], 2); ?></div>
                                    <div style="font-size: 12px; color: var(--text-muted);"><?php echo date('M d, Y', strtotime($caterer['last_payment_date'])); ?></div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">No history</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Action">
                                <?php if ($caterer['pending_count'] > 0): ?>
                                    <button onclick="verifyPayment(<?php echo htmlspecialchars(json_encode($caterer)); ?>)" class="btn btn-verify">
                                        <i class="fas fa-search"></i> Verify Payment
                                    </button>
                                <?php else: ?>
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <?php if ($caterer['subscription_status'] === 'active' && $days_until_expiry > $warning_days): ?>
                                            <button class="btn btn-outline" disabled style="opacity: 0.5; cursor: default;" title="Subscription is active">
                                                <i class="fas fa-check"></i> Up to date
                                            </button>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="store_id" value="<?php echo $caterer['user_id']; ?>">
                                            <button type="submit" name="send_reminder" class="btn" style="background: #3498db; color: #fff; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer;" title="Send Reminder Notification" onclick="return portalConfirmButton(event, this, 'Send subscription reminder to <?php echo addslashes($caterer['business_name']); ?>?');">
                                                <i class="fas fa-bell"></i> Remind
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fas fa-inbox fa-2x" style="margin-bottom: 10px;"></i>
                            <p>No caterers found matching this filter.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Verification Modal -->
<div id="verifyModal" class="modal subscription-modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeVerifyModal()">&times;</span>
        <h2 style="margin-top: 0; color: var(--accent-gold);"><i class="fas fa-money-check-alt"></i> Verify Payment</h2>
        
        <div class="verify-details">
            <div class="verify-item">
                <label>Caterer Name</label>
                <div id="v_store_name">Loading...</div>
            </div>
            <div class="verify-item">
                <label>Amount Paid</label>
                <div id="v_amount" style="color: #2ecc71;">Loading...</div>
            </div>
            <div class="verify-item">
                <label>Reference Number</label>
                <div id="v_ref">Loading...</div>
            </div>
            <div class="verify-item">
                <label>Date Submitted</label>
                <div id="v_date">Loading...</div>
            </div>
        </div>

        <h4 style="margin: 10px 0; color: var(--text-muted);">Proof of Payment</h4>
        <img id="v_proof" src="" alt="Payment Proof" class="proof-img">
        
        <form method="POST" id="verifyForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="verify_payment" value="1">
            <input type="hidden" name="payment_id" id="v_payment_id">
            <input type="hidden" name="action" id="v_action">
            
            <div id="rejectReasonField" style="display: none; margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Rejection Reason:</label>
                <textarea name="reject_reason" id="rejectReasonInput" class="form-control" placeholder="e.g., Invalid Reference Number, Blurry Image" style="width: 100%; padding: 10px; border-radius: 6px; background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-main);"></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" onclick="submitVerdict('approve')" class="btn btn-approve">
                    <i class="fas fa-check"></i> Approve & Activate
                </button>
                <button type="button" onclick="toggleReject()" class="btn btn-reject" id="btnReject">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
        </form>
    </div>
</div>

<!-- QR Modal -->
<div id="qrModal" class="modal subscription-modal">
    <div class="modal-content" style="max-width: 400px; text-align: center;">
        <span class="close-modal" onclick="closeQrModal()">&times;</span>
        <h2>Update GCash QR</h2>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div style="margin-bottom: 20px;">
                <?php if ($portal_gcash): ?>
                    <img src="../<?php echo $portal_gcash['setting_value']; ?>" style="max-width: 200px; border-radius: 8px; margin-bottom: 15px;">
                <?php endif; ?>
                <input type="file" name="gcash_qr" accept="image/*" required style="width: 100%; padding: 10px; background: var(--input-bg); border-radius: 6px;">
            </div>
            <button type="submit" name="upload_gcash" class="btn btn-upload" style="width: 100%; justify-content: center;">
                Upload New QR
            </button>
        </form>
    </div>
</div>

<script>
// Keep open admin tabs current without interrupting payment review.
let subscriptionRefreshBusy = false;
async function refreshSubscriptionStatus() {
    if (document.hidden || subscriptionRefreshBusy ||
        document.getElementById('verifyModal').style.display === 'flex' ||
        document.getElementById('qrModal').style.display === 'flex') return;
    subscriptionRefreshBusy = true;
    try {
        const response = await fetch(window.location.href, {cache: 'no-store'});
        if (!response.ok) return;
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const updated = page.querySelector('.subscription-page .table-container');
        const current = document.querySelector('.subscription-page .table-container');
        if (updated && current) current.replaceWith(updated);
    } catch (error) {
        // A temporary network interruption will retry on the next interval.
    } finally {
        subscriptionRefreshBusy = false;
    }
}
setInterval(refreshSubscriptionStatus, 5000);
window.addEventListener('focus', refreshSubscriptionStatus);
document.querySelectorAll('.main-content > .alert').forEach(function(notification) {
    setTimeout(function() {
        notification.remove();
    }, 3000);
});

function verifyPayment(caterer) {
    document.getElementById('v_store_name').textContent = caterer.business_name;
    document.getElementById('v_amount').textContent = '₱' + parseFloat(caterer.pending_amount).toFixed(2);
    document.getElementById('v_ref').textContent = caterer.pending_ref || 'N/A';
    document.getElementById('v_date').textContent = new Date(caterer.pending_date).toLocaleString();
    document.getElementById('v_proof').src = '../' + caterer.pending_proof;
    document.getElementById('v_payment_id').value = caterer.pending_payment_id;
    
    // Reset state
    document.getElementById('rejectReasonField').style.display = 'none';
    document.getElementById('btnReject').textContent = 'Reject';
    document.getElementById('btnReject').setAttribute('onclick', 'toggleReject()');
    document.getElementById('btnReject').type = 'button';
    
    document.getElementById('verifyModal').style.display = 'flex';
}

function closeVerifyModal() {
    document.getElementById('verifyModal').style.display = 'none';
}

function toggleReject() {
    const field = document.getElementById('rejectReasonField');
    const btn = document.getElementById('btnReject');
    
    if (field.style.display === 'none') {
        field.style.display = 'block';
        btn.textContent = 'Confirm Rejection';
        btn.setAttribute('onclick', "submitVerdict('reject')");
    } else {
        field.style.display = 'none';
        btn.textContent = 'Reject';
        btn.setAttribute('onclick', 'toggleReject()');
    }
}

async function submitVerdict(action) {
    if (action === 'reject') {
        const reason = document.getElementById('rejectReasonInput').value;
        if (!reason) {
            alert('Please provide a reason for rejection.');
            return;
        }
    }
    
    if (await portalConfirm('Are you sure you want to ' + action + ' this payment?')) {
        document.getElementById('v_action').value = action;
        document.getElementById('verifyForm').submit();
    }
}

function openQrModal() {
    document.getElementById('qrModal').style.display = 'flex';
}

function closeQrModal() {
    document.getElementById('qrModal').style.display = 'none';
}

// Close modals on outside click
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
