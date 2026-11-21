<?php
// Start session first as header.php checks it
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/subscription_helper.php';
require_once __DIR__ . '/../includes/subscription_pricing_helper.php';

if (!isLoggedIn() || !in_array(getUserRole(), ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'subscription_price') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $qr_result = mysqli_query($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'portal_gcash_qr' LIMIT 1");
    $qr_path = $qr_result ? (mysqli_fetch_assoc($qr_result)['setting_value'] ?? '') : '';
    echo json_encode(['amount' => getSubscriptionFee($conn), 'qr_path' => $qr_path]);
    exit();
}
$user_id = $_SESSION['user_id'];
$success = '';
$error = '';
$success = $_SESSION['subscription_payment_success'] ?? '';
unset($_SESSION['subscription_payment_success']);

// Check if caterer is closed by portal
$store_status_query = "SELECT u.status as user_status FROM users u WHERE u.id = '$user_id'";
$store_status_result = mysqli_query($conn, $store_status_query);
$store_status = mysqli_fetch_assoc($store_status_result);
$is_store_closed_by_portal = ($store_status['user_status'] === 'closed');

// Get caterer subscription details
$subscription_query = "
    SELECT sd.*, s.last_payment_date
    FROM store_details sd
    LEFT JOIN (
        SELECT store_id, MAX(created_at) as last_payment_date
        FROM subscriptions 
        WHERE store_id = '$user_id'
        GROUP BY store_id
    ) s ON sd.user_id = s.store_id
    WHERE sd.user_id = '$user_id'
";
$subscription_result = mysqli_query($conn, $subscription_query);
$subscription = mysqli_fetch_assoc($subscription_result);

$subscription_fee = getSubscriptionFee($conn);
$subscription_warning_days = getSubscriptionWarningDays();
// Get portal's GCash QR
$portal_gcash_query = "SELECT setting_value FROM system_settings WHERE setting_key = 'portal_gcash_qr'";
$portal_gcash_result = mysqli_query($conn, $portal_gcash_query);
$portal_gcash = $portal_gcash_result ? mysqli_fetch_assoc($portal_gcash_result) : null;

// Handle subscription payment (only if caterer is not closed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment']) && !$is_store_closed_by_portal) {
    $price_lock_acquired = false;
    try {
    $price_lock_acquired = acquireSubscriptionPricingLock($conn);
    if (!$price_lock_acquired) throw new RuntimeException('Subscription pricing is being updated. Please try again.');
    mysqli_begin_transaction($conn);
    $subscription_fee = getSubscriptionFee($conn);
    // Serialize submissions for this caterer, including simultaneous requests.
    mysqli_query($conn, "SELECT user_id FROM store_details WHERE user_id = '$user_id' FOR UPDATE");
    $safe_reference = '';
    // Check if there's already a pending payment
    $check_pending_query = "SELECT COUNT(*) as pending_count FROM subscriptions 
                           WHERE store_id = '$user_id' AND payment_status = 'pending'";
    $check_pending_result = mysqli_query($conn, $check_pending_query);
    $check_pending = mysqli_fetch_assoc($check_pending_result);
    
    if ($check_pending['pending_count'] > 0) {
        $error = 'You already have a subscription payment pending review. Please wait for admin action before submitting again.';
    } else {
        // Handle payment screenshot upload
        $payment_screenshot = '';
        if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
            // Validate file type
            $image_info = @getimagesize($_FILES['payment_screenshot']['tmp_name']);
            $extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
            $extension = $extensions[$image_info[2] ?? 0] ?? null;

            if ($extension === null) {
                $error = 'Only JPEG, PNG, and GIF files are allowed for payment screenshot.';
            } else {
                $file_name = 'payment_' . bin2hex(random_bytes(16)) . '.' . $extension;
                $target_file = '../' . PAYMENT_DIR . $file_name;
                
                // Ensure payment directory exists
                if (!file_exists('../' . PAYMENT_DIR)) {
                    mkdir('../' . PAYMENT_DIR, 0777, true);
                }
                
                if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $target_file)) {
                    $payment_screenshot = PAYMENT_DIR . $file_name;
                } else {
                    $error = 'Failed to upload payment screenshot. Please try again.';
                }
            }
        } else {
            $error = 'Please upload payment screenshot.';
        }
        
        if ($payment_screenshot && !$error) {
            $amount = $subscription_fee;
            $reference_number = $safe_reference;
            
            // Insert payment record with pending status
            $query = "INSERT INTO subscriptions (store_id, amount, payment_screenshot, reference_number, payment_status, created_at) 
                      VALUES ('$user_id', '$amount', '$payment_screenshot', '$reference_number', 'pending', NOW())";
            
            if (mysqli_query($conn, $query)) {
                $success = 'Subscription payment submitted successfully. Please wait for portal admin review.';

                notifyPortalAdmins(
                    'payment_received', 
                    'Subscription Payment Submitted', 
                    "Caterer {$subscription['business_name']} submitted a subscription payment of ?{$amount} for review.",
                    'subscription.php'
                );
                
                // Refresh subscription data after successful payment submission
                $subscription_result = mysqli_query($conn, $subscription_query);
                $subscription = mysqli_fetch_assoc($subscription_result);
                
            } else {
                appLogError('Caterer subscription payment submission failed', mysqli_error($conn));
                $error = 'Failed to submit payment. Please try again.';
            }
        }
    }
    if ($error) {
        mysqli_rollback($conn);
    } else {
        mysqli_commit($conn);
        $_SESSION['subscription_payment_success'] = $success;
        releaseSubscriptionPricingLock($conn);
        $price_lock_acquired = false;
        header('Location: subscription.php');
        exit();
    }
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        appLogError('Subscription submission failed', $e->getMessage());
        $error = 'Failed to submit payment. Please try again.';
    } finally {
        if ($price_lock_acquired) releaseSubscriptionPricingLock($conn);
    }
}

