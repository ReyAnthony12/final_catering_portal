<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
requireLogin();

if (getUserRole() !== 'portal') {
    header('Location: ../index.php');
    exit();
}

$csrf_token = generateCSRFToken();

// Get statistics - Updated with pending stores count
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users WHERE role = 'store' AND status = 'pending') as pending_stores,
        (SELECT COUNT(*) FROM users WHERE role = 'store' AND status = 'active') as active_stores,
        (SELECT COUNT(*) FROM users WHERE role = 'store' AND status = 'rejected') as rejected_stores,
        (SELECT COUNT(*) FROM users WHERE role = 'customer') as total_customers
";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['success'] = 'Invalid security token. Please try again.';
        header('Location: users.php');
        exit();
    }

    $user_id = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'];

    if ($user_id <= 0) {
        $_SESSION['success'] = 'Invalid user selected.';
        header('Location: users.php');
        exit();
    }

    if ($action === 'approve_store') {
        $user_data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT email, first_name, last_name, username FROM users WHERE id = $user_id"));
        $ok_user = mysqli_query($conn, "UPDATE users SET status = 'active' WHERE id = $user_id AND role = 'store' AND status = 'pending'");

        if ($ok_user && mysqli_affected_rows($conn) > 0) {
            $store_details = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM store_details WHERE user_id = $user_id"));

            if ($store_details) {
                $trial_start = date('Y-m-d H:i:s');
                $trial_end = date('Y-m-d H:i:s', strtotime('+7 days'));

                mysqli_query($conn, "UPDATE store_details SET
                    approval_status = 'approved',
                    approved_at = NOW(),
                    free_trial_start = '$trial_start',
                    free_trial_expiry = '$trial_end',
                    free_trial_used = 0,
                    subscription_status = 'trial'
                    WHERE user_id = $user_id");
            }

            // Notify caterer owner
            $notif_title = mysqli_real_escape_string($conn, 'Account Approved');
            $notif_message = mysqli_real_escape_string($conn, 'Your caterer account has been approved. 7-day free trial is now active.');
            mysqli_query($conn, "INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
                VALUES ($user_id, 'account_approved', '$notif_title', '$notif_message', 'caterer/dashboard.php', 0, NOW())");

            // Send email notification to caterer owner's registered Gmail
            require_once __DIR__ . '/../includes/mail_helper.php';
            if (!empty($user_data['email'])) {
                $store_name = $store_details['business_name'] ?? 'Caterer';
                $owner_name = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? '')) ?: ($user_data['username'] ?? '');
                $mail_error = null;
                sendStoreApprovalEmail($user_data['email'], $store_name, $owner_name, $mail_error);
            }

            $_SESSION['success'] = 'Caterer approved successfully. 7-day free trial activated.';
        } else {
            $_SESSION['success'] = 'Caterer could not be approved (already processed or not found).';
        }
    } elseif ($action === 'reject_store') {
        $reason = mysqli_real_escape_string($conn, trim((string) ($_POST['reject_reason'] ?? '')));
        if ($reason === '') {
            $_SESSION['success'] = 'Please provide a rejection reason.';
            header('Location: users.php');
            exit();
        }

        $user_data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT email, first_name, last_name, username FROM users WHERE id = $user_id"));
        $ok_user = mysqli_query($conn, "UPDATE users SET status = 'rejected' WHERE id = $user_id AND role = 'store' AND status = 'pending'");

        if ($ok_user && mysqli_affected_rows($conn) > 0) {
            $store_details = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM store_details WHERE user_id = $user_id"));
            if ($store_details) {
                mysqli_query($conn, "UPDATE store_details SET approval_status = 'rejected', rejection_reason = '$reason', rejected_at = NOW() WHERE user_id = $user_id");
            }

            $notif_title = mysqli_real_escape_string($conn, 'Account Rejected');
            $notif_message = mysqli_real_escape_string($conn, 'Your caterer registration was rejected. Reason: ' . $reason);
            mysqli_query($conn, "INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
                VALUES ($user_id, 'account_rejected', '$notif_title', '$notif_message', 'login.php', 0, NOW())");

            // Send disapproval email with reason to caterer owner's registered Gmail
            require_once __DIR__ . '/../includes/mail_helper.php';
            if (!empty($user_data['email'])) {
                $store_name = $store_details['business_name'] ?? 'Caterer';
                $owner_name = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? '')) ?: ($user_data['username'] ?? '');
                $mail_error = null;
                sendStoreRejectionEmail($user_data['email'], $store_name, $reason, $owner_name, $mail_error);
            }

            $_SESSION['success'] = 'Caterer rejected successfully.';
        } else {
            $_SESSION['success'] = 'Caterer could not be rejected (already processed or not found).';
        }
    }

    header('Location: users.php');
    exit();
}

