<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

function getSafeLoginRedirect($role) {
    $redirect = trim((string) ($_GET['redirect'] ?? $_POST['redirect'] ?? ''));
    if ($redirect === '') {
        return '';
    }

    $decoded_redirect = ltrim(urldecode($redirect), '/\\');
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $decoded_redirect) || strpos($decoded_redirect, '..') !== false) {
        return '';
    }

    if ($role === 'customer' && strpos($decoded_redirect, 'customer/') === 0) {
        return $decoded_redirect;
    }

    return '';
}

// Function to add missing columns to users table
function addMissingColumns($conn) {
    $columns_to_add = [
        'login_attempts' => "ALTER TABLE users ADD COLUMN IF NOT EXISTS login_attempts INT DEFAULT 0",
        'locked_until' => "ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME DEFAULT NULL",
        'last_login_attempt' => "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_attempt DATETIME DEFAULT NULL",
        'last_login' => "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login DATETIME DEFAULT NULL"
    ];
    
    foreach ($columns_to_add as $column => $sql) {
        if (!columnExists($conn, 'users', $column)) {
            @mysqli_query($conn, $sql);
        }
    }
}

// Add missing columns silently
addMissingColumns($conn);

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    // Check if user account is closed by admin
    $user_id = $_SESSION['user_id'];
    $check_status_query = "SELECT status FROM users WHERE id = '$user_id'";
    $check_status_result = mysqli_query($conn, $check_status_query);
    $user_status = mysqli_fetch_assoc($check_status_result);
    
    // If account is closed by admin, redirect to subscription page
    if ($user_status && $user_status['status'] === 'closed') {
        $_SESSION['error'] = 'Your account has been closed by the Portal Admin.';
        
        // Redirect based on role
        switch ($_SESSION['role']) {
            case 'portal':
                header('Location: portal123/dashboard.php');
                exit();
            case 'store':
                header('Location: caterer/subscription.php');
                exit();
            case 'customer':
                $safe_redirect = getSafeLoginRedirect('customer');
                if ($safe_redirect !== '') {
                    header('Location: ' . $safe_redirect);
                    exit();
                }
                header('Location: customer/dashboard.php');
                exit();
        }
    } else {
        // Normal redirect for active accounts
        switch ($_SESSION['role']) {
            case 'portal':
                header('Location: portal123/dashboard.php');
                exit();
            case 'store':
                header('Location: caterer/dashboard.php');
                exit();
            case 'customer':
                header('Location: customer/dashboard.php');
                exit();
        }
    }
}

$error = '';
$success = '';