// Get payment history
$payments_query = "
    SELECT * FROM subscriptions 
    WHERE store_id = '$user_id' 
    ORDER BY created_at DESC
";
$payments_result = mysqli_query($conn, $payments_query);

// Check free trial status
$current_date = date('Y-m-d');
$trial_days_left = 0;
$free_trial_active = false;

if ($subscription && $subscription['free_trial_start'] && !$subscription['free_trial_used']) {
    if ($subscription['free_trial_expiry'] >= $current_date) {
        $trial_days_left = floor((strtotime($subscription['free_trial_expiry']) - strtotime($current_date)) / (60 * 60 * 24));
        $free_trial_active = ($trial_days_left > 0);
    }
}

// Check if user has active or pending subscription
$has_active_subscription = ($subscription && $subscription['subscription_status'] === 'active');
$subscription_status = $subscription ? $subscription['subscription_status'] : 'inactive';

// Check for pending subscription payment
$pending_query = "SELECT COUNT(*) as pending_count FROM subscriptions 
                  WHERE store_id = '$user_id' AND payment_status = 'pending'";
$pending_result = mysqli_query($conn, $pending_query);
$pending_data = mysqli_fetch_assoc($pending_result);
$has_pending_payment = ($pending_data['pending_count'] > 0);
// Check if subscription is expiring soon
$is_expiring_soon = false;
$days_until_expiry = 0;
if ($subscription && $subscription['subscription_expiry'] && $subscription['subscription_status'] === 'active') {
    $days_until_expiry = floor((strtotime($subscription['subscription_expiry']) - strtotime($current_date)) / (60 * 60 * 24));
    $is_expiring_soon = ($days_until_expiry <= $subscription_warning_days && $days_until_expiry > 0);
}

// Check if subscription has expired
$is_expired = false;
if ($subscription && $subscription['subscription_expiry'] && $subscription['subscription_status'] === 'active') {
    $is_expired = (strtotime($subscription['subscription_expiry']) < time());
}

// Only show free trial if:
// 1. Free trial hasn't been used yet
// 2. User doesn't have active subscription
// 3. User doesn't have pending payment
// 4. Free trial is not expired
$show_free_trial = (
    $subscription && 
    $subscription['free_trial_start'] && 
    !$subscription['free_trial_used'] && 
    !$has_active_subscription && 
    !$has_pending_payment && 
    $free_trial_active
);

// Check if caterer can make payments (not in free trial or has active subscription)
$can_make_payment = (!$show_free_trial && !$has_pending_payment);

