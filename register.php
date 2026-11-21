<?php
require_once 'config.php';
require_once 'includes/philippine_address.php';
redirectBasedOnRole();

$error = '';
$success = '';
$suffixOptions = ['', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];
$registrationMonthlyFee = number_format(getSubscriptionFee($conn), 2);

// Fetch system settings
$settings_query = "SELECT * FROM system_settings";
$settings_result = mysqli_query($conn, $settings_query);
$settings = [];
if ($settings_result) {
    while ($row = mysqli_fetch_assoc($settings_result)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

function getSafeRegisterRedirect($role) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $addressResult = buildRegistrationAddress($_POST);
    $_POST['address'] = $addressResult['address'] ?? '';
    // Validate and sanitize inputs
    $role = isset($_POST['role']) ? mysqli_real_escape_string($conn, $_POST['role']) : '';
    $first_name = is_string($_POST['first_name'] ?? null) ? mysqli_real_escape_string($conn, trim($_POST['first_name'])) : '';
    $last_name = is_string($_POST['last_name'] ?? null) ? mysqli_real_escape_string($conn, trim($_POST['last_name'])) : '';
    $middle_name = is_string($_POST['middle_name'] ?? null) ? mysqli_real_escape_string($conn, trim($_POST['middle_name'])) : '';
    $suffix = isset($_POST['suffix']) ? mysqli_real_escape_string($conn, $_POST['suffix']) : '';
    $phone_number = isset($_POST['phone_number']) ? mysqli_real_escape_string($conn, $_POST['phone_number']) : '';
    $address = isset($_POST['address']) ? mysqli_real_escape_string($conn, $_POST['address']) : '';
    $username_input = isset($_POST['username']) ? trim($_POST['username']) : '';
    $email_input = isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '';
    $username = mysqli_real_escape_string($conn, $username_input);
    $email = mysqli_real_escape_string($conn, $email_input);
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    $identityError = '';
    foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'middle_name' => 'Middle name', 'username' => 'Username'] as $field => $label) {
        $value = $_POST[$field] ?? '';
        $pattern = $field === 'username' ? '/\A\p{L}{1,250}\z/u' : '/\A(?=[^\p{L}]*\p{L})[\p{L}\p{M} .’\x27\x2D]{1,100}\z/u';
        if (!is_string($value) || ($value !== '' && (!preg_match($pattern, $value) || trim($value) === ''))) {
            $identityError = $field === 'username' ? 'Username must contain letters only, maximum 250 characters.' : $label . ' must contain at least one letter and no more than 100 characters. Spaces, periods, apostrophes and hyphens are allowed.';
            break;
        }
    }

    // Validate required fields
    if ($identityError !== '') {
        $error = $identityError;
    } elseif (isset($addressResult['error'])) {
        $error = $addressResult['error'];
    } elseif (empty($role) || empty($first_name) || empty($last_name) || empty($phone_number) || empty($address) || empty($username) || empty($email) || empty($_POST['password'])) {
        $error = 'All required fields must be filled!';
    } elseif (!preg_match('/^[0-9]{11}$/', $phone_number)) {
        $error = 'Phone number must contain exactly 11 digits, with no letters or symbols.';
    } elseif (!in_array($suffix, $suffixOptions, true)) {
        $error = 'Please select a valid suffix.';
    } elseif (filter_var($username_input, FILTER_VALIDATE_EMAIL)) {
        $error = 'Username cannot be an email address. Please choose a separate username.';
    } elseif (!preg_match('/\A\p{L}{1,250}\z/u', $username_input)) {
        $error = 'Username must contain letters only, maximum 250 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($_POST['password'] !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($_POST['password']) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Za-z]/', $_POST['password'])) {
        $error = 'Password must contain at least one letter.';
    } elseif (!preg_match('/[0-9]/', $_POST['password'])) {
        $error = 'Password must contain at least one number.';
    } else {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        // Prevent identifier collisions because login accepts username or email
        $check_query = "SELECT id FROM users WHERE username = '$username' OR email = '$username'";
        $check_result = mysqli_query($conn, $check_query);
        
        if (mysqli_num_rows($check_result) > 0) {
            $error = 'Username already exists or conflicts with an existing email address.';
        } else {
            $check_email_query = "SELECT id FROM users WHERE email = '$email' OR username = '$email'";
            $check_email_result = mysqli_query($conn, $check_email_query);

            if ($check_email_result && mysqli_num_rows($check_email_result) > 0) {
                $error = 'Email address already exists or conflicts with an existing username.';
            }
        }

        if (empty($error)) {
            // Check role-specific agreements
            if ($role === 'caterer') {
                if (!isset($_POST['subscription_agreement']) || $_POST['subscription_agreement'] !== '1') {
                    $error = 'You must accept the subscription agreement to register as a caterer owner';
                }
                // Check if business name is provided for caterer
                if (empty($_POST['business_name'])) {
                    $error = 'Business name is required for caterer registration';
                }
            } else if ($role === 'customer') {
                if (!isset($_POST['customer_agreement']) || $_POST['customer_agreement'] !== '1') {
                    $error = 'You must accept the customer agreement to register as a customer';
                }
            } else {
                $error = 'Invalid account type selected';
            }
            
            // If no error yet, proceed with registration
            if (empty($error)) {
                // Initialize profile image variable
                $profile_image = null;
                
                // Function to handle permanent file upload with validation
                function uploadPermanentFile($file_input, $upload_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'], $max_size = 5242880) {
                    if (!isset($_FILES[$file_input]) || $_FILES[$file_input]['error'] !== UPLOAD_ERR_OK) {
                        return ['success' => false, 'error' => 'No file uploaded or upload error'];
                    }
                    
                    $file = $_FILES[$file_input];
                    
                    // Check file size
                    if ($file['size'] > $max_size) {
                        return ['success' => false, 'error' => 'File size exceeds maximum limit (5MB)'];
                    }
                    
                    // Get file extension
                    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    
                    // Validate file type when an allowlist is provided
                    if (is_array($allowed_types) && !in_array($file_ext, $allowed_types)) {
                        return ['success' => false, 'error' => 'Invalid file type. Allowed: JPG, JPEG, PNG, GIF, WEBP'];
                    }
                    
                    // Generate unique permanent filename
                    $timestamp = time();
                    $random_string = bin2hex(random_bytes(8));
                    $clean_name = preg_replace('/[^A-Za-z0-9]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
                    $clean_name = substr($clean_name, 0, 50);
                    
                    // Create filename format
                    $file_name = $timestamp . '_' . $random_string . '_' . $clean_name . '.' . $file_ext;
                    
                    // Ensure upload directory exists
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    // Set full target path
                    $target_file = $upload_dir . '/' . $file_name;
                    
                    // Validate image dimensions
                    $image_info = getimagesize($file['tmp_name']);
                    if (!$image_info) {
                        return ['success' => false, 'error' => 'File is not a valid image'];
                    }
                    
                    // Move uploaded file to permanent location
                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        // Set proper permissions
                        chmod($target_file, 0644);
                        
                        // Caterer relative path for database
                        $relative_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $target_file);
                        if (strpos($relative_path, '/') !== 0) {
                            $relative_path = '/' . $relative_path;
                        }
                        
                        return [
                            'success' => true,
                            'path' => $relative_path,
                            'full_path' => $target_file,
                            'filename' => $file_name,
                            'timestamp' => $timestamp
                        ];
                    } else {
                        return ['success' => false, 'error' => 'Failed to move uploaded file'];
                    }
                }
                
                // Handle profile picture upload
                if ($role === 'caterer') {
                    // Caterer: Upload from profile_store input (required)
                    if (isset($_FILES['profile_store']) && $_FILES['profile_store']['error'] === UPLOAD_ERR_OK) {
                        $upload_result = uploadPermanentFile('profile_store', PROFILE_DIR);
                        if ($upload_result['success']) {
                            $profile_image = $upload_result['path'];
                            $profile_image = ltrim($profile_image, '/');
                        } else {
                            $error = 'Caterer profile picture upload failed: ' . $upload_result['error'];
                        }
                    } else {
                        $error = 'Caterer profile picture is required';
                    }
                } else if ($role === 'customer') {
                    // Customer: Upload from profile_customer input (optional)
                    if (isset($_FILES['profile_customer']) && $_FILES['profile_customer']['error'] === UPLOAD_ERR_OK) {
                        $upload_result = uploadPermanentFile('profile_customer', PROFILE_DIR);
                        if ($upload_result['success']) {
                            $profile_image = $upload_result['path'];
                            $profile_image = ltrim($profile_image, '/');
                        }
                        // No error if customer doesn't upload profile picture (it's optional)
                    }
                }
                
                // Only proceed if no upload errors (for caterer) or if customer (where profile is optional)
                if (empty($error) || ($role === 'customer' && strpos($error, 'profile picture') !== false)) {
                    // Clear the profile picture error for customers since it's optional
                    if ($role === 'customer') {
                        $error = '';
                    }
                    
                    // For caterer users, set status to 'pending' if approval is required
                    $status = ($role === 'caterer' && APPROVAL_REQUIRED) ? 'pending' : 'active';
                    
                    // Insert user with profile image
                    $db_role = ($role === 'caterer') ? 'store' : $role;
                    $query = "INSERT INTO users (username, email, password, first_name, last_name, middle_name, suffix, phone_number, address, role, status, profile_image) 
                              VALUES ('$username', '$email', '$password', '$first_name', '$last_name', '$middle_name', '$suffix', '$phone_number', '$address', '$db_role', '$status', " . 
                              ($profile_image ? "'$profile_image'" : "NULL") . ")";
                    
                    if (mysqli_query($conn, $query)) {
                        $user_id = mysqli_insert_id($conn);
                        
                        // If caterer, insert into store_details
                        if ($role === 'caterer') {
                            // Get business name and location from form
                            $business_name = mysqli_real_escape_string($conn, $_POST['business_name']);
                            $store_location = $address; // Use user's address as caterer location
                            
                            // Handle file uploads for caterer documents
                            $valid_id = null;
                            $business_permit = null;
                            $gcash_qr = null;
                            
                            // Upload Valid ID (required)
                            if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
                                $valid_id_result = uploadPermanentFile('valid_id', ID_DIR);
                                if ($valid_id_result['success']) {
                                    $valid_id = $valid_id_result['path'];
                                    $valid_id = ltrim($valid_id, '/');
                                } else {
                                    $error = 'Valid ID upload failed: ' . $valid_id_result['error'];
                                }
                            } else {
                                $error = 'Valid ID is required';
                            }
                            
                            // Upload Business Permit (required)
                            if (isset($_FILES['business_permit']) && $_FILES['business_permit']['error'] === UPLOAD_ERR_OK) {
                                $business_permit_result = uploadPermanentFile('business_permit', BUSINESS_DIR);
                                if ($business_permit_result['success']) {
                                    $business_permit = $business_permit_result['path'];
                                    $business_permit = ltrim($business_permit, '/');
                                } else {
                                    $error = 'Business permit upload failed: ' . $business_permit_result['error'];
                                }
                            } else {
                                $error = 'Business permit is required';
                            }
                            
                            // Upload GCash QR (required)
                            if (isset($_FILES['gcash_qr']) && $_FILES['gcash_qr']['error'] === UPLOAD_ERR_OK) {
                                $gcash_qr_result = uploadPermanentFile('gcash_qr', GCASH_DIR, null);
                                if ($gcash_qr_result['success']) {
                                    $gcash_qr = $gcash_qr_result['path'];
                                    $gcash_qr = ltrim($gcash_qr, '/');
                                } else {
                                    $error = 'GCash QR upload failed: ' . $gcash_qr_result['error'];
                                }
                            } else {
                                $error = 'GCash QR is required';
                            }
                            
                            // Validate that all required caterer documents are uploaded
                            if (empty($error) && $valid_id && $business_permit && $gcash_qr && $profile_image) {
                                // INSERT CATERER DETAILS
                                $store_query = "INSERT INTO store_details (user_id, business_name, valid_id_image, business_permit_image, gcash_qr_image, location, approval_status) 
                                               VALUES ('$user_id', '$business_name', '$valid_id', '$business_permit', '$gcash_qr', " . (!empty($store_location) ? "'$store_location'" : "NULL") . ", 'pending')";
                                if (mysqli_query($conn, $store_query)) {
                                    if (function_exists('notifyPortalAdmins')) {
                                        notifyPortalAdmins(
                                            'store_registration',
                                            'New Caterer Registration',
                                            $business_name . ' submitted a new caterer registration for approval.',
                                            'users.php'
                                        );
                                    }
                                    
                                    startSession();
                                    $_SESSION['success'] = 'Caterer registration submitted successfully! Your account is pending approval by the portal admin within ' . APPROVAL_TIME_HOURS . ' hours. A notification will be sent to your registered Gmail (' . htmlspecialchars($email_input) . ') once your account is reviewed.';
                                    $_SESSION['registration_draft_completed'] = true;
                                    header('Location: login.php');
                                    exit();
                                } else {
                                    appLogError('Caterer registration store_details insert failed', mysqli_error($conn));
                                    $error = 'Caterer registration could not be completed right now. Please try again.';
                                    // Delete the user if caterer details insertion failed
                                    mysqli_query($conn, "DELETE FROM users WHERE id = '$user_id'");
                                }
                            } else {
                                if (empty($error)) {
                                    $error = 'Please upload all required caterer documents and profile picture!';
                                }
                                // Delete the user if documents are missing
                                mysqli_query($conn, "DELETE FROM users WHERE id = '$user_id'");
                            }
                            
                        } else if ($role === 'customer') {
                            if (function_exists('notifyPortalAdmins')) {
                                notifyPortalAdmins(
                                    'customer_registration',
                                    'New Customer Registration',
                                    $first_name . ' ' . $last_name . ' created a new customer account.',
                                    'manage-users.php?type=customer&account_id=' . (int) $user_id
                                );
                            }
                            
                            // Update last_login
                            mysqli_query($conn, "UPDATE users SET last_login = NOW() WHERE id = '$user_id'");

                            // Automatic login for customer
                            startSession();
                            $_SESSION['user_id'] = $user_id;
                            $_SESSION['registration_draft_completed'] = true;
                            $_SESSION['username'] = $username_input;
                            $_SESSION['role'] = 'customer';
                            $_SESSION['first_name'] = $first_name;
                            $_SESSION['login_time'] = time();
                            $_SESSION['last_activity'] = time();
                            $_SESSION['auth_popup'] = [
                                'type' => 'welcome_new',
                                'name' => trim($first_name . ' ' . $last_name) ?: $username_input,
                                'role' => 'customer'
                            ];

                            $safe_redirect = getSafeRegisterRedirect('customer');
                            if ($safe_redirect !== '') {
                                header('Location: ' . $safe_redirect);
                                exit();
                            }

                            header('Location: customer/dashboard.php');
                            exit();
                        }
                    } else {
                        appLogError('Registration user insert failed', mysqli_error($conn));
                        $error = 'Registration failed. Please try again.';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="soft-sage-theme auth-sage-theme register-layout">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Register - Catering Services Portal</title>
    <link rel="icon" type="image/png" href="uploads/logo.png">
    <link rel="shortcut icon" href="uploads/logo.png">
    <link rel="apple-touch-icon" href="uploads/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
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
            gap: 20px;
            padding: 20px;
            position: relative;
        }

        .login-container {
            width: 100%;
            max-width: 750px;
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
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-header {
            padding: 40px 30px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            flex-shrink: 0;
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
            position: relative;
            overflow-y: auto;
            flex: 1;
            scrollbar-color: var(--border-color) rgba(255, 255, 255, 0.03);
        }
        
        /* Custom scrollbar */
        .form-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .form-content::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 4px;
        }
        
        .form-content::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }
        
        .form-content::-webkit-scrollbar-thumb:hover {
            background: var(--accent-gold);
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 14px;
            line-height: 1.5;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.5s ease-out;
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
        
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        
        .form-group {
            margin-bottom: 20px;
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
        
        .form-group input, 
        .form-group select, 
        .form-group textarea {
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
        
        .form-group input:focus, 
        .form-group select:focus, 
        .form-group textarea:focus {
            border-color: var(--accent-gold);
            outline: none;
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.15);
            transform: translateY(-1px);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
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
        
        .form-group small {
            display: block;
            margin-top: 6px;
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.5;
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
        
        .btn::before { display: none; } /* Remove old animation */
        
        .btn:hover {
            background: var(--accent-gold-hover);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-links a:hover {
            color: #fff;
            transform: translateY(-1px);
        }
        
        /* Caterer Fields & Sections */
        .caterer-fields {
            background: rgba(255, 255, 255, 0.03);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            margin-bottom: 25px;
        }
        
        .caterer-fields h4 {
            color: var(--accent-gold);
            margin-bottom: 20px;
            font-weight: 600;
            font-size: 16px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }
        
        .caterer-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        /* Agreements */
        .subscription-agreement {
            background: rgba(212, 175, 55, 0.05);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid rgba(212, 175, 55, 0.18);
            margin: 25px 0;
        }
        
        .subscription-agreement h4 {
            color: var(--accent-gold);
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .subscription-agreement ul, 
        .customer-agreement ul,
        .subscription-agreement ol,
        .customer-agreement ol,
        .agreement-content ul,
        .agreement-content ol {
            padding-left: 28px;
            margin: 10px 0;
            color: var(--text-muted);
        }
        
        .subscription-agreement li,
        .customer-agreement li,
        .agreement-content li {
            margin-bottom: 8px;
        }
        
        .subscription-agreement .highlight {
            background: rgba(212, 175, 55, 0.1);
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            border: 1px solid rgba(212, 175, 55, 0.2);
        }
        
        .subscription-agreement .highlight p {
            color: var(--accent-gold);
            font-weight: 600;
            margin: 0;
        }
        
        .customer-agreement {
            background: rgba(76, 175, 80, 0.05);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid rgba(76, 175, 80, 0.2);
            margin: 25px 0;
        }
        
        .customer-agreement h4 {
            color: var(--success-color);
            margin-bottom: 15px;
        }
        
        .customer-agreement .highlight {
            background: rgba(76, 175, 80, 0.1);
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            border: 1px solid rgba(76, 175, 80, 0.2);
        }
        
        .customer-agreement .highlight p {
            color: var(--success-color);
            font-weight: 600;
            margin: 0;
        }

        .agreement-content {
            background: rgba(255, 255, 255, 0.03);
            padding: 15px 15px 15px 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            max-height: 260px;
            overflow-y: auto;
            box-sizing: border-box;
        }

        .agreement-content p,
        .agreement-content ul,
        .agreement-content ol {
            color: var(--text-muted);
        }

        .agreement-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px dashed var(--border-color);
        }
        
        .agreement-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 3px;
            accent-color: var(--accent-gold);
        }
        
        .agreement-checkbox label {
            flex: 1;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-muted);
        }
        
        /* Role Selection */
        .role-selector {
            text-align: center;
            padding: 0;
        }
        
        .role-selector h3 {
            color: var(--text-main);
            margin-bottom: 30px;
            font-size: 20px;
            font-weight: 600;
        }
        
        .role-options {
            display: flex;
            justify-content: center;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .role-option {
            flex: 1;
            max-width: 220px;
            text-align: center;
            cursor: pointer;
            padding: 25px;
            border: 1px solid var(--border-color);
            border-radius: 18px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.03);
            position: relative;
            overflow: hidden;
        }
        
        .role-option:hover {
            border-color: var(--accent-gold);
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .role-option.active {
            border-color: var(--accent-gold);
            background: rgba(212, 175, 55, 0.05);
            transform: translateY(-5px);
        }
        
        .role-option.active::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--accent-gold);
        }
        
        .role-option i {
            font-size: 48px;
            color: var(--text-muted);
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .role-option:hover i,
        .role-option.active i {
            color: var(--accent-gold);
            transform: scale(1.1);
        }
        
        .role-option h4 {
            color: var(--text-main);
            margin-bottom: 8px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .role-option p {
            color: var(--text-muted);
            font-size: 13px;
            margin: 0;
            line-height: 1.4;
        }
        
        /* Back Button */
        .back-btn {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            padding: 12px 18px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            margin-bottom: 25px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-btn:hover {
            border-color: var(--accent-gold);
            color: var(--text-main);
        }
        
        /* Additional Buttons */
        .additional-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .additional-btn {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            padding: 12px 24px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .additional-btn:hover {
            border-color: var(--accent-gold);
            color: #fff;
            background: rgba(255, 255, 255, 0.1);
        }
        
        .additional-btn.home {
            background: var(--accent-gold);
            color: #000;
            border-color: var(--accent-gold);
            font-weight: 600;
        }
        
        .additional-btn.home:hover {
            background: var(--accent-gold-hover);
            color: #000;
        }
        
        /* File Upload */
        input[type="file"] {
            background: rgba(255, 255, 255, 0.03);
            padding: 12px;
            border-radius: 12px;
            border: 1px dashed var(--border-color);
            color: var(--text-muted);
            cursor: pointer;
        }
        
        input[type="file"]::file-selector-button {
            background: rgba(255, 255, 255, 0.08);
            color: var(--text-main);
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            margin-right: 10px;
        }
        
        input[type="file"]:hover {
            border-color: var(--accent-gold);
        }
        
        .file-preview img {
            border: 1px solid var(--border-color);
            background: #000;
            max-width: 100%;
            max-height: 120px;
            width: auto;
            border-radius: 10px;
            object-fit: contain;
            margin-top: 10px;
        }
        
        /* Notes */
        .caterer-profile-note {
            background: rgba(33, 150, 243, 0.1);
            border: 1px solid rgba(33, 150, 243, 0.25);
            color: #64b5f6;
            margin-top: 8px;
            padding: 10px;
            border-radius: 12px;
            font-size: 13px;
        }
        
        .customer-profile-note {
            background: rgba(156, 39, 176, 0.1);
            border: 1px solid rgba(171, 71, 188, 0.25);
            color: #ce93d8;
            margin-top: 8px;
            padding: 10px;
            border-radius: 12px;
            font-size: 13px;
        }
        
        /* Approval Notice */
        .approval-notice {
            background: rgba(255, 152, 0, 0.1);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid rgba(255, 152, 0, 0.22);
            margin: 20px 0;
        }
        
        .approval-notice h4 {
            color: #ffb74d;
            margin-bottom: 10px;
        }
        
        .approval-notice p {
            color: var(--text-muted);
            margin-bottom: 5px;
        }
        
        .registration-success {
            background: rgba(76, 175, 80, 0.1);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid rgba(76, 175, 80, 0.22);
            margin: 20px 0;
        }
        
        .registration-success h4 {
            color: #81c784;
            margin-bottom: 10px;
        }
        
        .registration-success p {
            color: var(--text-muted);
            margin-bottom: 5px;
        }
        
        /* Animation Classes */
        .fade-out-left { animation: fadeOutToLeft 0.3s ease-out forwards; }
        .fade-out-right { animation: fadeOutToRight 0.3s ease-out forwards; }
        .slide-in-right { animation: slideInFromRight 0.3s ease-out forwards; }
        .slide-in-left { animation: slideInFromLeft 0.3s ease-out forwards; }
        
        @keyframes fadeOutToLeft {
            to { opacity: 0; transform: translateX(-20px); }
        }
        @keyframes fadeOutToRight {
            to { opacity: 0; transform: translateX(20px); }
        }
        @keyframes slideInFromRight {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes slideInFromLeft {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            body { 
                padding: 15px; 
                justify-content: flex-start;
                overflow-y: auto;
            }
            .login-container { 
                max-height: none; 
                border-radius: 22px; 
                margin: auto 0;
            }
            .login-header { padding: 32px 22px; }
            .form-content { padding: 28px 22px; }
            .role-options { flex-direction: column; align-items: center; gap: 15px; }
            .role-option { width: 100%; max-width: 100%; }
            .caterer-grid { grid-template-columns: 1fr; }
            .form-row { flex-direction: column; gap: 15px; }
            .additional-buttons { flex-direction: column; }
            .additional-btn { justify-content: center; }
            .form-links { flex-direction: column; gap: 12px; text-align: center; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/register-responsive.css?v=20261021-login-style-choices">
    <link rel="stylesheet" href="assets/css/philippine-address.css?v=5">
    <link rel="stylesheet" href="assets/css/soft-sage-theme.css?v=20261019-8">
    <link rel="stylesheet" href="assets/css/auth-sage-theme.css?v=20261021-refined-fields">
    <link rel="stylesheet" href="assets/css/public-footer.css?v=20261021-18">
    <link rel="stylesheet" href="assets/css/register-welcome.css?v=20261025-4">
</head>
<body class="login-page register-page">
<div class="login-stage">
<div class="login-container">
    <div class="login-header">
        <div class="logo-wrap">
            <img src="uploads/logo.png" alt="Logo">
        </div>
        <h2>Create an Account</h2>
        <p>Join our catering services portal</p>
    </div>
    
    <div class="form-content">
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo $success; ?>
            </div>
            <?php if (isset($role) && $role === 'caterer'): ?>
                <div class="approval-notice">
                    <h4><i class="fas fa-clock"></i> Approval Process</h4>
                    <p><strong>Important:</strong> Your caterer registration needs approval from the portal administrator. This process usually takes up to <?php echo APPROVAL_TIME_HOURS; ?> hours. You will be notified once your account is approved.</p>
                    <p><strong>Note:</strong> Your caterer profile picture will be automatically displayed on the homepage once approved!</p>
                </div>
                <div class="registration-success">
                    <h4><i class="fas fa-check-circle"></i> Next Steps:</h4>
                    <p>1. Wait for portal administrator to review your documents</p>
                    <p>2. Your caterer profile picture will appear on homepage after approval</p>
                    <p>3. You will receive notification once approved</p>
                    <p>4. After approval, you will get 7-day free trial</p>
                    <p>5. Login to your account to check status</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- Role Selection Section -->
        <div id="role-selection" class="role-selector" style="display: <?php echo (isset($_POST['role']) || $error) ? 'none' : 'block'; ?>">
            <span class="welcome-step">LET'S GET STARTED</span>
            <h3>How will you use the portal?</h3>
            <p class="welcome-intro">Great food brings people together. Choose your account to get started.</p>
            <div class="role-options">
                <button type="button" class="role-option" onclick="selectRole('caterer', this)">
                    <i class="fas fa-utensils" aria-hidden="true"></i>
                    <span class="role-copy"><strong>Caterer Owner</strong><span>Showcase your menus and manage event bookings.</span><em>Grow your catering business <span aria-hidden="true">&rarr;</span></em></span>
                </button>
                <button type="button" class="role-option" onclick="selectRole('customer', this)">
                    <i class="fas fa-user" aria-hidden="true"></i>
                    <span class="role-copy"><strong>Customer</strong><span>Explore caterers and find a menu for your next celebration.</span><em>Plan your next event <span aria-hidden="true">&rarr;</span></em></span>
                </button>
            </div>
            
            <!-- NEW: Additional Buttons Section -->
            <div class="additional-buttons">
                <a href="login.php" class="additional-btn">
                    <i class="fas fa-sign-in-alt"></i> Back to Login
                </a>
                <a href="index.php" class="additional-btn home">
                    <i class="fas fa-home"></i> Back to Homepage
                </a>
            </div>
        </div>
        
        <!-- Registration Form (Hidden by default) -->
        <div id="registration-form" class="form-container" style="display: <?php echo (isset($_POST['role']) || $error) ? 'block' : 'none'; ?>">
            <button type="button" class="back-btn" onclick="goBackToRoleSelection()">
                <i class="fas fa-arrow-left"></i> Back to Account Type
            </button>
            
            <form method="POST" action="" enctype="multipart/form-data" id="registerForm">
                <!-- Hidden role field -->
                <input type="hidden" id="role" name="role" value="<?php echo isset($_POST['role']) ? htmlspecialchars($_POST['role']) : ''; ?>">
                
                <!-- Caterer Business Name Field -->
                <div id="business-name-field" class="business-name-group" style="display: none;">
                    <div class="form-group">
                        <label for="business_name"><i class="fas fa-utensils"></i> Business Name <span style="color: #e74c3c;"></span></label>
                        <input type="text" id="business_name" name="business_name" class="capitalize-first" oninput="autoCapitalize(this)" placeholder="Enter your business/caterer name" value="<?php echo isset($_POST['business_name']) ? htmlspecialchars($_POST['business_name']) : ''; ?>">
                        <small>Your business name as it will appear on the platform</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name"><i class="fas fa-user"></i> First Name <span style="color: #e74c3c;"></span></label>
                        <input type="text" id="first_name" name="first_name" maxlength="100" pattern="(?=[^\p{L}]*\p{L})[\p{L}\p{M} .’&#39;\x2D]{1,100}" title="Enter a name with at least one letter and no more than 100 characters." class="capitalize-first" oninput="filterIdentityInput(this); autoCapitalize(this)" required value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name"><i class="fas fa-user"></i> Last Name <span style="color: #e74c3c;"></span></label>
                        <input type="text" id="last_name" name="last_name" maxlength="100" pattern="(?=[^\p{L}]*\p{L})[\p{L}\p{M} .’&#39;\x2D]{1,100}" title="Enter a name with at least one letter and no more than 100 characters." class="capitalize-first" oninput="filterIdentityInput(this); autoCapitalize(this)" required value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="middle_name"><i class="fas fa-user"></i> Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name" maxlength="100" pattern="(?=[^\p{L}]*\p{L})[\p{L}\p{M} .’&#39;\x2D]{1,100}" title="Enter a name with at least one letter and no more than 100 characters." class="capitalize-first" oninput="filterIdentityInput(this); autoCapitalize(this)" value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="suffix"><i class="fas fa-user"></i> Suffix</label>
                        <select id="suffix" name="suffix">
                            <?php foreach ($suffixOptions as $suffixOption): ?>
                                <option value="<?php echo htmlspecialchars($suffixOption); ?>"<?php echo ($_POST['suffix'] ?? '') === $suffixOption ? ' selected' : ''; ?>><?php echo $suffixOption === '' ? 'None' : htmlspecialchars($suffixOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="width: 100%;">
                        <label for="phone_number"><i class="fas fa-phone"></i> Phone Number <span style="color: #e74c3c;"></span></label>
                        <input type="tel" id="phone_number" name="phone_number" required inputmode="numeric" autocomplete="tel-national" minlength="11" maxlength="11" pattern="[0-9]{11}" title="Enter exactly 11 digits, with no letters or symbols." value="<?php echo isset($_POST['phone_number']) ? htmlspecialchars($_POST['phone_number']) : ''; ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="username"><i class="fas fa-id-badge"></i> Username <span style="color: #e74c3c;"></span></label>
                        <input type="text" id="username" name="username" required maxlength="250" pattern="[\p{L}]{1,250}" title="Letters only, maximum 250 characters." oninput="filterIdentityInput(this)" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email Address <span style="color: #e74c3c;"></span></label>
                        <input type="email" id="email" name="email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-map-marker-alt"></i> Address</label>
                    <div id="ph-address-picker" class="ph-address-picker" data-region="<?php echo htmlspecialchars(is_string($_POST['address_region'] ?? '') ? ($_POST['address_region'] ?? '') : '', ENT_QUOTES); ?>" data-province="<?php echo htmlspecialchars(is_string($_POST['address_province'] ?? '') ? ($_POST['address_province'] ?? '') : '', ENT_QUOTES); ?>" data-city="<?php echo htmlspecialchars(is_string($_POST['address_city'] ?? '') ? ($_POST['address_city'] ?? '') : '', ENT_QUOTES); ?>" data-barangay="<?php echo htmlspecialchars(is_string($_POST['address_barangay'] ?? '') ? ($_POST['address_barangay'] ?? '') : '', ENT_QUOTES); ?>">
                        <div class="ph-address-islands"><label for="address-island">Island Group</label><select id="address-island" name="address_island"><option value="">All Philippines</option><option value="luzon">Luzon</option><option value="visayas">Visayas</option><option value="mindanao">Mindanao</option></select><small class="ph-address-help"></small></div>
                        <div><label for="address-region">Region</label><select id="address-region" name="address_region" required><option value="">Loading regions…</option></select></div>
                        <div><label for="address-province">Province</label><select id="address-province" name="address_province" required disabled><option value="">Select a region first</option></select></div>
                        <div><label for="address-city">City / Municipality</label><select id="address-city" name="address_city" required disabled><option value="">Select a province first</option></select></div>
                        <div><label for="address-barangay">Barangay</label><select id="address-barangay" name="address_barangay" required disabled><option value="">Select a city first</option></select></div>
                    </div>
                    <input id="address" name="address" type="hidden" value="">
                   
                    <button id="address-retry" type="button" hidden>Retry loading address choices</button>
                </div>
                
                <!-- Caterer specific fields -->
                <div id="caterer-fields" class="caterer-fields" style="display: none;">
                    <h4><i class="fas fa-utensils"></i> Caterer Information</h4>
                    
                    <div class="caterer-grid">
                        <div class="form-group">
                            <label for="valid_id"><i class="fas fa-id-card"></i> Valid ID <span style="color: #e74c3c;"></span></label>
                            <input type="file" id="valid_id" name="valid_id" accept="image/*" onchange="previewFile('valid_id')">
                            <div class="file-preview" id="valid_id_preview"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="business_permit"><i class="fas fa-file-contract"></i> Business Permit <span style="color: #e74c3c;"></span></label>
                            <input type="file" id="business_permit" name="business_permit" accept="image/*" onchange="previewFile('business_permit')">
                            <div class="file-preview" id="business_permit_preview"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="gcash_qr"><i class="fas fa-qrcode"></i> GCash QR Code <span style="color: #e74c3c;"></span></label>
                            <input type="file" id="gcash_qr" name="gcash_qr" onchange="previewFile('gcash_qr')">
                            <div class="file-preview" id="gcash_qr_preview"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="profile_store"><i class="fas fa-camera"></i> Caterer Profile Picture <span style="color: #e74c3c;"></span></label>
                            <input type="file" id="profile_store" name="profile_store" accept="image/*" onchange="previewFile('profile_store')">
                            <div class="file-preview" id="profile_store_preview"></div>
                        </div>
                    </div>


                </div>
                
                <!-- Subscription Agreement for Caterer Owners -->
                <div id="subscription-agreement" class="subscription-agreement" style="display: none;">
                    <details class="subscription-agreement-details">
                    <summary><i class="fas fa-file-contract"></i> Subscription Agreement for Caterer Owner</summary>
                    
                    <div class="agreement-content">
                        <?php 
                        $default_caterer_agreement = '
                        <p><strong>By registering a Caterer Owner, I agree to the following subscription terms:</strong></p>
                        <ul>
                            <li><strong>Approval Required:</strong> Your caterer registration needs manual approval.</li>
                            <li><strong>Profile Display:</strong> Your caterer profile picture will be automatically displayed on the homepage after approval.</li>
                            <li><strong>7-Day Free Trial:</strong> After approval, you will receive a 7-day free trial.</li>
                            <li><strong>Automatic Subscription:</strong> After the 7-day free trial, you will automatically be subscribed to our monthly plan.</li>
                            <li><strong>Monthly Fee:</strong> The subscription fee is <strong>&#x20B1;' . $registrationMonthlyFee . ' per month</strong>.</li>
                            <li><strong>Payment Method:</strong> Payments will be processed through the GCash QR code.</li>
                            <li><strong>Service Access:</strong> Full access to caterer features will be available only with an active subscription.</li>
                        </ul>
                        <div class="highlight">
                            <p><i class="fas fa-exclamation-circle"></i> IMPORTANT: Your caterer profile picture will be visible on the homepage automatically once approved. To keep using all features, pay your subscription on time. If payment is missed, you\'ll lose access to premium features.</p>
                        </div>';
                        echo !empty($settings['caterer_subscription_agreement']) 
                            ? stripslashes($settings['caterer_subscription_agreement']) 
                            : $default_caterer_agreement;
                        ?>
                    </div>
                    </details>
                    
                    <div class="agreement-checkbox">
                        <input type="checkbox" id="subscription_agreement" name="subscription_agreement" value="1" <?php echo (isset($_POST['subscription_agreement']) && $_POST['subscription_agreement'] == '1') ? 'checked' : ''; ?>>
                        <label for="subscription_agreement">
                            I have read and agree to the subscription terms. I understand that my caterer needs approval first, then my profile picture will appear on the homepage, and I will get a 7-day free trial, and after that, I will be charged &#x20B1;<?php echo $registrationMonthlyFee; ?> monthly.
                        </label>
                    </div>
                </div>
                
                <!-- Customer profile field -->
                <div id="customer-profile" style="display: none;">
                    <div class="form-group">
                        <label for="profile_customer"><i class="fas fa-user-circle"></i> Profile Picture</label>
                        <input type="file" id="profile_customer" name="profile_customer" accept="image/*" onchange="previewFile('profile_customer')">

                        <div class="file-preview" id="profile_customer_preview"></div>
                    </div>
                </div>
                
                <!-- Customer Agreement -->
                <div id="customer-agreement" class="customer-agreement" style="display: none;">
                    <details class="customer-agreement-details">
                    <summary><i class="fas fa-file-contract"></i> Customer Agreement</summary>
                    
                    <div class="agreement-content">
                        <?php 
                        $default_customer_agreement = '
                        <p><strong>By registering as a Customer, I agree to the following terms and conditions:</strong></p>
                        <ul>
                            <li><strong>No Cancellation Policy:</strong> Once an order or booking is placed and confirmed, <strong>it cannot be cancelled</strong>.</li>
                            <li><strong>Payment Commitment:</strong> All orders/booking payments must be completed as agreed upon during the booking process.</li>
                            <li><strong>Responsibility:</strong> I am responsible for providing accurate information for delivery or pickup.</li>
                            <li><strong>Refund Policy:</strong> No refunds will be provided for completed orders, except in cases of caterer cancellation or force majeure.</li>
                            <li><strong>Order Modifications:</strong> Any modifications to orders must be requested and agreed upon with the caterer <strong>before</strong> the order is confirmed.</li>
                        </ul>
                        <div class="highlight">
                            <p><i class="fas fa-exclamation-circle"></i> IMPORTANT: Once you place an order and it is confirmed by the caterer, the transaction becomes final and cannot be cancelled. Please review your order carefully before confirming.</p>
                        </div>';
                        echo !empty($settings['customer_agreement']) 
                            ? stripslashes($settings['customer_agreement']) 
                            : $default_customer_agreement;
                        ?>
                    </div>
                    </details>
                    
                    <div class="agreement-checkbox">
                        <input type="checkbox" id="customer_agreement" name="customer_agreement" value="1" <?php echo (isset($_POST['customer_agreement']) && $_POST['customer_agreement'] == '1') ? 'checked' : ''; ?>>
                        <label for="customer_agreement">
                            I have read and agree to the customer agreement. I understand that once I place an order and it is confirmed, I cannot cancel it and I am responsible for the payment.
                        </label>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Password <span style="color: #e74c3c;"></span></label>
                        <div class="password-field">
                            <input type="password" id="password" name="password" aria-describedby="password-char-hint" required minlength="8" pattern="(?=.*[A-Za-z])(?=.*[0-9]).{8,}" title="Use at least 8 characters, including a letter and a number.">
                            <button type="button" class="password-toggle" data-toggle-password="password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small id="password-char-hint" role="status" aria-live="polite" hidden></small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password"><i class="fas fa-lock"></i> Confirm Password <span style="color: #e74c3c;"></span></label>
                        <div class="password-field">
                            <input type="password" id="confirm_password" name="confirm_password" aria-describedby="confirm-password-hint" required minlength="8">
                            <button type="button" class="password-toggle" data-toggle-password="confirm_password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small id="confirm-password-hint" role="status" aria-live="polite" hidden></small>
                    </div>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>
                </div>
            </form>
            
            <div class="form-links">
                <a href="login.php">
                    <i class="fas fa-sign-in-alt"></i> Back to Login
                </a>
                <a href="index.php">
                    <i class="fas fa-home"></i> Back to Homepage
                </a>
            </div>
        </div>
    </div>
</div>

</div>
<?php include __DIR__ . '/includes/public-footer.php'; ?>

<script>
    function filterIdentityInput(input) {
        const cursor = input.selectionStart;
        const disallowed = input.id === 'username' ? /[^\p{L}]/gu : /[^\p{L}\p{M} .’'\x2D]/gu;
        const beforeCursor = input.value.slice(0, cursor).replace(disallowed, '');
        input.value = Array.from(input.value.replace(disallowed, '')).slice(0, input.maxLength).join('');
        input.setSelectionRange(beforeCursor.length, beforeCursor.length);
    }

    // NEW: Auto-capitalize function for first letter of each word
    function autoCapitalize(inputElement) {
        // Get the current cursor position
        const cursorPosition = inputElement.selectionStart;
        
        // Get the current value
        let currentValue = inputElement.value;
        
        // Capitalize first letter of each word
        currentValue = currentValue.replace(/\b\w/g, function(char) {
            return char.toUpperCase();
        });
        
        // Update the input value
        inputElement.value = currentValue;
        
        // Restore cursor position
        inputElement.selectionStart = cursorPosition;
        inputElement.selectionEnd = cursorPosition;
    }
    
    // Role selection functions
    let selectedRole = '';
    const registrationViewKey = 'catering.registration.role';
    function saveRegistrationView(role) {
        try {
            if (role === 'caterer' || role === 'customer') {
                sessionStorage.setItem(registrationViewKey, role);
            } else {
                sessionStorage.removeItem(registrationViewKey);
            }
        } catch (error) { /* Registration still works when storage is unavailable. */ }
    }
    
    function selectRole(role, option) {
        selectedRole = role;
        saveRegistrationView(role);
        
        // Update visual selection
        document.querySelectorAll('.role-option').forEach(option => {
            option.classList.remove('active');
        });
        
        if (option) option.classList.add('active');
        
        // Animate the role selection out
        const roleSelection = document.getElementById('role-selection');
        roleSelection.classList.add('fade-out-left');
        
        // Wait for animation to complete, then show registration form
        setTimeout(function() {
            roleSelection.style.display = 'none';
            roleSelection.classList.remove('fade-out-left');
            showRegistrationForm(role);
        }, 300);
    }
    
    function showRegistrationForm(role) {
        const registrationForm = document.getElementById('registration-form');
        registrationForm.style.display = 'block';
        registrationForm.classList.remove('slide-in-right', 'slide-in-left');
        void registrationForm.offsetWidth; // Trigger reflow
        registrationForm.classList.add('slide-in-right');
        
        document.getElementById('role').value = role;
        toggleRegistrationFields();
    }
    
    function goBackToRoleSelection() {
        saveRegistrationView('');
        selectedRole = '';
        document.getElementById('role').value = '';
        toggleRegistrationFields();
        const registrationForm = document.getElementById('registration-form');
        registrationForm.classList.remove('slide-in-right');
        registrationForm.classList.add('fade-out-right');
        
        setTimeout(function() {
            registrationForm.style.display = 'none';
            registrationForm.classList.remove('fade-out-right');
            
            const roleSelection = document.getElementById('role-selection');
            roleSelection.style.display = 'block';
            roleSelection.classList.remove('fade-out-left');
            roleSelection.classList.add('slide-in-left');
            
            document.querySelectorAll('.role-option').forEach(option => {
                option.classList.remove('active');
            });
        }, 300);
    }
    
    function toggleRegistrationFields() {
        const role = document.getElementById('role').value;
        const storeFields = document.getElementById('caterer-fields');
        const customerProfile = document.getElementById('customer-profile');
        const subscriptionAgreement = document.getElementById('subscription-agreement');
        const customerAgreement = document.getElementById('customer-agreement');
        const businessNameField = document.getElementById('business-name-field');
        const businessNameInput = document.getElementById('business_name');
        
        if (role === 'caterer') {
            storeFields.style.display = 'block';
            customerProfile.style.display = 'none';
            subscriptionAgreement.style.display = 'block';
            customerAgreement.style.display = 'none';
            businessNameField.style.display = 'block';
            
            // Make caterer-specific fields required
            if (document.getElementById('valid_id')) document.getElementById('valid_id').required = true;
            if (document.getElementById('business_permit')) document.getElementById('business_permit').required = true;
            if (document.getElementById('gcash_qr')) document.getElementById('gcash_qr').required = true;
            if (document.getElementById('profile_store')) document.getElementById('profile_store').required = true;
            if (document.getElementById('subscription_agreement')) document.getElementById('subscription_agreement').required = true;
            if (document.getElementById('customer_agreement')) document.getElementById('customer_agreement').required = false;
            if (businessNameInput) businessNameInput.required = true;
            
            // Hide customer profile preview
            if (document.getElementById('profile_customer_preview')) document.getElementById('profile_customer_preview').style.display = 'none';
        } else if (role === 'customer') {
            storeFields.style.display = 'none';
            customerProfile.style.display = 'block';
            subscriptionAgreement.style.display = 'none';
            customerAgreement.style.display = 'block';
            businessNameField.style.display = 'none';
            
            // Remove required attribute from caterer fields
            if (document.getElementById('valid_id')) document.getElementById('valid_id').required = false;
            if (document.getElementById('business_permit')) document.getElementById('business_permit').required = false;
            if (document.getElementById('gcash_qr')) document.getElementById('gcash_qr').required = false;
            if (document.getElementById('profile_store')) document.getElementById('profile_store').required = false;
            if (document.getElementById('profile_customer')) document.getElementById('profile_customer').required = false;
            if (document.getElementById('subscription_agreement')) document.getElementById('subscription_agreement').required = false;
            if (document.getElementById('customer_agreement')) document.getElementById('customer_agreement').required = true;
            if (businessNameInput) businessNameInput.required = false;
            
            // Hide caterer file previews
            if (document.getElementById('valid_id_preview')) document.getElementById('valid_id_preview').style.display = 'none';
            if (document.getElementById('business_permit_preview')) document.getElementById('business_permit_preview').style.display = 'none';
            if (document.getElementById('gcash_qr_preview')) document.getElementById('gcash_qr_preview').style.display = 'none';
            if (document.getElementById('profile_store_preview')) document.getElementById('profile_store_preview').style.display = 'none';
        } else {
            storeFields.style.display = 'none';
            customerProfile.style.display = 'none';
            subscriptionAgreement.style.display = 'none';
            customerAgreement.style.display = 'none';
            businessNameField.style.display = 'none';
            
            // Remove all required attributes
            if (document.getElementById('valid_id')) document.getElementById('valid_id').required = false;
            if (document.getElementById('business_permit')) document.getElementById('business_permit').required = false;
            if (document.getElementById('gcash_qr')) document.getElementById('gcash_qr').required = false;
            if (document.getElementById('profile_store')) document.getElementById('profile_store').required = false;
            if (document.getElementById('profile_customer')) document.getElementById('profile_customer').required = false;
            if (document.getElementById('subscription_agreement')) document.getElementById('subscription_agreement').required = false;
            if (document.getElementById('customer_agreement')) document.getElementById('customer_agreement').required = false;
            if (businessNameInput) businessNameInput.required = false;
        }
    }
    
    // File preview function with validation
    function previewFile(inputId) {
        const input = document.getElementById(inputId);
        const previewId = inputId + '_preview';
        const preview = document.getElementById(previewId);
        
        if (input && input.files && input.files[0]) {
            const file = input.files[0];
            
            // Validate file size (5MB limit)
            if (file.size > 5242880) {
                alert('File size exceeds 5MB limit! Please choose a smaller file.');
                input.value = '';
                if (preview) {
                    preview.style.display = 'none';
                    preview.innerHTML = '';
                }
                return;
            }
            
            // Validate file type, except GCash QR which accepts any image extension.
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (inputId !== 'gcash_qr' && !allowedTypes.includes(file.type)) {
                alert('Invalid file type! Please upload an image (JPG, JPEG, PNG, GIF, WEBP).');
                input.value = '';
                if (preview) {
                    preview.style.display = 'none';
                    preview.innerHTML = '';
                }
                return;
            }
            
            const reader = new FileReader();
            
            reader.onload = function(e) {
                if (preview) {
                    preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
                    preview.style.display = 'block';
                }
            }
            
            reader.onerror = function() {
                alert('Error reading file! Please try again.');
                input.value = '';
                if (preview) {
                    preview.style.display = 'none';
                    preview.innerHTML = '';
                }
            }
            
            reader.readAsDataURL(file);
        } else {
            if (preview) {
                preview.style.display = 'none';
                preview.innerHTML = '';
            }
        }
    }
    
    // Form validation
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

    // Real-time password length, letter, and number enforcement
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const phoneInput = document.getElementById('phone_number');

    function updatePhoneNumber() {
        phoneInput.value = phoneInput.value.replace(/[^0-9]/g, '').slice(0, 11);
        const valid = /^[0-9]{11}$/.test(phoneInput.value);
        phoneInput.setCustomValidity(valid ? '' : 'Enter exactly 11 digits for your phone number.');
    }

    phoneInput.addEventListener('input', updatePhoneNumber);
    phoneInput.addEventListener('change', updatePhoneNumber);
    updatePhoneNumber();

    function updateSubmitState() {
        if (!passwordInput) return;
        const val = passwordInput.value;
        const hasMinLength = val.length >= 8;
        const hasNumber = /[0-9]/.test(val);
        const hasLetter = /[A-Za-z]/.test(val);
        const hint = document.getElementById('password-char-hint');
        passwordInput.setCustomValidity(hasMinLength && hasLetter && hasNumber ? '' : 'Password must be at least 8 characters long and contain a letter and a number.');
        confirmPasswordInput.setCustomValidity(confirmPasswordInput.value === val ? '' : 'Passwords do not match.');

        const valid = hasMinLength && hasLetter && hasNumber;
        // Keep text indicators hidden; show validation through field colors.
        if (hint) hint.hidden = true;
        const confirmHint = document.getElementById('confirm-password-hint');
        if (confirmHint) confirmHint.hidden = true;
        function colorField(input, isValid) {
            const color = isValid ? '#245b3c' : '#c0392b';
            input.style.setProperty('border-color', color, 'important');
            input.style.setProperty('box-shadow', '0 0 0 1px ' + color, 'important');
            input.setAttribute('aria-invalid', String(!isValid));
        }
        colorField(passwordInput, valid);
        colorField(confirmPasswordInput, valid && confirmPasswordInput.value === val);
    }
    
    if (passwordInput) {
        passwordInput.addEventListener('input', updateSubmitState);
        passwordInput.addEventListener('change', updateSubmitState);
        confirmPasswordInput.addEventListener('input', updateSubmitState);
        confirmPasswordInput.addEventListener('change', updateSubmitState);
        updateSubmitState(); // run once on load
    }

    if (document.getElementById('registerForm')) {
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const role = document.getElementById('role').value;
            
            // Password validation
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long!');
                return false;
            }

            if (!/[A-Za-z]/.test(password)) {
                e.preventDefault();
                alert('Password must contain at least one letter!');
                return false;
            }

            if (!/[0-9]/.test(password)) {
                e.preventDefault();
                alert('Password must contain at least one number!');
                return false;
            }
            
            // Check required fields based on role
            if (role === 'caterer') {
                // Check business name
                const businessName = document.getElementById('business_name').value.trim();
                if (!businessName) {
                    e.preventDefault();
                    alert('Please enter your Business Name!');
                    return false;
                }
                
                // Check file uploads
                const validId = document.getElementById('valid_id') ? document.getElementById('valid_id').files.length : 0;
                const businessPermit = document.getElementById('business_permit') ? document.getElementById('business_permit').files.length : 0;
                const gcashQr = document.getElementById('gcash_qr') ? document.getElementById('gcash_qr').files.length : 0;
                const profileStore = document.getElementById('profile_store') ? document.getElementById('profile_store').files.length : 0;
                const agreementChecked = document.getElementById('subscription_agreement') ? document.getElementById('subscription_agreement').checked : false;
                
                if (!validId || !businessPermit || !gcashQr || !profileStore) {
                    e.preventDefault();
                    alert('Please upload all required documents for caterer registration!');
                    return false;
                }
                
                if (!agreementChecked) {
                    e.preventDefault();
                    alert('You must accept the subscription agreement to register as a caterer owner!');
                    return false;
                }
            } else if (role === 'customer') {
                // Check customer agreement
                const agreementChecked = document.getElementById('customer_agreement') ? document.getElementById('customer_agreement').checked : false;
                
                if (!agreementChecked) {
                    e.preventDefault();
                    alert('You must accept the customer agreement to register as a customer!');
                    return false;
                }
            } else {
                e.preventDefault();
                alert('Please select an account type!');
                return false;
            }
        });
    }
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // If form was submitted and there's an error, show the registration form
        <?php if (isset($_POST['role']) || $error): ?>
            selectedRole = '<?php echo isset($_POST['role']) ? $_POST['role'] : ''; ?>';
            toggleRegistrationFields();
            
            // Auto-select the role option
            const roleOption = document.querySelector('.role-option:nth-child(<?php echo (isset($_POST['role']) && $_POST['role'] === 'caterer') ? '1' : '2'; ?>)');
            if (roleOption) {
                roleOption.classList.add('active');
            }
        <?php endif; ?>
        
        // Restore the selected view without replaying the selection animation.
        const submittedRole = <?php echo json_encode($_POST['role'] ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let restoredRole = submittedRole;
        if (restoredRole === null) {
            try { restoredRole = sessionStorage.getItem(registrationViewKey); } catch (error) {}
        }
        if (restoredRole === 'caterer' || restoredRole === 'customer') {
            selectedRole = restoredRole;
            saveRegistrationView(restoredRole);
            document.getElementById('role-selection').style.display = 'none';
            document.getElementById('registration-form').style.display = 'block';
            document.getElementById('role').value = restoredRole;
            toggleRegistrationFields();
            document.querySelectorAll('.role-option').forEach((option, index) => {
                option.classList.toggle('active', index === (restoredRole === 'caterer' ? 0 : 1));
            });
        }
        // Apply auto-capitalization to existing values on page load
        const capitalizeFields = document.querySelectorAll('.capitalize-first');
        capitalizeFields.forEach(field => {
            if (field.value) {
                autoCapitalize(field);
            }
        });
        
        // Better mobile experience
        if (window.innerWidth <= 480) {
            document.body.style.minHeight = '100vh';
            document.body.style.display = 'flex';
            document.body.style.flexDirection = 'column';
            document.body.style.justifyContent = 'flex-start';
        }
    });
</script>
<script>
window.registrationDraftState = {
completed: <?php echo json_encode(!empty($_SESSION['registration_draft_completed'])); ?>,
submitted: <?php echo json_encode($_SERVER['REQUEST_METHOD'] === 'POST'); ?>
};
<?php unset($_SESSION['registration_draft_completed']); ?>
</script>
<script src="assets/js/registration-draft.js?v=2"></script>
<script src="assets/js/registration-profile-draft.js?v=1"></script>
<script src="assets/js/philippine-address.js?v=7"></script>
</body>
</html>