// Get only PENDING stores for approval
$users_query = "
    SELECT u.*, sd.business_name,
           (SELECT COUNT(*) FROM bookings WHERE store_id = u.id) as total_orders
    FROM users u 
    LEFT JOIN store_details sd ON u.id = sd.user_id 
    WHERE u.role = 'store' AND u.status = 'pending'
    ORDER BY u.created_at DESC
";
$users_result = mysqli_query($conn, $users_query);

$page_title = 'Pending Caterer Approvals - Portal Dashboard';
$extra_head = '
<style>
.main-content {
    max-width: 1400px;
    margin-left: 260px;
    padding: 20px;
}

.content-body {
    padding: 15px;
}

.data-table th, 
.data-table td {
    padding: 10px 12px;
    font-size: 13px;
}

.content-header h1 {
    font-size: 24px;
}

.btn {
    padding: 8px 16px;
    font-size: 13px;
}
</style>
<link rel="stylesheet" href="../assets/css/portal-approvals.css?v=20261027-4">
';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

    <div class="main-content approvals-page">
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success" style="margin: 20px;">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Users Table -->
        <div class="content-body">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2><i class="fas fa-clock"></i> Pending Caterer Approvals</h2>
            </div>
            
            <?php if (mysqli_num_rows($users_result) > 0): ?>
                <table class="data-table" id="usersTable">
                    <thead>
                        <tr>
                            <th>User Type</th>
                            <th>User Info</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th>Registration Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($user = mysqli_fetch_assoc($users_result)): 
                            $isPendingStore = ($user['role'] === 'store' && $user['status'] === 'pending');
                            $isRejectedStore = ($user['role'] === 'store' && $user['status'] === 'rejected');
                            
                            // Determine status class
                            $statusClass = '';
                            if ($user['status'] === 'active') {
                                $statusClass = 'status-active';
                            } elseif ($user['status'] === 'pending') {
                                $statusClass = 'status-pending';
                            } elseif ($user['status'] === 'rejected') {
                                $statusClass = 'status-closed';
                            } elseif ($user['status'] === 'inactive') {
                                $statusClass = 'status-closed';
                            }
                        ?>
                            <tr id="approval-user-<?php echo (int)$user['id']; ?>" class="user-row" data-type="<?php echo $user['role']; ?>" data-status="<?php echo $user['status']; ?>">
                                <td data-label="User type">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div class="user-role-icon <?php echo $user['role'] === 'store' ? 'caterer-role' : 'customer-role'; ?>">
                                            <?php echo $user['role'] === 'store' ? '<i class="fas fa-store"></i>' : '<i class="fas fa-user"></i>'; ?>
                                        </div>
                                        <div>
                                            <span class="status-badge <?php echo $user['role'] === 'store' ? 'status-active' : 'status-pending'; ?>" style="font-size: 11px;">
                                                <?php echo $user['role'] === 'store' ? 'Caterer' : ucfirst($user['role']); ?>
                                            </span>
                                            <?php if ($user['role'] === 'store' && !empty($user['business_name'])): ?>
                                                <br><small style="color: var(--text-muted); font-size: 11px;"><strong>Business:</strong> <?php echo htmlspecialchars($user['business_name']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="User info">
                                    <div style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 5px; border-radius: 5px; transition: 0.2s;" class="approval-name-toggle" role="button" tabindex="0" aria-expanded="false" onkeydown="if(event.key === 'Enter' || event.key === ' '){event.preventDefault();this.click();}" onclick="viewUserDetails(<?php echo $user['id']; ?>, '<?php echo $user['role']; ?>')" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">
                                        <?php 
                                        // Get profile image
                                        $profile_query = "SELECT profile_image FROM users WHERE id = '{$user['id']}'";
                                        $profile_result = mysqli_query($conn, $profile_query);
                                        $profile_data = mysqli_fetch_assoc($profile_result);
                                        $profile_image = $profile_data['profile_image'] ?? '';
                                        ?>
                                        <?php if ($profile_image): ?>
                                            <img src="../<?php echo $profile_image; ?>" 
                                                 alt="Profile" class="user-avatar-small" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; flex-shrink: 0;">
                                        <?php else: ?>
                                            <div class="user-avatar-small" style="width: 45px; height: 45px; border-radius: 50%; background: var(--info-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; flex-shrink: 0;">
                                                <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div style="display: flex; flex-direction: column; justify-content: center;">
                                            <strong style="color: var(--info-color); text-decoration: underline; display: flex; align-items: center; gap: 5px; line-height: 1.2;">
                                                <?php echo $user['first_name'] . ' ' . $user['last_name']; ?>
                                                <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                                            </strong>
                                            <small style="color: var(--text-muted); line-height: 1.2; margin-top: 4px;">@<?php echo $user['username']; ?></small>
                                            <?php if(!empty($user['email'])): ?>
                                                <small style="color: var(--text-muted); line-height: 1.2; margin-top: 2px;"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Contact">
                                    <p><i class="fas fa-phone"></i> <?php echo $user['phone_number']; ?></p>
                                    <p><i class="fas fa-map-marker-alt"></i> <?php echo substr($user['address'], 0, 30) . '...'; ?></p>
                                </td>
                                <td data-label="Status">
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php 
                                        // Display proper status text
                                        if ($user['role'] === 'customer') {
                                            echo 'Active';
                                        } else {
                                            echo ucfirst($user['status']);
                                        }
                                        ?>
                                    </span>
                                    
                                    <?php if ($user['role'] === 'store'): ?>
                                        <?php if ($user['total_orders'] > 0): ?>
                                            <br>
                                            <small style="color: var(--info-color); font-size: 11px;">
                                                <i class="fas fa-shopping-cart"></i> <?php echo $user['total_orders']; ?> orders
                                            </small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Registration date">
                                    <p><?php echo date('M d, Y', strtotime($user['created_at'])); ?></p>
                                    <small style="color: var(--text-muted);">
                                        <i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($user['created_at'])); ?>
                                    </small>
                                </td>
                                <td data-label="Actions">
                                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                        <button onclick="viewUserDetails(<?php echo $user['id']; ?>, '<?php echo $user['role']; ?>')" 
                                                class="btn btn-info" style="padding: 5px 10px; font-size: 12px;">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <form method="POST" action="login_as_user.php" style="display: inline-block;" onsubmit="return portalConfirmForm(event, this, 'Login as this user account?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                                            <button type="submit" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                                <i class="fas fa-sign-in-alt"></i> Login
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php if ($user['role'] === 'store'): ?>
                                <?php 
                                // Get caterer details for the expanded view
                                $store_details_query = "SELECT * FROM store_details WHERE user_id = '{$user['id']}'";
                                $store_details_result = mysqli_query($conn, $store_details_query);
                                $store_details = mysqli_fetch_assoc($store_details_result);
                                ?>
                                <tr class="caterer-details-row" id="caterer-details-<?php echo $user['id']; ?>" style="display: none; background: rgba(0, 0, 0, 0.15);">
                                    <td colspan="6" style="padding: 25px; border-top: 1px solid var(--border-color);">
                                        <div class="approval-review-layout" style="display: flex; flex-wrap: wrap; gap: 30px;">
                                            <!-- Left: Documents -->
                                            <div class="approval-documents" style="flex: 1; min-width: 300px;">
                                                <h4 style="font-size: 14px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                                                    <i class="fas fa-file-alt"></i> Caterer Documents
                                                </h4>
                                                <?php if ($store_details): ?>
                                                    <div class="approval-document-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 15px;">
                                                        <?php if (!empty($store_details['valid_id_image'])): ?>
                                                            <div onclick="viewImage('<?php echo $store_details['valid_id_image']; ?>', 'Valid ID')" style="cursor: pointer; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; background: #fff; text-align: center; position: relative;">
                                                                <img src="../<?php echo $store_details['valid_id_image']; ?>" alt="Valid ID" style="width: 100%; height: 90px; object-fit: cover;">
                                                                <div style="padding: 8px 5px; background: #f8f9fa; border-top: 1px solid #eee;">
                                                                    <p style="font-size: 11px; color: #333; margin: 0; font-weight: 600;">Valid ID</p>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($store_details['business_permit_image'])): ?>
                                                            <div onclick="viewImage('<?php echo $store_details['business_permit_image']; ?>', 'Business Permit')" style="cursor: pointer; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; background: #fff; text-align: center; position: relative;">
                                                                <img src="../<?php echo $store_details['business_permit_image']; ?>" alt="Business Permit" style="width: 100%; height: 90px; object-fit: cover;">
                                                                <div style="padding: 8px 5px; background: #f8f9fa; border-top: 1px solid #eee;">
                                                                    <p style="font-size: 11px; color: #333; margin: 0; font-weight: 600;">Business Permit</p>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($store_details['gcash_qr_image'])): ?>
                                                            <div onclick="viewImage('<?php echo $store_details['gcash_qr_image']; ?>', 'GCash QR')" style="cursor: pointer; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; background: #fff; text-align: center; position: relative;">
                                                                <img src="../<?php echo $store_details['gcash_qr_image']; ?>" alt="GCash QR" style="width: 100%; height: 90px; object-fit: contain;">
                                                                <div style="padding: 8px 5px; background: #f8f9fa; border-top: 1px solid #eee;">
                                                                    <p style="font-size: 11px; color: #333; margin: 0; font-weight: 600;">GCash QR</p>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <p style="color: var(--text-muted); font-style: italic;">No documents submitted yet.</p>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- Right: Checklist & Actions -->
                                            <div class="approval-verification" style="flex: 1.5; min-width: 350px; display: flex; flex-direction: column; justify-content: space-between;">
                                                <div>
                                                    <h4 style="font-size: 14px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                                                        <i class="fas fa-clipboard-check"></i> Verification Checklist
                                                    </h4>
                                                    <div style="background: var(--card-bg); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color);">
                                                        <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px;">
                                                            <li><label style="cursor: pointer; display: flex; align-items: center; gap: 10px;"><input type="checkbox" style="width:16px; height:16px; accent-color: var(--success-color);"> Name matches Valid ID</label></li>
                                                            <li><label style="cursor: pointer; display: flex; align-items: center; gap: 10px;"><input type="checkbox" style="width:16px; height:16px; accent-color: var(--success-color);"> Business name matches Permit</label></li>
                                                            <li><label style="cursor: pointer; display: flex; align-items: center; gap: 10px;"><input type="checkbox" style="width:16px; height:16px; accent-color: var(--success-color);"> Documents are clear and readable</label></li>
                                                            <li><label style="cursor: pointer; display: flex; align-items: center; gap: 10px;"><input type="checkbox" style="width:16px; height:16px; accent-color: var(--success-color);"> GCash QR is valid</label></li>
                                                        </ul>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($isPendingStore): ?>
                                                    <div class="approval-decision-actions" style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 15px; border-top: 1px solid var(--border-color); padding-top: 15px;">
                                                        <button onclick="showRejectModal(<?php echo $user['id']; ?>)" class="btn btn-danger" style="padding: 10px 20px; font-size: 14px;">
                                                            <i class="fas fa-times"></i> Reject Caterer
                                                        </button>
                                                        <button onclick="showApproveModal(<?php echo $user['id']; ?>)" class="btn btn-success" style="padding: 10px 20px; font-size: 14px;">
                                                            <i class="fas fa-check"></i> Approve Caterer
                                                        </button>
                                                    </div>
                                                <?php elseif ($isRejectedStore): ?>
                                                    <div style="background: rgba(244, 67, 54, 0.1); padding: 15px; border-radius: 5px; margin-top: 10px;">
                                                        <h5><i class="fas fa-times-circle"></i> Rejected Caterer</h5>
                                                        <?php if ($store_details && !empty($store_details['rejection_reason'])): ?>
                                                            <p><strong>Rejection Reason:</strong> <?php echo htmlspecialchars($store_details['rejection_reason']); ?></p>
                                                            <?php if (!empty($store_details['rejected_at'])): ?>
                                                                <p><strong>Rejected on:</strong> <?php echo date('M d, Y', strtotime($store_details['rejected_at'])); ?></p>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <p>This caterer has been rejected.</p>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif ($user['status'] === 'active'): ?>
                                                    <div style="background: rgba(76, 175, 80, 0.1); padding: 15px; border-radius: 5px; margin-top: 10px;">
                                                        <h5><i class="fas fa-check-circle"></i> Active Caterer</h5>
                                                        <?php if ($store_details && !empty($store_details['approved_at'])): ?>
                                                            <p><strong>Approved on:</strong> <?php echo date('M d, Y', strtotime($store_details['approved_at'])); ?></p>
                                                        <?php endif; ?>
                                                        <p><strong>Status:</strong> Active Caterer</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php elseif ($user['role'] === 'customer'): ?>
                                <?php 
                                // Customer accounts are stored in users; no separate profile table.
                                $customer_details = [
                                    'preferences' => '',
                                    'notes' => ''
                                ];
                                
                                ?>
                                <tr class="customer-details-row" id="customer-details-<?php echo $user['id']; ?>" style="display: none; background: rgba(0, 0, 0, 0.15);">
                                    <td colspan="6">
                                        <h4><i class="fas fa-user-circle"></i> Customer Details</h4>
                                        
                                        <div class="customer-info-grid">
                                            <div class="info-card">
                                                <h4><i class="fas fa-user"></i> Personal Information</h4>
                                                <div class="info-item">
                                                    <span class="info-label">Full Name:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label">Username:</span>
                                                    <span class="info-value">@<?php echo htmlspecialchars($user['username']); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label">Phone Number:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($user['phone_number']); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label">Full Address:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($user['address']); ?></span>
                                                </div>
                                            </div>
                                            
                                            <div class="info-card">
                                                <h4><i class="fas fa-calendar-alt"></i> Account Information</h4>
                                                <div class="info-item">
                                                    <span class="info-label">Member Since:</span>
                                                    <span class="info-value"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label">Account Status:</span>
                                                    <span class="info-value"><span class="status-badge status-active">Active</span></span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label">Last Login:</span>
                                                    <span class="info-value"><?php echo !empty($user['last_login']) ? date('M d, Y h:i A', strtotime($user['last_login'])) : 'Never logged in'; ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label">Profile Picture:</span>
                                                    <span class="info-value">
                                                        <?php if ($profile_image): ?>
                                                            <img src="../<?php echo $profile_image; ?>" alt="Profile" style="width: 40px; height: 40px; border-radius: 50%; border: 2px solid var(--accent-gold);">
                                                        <?php else: ?>
                                                            <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--info-color); display: flex; align-items: center; justify-content: center; color: white;">
                                                                <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($customer_details['preferences']) || !empty($customer_details['notes'])): ?>
                                            <div class="info-card" style="grid-column: 1 / -1; margin-top: 20px;">
                                                <h4><i class="fas fa-info-circle"></i> Additional Information</h4>
                                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                                    <?php if (!empty($customer_details['preferences'])): ?>
                                                        <div>
                                                            <h5><i class="fas fa-heart"></i> Preferences</h5>
                                                            <p style="color: var(--text-muted); font-size: 14px;"><?php echo htmlspecialchars($customer_details['preferences']); ?></p>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($customer_details['notes'])): ?>
                                                        <div>
                                                            <h5><i class="fas fa-sticky-note"></i> Admin Notes</h5>
                                                            <p style="color: var(--text-muted); font-size: 14px;"><?php echo htmlspecialchars($customer_details['notes']); ?></p>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="workspace-empty">
                    <span class="workspace-empty-icon"><i class="fas fa-user-check"></i></span>
                    <h3>You're all caught up</h3>
                    <p>New caterer applications will appear here, ready for your review.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Approval Modal -->
    <div id="approveModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: var(--card-bg); padding: 30px; border-radius: 10px; max-width: 500px; width: 90%; border: 1px solid var(--border-color); color: var(--text-main);">
            <h3><i class="fas fa-check-circle"></i> Approve Caterer</h3>
            <p style="color: var(--text-muted);">Are you sure you want to approve this caterer? They will receive a 7-day free trial.</p>
            <form method="POST" action="" style="margin-top: 20px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="user_id" id="approve_user_id">
                <input type="hidden" name="action" value="approve_store">
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="hideModal('approveModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve Caterer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: var(--card-bg); padding: 30px; border-radius: 10px; max-width: 500px; width: 90%; border: 1px solid var(--border-color); color: var(--text-main);">
            <h3><i class="fas fa-times-circle"></i> Reject Caterer</h3>
            <p style="color: var(--text-muted);">Please provide a reason for rejecting this caterer. The caterer owner will see this reason when they try to login.</p>
            <form method="POST" action="" style="margin-top: 20px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="user_id" id="reject_user_id">
                <input type="hidden" name="action" value="reject_store">
                <div class="form-group">
                    <label for="reject_reason">Rejection Reason:</label>
                    <textarea id="reject_reason" name="reject_reason" rows="4" required 
                              style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 5px; background: var(--input-bg); color: var(--text-main);"
                              placeholder="Example: Valid ID doesn't match name, Business permit expired, etc."></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="hideModal('rejectModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Caterer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Image View Modal -->
    <div id="imageModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1001; align-items: center; justify-content: center;">
        <div style="background: var(--card-bg); padding: 20px; border-radius: 10px; max-width: 90%; max-height: 90%; position: relative; border: 1px solid var(--border-color);">
            <button onclick="hideModal('imageModal')" style="position: absolute; top: 10px; right: 10px; background: var(--error-color); color: white; border: none; width: 30px; height: 30px; border-radius: 50%; cursor: pointer;">×</button>
            <h3 id="imageTitle" style="margin-bottom: 15px; color: var(--text-main);"></h3>
            <img id="modalImage" src="" alt="" style="max-width: 100%; max-height: 70vh;">
        </div>
    </div>

    <script>
        function filterUsers(filter) {
            const rows = document.querySelectorAll('.user-row');
            const buttons = document.querySelectorAll('.filter-buttons .btn');
            
            // Update button styles
            buttons.forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            rows.forEach(row => {
                const type = row.dataset.type;
                const status = row.dataset.status;
                
                if (filter === 'all') {
                    row.style.display = '';
                } else if (filter === 'caterer' && type === 'store') {
                    row.style.display = '';
                } else if (filter === 'customer' && type === 'customer') {
                    row.style.display = '';
                } else if (filter === 'pending' && status === 'pending') {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
                
                // Hide details rows
                const detailsRow = row.nextElementSibling;
                if (detailsRow && (detailsRow.classList.contains('caterer-details-row') || detailsRow.classList.contains('customer-details-row'))) {
                    detailsRow.style.display = 'none';
                }
            });
        }
        
        function viewUserDetails(userId, userType) {
            const detailsId = userType === 'store' ? 'caterer-details-' + userId : 'customer-details-' + userId;
            const detailsRow = document.getElementById(detailsId);
            const userRow = document.getElementById('approval-user-' + userId);
            if (!detailsRow || !userRow) return;
            const opening = detailsRow.style.display === 'none' || detailsRow.style.display === '';
            document.querySelectorAll('.caterer-details-row, .customer-details-row').forEach(row => {
                row.style.display = 'none';
            });
            document.querySelectorAll('.user-row').forEach(row => {
                row.classList.remove('approval-expanded');
                row.querySelector('.approval-name-toggle')?.setAttribute('aria-expanded', 'false');
            });
            if (opening) {
                userRow.classList.add('approval-expanded');
                userRow.querySelector('.approval-name-toggle')?.setAttribute('aria-expanded', 'true');
                detailsRow.style.display = 'table-row';
            }
        }

        function showApproveModal(userId) {
            document.getElementById('approve_user_id').value = userId;
            document.getElementById('approveModal').style.display = 'flex';
        }
        
        function showRejectModal(userId) {
            document.getElementById('reject_user_id').value = userId;
            document.getElementById('rejectModal').style.display = 'flex';
            document.getElementById('reject_reason').focus();
        }
        
        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function viewImage(imagePath, title) {
            document.getElementById('modalImage').src = '../' + imagePath;
            document.getElementById('imageTitle').textContent = title;
            document.getElementById('imageModal').style.display = 'flex';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.id === 'approveModal' || event.target.id === 'rejectModal' || event.target.id === 'imageModal') {
                event.target.style.display = 'none';
            }
        }
        
        // Initialize filter buttons
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('.filter-buttons .btn:first-child')?.classList.add('active');
            
            // Show success message if exists
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('approved')) {
                alert('Caterer approved successfully!');
            } else if (urlParams.has('rejected')) {
                alert('Caterer rejected successfully!');
            }
        });
    </script>
<?php require __DIR__ . '/includes/footer-bar.php'; ?>

</body>
</html>