// Caterer payment history in array for later use
$payment_history = [];
while ($payment = mysqli_fetch_assoc($payments_result)) {
    $payment_history[] = $payment;
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
        .main-content {
            padding: 30px;
            transition: all 0.3s ease;
        }
        .page-header {
            margin-bottom: 30px;
        }

        .page-header h1 {
            margin: 0;
            font-size: 24px;
            color: var(--text-main);
        }

        .page-header p {
            margin: 5px 0 0;
            color: var(--text-muted);
        }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid var(--border-color);
        }

        .card h3 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 18px;
            color: var(--text-main);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid transparent;
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
            border-color: rgba(46, 204, 113, 0.2);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border-color: rgba(231, 76, 60, 0.2);
        }
        
        .alert-warning {
            background: rgba(241, 196, 15, 0.1);
            color: var(--warning);
            border-color: rgba(241, 196, 15, 0.2);
        }

        /* Status Summary Cards */
        .status-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
            color: var(--text-main);
        }
        
        .summary-card h3 {
            color: var(--accent-gold);
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 10px;
        }

        .status-dot.active { background: var(--success); }
        .status-dot.inactive { background: var(--danger); }
        .status-dot.pending { background: var(--warning); }
        .status-dot.trial { background: #9b59b6; }

        .countdown-number {
            font-size: 32px;
            font-weight: 700;
            color: var(--accent-gold);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 25px;
            background: var(--accent-gold);
            color: #000;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-pay-now {
            background: var(--success);
            color: #fff;
            font-size: 16px;
            padding: 15px 30px;
        }
        
        .btn:disabled, .btn-disabled {
            background: var(--text-muted);
            cursor: not-allowed;
            opacity: 0.7;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-family: inherit;
            background: var(--primary-bg);
            color: var(--text-main);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table th, .table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            font-weight: 600;
            color: var(--text-muted);
            font-size: 13px;
            text-transform: uppercase;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: transparent;
            border: 1px solid var(--border-color);
        }

        .status-badge.active { 
            color: var(--success);
            border-color: rgba(46, 204, 113, 0.5);
        }
        .status-badge.pending { 
            color: var(--warning);
            border-color: rgba(241, 196, 15, 0.5);
        }
        .status-badge.rejected { 
            color: var(--danger);
            border-color: rgba(231, 76, 60, 0.5);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow: auto;
            position: relative;
            color: var(--text-main);
        }

        .modal-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
        }

        .status-summary { grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); }
        .summary-card { border-radius: 16px; }
        .summary-card h3 { font-size: 14px; margin-bottom: 18px; }
        #payment-section { scroll-margin-top: 78px; border-radius: 18px; }
        .payment-intro { color: var(--text-muted); line-height: 1.6; margin: -8px 0 24px; }
        .subscription-payment-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }
        .payment-step { min-width: 0; padding: 22px; border: 1px solid var(--border-color); border-radius: 14px; background: rgba(255,255,255,.02); }
        .payment-step h4 { display: flex; align-items: center; gap: 10px; margin: 0 0 16px; font-size: 16px; }
        .payment-step-number { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; flex-shrink: 0; border-radius: 50%; background: rgba(212,175,55,.12); color: var(--accent-gold); font-size: 13px; }
        .payment-step p { color: var(--text-muted); line-height: 1.6; }
        .payment-qr { text-align: center; padding: 20px; background: rgba(0,0,0,.16); border-radius: 12px; }
        .payment-qr img { display: block; width: 100%; max-width: 220px; height: auto; margin: 0 auto; border-radius: 10px; }
        .payment-step .form-control { max-width: 100%; min-height: 48px; border-radius: 10px; padding: 12px; }
        .payment-step .form-control:focus { outline: 2px solid var(--accent-gold); outline-offset: 2px; }
        .payment-step input[type=file] { font-size: 13px; }
        .payment-step input::file-selector-button { background: #333; color: #eee; border: 0; border-radius: 6px; padding: 8px; margin-right: 8px; cursor: pointer; }
        .subscription-payment-grid .btn { overflow-wrap: normal; word-break: normal; border-radius: 10px; padding: 14px 16px; }
        .payment-help { font-size: 12px; margin: 12px 0 0; }
        .payment-history { border-radius: 18px; }
        .payment-history-heading { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
        .payment-history-icon { display: grid; place-items: center; flex: 0 0 42px; height: 42px; border-radius: 12px; background: rgba(212,175,55,.12); color: var(--accent-gold); }
        .payment-history .payment-history-heading h3 { border: 0; padding: 0; margin: 0 0 5px; font-size: 18px; }
        .payment-history-heading p { margin: 0; color: var(--text-muted); font-size: 12px; line-height: 1.5; }
        .payment-history-table .payment-amount { font-weight: 700; color: var(--accent-gold); }
        .payment-history-table .status-badge { line-height: 1.5; padding: 5px 10px; }
        .payment-history-table .status-badge.pending { background: rgba(241,196,15,.08); }
        .payment-history-table .status-badge.active { background: rgba(46,204,113,.08); }
        .payment-history-table .status-badge.rejected { background: rgba(231,76,60,.08); }
        .payment-history-table .proof-button { padding: 10px 14px; font-size: 12px; border-radius: 9px; }
        .payment-history-table .proof-button:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 3px; }
        .payment-history-table .payment-date,
        .payment-history-table .payment-amount,
        .payment-history-table .status-badge,
        .payment-history-table .btn { white-space: nowrap; overflow-wrap: normal; word-break: normal; }
        .payment-history-table .payment-reference { overflow-wrap: anywhere; }
        @media (max-width: 768px) {
            .payment-history-table,
            .payment-history-table tbody { display: block; width: 100%; }
            .payment-history-table thead {
                position: absolute; width: 1px; height: 1px; padding: 0;
                overflow: hidden; clip-path: inset(50%); white-space: nowrap;
            }
            .payment-history-table tr {
                display: flex; flex-direction: column; padding: 16px; margin-bottom: 16px;
                background: linear-gradient(135deg, rgba(212,175,55,.05), transparent 60%);
                border: 1px solid var(--border-color); border-radius: 14px;
            }
            .payment-history-table tr:last-child { margin-bottom: 0; }
            .payment-history-table.table td {
                display: grid; grid-template-columns: 80px minmax(0, 1fr);
                align-items: start; gap: 12px; padding: 10px 0;
                font-size: 14px; line-height: 1.5; white-space: normal;
            }
            .payment-history-table td::before {
                content: attr(data-label); color: var(--text-muted);
                font-size: 12px; font-weight: 600;
            }
            .payment-history-table td:last-child { border-bottom: 0; }
            .payment-history-table td > div { min-width: 0; }
            .payment-history-table.table .payment-amount {
                order: -1; display: flex; flex-direction: column; gap: 4px;
                padding-top: 0; padding-bottom: 14px; font-size: clamp(22px, 6vw, 28px);
                letter-spacing: -.02em;
            }
            .payment-history-table .payment-amount::before { content: 'Subscription payment'; letter-spacing: 0; font-weight: 500; }
            .payment-history-table.table td[data-label="Proof"] { display: block; padding: 14px 0 0; }
            .payment-history-table td[data-label="Proof"]::before { display: none; }
            .payment-history-table .status-badge { white-space: normal; overflow-wrap: normal; }
            .payment-history-table .btn { width: 100%; min-height: 44px; }
        }
        @media (max-width: 992px) {
            .main-content { padding: 74px 16px 24px; }
        }
        @media (max-width: 480px) {
            .main-content { padding-left: 12px; padding-right: 12px; }
        }
        @media (max-width: 768px) {
            .subscription-payment-grid { grid-template-columns: minmax(0, 1fr); gap: 16px; }
            .payment-step { padding: 16px; }
            .status-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }
            .summary-card, .card {
                min-width: 0;
                padding: 18px;
                overflow-wrap: anywhere;
            }
            .status-summary .summary-card {
                padding: 14px 12px;
                font-size: 13px;
                line-height: 1.5;
                overflow-wrap: break-word;
            }
            .status-summary .summary-card h3 {
                gap: 6px;
                font-size: 13px;
                margin-bottom: 12px;
            }
            .status-summary .summary-card h3 > i,
            .status-summary .status-dot { flex-shrink: 0; }
            .status-summary .btn-pay-now {
                box-sizing: border-box;
                padding: 10px 8px;
                gap: 6px;
                font-size: 12px;
                overflow-wrap: normal;
            }
            .form-control { min-width: 0; font-size: 16px; }
            .btn { min-height: 44px; white-space: normal; }
            .alert { align-items: flex-start; gap: 10px; }
            .alert > i { flex-shrink: 0; margin-top: 3px; }
            .table th, .table td { padding: 12px; }
            .modal-content { width: calc(100% - 24px); }
        }
        .status-summary .summary-card {
            position: relative;
            display: flex;
            flex-direction: column;
            min-width: 0;
            padding: 16px;
            border-radius: 12px;
            background: radial-gradient(ellipse at top left, rgba(212,175,55,.09), transparent 70%), var(--card-bg);
            box-shadow: 0 8px 24px rgba(0,0,0,.12);
            overflow-wrap: break-word;
        }
        .status-summary .membership-renewal {
            border-color: rgba(212,175,55,.35);
            background: radial-gradient(ellipse at top right, rgba(212,175,55,.18), transparent 75%), var(--card-bg);
        }
        .membership-icon {
            display: grid; place-items: center; width: 30px; height: 30px;
            margin-bottom: 10px; border-radius: 8px; color: var(--accent-gold);
            background: rgba(212,175,55,.1); border: 1px solid rgba(212,175,55,.16);
            font-size: 14px;
        }
        .status-summary .summary-card h3 { color: var(--text-muted); font-size: 12px; font-weight: 500; margin: 0 0 12px; }
        .membership-badge { display: inline-flex; align-items: center; max-width: 100%; font-size: 15px; font-weight: 700; line-height: 1.35; }
        .membership-badge .status-dot { width: 8px; height: 8px; margin-right: 8px; flex-shrink: 0; }
        .membership-badge .status-dot.active { box-shadow: 0 0 0 4px rgba(46,204,113,.1); }
        .membership-expiry { display: flex; flex-direction: column; gap: 4px; margin: 10px 0 0; font-size: 12px; color: var(--text-muted); }
        .membership-expiry strong { color: var(--text-main); font-weight: 500; font-size: 13px; }
        .membership-price { font-size: 24px; font-weight: 750; letter-spacing: -.04em; line-height: 1.2; color: var(--accent-gold); }
        .membership-price span { display: block; font-size: 11px; letter-spacing: 0; color: var(--text-muted); font-weight: 400; margin-top: 4px; }
        .membership-description { font-size: 12px; color: var(--text-muted); line-height: 1.5; margin: 8px 0 12px; }
        .status-summary .btn-pay-now {
            box-sizing: border-box; margin-top: auto; padding: 12px; border-radius: 10px;
            background: linear-gradient(120deg, #e8cb70, #c8a43b); color: #211b0b;
            font-size: 13px; gap: 10px; box-shadow: 0 4px 12px rgba(212,175,55,.12);
        }
        .status-summary .btn-pay-now:hover { filter: brightness(1.08); }
        .status-summary .btn-pay-now:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 4px; }
        @media (max-width: 768px) {
            .status-summary { gap: 12px; margin-bottom: 22px; }
            .status-summary .summary-card { padding: 12px; border-radius: 12px; }
            .membership-icon { width: 28px; height: 28px; font-size: 13px; margin-bottom: 8px; border-radius: 8px; }
            .membership-badge { font-size: 14px; }
            .membership-price { font-size: 22px; }
            .status-summary .btn-pay-now { padding: 10px 8px; font-size: 12px; gap: 7px; }
        }
        /* Compact subscription stats on desktop and mobile. */
        .status-summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
            align-items: stretch;
        }
        .status-summary .summary-card {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            background: linear-gradient(145deg, rgba(255,255,255,.035), transparent 65%), var(--card-bg);
            box-shadow: 0 4px 16px rgba(0,0,0,.08), inset 0 1px 0 rgba(255,255,255,.025);
        }
        .status-summary .membership-renewal {
            border-color: rgba(212,175,55,.3);
            background: linear-gradient(135deg, rgba(212,175,55,.07), transparent 75%), var(--card-bg);
        }
        .status-summary .summary-card h3 { margin-bottom: 6px; font-size: 11px; font-weight: 500; letter-spacing: .025em; line-height: 1.4; }
        .membership-badge { display: inline-flex; align-items: center; font-size: 16px; font-weight: 650; line-height: 1.6; }
        .membership-status .membership-details { display: flex; flex: 1; flex-direction: column; align-items: flex-start; }
        .membership-badge .status-dot { display: none; }
        .membership-expiry { box-sizing: border-box; width: 100%; flex-direction: column; gap: 2px; margin: auto 0 0; padding-top: 6px; font-size: 11px; line-height: 1.5; }
        .membership-expiry strong { font-size: 12px; font-weight: 500; font-variant-numeric: tabular-nums; }
        .membership-price { font-size: 25px; letter-spacing: -.035em; font-weight: 700; line-height: 1.1; }
        .membership-price span { display: inline-block; margin-left: 5px; font-size: 11px; letter-spacing: 0; vertical-align: baseline; }
        .status-summary .btn-pay-now {
            display: flex; align-items: center; justify-content: center;
            min-height: 44px; margin-top: 8px; padding: 8px 10px;
            border: 1px solid rgba(240,216,136,.35); border-radius: 8px;
            background: linear-gradient(110deg, #e6ca72, #c8a640);
            box-shadow: 0 2px 6px rgba(0,0,0,.12), inset 0 1px 0 rgba(255,255,255,.18);
            font-size: 12px; font-weight: 600; line-height: 1.4;
            transition: filter .15s ease;
        }
        .membership-renewal > div[style] { padding: 0 !important; text-align: left !important; font-size: 12px; line-height: 1.5; }
        .membership-renewal > div[style] p { margin: 4px 0 0; }
        @media (max-width: 768px) {
            .status-summary { gap: 10px; }
            .status-summary .summary-card { padding: 10px; border-radius: 12px; }
            .membership-price { font-size: 23px; }
        }
        @media (max-width: 360px) {
            .status-summary { grid-template-columns: minmax(0, 1fr); }
        }
        /* Payment panels share a restrained gold finish and consistent spacing. */
        #payment-section, .card.payment-history {
            box-sizing: border-box;
            padding: 18px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(255,255,255,.025), transparent 55%), var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 6px 24px rgba(0,0,0,.08);
        }
        #payment-section > h3, .payment-history .payment-history-heading h3 {
            margin: 0 0 6px;
            padding: 0;
            border: 0;
            font-size: 16px;
            font-weight: 600;
            letter-spacing: -.025em;
            line-height: 1.4;
        }
        .payment-intro { margin: 0 0 14px; font-size: 12px; line-height: 1.5; }
        .subscription-payment-grid { gap: 12px; align-items: stretch; }
        .payment-step { padding: 14px; border-radius: 12px; background: rgba(255,255,255,.015); }
        .payment-step h4 { margin-bottom: 10px; font-size: 13px; font-weight: 600; }
        .payment-step-number { width: 25px; height: 25px; border-radius: 8px; font-size: 11px; border: 1px solid rgba(212,175,55,.16); }
        .payment-step > p { margin: 0 0 10px; font-size: 12px; }
        .payment-qr { padding: 12px; background: rgba(0,0,0,.1); border: 1px solid var(--border-color); border-radius: 10px; }
        .payment-qr img { max-width: 160px; border-radius: 8px; }
        .payment-step .form-group { margin-bottom: 12px; min-width: 0; }
        .payment-step .form-group label { display: block; margin-bottom: 8px; font-size: 12px; font-weight: 500; }
        .payment-step .form-control { box-sizing: border-box; width: 100%; min-width: 0; font-size: 16px; background: rgba(0,0,0,.12); border: 1px solid var(--border-color); }
        .payment-step input[type=file] { padding: 8px; font-size: 12px; }
        .payment-step input::file-selector-button { padding: 9px 10px; background: rgba(212,175,55,.12); color: var(--text-main); }
        .subscription-payment-grid .btn { min-height: 44px; padding: 12px; background: linear-gradient(110deg, #e6ca72, #c8a640); color: #211b0b; font-size: 13px; font-weight: 600; border: 1px solid rgba(240,216,136,.3); }
        .payment-help { margin-top: 12px; line-height: 1.6; }
        .payment-history-heading { padding-bottom: 12px; margin-bottom: 6px; border-bottom: 1px solid var(--border-color); }
        .payment-history-table { margin-bottom: 0; border-collapse: collapse; }
        .payment-history-table.table th { background: transparent; font-size: 11px; font-weight: 500; color: var(--text-muted); padding: 16px 12px; }
        .payment-history-table.table td { padding: 10px; font-size: 12px; vertical-align: middle; }
        .payment-history-table .payment-amount { font-variant-numeric: tabular-nums; }
        .payment-history-table .status-badge { font-size: 10px; font-weight: 600; border-radius: 6px; letter-spacing: .02em; }
        .payment-history-table .proof-button { min-height: 44px; background: rgba(212,175,55,.07); color: var(--accent-gold); border: 1px solid rgba(212,175,55,.25); box-shadow: none; font-weight: 500; }
        .payment-history-table .proof-button:hover { background: rgba(212,175,55,.15); }
        @media (max-width: 1000px) {
            .subscription-payment-grid { grid-template-columns: minmax(0, 1fr); }
        }
        @media (max-width: 768px) {
            #payment-section, .card.payment-history { padding: 12px; border-radius: 12px; }
            #payment-section > h3, .payment-history .payment-history-heading h3 { font-size: 14px; }
            .payment-intro { font-size: 12px; margin-bottom: 12px; }
            .subscription-payment-grid { gap: 10px; }
            .payment-step { padding: 12px; }
            .payment-qr { padding: 10px; }
            .payment-history-heading { margin-bottom: 8px; padding-bottom: 8px; }
            .payment-history-table tr { padding: 10px; margin-bottom: 8px; border-radius: 10px; background: rgba(255,255,255,.015); }
            .payment-history-table.table td { grid-template-columns: 58px minmax(0, 1fr); gap: 8px; padding: 5px 0; font-size: 12px; }
            .payment-history-table td::before { font-size: 11px; font-weight: 400; }
            .payment-history-table.table .payment-amount { font-size: 19px; padding: 0 0 6px; gap: 2px; }
            .payment-history-table.table td[data-label="Proof"] { padding-top: 6px; }
            .payment-history-table .status-badge { padding: 3px 7px; font-size: 9px; }
            .payment-history-table .proof-button { padding: 6px 10px; font-size: 11px; border-radius: 7px; }
        }
        .payment-qr-preview { display: block; width: 100%; padding: 0; border: 0; background: transparent; cursor: zoom-in; border-radius: 8px; }
        .payment-qr-preview:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 5px; }
        #qrModal .modal-content { box-sizing: border-box; max-height: 90vh; overflow-y: auto; }
    </style>

<?php if ($is_store_closed_by_portal): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>ACCOUNT PERMANENTLY CLOSED</strong> - Your caterer account has been closed by the Portal Admin. 
                    You cannot make any payments or access most features. Only Messages is available.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div id="subscription-success-alert" class="alert alert-success" role="status">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $success; ?></div>
            </div>
        <?php endif; ?>



        <?php if ($is_expiring_soon): ?>
<dialog id="subscriptionExpiryNotice" aria-labelledby="subscriptionExpiryTitle">
    <header><h2 id="subscriptionExpiryTitle">Subscription expiring soon</h2><button type="button" aria-label="Close notice" onclick="document.getElementById('subscriptionExpiryNotice').close()">&times;</button></header>
    <div><strong><?php echo (int) $days_until_expiry; ?> days remaining</strong><p>Please renew before expiry to avoid service interruption.</p><button type="button" class="btn btn-primary" onclick="document.getElementById('subscriptionExpiryNotice').close()">View subscription</button></div>
</dialog>
<style>
#subscriptionExpiryNotice {width:min(440px,calc(100% - 24px));max-height:calc(100dvh - 24px);box-sizing:border-box;padding:0;border:1px solid #96a38e;border-radius:16px;background:#d4dbcd;color:#20372b;box-shadow:0 24px 64px #0c201940;}
#subscriptionExpiryNotice::backdrop {background:rgb(12 32 25 / 65%);backdrop-filter:blur(4px);}
#subscriptionExpiryNotice header {display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid #a8b69d;}
#subscriptionExpiryNotice h2 {flex:1;margin:0;font-size:17px;line-height:1.4;}
#subscriptionExpiryNotice header button {width:44px;height:44px;flex-shrink:0;border:0;background:transparent;color:#20372b;font-size:26px;cursor:pointer;}
#subscriptionExpiryNotice > div {padding:20px;}
#subscriptionExpiryNotice strong {font-size:22px;color:#765619;}
#subscriptionExpiryNotice p {font-size:14px;line-height:1.6;color:#425642;margin:12px 0 20px;}
#subscriptionExpiryNotice .btn {width:100%;min-height:44px;justify-content:center;background:#173d32;color:#f0f1e6;}
@media(max-width:600px){#subscriptionExpiryNotice h2 {font-size:16px;}#subscriptionExpiryNotice > div {padding:16px;}}
</style>
<script> (() => {const modal=document.getElementById('subscriptionExpiryNotice');modal.addEventListener('cancel',event=>event.preventDefault());modal.showModal();})(); </script>
        <?php endif; ?>

        <?php if ($can_make_payment && !$is_store_closed_by_portal): ?>
        <div id="payment-section" class="card">
            <h3>Make a Payment</h3><p>Amount to pay: <strong data-subscription-payment-price>₱<?php echo number_format($subscription_fee, 2); ?></strong></p>
            <div class="subscription-payment-grid">
                <div class="payment-step">
                    <h4><span class="payment-step-number">1</span> Scan &amp; pay</h4>
                    <div class="payment-qr">
                        <?php if ($portal_gcash): ?>
                            <button type="button" class="payment-qr-preview" aria-label="Enlarge payment QR code" aria-haspopup="dialog" onclick="viewQR(this.querySelector('img').src)">
                                <img src="../<?php echo htmlspecialchars($portal_gcash['setting_value']); ?>" alt="GCash payment QR code">
                            </button>
                        <?php else: ?>
                            <p>GCash QR not configured by admin.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="payment-step">
                    <h4><span class="payment-step-number">2</span> Submit your receipt</h4>
                    <form method="POST" action="" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="subscription-receipt">Payment Screenshot</label>
                            <input id="subscription-receipt" type="file" name="payment_screenshot" class="form-control" accept="image/*" required>
                        </div>
                        <button type="submit" name="submit_payment" class="btn btn-pay-now" style="width: 100%;">
                            Submit Payment
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card payment-history">
            <div class="payment-history-heading">
                <div><h3>Payment History</h3></div>
                <?php if ($subscription_status === 'active' && !empty($subscription['subscription_expiry'])): ?>
                    <span class="history-valid-date" aria-label="Valid until <?php echo date('M d, Y', strtotime($subscription['subscription_expiry'])); ?>">Valid until <?php echo date('M d, Y', strtotime($subscription['subscription_expiry'])); ?></span>
                <?php endif; ?>
            </div>
            <?php if (count($payment_history) > 0): ?>
                <div class="subscription-timeline">
                <?php foreach ($payment_history as $payment): ?>
                    <article class="subscription-timeline-entry status-<?php echo htmlspecialchars($payment['payment_status']); ?>">
                        <span class="subscription-timeline-dot" aria-hidden="true"></span>
                        <div class="subscription-timeline-info">
                            <strong>Subscription payment</strong>
                            <time datetime="<?php echo htmlspecialchars($payment['created_at']); ?>"><?php echo date('M d, Y h:i A', strtotime($payment['created_at'])); ?></time>
                            <span class="subscription-payment-state"><?php echo $payment['payment_status'] === 'paid' ? 'Approved' : ($payment['payment_status'] === 'rejected' ? 'Rejected' : 'Pending review'); ?></span>
                            <?php if ($payment['payment_status'] === 'rejected' && !empty($payment['reject_reason'])): ?>
                                <p class="subscription-reject-reason"><?php echo htmlspecialchars($payment['reject_reason']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($payment['payment_screenshot'])): ?>
                                <button type="button" class="subscription-proof-link" onclick="viewProof('<?php echo '../' . htmlspecialchars($payment['payment_screenshot'], ENT_QUOTES); ?>')">View Proof</button>
                            <?php else: ?><span>No proof</span><?php endif; ?>
                        </div>
                        <strong class="subscription-timeline-amount">&#8369;<?php echo number_format($payment['amount'], 2); ?></strong>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-muted); padding: 20px;">No payment history found.</p>
            <?php endif; ?>
        </div>

    <!-- Payment Proof Modal -->
    <div id="paymentProofModal" class="modal">
        <div class="modal-content">
            <button type="button" class="modal-close" aria-label="Close payment proof" onclick="closeModal()">&times;</button>
            <h3 style="margin-top: 0;">Payment Proof</h3>
            <div style="text-align: center; margin-top: 20px;">
                <img id="modalImage" src="" alt="Payment Proof" style="max-width: 100%; max-height: 500px; border-radius: 8px;">
            </div>
        </div>
    </div>

    <!-- QR Code Enlarge Modal -->
    <div id="qrModal" class="modal" role="dialog" aria-modal="true" aria-label="Payment QR code" style="z-index: 1100;">
        <div class="modal-content" style="max-width: 380px; text-align: center;">
            <button type="button" class="modal-close" aria-label="Close QR preview" onclick="closeQR()">&times;</button>
            <h3 style="margin-top: 0;"><i class="fas fa-qrcode"></i> GCash QR Code</h3>
            <img id="qrModalImage" src="" alt="GCash QR Code" style="max-width: 100%; border-radius: 8px; margin-top: 10px;">
            <p style="color: var(--text-muted); font-size: 13px; margin-top: 12px; margin-bottom: 20px;">
                Scan using your GCash app to pay your subscription.
            </p>
        </div>
    </div>

    <script>
        const successAlert = document.getElementById('subscription-success-alert');
        if (successAlert) {
            window.setTimeout(() => successAlert.remove(), 3000);
        }


        function viewProof(imageUrl) {
            document.getElementById('modalImage').src = imageUrl;
            document.getElementById('paymentProofModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('paymentProofModal').style.display = 'none';
        }

        function viewQR(imageUrl) {
            window.qrTrigger = document.activeElement;
            document.getElementById('qrModalImage').src = imageUrl;
            document.getElementById('qrModal').style.display = 'flex';
            document.querySelector('#qrModal .modal-close').focus();
        }

        function closeQR() {
            document.getElementById('qrModal').style.display = 'none';
            if (window.qrTrigger) window.qrTrigger.focus();
        }


    </script>

<script>
(() => {
    let checking = false;
    async function refreshSubscriptionPrice() {
        if (checking || document.hidden) return;
        checking = true;
        try {
            const response = await fetch('subscription.php?action=subscription_price', {cache: 'no-store'});
            if (!response.ok) return;
            const data = await response.json();
            const amount = Number(data.amount);
            if (!Number.isFinite(amount) || amount <= 0) return;
            const price = '₱' + amount.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.querySelectorAll('[data-subscription-price]').forEach(el => {
                el.replaceChildren(document.createTextNode(price));
                const period = document.createElement('span');
                period.textContent = '/ month';
                el.append(period);
            });
            document.querySelectorAll('[data-subscription-payment-price]').forEach(el => el.textContent = price);
            if (typeof data.qr_path === 'string' && data.qr_path.startsWith('uploads/qr_codes/')) {
                const source = '../' + data.qr_path;
                const container = document.querySelector('.payment-qr');
                if (container) {
                    let preview = container.querySelector('.payment-qr-preview');
                    if (!preview) {
                        preview = document.createElement('button');
                        preview.type = 'button';
                        preview.className = 'payment-qr-preview';
                        preview.setAttribute('aria-label', 'Enlarge payment QR code');
                        preview.setAttribute('aria-haspopup', 'dialog');
                        const image = document.createElement('img');
                        image.alt = 'GCash payment QR code';
                        preview.append(image);
                        preview.addEventListener('click', () => viewQR(image.src));
                        container.replaceChildren(preview);
                    }
                    const image = preview.querySelector('img');
                    if (image.getAttribute('src') !== source) image.src = source;
                }
                const modalImage = document.getElementById('qrModalImage');
                if (modalImage && modalImage.getAttribute('src') !== source) modalImage.src = source;
            }
        } catch (error) { /* Retry on the next refresh. */ }
        finally { checking = false; }
    }
    setInterval(refreshSubscriptionPrice, 3000);
    window.addEventListener('focus', refreshSubscriptionPrice);
    document.addEventListener('visibilitychange', refreshSubscriptionPrice);
})();
</script>
<style>
@media screen {
    body.bounded-caterer-page .caterer-workspace .status-summary {
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:0;
        padding:14px;
        border:1px solid #96a38e;
        border-radius:12px;
        background:#d4dbcd;
    }
    body.bounded-caterer-page .caterer-workspace .status-summary .summary-card {
        background:transparent !important;
        border:0 !important;
        border-radius:0;
        box-shadow:none;
        margin:0;
        padding:0 14px 0 0;
    }
    body.bounded-caterer-page .caterer-workspace .status-summary .membership-renewal {
        border-left:1px solid #a8b69d !important;
        padding:0 0 0 14px;
    }
}
@media screen and (max-width:600px) {
    body.bounded-caterer-page .caterer-workspace .status-summary {grid-template-columns:minmax(0,1fr);padding:12px;}
    body.bounded-caterer-page .caterer-workspace .status-summary .summary-card {padding:0 0 12px;}
    body.bounded-caterer-page .caterer-workspace .status-summary .membership-renewal {border-left:0 !important;border-top:1px solid #a8b69d !important;padding:12px 0 0;}
}
</style>
<style>@media screen {body.bounded-caterer-page .caterer-workspace .status-summary {grid-template-columns:minmax(0,1fr);} body.bounded-caterer-page .caterer-workspace .status-summary .membership-status {padding:0;}}</style>
<style>
.subscription-timeline {max-height:400px;overflow-y:auto;overflow-x:hidden;padding:4px 8px 4px 4px;scrollbar-width:thin;scrollbar-color:#84977e #d4dbcd;}
.subscription-timeline-entry {position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:0 0 24px 30px;min-width:0;}
.subscription-timeline-entry::before {content:'';position:absolute;left:9px;top:12px;bottom:0;width:2px;background:#96a38e;}
.subscription-timeline-entry:last-child {padding-bottom:6px;}
.subscription-timeline-entry:last-child::before {display:none;}
.subscription-timeline-dot {position:absolute;left:0;top:1px;width:20px;height:20px;border-radius:50%;background:#765619;}
.subscription-timeline-entry.status-paid .subscription-timeline-dot {background:#246044;}
.subscription-timeline-entry.status-rejected .subscription-timeline-dot {background:#963e35;}
.subscription-timeline-info {display:flex;flex-direction:column;align-items:flex-start;gap:4px;min-width:0;font-size:13px;overflow-wrap:anywhere;color:#20372b;}
.subscription-timeline-info time {font-size:11px;color:#425642;}
.subscription-payment-state {font-size:12px;color:#425642;}
.subscription-timeline-amount {font-size:15px;color:#173d32;white-space:nowrap;font-variant-numeric:tabular-nums;}
.subscription-proof-link {padding:4px 0;min-height:36px;border:0;background:transparent;color:#173d32;font:inherit;font-weight:600;cursor:pointer;}
.subscription-reject-reason {margin:0;color:#963e35;font-size:12px;}
@media(max-width:600px){.subscription-timeline-entry {grid-template-columns:minmax(0,1fr);gap:6px;padding-left:28px;}.subscription-timeline-amount {grid-column:1;font-size:16px;}.subscription-proof-link {min-height:44px;}}
</style>
<style>
.payment-history .payment-history-heading {display:flex;align-items:center;justify-content:space-between;gap:12px;}
.payment-history .history-valid-date {margin-left:auto;flex-shrink:0;color:#425642;font-size:13px;line-height:1.4;}
@media(max-width:600px){.payment-history .history-valid-date {font-size:12px;}.payment-history .payment-history-heading h3 {font-size:15px;}}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