// Display session messages if any
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_identifier_input = isset($_POST['username']) ? trim($_POST['username']) : '';
    $login_identifier = mysqli_real_escape_string($conn, $login_identifier_input);
    $password = $_POST['password'];
    
    // First, check for auto-unlock conditions before login attempt
    $precheck_query = "SELECT * FROM users WHERE username = '$login_identifier' OR email = '$login_identifier'";
    $precheck_result = mysqli_query($conn, $precheck_query);
    
    if ($precheck_result && mysqli_num_rows($precheck_result) > 0) {
        $pre_user = mysqli_fetch_assoc($precheck_result);
        
        // Safely get lock-related fields with fallback
        $user_status = isset($pre_user['status']) ? $pre_user['status'] : 'active';
        $locked_until = isset($pre_user['locked_until']) ? $pre_user['locked_until'] : null;
        
        // Check if account is temporarily locked (not closed by admin)
        if ($user_status === 'locked') {
            $current_time = date('Y-m-d H:i:s');
            
            // Auto-unlock if lock time has expired or if locked_until is null
            if ($locked_until && $current_time > $locked_until) {
                // Build update query with only existing columns
                $unlock_fields = [];
                $unlock_fields[] = "status = 'active'";
                
                if (columnExists($conn, 'users', 'login_attempts')) {
                    $unlock_fields[] = "login_attempts = 0";
                }
                
                if (columnExists($conn, 'users', 'locked_until')) {
                    $unlock_fields[] = "locked_until = NULL";
                }
                
                if (!empty($unlock_fields)) {
                    $unlock_query = "UPDATE users SET " . implode(', ', $unlock_fields) . 
                                   " WHERE id = '{$pre_user['id']}'";
                    
                    if (mysqli_query($conn, $unlock_query)) {
                        $success = 'Your account has been automatically unlocked. You may now login.';
                        
                        // Also clear any existing lock badge by ensuring locked_until is NULL
                        if (columnExists($conn, 'users', 'locked_until')) {
                            mysqli_query($conn, "UPDATE users SET locked_until = NULL WHERE id = '{$pre_user['id']}'");
                        }
                    }
                } else {
                    // Just update status if other columns don't exist
                    mysqli_query($conn, "UPDATE users SET status = 'active' WHERE id = '{$pre_user['id']}'");
                    $success = 'Your account has been automatically unlocked. You may now login.';
                }
            }
        }
    }
    
    // Now proceed with normal login
    $query = "SELECT * FROM users WHERE username = '$login_identifier' OR email = '$login_identifier'";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        
        if (password_verify($password, $user['password'])) {
            // Safely get user status
            $status = isset($user['status']) ? $user['status'] : 'active';
            $locked_until = isset($user['locked_until']) ? $user['locked_until'] : null;
            
            // Handle accounts closed by admin - PERMANENT LOCK
            if ($status === 'closed') {
                $error = 'Your account has been permanently closed by the Portal Administrator. Please contact support.';
                // Set session to indicate closed account
                $_SESSION['account_closed'] = true;
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                
                // Redirect store users to subscription page where they can see the permanent lock
                if (in_array($user['role'], ['store', 'caterer'])) {
                    header('Location: caterer/subscription.php');
                    exit();
                }
            }
            // Handle temporarily locked accounts
            elseif ($status === 'locked') {
                $current_time = date('Y-m-d H:i:s');
                
                // Check if account should still be locked
                if ($locked_until && $current_time > $locked_until) {
                    // Auto-unlock the account
                    $unlock_fields = [];
                    $unlock_fields[] = "status = 'active'";
                    
                    if (columnExists($conn, 'users', 'login_attempts')) {
                        $unlock_fields[] = "login_attempts = 0";
                    }
                    
                    if (columnExists($conn, 'users', 'locked_until')) {
                        $unlock_fields[] = "locked_until = NULL";
                    }
                    
                    if (!empty($unlock_fields)) {
                        $unlock_query = "UPDATE users SET " . implode(', ', $unlock_fields) . 
                                       " WHERE id = '{$user['id']}'";
                        
                        if (mysqli_query($conn, $unlock_query)) {
                            // Update user array with new status
                            $user['status'] = 'active';
                            
                            // Also clear locked_until in the user array for consistency
                            $user['locked_until'] = null;
                            
                            // Continue to login checks
                            checkAndLogin($conn, $user);
                        } else {
                            $error = 'Failed to unlock account. Please contact administrator.';
                        }
                    } else {
                        // Just update status if other columns don't exist
                        mysqli_query($conn, "UPDATE users SET status = 'active' WHERE id = '{$user['id']}'");
                        $user['status'] = 'active';
                        checkAndLogin($conn, $user);
                    }
                } else {
                    // Account is still locked
                    $remaining_time = '';
                    if ($locked_until) {
                        $remaining = strtotime($locked_until) - time();
                        $minutes = ceil($remaining / 60);
                        $remaining_time = " Please try again in {$minutes} minutes.";
                    }
                    $error = 'Your account is locked.' . $remaining_time;
                }
            }
            // Handle inactive or pending accounts
            elseif ($status !== 'active') {
                // For store owners, check approval status
                if (in_array($user['role'], ['store', 'caterer'])) {
                    checkStoreApproval($conn, $user);
                } else {
                    // For non-store users
                    if ($status === 'pending') {
                        $error = 'Your account registration is pending approval.';
                    } else {
                        $error = 'Your account is not active. Please contact administrator.';
                    }
                }
            }
            // Active account
            else {
                // Check if locked_until is still set (for badge removal)
                if ($locked_until && columnExists($conn, 'users', 'locked_until')) {
                    // Clear locked_until if status is active
                    mysqli_query($conn, "UPDATE users SET locked_until = NULL WHERE id = '{$user['id']}'");
                    $user['locked_until'] = null;
                }
                
                checkAndLogin($conn, $user);
            }
        } else {
            // Wrong password - increment login attempts
            handleFailedLogin($conn, $user);
            $error = 'Invalid username/email or password';
        }
    } else {
        $error = 'Invalid username/email or password';
    }
}

// Function to handle failed login attempts
function handleFailedLogin($conn, $user) {
    $user_id = $user['id'];
    
    // Check if login_attempts column exists
    if (columnExists($conn, 'users', 'login_attempts')) {
        $current_attempts = isset($user['login_attempts']) ? $user['login_attempts'] + 1 : 1;
        
        // If attempts reach 3, lock the account for 15 minutes (temporary lock)
        if ($current_attempts >= 3) {
            $lock_time = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Build update query with available columns
            $update_fields = [];
            $update_fields[] = "login_attempts = '$current_attempts'";
            $update_fields[] = "status = 'locked'";
            
            if (columnExists($conn, 'users', 'locked_until')) {
                $update_fields[] = "locked_until = '$lock_time'";
            }
            
            if (columnExists($conn, 'users', 'last_login_attempt')) {
                $update_fields[] = "last_login_attempt = NOW()";
            }
            
            $update_query = "UPDATE users SET " . implode(', ', $update_fields) . 
                           " WHERE id = '$user_id'";
        } else {
            // Just increment attempts
            $update_fields = [];
            $update_fields[] = "login_attempts = '$current_attempts'";
            
            if (columnExists($conn, 'users', 'last_login_attempt')) {
                $update_fields[] = "last_login_attempt = NOW()";
            }
            
            $update_query = "UPDATE users SET " . implode(', ', $update_fields) . 
                           " WHERE id = '$user_id'";
        }
        
        mysqli_query($conn, $update_query);
    } else {
        // If login_attempts column doesn't exist, just update status to locked after 3 fails
        // We'll track attempts in session for this case
        if (!isset($_SESSION['failed_attempts'])) {
            $_SESSION['failed_attempts'] = 1;
        } else {
            $_SESSION['failed_attempts']++;
        }
        
        if ($_SESSION['failed_attempts'] >= 3) {
            if (columnExists($conn, 'users', 'status')) {
                mysqli_query($conn, "UPDATE users SET status = 'locked' WHERE id = '$user_id'");
            }
        }
    }
}

// Function to check store approval
function checkStoreApproval($conn, $user) {
    global $error;
    
    // Check if store_details table exists
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'store_details'");
    if ($table_check && mysqli_num_rows($table_check) > 0) {
        $store_query = "SELECT approval_status FROM store_details WHERE user_id = '{$user['id']}'";
        $store_result = mysqli_query($conn, $store_query);
        
        if ($store_result && mysqli_num_rows($store_result) > 0) {
            $store = mysqli_fetch_assoc($store_result);
            $approval_status = $store['approval_status'];
            
            // If store is approved but user status is not active
            if ($approval_status === 'approved' && $user['status'] !== 'active') {
                // Auto-update user status to active
                mysqli_query($conn, "UPDATE users SET status = 'active' WHERE id = '{$user['id']}'");
                $user['status'] = 'active';
                
                // Also clear locked_until if exists
                if (columnExists($conn, 'users', 'locked_until')) {
                    mysqli_query($conn, "UPDATE users SET locked_until = NULL WHERE id = '{$user['id']}'");
                    $user['locked_until'] = null;
                }
                
                // Proceed with login
                checkAndLogin($conn, $user);
            } 
            // If store is pending
            elseif ($approval_status === 'pending') {
                $error = 'Your store registration is pending approval. Please wait for admin approval.';
            }
            // If store is rejected
            elseif ($approval_status === 'rejected') {
                $error = 'Your store registration has been rejected. Please contact administrator.';
            } else {
                $error = 'Your account is not active. Please contact the administrator.';
            }
        } else {
            // No store details found
            $error = 'Store details not found. Please contact administrator.';
        }
    } else {
        // Store details table doesn't exist
        $error = 'Store system not properly configured. Please contact administrator.';
    }
}

// Function to check all conditions and login
function checkAndLogin($conn, $user) {
    // Reset login attempts on successful login if column exists
    $update_fields = [];
    
    if (columnExists($conn, 'users', 'login_attempts')) {
        $update_fields[] = "login_attempts = 0";
    }
    
    if (columnExists($conn, 'users', 'locked_until')) {
        $update_fields[] = "locked_until = NULL";
    }
    
    if (columnExists($conn, 'users', 'last_login')) {
        $update_fields[] = "last_login = NOW()";
    }
    
    // Also reset failed attempts in session
    if (isset($_SESSION['failed_attempts'])) {
        unset($_SESSION['failed_attempts']);
    }
    
    if (!empty($update_fields)) {
        $reset_query = "UPDATE users SET " . implode(', ', $update_fields) . 
                      " WHERE id = '{$user['id']}'";
        mysqli_query($conn, $reset_query);
    }
    
    // Additional check for store owners
    if (in_array($user['role'], ['store', 'caterer'])) {
        // Check if store_details table exists
        $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'store_details'");
        if ($table_check && mysqli_num_rows($table_check) > 0) {
            $store_query = "SELECT approval_status FROM store_details WHERE user_id = '{$user['id']}'";
            $store_result = mysqli_query($conn, $store_query);
            
            if ($store_result && mysqli_num_rows($store_result) > 0) {
                $store = mysqli_fetch_assoc($store_result);
                
                if ($store['approval_status'] !== 'approved') {
                    $_SESSION['error'] = 'Your store account is pending approval. Please wait for admin approval.';
                    header('Location: login.php');
                    exit();
                }
            } else {
                $_SESSION['error'] = 'Store details not found. Please contact administrator.';
                header('Location: login.php');
                exit();
            }
        }
    }
    
    // Set session variables
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['first_name'] = isset($user['first_name']) ? $user['first_name'] : '';
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    if (in_array($user['role'], ['store', 'caterer', 'customer'], true)) {
        $_SESSION['auth_popup'] = [
            'type' => 'welcome_back',
            'name' => trim((string) ($user['first_name'] ?? $user['username'] ?? '')),
            'role' => $user['role']
        ];
    }
    
    // Check if account is closed by admin
    $user_id = $user['id'];
    $check_status_query = "SELECT status FROM users WHERE id = '$user_id'";
    $check_status_result = mysqli_query($conn, $check_status_query);
    $user_status = mysqli_fetch_assoc($check_status_result);
    
    // If account is closed by admin, redirect store to subscription page
    if ($user_status && $user_status['status'] === 'closed' && in_array($user['role'], ['store', 'caterer'])) {
        header('Location: caterer/subscription.php');
        exit();
    }
    
    // Normal redirect based on role
    switch ($user['role']) {
        case 'portal':
            header('Location: portal123/dashboard.php');
            exit();
        case 'store':
            header('Location: caterer/dashboard.php');
            exit();
        case 'customer':
            $safe_redirect = getSafeLoginRedirect('customer');
            if ($safe_redirect !== '') {
                header('Location: ' . $safe_redirect);
                exit();
            }
            header('Location: customer/dashboard.php');
            exit();
        default:
            header('Location: index.php');
            exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="soft-sage-theme auth-sage-theme login-layout">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Login - Catering Services Portal</title>
    <link rel="icon" type="image/png" href="uploads/logo.png">
    <link rel="shortcut icon" href="uploads/logo.png">
    <link rel="apple-touch-icon" href="uploads/logo.png">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #0a0a0a;
            --secondary-bg: #141414;
            --card-bg: rgba(22, 22, 22, 0.95);
            --text-main: #ffffff;
            --text-muted: #a0a0a0;
            --accent-gold: #d4af37;
            --accent-gold-hover: #b4942b;
            --border-color: rgba(212, 175, 55, 0.2);
            --input-bg: rgba(255, 255, 255, 0.05);
            --success-color: #4caf50;
            --error-color: #f44336;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at center, rgba(30, 30, 30, 0.4) 0%, rgba(0, 0, 0, 0.8) 100%);
            z-index: -1;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { height: 100%; }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background: url('uploads/background/login.png') no-repeat center center fixed;
            background-size: cover;
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 18px;
            padding: 20px;
            position: relative;
        }

        .login-container {
            width: 100%;
            max-width: 480px;
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 40px 100px rgba(0, 0, 0, 0.6);
            overflow: hidden;
            animation: fadeIn 0.6s ease-out;
            border: 1px solid var(--border-color);
            position: relative;
            z-index: 1;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-header {
            padding: 40px 30px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .logo-wrap {
            position: relative;
            display: inline-block;
            margin-bottom: 20px;
            animation: logoEntrance 0.85s ease-out both;
            isolation: isolate;
        }

        .logo-wrap::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.18) 0%, rgba(212, 175, 55, 0.08) 45%, rgba(212, 175, 55, 0) 72%);
            opacity: 0.55;
            transform: scale(0.96);
            animation: logoHaloPulse 4.6s ease-in-out infinite;
            z-index: -1;
            pointer-events: none;
        }

        .logo-wrap::after {
            content: '';
            position: absolute;
            top: -10%;
            left: -45%;
            width: 42%;
            height: 120%;
            background: linear-gradient(115deg, transparent 0%, rgba(255, 255, 255, 0.12) 45%, rgba(255, 255, 255, 0.65) 50%, rgba(255, 255, 255, 0.12) 55%, transparent 100%);
            transform: skewX(-20deg);
            opacity: 0;
            pointer-events: none;
        }

        .logo-wrap:hover::after {
            animation: logoShine 1.1s ease;
        }

        .login-header img {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            border: 2px solid var(--accent-gold);
            padding: 4px;
            background: rgba(0,0,0,0.3);
            object-fit: cover;
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.3);
            animation: logoFloat 4.8s ease-in-out infinite, logoGlow 3.6s ease-in-out infinite;
            transform-origin: center;
            will-change: transform, box-shadow;
            transition: transform 0.28s ease, box-shadow 0.28s ease, filter 0.28s ease;
            display: block;
        }

        .logo-wrap:hover img {
            transform: translateY(-2px) scale(1.04);
            box-shadow:
                0 0 24px rgba(212, 175, 55, 0.48),
                0 0 38px rgba(212, 175, 55, 0.22);
            filter: brightness(1.05);
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }

        @keyframes logoEntrance {
            0% {
                opacity: 0;
                transform: translateY(10px) scale(0.94);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes logoGlow {
            0%, 100% {
                box-shadow:
                    0 0 14px rgba(212, 175, 55, 0.25),
                    0 0 24px rgba(212, 175, 55, 0.16);
            }
            50% {
                box-shadow:
                    0 0 20px rgba(212, 175, 55, 0.45),
                    0 0 34px rgba(212, 175, 55, 0.24);
            }
        }

        @keyframes logoHaloPulse {
            0%, 100% {
                opacity: 0.42;
                transform: scale(0.96);
            }
            50% {
                opacity: 0.7;
                transform: scale(1.02);
            }
        }

        @keyframes logoShine {
            0% {
                left: -45%;
                opacity: 0;
            }
            15% {
                opacity: 1;
            }
            100% {
                left: 120%;
                opacity: 0;
            }
        }
        
        .login-header h2 {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 10px;
            letter-spacing: -0.02em;
            background: linear-gradient(135deg, #fff 0%, var(--accent-gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .login-header p {
            font-size: 15px;
            color: var(--text-muted);
        }
        
        .form-content {
            padding: 40px;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: shake 0.4s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .alert-error {
            background: rgba(244, 67, 54, 0.1);
            border: 1px solid rgba(244, 67, 54, 0.2);
            color: #ffb4b4;
        }
        
        .alert-success {
            background: rgba(76, 175, 80, 0.1);
            border: 1px solid rgba(76, 175, 80, 0.2);
            color: #baffc9;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: var(--text-main);
            font-weight: 600;
            font-size: 14px;
            letter-spacing: 0.02em;
        }
        
        .form-group label i {
            color: var(--accent-gold);
            margin-right: 8px;
        }
        
        .form-group input {
            width: 100%;
            padding: 15px 18px;
            background: var(--input-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            font-size: 16px;
            color: var(--text-main);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
        }
        
        .form-group input:focus {
            border-color: var(--accent-gold);
            outline: none;
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.15);
            transform: translateY(-1px);
        }

        .password-field {
            position: relative;
        }

        .password-field input {
            padding-right: 54px;
        }

        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 16px;
            padding: 6px;
        }

        .password-toggle:hover {
            color: var(--accent-gold);
        }

        .btn {
            background: var(--accent-gold);
            color: #000;
            padding: 16px 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn:hover {
            background: var(--accent-gold-hover);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
        }

        .forgot-link {
            display: flex;
            justify-content: center;
            margin: 4px 0 22px;
        }

        .forgot-link a {
            color: var(--accent-gold);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .forgot-link a:hover {
            color: #fff;
            transform: translateY(-1px);
        }

        .form-links {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 14px;
        }
        
        .form-links a {
            color: var(--accent-gold);
            text-decoration: none;
            transition: all 0.2s;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .form-links a:hover {
            color: #fff;
            transform: translateY(-1px);
        }

        @media (max-width: 768px) {
            body { padding: 15px; }
            .login-container { max-width: 100%; }
            .login-header { padding: 32px 22px; }
            .form-content { padding: 28px 22px; }
            
            .form-links {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
        }

        /* Keep the card in document flow so short screens can scroll naturally. */
        html { min-height: 100%; height: auto; background: #11130f; }
        body {
            height: auto; min-height: 100vh; min-height: 100svh;
            padding: 32px 20px; justify-content: flex-start;
            background: linear-gradient(125deg, #0b100ce8, #12130eb8), url('uploads/background/login.png') center / cover;
            isolation: isolate;
        }
        body::before { display: none; }
        .login-container {
            flex: 0 0 auto; margin-block: auto; max-width: 440px;
            background: linear-gradient(150deg, #26271ff5, #151713fa);
            border: 1px solid #e1bd5547; border-radius: 26px;
            box-shadow: 0 32px 90px #0008, inset 0 1px 0 #ffffff0d;
        }
        .login-container::before { content: ''; display: block; height: 3px; background: linear-gradient(90deg, transparent, #e1bd55, transparent); }
        .login-header { padding: 28px 30px 22px; border-bottom: 0; }
        .logo-wrap { padding: 7px; border: 1px solid #e1bd5540; border-radius: 50%; background: #e1bd5508; margin-bottom: 16px; }
        .login-header img { width: 64px; height: 64px; animation: none; }
        .logo-wrap, .logo-wrap::before { animation: none; }
        .eyebrow { color: #e1bd55; font-size: 10px; font-weight: 700; letter-spacing: .18em; margin-bottom: 10px; }
        .login-header h2 { font-size: clamp(27px, 6vw, 32px); letter-spacing: -.04em; margin-bottom: 0; }
        .login-header p { color: #b5b8ac; font-size: 13px; line-height: 1.6; }
        .form-content { padding: 24px 30px; }
        #loginForm { display: flex; flex-direction: column; }
        #loginForm > .form-group:last-child { margin-bottom: 0; }
        .form-group { margin-bottom: 18px; }
        .form-group label { font-size: 13px; margin-bottom: 9px; }
        .form-group input { min-height: 52px; min-width: 0; font-size: 16px; padding: 14px 15px; background: #ffffff06; border-color: #ffffff26; }
        .form-group input::placeholder { color: #999c90; font-size: 14px; }
        .password-field input { padding-right: 54px; }
        .password-toggle { width: 44px; height: 44px; right: 4px; }
        .forgot-link { justify-content: flex-end; margin: -10px 0 16px; }
        .forgot-link a { min-height: 44px; font-size: 12px; }
        .btn { min-height: 52px; background: linear-gradient(115deg, #efd47f, #d6ae3b); border: 1px solid #f2d476; color: #211c0c; box-shadow: 0 6px 22px #d6ae3b16; }
        .btn:disabled { opacity: .7; cursor: wait; }
        .form-links { flex-direction: row; align-items: center; justify-content: center; gap: 16px; margin-top: 20px; padding-top: 10px; }
        .form-links a { min-height: 36px; justify-content: center; font-size: 12px; gap: 6px; white-space: nowrap; }
        .form-links a:last-child { color: #b5b8ac; font-size: 12px; font-weight: 400; }
        a:focus-visible, button:focus-visible { outline: 2px solid #e1bd55; outline-offset: 3px; }
        .alert { overflow-wrap: anywhere; line-height: 1.6; }
        @media (max-width: 480px) {
            body { padding: 20px 16px; padding-top: max(20px, env(safe-area-inset-top)); padding-bottom: max(20px, env(safe-area-inset-bottom)); }
            .login-container { border-radius: 22px; }
            .login-header { padding: 24px 24px 18px; }
            .form-content { padding: 22px 24px; }
            .form-links { gap: 12px; }
            .form-links a, .form-links a:last-child { font-size: 11px; gap: 4px; }
            .login-header img { width: 56px; height: 56px; }
        }
        @media (max-height: 700px) {
            body { padding-top: max(12px, env(safe-area-inset-top)); padding-bottom: max(12px, env(safe-area-inset-bottom)); }
            .login-header { padding-top: 18px; padding-bottom: 15px; }
            .logo-wrap { margin-bottom: 10px; }
            .login-header img { width: 46px; height: 46px; }
            .form-group { margin-bottom: 14px; }
            .form-links { margin-top: 18px; padding-top: 10px; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; } }
    </style>
    <link rel="stylesheet" href="assets/css/soft-sage-theme.css?v=20261019-8">
    <link rel="stylesheet" href="assets/css/auth-sage-theme.css?v=20261021-refined-fields">
    <link rel="stylesheet" href="assets/css/public-footer.css?v=20261021-19">
</head>
<body class="login-page">
    <div class="login-stage">
    <main class="login-container">
        <div class="login-header">
            <div class="logo-wrap">
                <img src="uploads/logo.png" alt="Logo">
            </div>
            <div class="eyebrow">CATERING SERVICES PORTAL</div>
            <h2>Welcome back.</h2>
        </div>
        
        <div class="form-content">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label for="username"><i class="fas fa-user-circle"></i> Username or Email</label>
                    <input type="text" id="username" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required 
                           placeholder="Enter your username or email"
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Password</label>
                    <div class="password-field">
                        <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                        <button type="button" id="togglePassword" class="password-toggle" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="forgot-link">
                    <a href="forgot_password.php">
                        <i class="fas fa-key"></i> Forgot Password?
                    </a>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn" id="loginBtn">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </button>
                </div>
            </form>
            
            <div class="form-links">
                <a href="register.php"><i class="fas fa-user-plus"></i> Create Account</a>
                <a href="index.php"><i class="fas fa-home"></i> Back to Homepage</a>
            </div>
        </div>
    </main>
    </div>

    <?php include __DIR__ . '/includes/public-footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-focus on username field
            if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
                document.getElementById('username').focus({ preventScroll: true });
            }
            
            // Password visibility toggle
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.setAttribute('aria-label', type === 'password' ? 'Show password' : 'Hide password');
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });
            
            // Form validation
            const form = document.getElementById('loginForm');
            const loginBtn = document.getElementById('loginBtn');
            
            form.addEventListener('submit', function(e) {
                const username = document.getElementById('username').value.trim();
                const password = document.getElementById('password').value;
                
                if (!username) {
                    e.preventDefault();
                    showError('Please enter your username or email!');
                    document.getElementById('username').focus();
                    return false;
                }
                
                if (!password) {
                    e.preventDefault();
                    showError('Please enter your password!');
                    document.getElementById('password').focus();
                    return false;
                }
                
                if (password.length < 6) {
                    e.preventDefault();
                    showError('Password must be at least 6 characters long!');
                    document.getElementById('password').focus();
                    return false;
                }
                
                // Disable button and show loading state
                loginBtn.disabled = true;
                loginBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...';
                
                return true;
            });
            
            function showError(message) {
                // Remove any existing error alerts
                const existingAlert = document.querySelector('.alert-error');
                if (existingAlert) {
                    existingAlert.remove();
                }
                
                // Create and insert new error alert
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-error';
                alertDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
                
                const formContent = document.querySelector('.form-content');
                const formElement = document.querySelector('form');
                formContent.insertBefore(alertDiv, formElement);
                
                // Scroll to alert
                alertDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            
            // Check if there are session messages to display
            if (document.querySelector('.alert-error') || document.querySelector('.alert-success')) {
                setTimeout(() => {
                    const alerts = document.querySelectorAll('.alert');
                    alerts.forEach(alert => {
                        alert.style.animation = 'slideIn 0.5s ease-out';
                    });
                }, 100);
            }
            
        });
        
        // Auto-hide success message after 5 seconds
        setTimeout(() => {
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                successAlert.style.transition = 'opacity 0.5s';
                successAlert.style.opacity = '0';
                setTimeout(() => {
                    if (successAlert.parentNode) {
                        successAlert.parentNode.removeChild(successAlert);
                    }
                }, 500);
            }
        }, 5000);
    </script>
</body>
</html>
