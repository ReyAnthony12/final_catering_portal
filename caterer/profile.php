<?php
// Start session first as header.php checks it
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/profile_fields.php';
/** @var mysqli $conn */

function ensureStoreTermsColumn($conn) {
    $check = mysqli_query($conn, "SHOW COLUMNS FROM store_details LIKE 'store_terms_conditions'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE store_details ADD COLUMN store_terms_conditions TEXT NULL");
    }
}

if (!isLoggedIn() || !in_array(getUserRole(), ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

ensureStoreTermsColumn($conn);

// Get caterer details
$query = "
    SELECT u.*, sd.*, COALESCE(NULLIF(sd.location, ''), u.address) AS location 
    FROM users u 
    LEFT JOIN store_details sd ON u.id = sd.user_id 
    WHERE u.id = '$user_id'
";
$result = mysqli_query($conn, $query);
$caterer = mysqli_fetch_assoc($result);

// Check if caterer is closed by portal - FIXED: Always check from users.status
$store_status_query = "SELECT u.status as user_status FROM users u WHERE u.id = '$user_id'";
$store_status_result = mysqli_query($conn, $store_status_query);
$store_status = mysqli_fetch_assoc($store_status_result);
$is_store_closed_by_portal = ($store_status['user_status'] === 'closed');

// Get current page for sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

// Handle form submission
$csrf_token = generateCSRFToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? null;
    if (!is_string($submitted_token) || !validateCSRFToken($submitted_token)) {
        http_response_code(403);
        exit('Your session expired. Refresh the page and try again.');
    }
}
if (isset($_POST['save_store_terms'])) {
    $store_terms_conditions = mysqli_real_escape_string($conn, trim($_POST['store_terms_conditions'] ?? ''));
    $save_terms_query = "UPDATE store_details SET store_terms_conditions = '$store_terms_conditions' WHERE user_id = '$user_id'";

    if (mysqli_query($conn, $save_terms_query)) {
        $success = "Caterer terms and conditions updated successfully!";
        $result = mysqli_query($conn, $query);
        $caterer = mysqli_fetch_assoc($result);
    } else {
        appLogError('Caterer terms update failed', mysqli_error($conn));
        $error = "Unable to update your caterer terms right now. Please try again.";
    }
} elseif (isset($_POST['upload_document'])) {
    $doc_type = $_POST['document_type'];
    $allowed_types = ['valid_id_image', 'business_permit_image', 'gcash_qr_image'];
    
    if (in_array($doc_type, $allowed_types) && isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $file_name = time() . '_' . $doc_type . '_' . basename($_FILES['document_file']['name']);
        
        $target_dir = '../uploads/documents/';
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $target_file = $target_dir . $file_name;
        
        if (move_uploaded_file($_FILES['document_file']['tmp_name'], $target_file)) {
            // Delete old file if exists
            if (!empty($caterer[$doc_type]) && file_exists('../' . $caterer[$doc_type])) {
                unlink('../' . $caterer[$doc_type]);
            }
            
            $db_path = 'uploads/documents/' . $file_name;
            $update_query = "UPDATE store_details SET $doc_type = '$db_path' WHERE user_id = '$user_id'";
            if (mysqli_query($conn, $update_query)) {
                $success = "Document updated successfully!";
                $result = mysqli_query($conn, $query);
                $caterer = mysqli_fetch_assoc($result);
            } else {
                appLogError('Document update failed', mysqli_error($conn));
                $error = "Failed to update database with new document.";
            }
        } else {
            $error = "Failed to upload document file.";
        }
    } else {
        $error = "Please select a valid file to upload.";
    }
} elseif (isset($_POST['delete_cover_photo'])) {
    $cover_photo = $caterer['cover_photo'] ?? '';
    if (!empty($cover_photo) && file_exists('../' . $cover_photo)) {
        @unlink('../' . $cover_photo);
    }
    $delete_cover_query = "UPDATE store_details SET cover_photo = NULL WHERE user_id = '$user_id'";
    if (mysqli_query($conn, $delete_cover_query)) {
        $success = "Cover photo removed successfully!";
        $result = mysqli_query($conn, $query);
        $caterer = mysqli_fetch_assoc($result);
    } else {
        appLogError('Cover photo deletion failed', mysqli_error($conn));
        $error = "Unable to remove cover photo right now. Please try again.";
    }
} elseif (isset($_POST['update_profile'])) {
    $validation = validateProfileFields($_POST, (string) $caterer['address']);
    if (isset($validation['error'])) {
        $error = $validation['error'];
    } else {
    $first_name = mysqli_real_escape_string($conn, $_POST['first_name']);
    $last_name = mysqli_real_escape_string($conn, $_POST['last_name']);
    $middle_name = mysqli_real_escape_string($conn, $_POST['middle_name']);
        $suffix = isset($_POST['suffix']) ? mysqli_real_escape_string($conn, $_POST['suffix']) : '';
    $phone_number = mysqli_real_escape_string($conn, $_POST['phone_number']);
    $address = mysqli_real_escape_string($conn, $validation['address']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $business_name = mysqli_real_escape_string($conn, $_POST['business_name']);
    $gcash_number = mysqli_real_escape_string($conn, $_POST['gcash_number']);
    $business_open_time = mysqli_real_escape_string($conn, $_POST['business_open_time']);
    $business_close_time = mysqli_real_escape_string($conn, $_POST['business_close_time']);
    $store_terms_conditions = mysqli_real_escape_string($conn, trim($_POST['store_terms_conditions'] ?? ''));
    
    // Handle profile image upload
    $profile_image = $caterer['profile_image'];
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $file_name = time() . '_' . basename($_FILES['profile_image']['name']);
        $target_file = '../' . PROFILE_DIR . $file_name;
        
        // Ensure directory exists
        if (!file_exists('../' . PROFILE_DIR)) {
            mkdir('../' . PROFILE_DIR, 0777, true);
        }
        
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
            // Delete old profile image if exists
            if ($profile_image && file_exists('../' . $profile_image) && $profile_image !== 'uploads/logo.png') {
                unlink('../' . $profile_image);
            }
            $profile_image = PROFILE_DIR . $file_name;
        }
    }
    
    // Handle cover photo upload or removal
    $cover_photo = $caterer['cover_photo'] ?? '';
    $remove_cover_photo = isset($_POST['remove_cover_photo']) && $_POST['remove_cover_photo'] === '1';

    if (isset($_FILES['cover_photo']) && $_FILES['cover_photo']['error'] === UPLOAD_ERR_OK) {
        $cover_file_name = 'cover_' . time() . '_' . basename($_FILES['cover_photo']['name']);
        $cover_target_file = '../uploads/covers/' . $cover_file_name;
        
        // Ensure directory exists
        if (!file_exists('../uploads/covers')) {
            mkdir('../uploads/covers', 0777, true);
        }
        
        if (move_uploaded_file($_FILES['cover_photo']['tmp_name'], $cover_target_file)) {
            // Delete old cover photo if exists
            if (!empty($cover_photo) && file_exists('../' . $cover_photo)) {
                @unlink('../' . $cover_photo);
            }
            $cover_photo = 'uploads/covers/' . $cover_file_name;
        }
    } elseif ($remove_cover_photo) {
        if (!empty($cover_photo) && file_exists('../' . $cover_photo)) {
            @unlink('../' . $cover_photo);
        }
        $cover_photo = '';
    }
    
    // Update users table
    $update_user_query = "
        UPDATE users SET 
        first_name = '$first_name',
        last_name = '$last_name',
        middle_name = '$middle_name',
                                suffix = '$suffix',
        phone_number = '$phone_number',
        address = '$address',
        email = '$email',
        profile_image = '$profile_image'
        WHERE id = '$user_id'
    ";
    
    $facebook_link = mysqli_real_escape_string($conn, $_POST['facebook_link'] ?? '');
    $cover_photo_sql = !empty($cover_photo) ? "'" . mysqli_real_escape_string($conn, $cover_photo) . "'" : "NULL";
    $cover_position = mysqli_real_escape_string($conn, $_POST['cover_position'] ?? 'center');
    
    // Update store_details table
    $update_store_query = "
        UPDATE store_details SET 
        business_name = '$business_name',
        location = '$address',
        gcash_number = '$gcash_number',
        business_open_time = '$business_open_time',
        business_close_time = '$business_close_time',
        facebook_link = '$facebook_link',
        cover_photo = $cover_photo_sql,
        cover_position = '$cover_position',
        store_terms_conditions = '$store_terms_conditions'
        WHERE user_id = '$user_id'
    ";
    
    if (mysqli_query($conn, $update_user_query) && mysqli_query($conn, $update_store_query)) {
        $success = "Profile updated successfully!";
        // Refresh caterer data
        $result = mysqli_query($conn, $query);
        $caterer = mysqli_fetch_assoc($result);
    } else {
        appLogError('Caterer profile update failed', mysqli_error($conn));
        $error = "Unable to update your profile right now. Please try again.";
    }
}

}

// INCLUDE HEADER
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

        /* Profile Grid */
        .profile-grid {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
        }

        .profile-avatar {
            text-align: center;
        }

        .profile-avatar img {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid var(--accent-gold);
            box-shadow: 0 5px 15px rgba(212, 175, 55, 0.3);
            margin-bottom: 15px;
        }

        .profile-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-item {
            padding: 15px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 8px;
            border-left: 4px solid var(--accent-gold);
        }

        .info-item strong {
            display: block;
            color: var(--accent-gold);
            margin-bottom: 5px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Documents */
        .document-list {
            display: grid;
            gap: 15px;
        }

        .document-item {
            display: flex;
            align-items: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            transition: all 0.2s;
        }

        .document-item:hover {
            border-color: var(--accent-gold);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .document-icon {
            width: 50px;
            height: 50px;
            background: rgba(212, 175, 55, 0.1);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--accent-gold);
            font-size: 24px;
        }

        .document-details {
            flex: 1;
        }

        .document-details h4 {
            margin: 0 0 5px;
            font-size: 16px;
            color: var(--text-main);
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid transparent;
            background: transparent;
        }

        .status-uploaded { 
            color: var(--success);
            border-color: var(--success);
            background: rgba(46, 204, 113, 0.1);
        }
        .status-missing { 
            color: var(--danger);
            border-color: var(--danger);
            background: rgba(231, 76, 60, 0.1);
        }
        .status-not-uploaded { 
            color: var(--text-muted);
            border-color: var(--border-color);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--accent-blue);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-view {
            background: transparent;
            color: var(--text-main);
            border: 1px solid var(--border-color);
        }

        .btn-view:hover {
            background: rgba(255, 255, 255, 0.05);
            color: var(--accent-gold);
            border-color: var(--accent-gold);
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
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
            background-color: #111827;
            color: var(--text-main);
        }

        .form-control:focus {
            border-color: var(--accent-gold);
            outline: none;
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }

        .terms-preview {
            padding: 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.03);
            color: var(--text-main);
            line-height: 1.7;
            white-space: pre-wrap;
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
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow: auto;
            position: relative;
            padding: 20px;
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

        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
            .document-item {
                flex-direction: column;
                text-align: center;
            }
            .document-icon {
                margin-right: 0;
                margin-bottom: 10px;
            }
            .document-actions {
                margin-top: 15px;
                width: 100%;
            }
            .btn {
                width: 100%;
            }
        }

        /* Keep the profile aligned with the shared navigation breakpoint. */
        .main-content { padding: 32px; }
        .content-header {
            padding: 28px;
            background: radial-gradient(ellipse at top right, rgba(212,175,55,.16), transparent 65%), var(--card-bg);
            border-color: rgba(212,175,55,.25);
            border-radius: 20px;
        }
        .page-title h1 { margin: 0; font-size: clamp(24px, 3vw, 32px); letter-spacing: -.8px; }
        .page-title p { margin: 10px 0 0; line-height: 1.6; color: var(--text-muted); }
        .card { padding: 28px; border-radius: 20px; box-shadow: 0 12px 32px rgba(0,0,0,.14); }
        .card > h3 { font-size: 18px; gap: 12px; }
        .card > h3 i { color: var(--accent-gold); }
        .profile-grid { grid-template-columns: minmax(180px, 230px) minmax(0, 1fr); gap: 28px; }
        .profile-avatar {
            align-self: start;
            padding: 24px 16px;
            border-radius: 16px;
            border: 1px solid rgba(212,175,55,.18);
            background: linear-gradient(160deg, rgba(212,175,55,.10), rgba(255,255,255,.02));
        }
        .profile-avatar img { width: 120px; height: 120px; border-width: 3px; padding: 4px; background: #fff; }
        .profile-avatar h3 { display: block; border: 0; padding: 0; font-size: 22px; text-align: center; overflow-wrap: anywhere; }
        .profile-avatar p { line-height: 1.6; }
        .profile-info-grid, .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .info-item { min-width: 0; padding: 16px; line-height: 1.6; border-left-width: 3px; border-radius: 12px; overflow-wrap: anywhere; }
        .info-item strong { font-size: 11px; letter-spacing: .8px; }
        .document-item { gap: 16px; padding: 18px; border-radius: 14px; }
        .document-icon { flex-shrink: 0; margin: 0; }
        .document-details { min-width: 0; }
        .document-details h4 { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; line-height: 1.5; }
        .document-details p { line-height: 1.6; }
        .document-actions { display: flex; gap: 8px; flex-shrink: 0; }
        .document-actions .btn { margin-left: 0 !important; }
        .btn { min-height: 44px; border-radius: 10px; font-weight: 600; }
        .btn[type="submit"] { background: linear-gradient(135deg, #e4c45c, #d4af37); color: #17140b; }
        .form-group { min-width: 0; }
        .form-control { min-width: 0; max-width: 100%; min-height: 48px; font-size: 16px; border-radius: 10px; padding: 12px; background: rgba(0,0,0,.22); color-scheme: dark; }
        .form-group small { display: block; margin-top: 8px; line-height: 1.6; }
        .terms-preview { overflow-wrap: anywhere; }
        .modal { padding: 16px; }
        .modal-content { width: 100%; max-height: 90dvh; border-radius: 18px; padding: 24px; }
        .modal-content > h3 { padding-right: 40px; }
        .modal-close { width: 44px; height: 44px; top: 8px; right: 8px; }
        .btn:focus-visible, .modal-close:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 3px; }
        @media (max-width: 1200px) and (min-width: 993px) {
            .profile-grid { grid-template-columns: 1fr; }
            .document-item { flex-wrap: wrap; }
            .document-actions { width: 100%; }
        }
        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding: 78px 20px 32px; }
        }
        @media (max-width: 768px) {
            .profile-grid { grid-template-columns: 1fr; gap: 18px; }
            .profile-avatar { padding: 20px 16px; }
            .profile-avatar img { width: 100px; height: 100px; margin-bottom: 10px; }
            .document-item { flex-direction: row; flex-wrap: wrap; text-align: left; gap: 12px; }
            .document-details { flex: 1; }
            .document-icon { width: 42px; height: 42px; font-size: 20px; margin: 0; }
            .document-actions { margin-top: 4px; width: 100%; }
            .document-actions .btn { flex: 1; width: auto; }
            .btn { width: auto; }
        }
        @media (max-width: 576px) {
            .main-content { padding: 74px 14px calc(24px + env(safe-area-inset-bottom)); }
            .content-header { padding: 22px 20px; margin-bottom: 18px; border-radius: 16px; }
            .page-title p { font-size: 14px; }
            .card { padding: 20px 16px; margin-bottom: 18px; border-radius: 16px; }
            .card > h3 { font-size: 16px; margin-bottom: 18px; }
            .form-grid { grid-template-columns: minmax(0, 1fr); gap: 12px; }
            .profile-info-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .info-item {
                padding: 13px 10px;
                min-height: 88px;
                font-size: 13px;
                line-height: 1.5;
                border: 1px solid rgba(212,175,55,.16);
                border-top: 2px solid var(--accent-gold);
                background: linear-gradient(145deg, rgba(212,175,55,.07), rgba(255,255,255,.025));
            }
            .info-item strong { font-size: 10px; letter-spacing: .4px; margin-bottom: 8px; }
            .document-item { padding: 14px; }
            .document-details h4 { font-size: 14px; }
            .form-grid .form-group { margin-bottom: 6px; }
            .alert { align-items: flex-start; padding: 14px; line-height: 1.6; }
        }
        .profile-address-line {
            display: block;
            min-width: 0;
            max-width: 100%;
            white-space: normal;
            overflow-wrap: anywhere;
        }
        /* Quiet surfaces and generous typography keep profile details readable. */
        .profile-card { overflow: hidden; padding: 20px; }
        .profile-card > h3 {
            padding-bottom: 12px;
            margin-bottom: 16px;
            font-size: 16px;
            font-weight: 600;
            letter-spacing: -.3px;
        }
        .profile-card .profile-grid {
            grid-template-columns: minmax(160px, 200px) minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }
        .profile-card .profile-avatar {
            padding: 22px 14px;
            background: linear-gradient(155deg, #29271f, #1c1c1b 75%);
            border: 1px solid rgba(212,175,55,.16);
            border-radius: 18px;
        }
        .profile-card .profile-avatar img {
            width: 80px;
            height: 80px;
            border-width: 2px;
            box-shadow: 0 8px 24px rgba(0,0,0,.24);
            margin-bottom: 12px;
        }
        .profile-card .profile-avatar h3 { font-size: 18px; line-height: 1.3; letter-spacing: -.3px; }
        .profile-card .profile-avatar p { margin: 8px 0 0 !important; font-size: 11px !important; }
        .profile-card .profile-info-grid {
            gap: 1px;
            border: 1px solid rgba(255,255,255,.07);
            border-radius: 12px;
            overflow: hidden;
            background: rgba(255,255,255,.07);
        }
        .profile-card .info-item {
            background: var(--card-bg);
            border: 0;
            border-radius: 0;
            padding: 15px 16px;
            min-height: 78px;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.6;
            color: #eeede8;
            overflow-wrap: anywhere;
        }
        .profile-card .info-item strong {
            color: #a6a49c;
            font-size: 11px;
            font-weight: 400;
            text-transform: none;
            letter-spacing: .1px;
            margin-bottom: 6px;
        }
        .profile-card .info-item em { font-style: normal; }
        .profile-card .info-item:nth-child(7) > span {
            display: inline-block;
            font-size: 11px;
            letter-spacing: .4px;
        }
        .profile-card .info-item a { color: var(--accent-gold) !important; }
        @media (max-width: 1200px) {
            .profile-card .profile-grid { grid-template-columns: minmax(0, 1fr); gap: 16px; }
            .profile-card .profile-avatar { padding: 20px 16px; }
        }
        @media (max-width: 576px) {
            .profile-card { padding: 16px 14px; border-radius: 14px; }
            .profile-card > h3 { font-size: 15px; margin-bottom: 14px; padding-bottom: 10px; }
            .profile-card .profile-grid { gap: 10px; }
            .profile-card .profile-avatar { padding: 16px 12px; border-radius: 12px; }
            .profile-card .profile-avatar img { width: 68px; height: 68px; margin-bottom: 10px; }
            .profile-card .profile-avatar h3 { font-size: 18px; }
            .profile-card .profile-info-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; }
            .profile-card .info-item { min-height: 72px; padding: 12px 10px; font-size: 12px; }
            .profile-card .info-item strong { font-size: 10px; margin-bottom: 5px; }
        }
        @media (max-width: 359px) {
            .profile-card .profile-info-grid { grid-template-columns: minmax(0, 1fr); }
            .profile-card .info-item { min-height: 0; }
        }
        .profile-section-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 18px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--card-bg);
            margin-bottom: 18px;
        }
        .profile-section-actions label { font-size: 13px; font-weight: 600; white-space: nowrap; }
        .profile-section-select {
            width: min(100%, 300px);
            min-width: 0;
            min-height: 44px;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: #20201e;
            color: var(--text-main);
            font: inherit;
            font-size: 13px;
            cursor: pointer;
            color-scheme: dark;
        }
        .profile-section-select:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 3px; }
        .profile-section-panel[hidden] { display: none !important; }
        @media (max-width: 576px) {
            .profile-section-actions { flex-direction: column; align-items: stretch; gap: 10px; padding: 14px; }
            .profile-section-select { width: 100%; font-size: 12px; }
        }
        .profile-section-select, .profile-section-select option { font-size: 12px; }
        #profile-documents { padding: 20px; }
        #profile-documents > h3 {
            font-size: 16px;
            font-weight: 600;
            letter-spacing: -.2px;
            padding-bottom: 14px;
            margin-bottom: 4px;
        }
        #profile-documents .document-list { gap: 0; }
        #profile-documents .document-item {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            gap: 20px;
            padding: 18px 0;
            border: 0;
            border-bottom: 1px solid var(--border-color);
            border-radius: 0;
            background: transparent;
            text-align: left;
        }
        #profile-documents .document-item:last-child { border-bottom: 0; padding-bottom: 0; }
        #profile-documents .document-item:hover { transform: none; box-shadow: none; }
        #profile-documents .document-details h4 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            gap: 10px;
        }
        #profile-documents .status-badge {
            padding: 3px 8px;
            font-size: 9px;
            line-height: 1.4;
            letter-spacing: .3px;
            font-weight: 500;
            border-radius: 5px;
        }
        #profile-documents .document-actions { width: auto; margin: 0; gap: 8px; }
        #profile-documents .document-actions .btn {
            min-height: 40px;
            min-width: 76px;
            padding: 8px 14px;
            font-size: 12px;
            border-radius: 8px;
            font-weight: 500;
        }
        #profile-documents .document-actions .btn:last-child {
            color: var(--accent-gold);
            border-color: rgba(212,175,55,.28);
            background: rgba(212,175,55,.06);
        }
        @media (max-width: 768px) {
            #profile-documents { padding: 16px; }
            #profile-documents .document-item { flex-wrap: wrap; gap: 12px; padding: 16px 0; }
            #profile-documents .document-details { flex-basis: 100%; }
            #profile-documents .document-details h4 { justify-content: space-between; gap: 8px; font-size: 13px; }
            #profile-documents .document-actions { width: 100%; }
            #profile-documents .document-actions .btn { flex: 1; min-height: 44px; min-width: 0; }
        }
    </style>

<?php if ($is_store_closed_by_portal): ?>
    <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>ACCOUNT CLOSED</strong> - Your caterer account has been closed by the Portal Admin. 
                    You can view your profile but cannot make changes. Please contact the Portal Admin to restore your account.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success && !in_array($success, ['Profile updated successfully!', 'Caterer terms and conditions updated successfully!'], true)): ?>
            <div class="alert alert-success" id="successAlert">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $success; ?></div>
            </div>
            <script>
                setTimeout(function() {
                    var alertElement = document.getElementById('successAlert');
                    if (alertElement) {
                        alertElement.style.transition = 'opacity 0.5s ease';
                        alertElement.style.opacity = '0';
                        setTimeout(function() {
                            alertElement.style.display = 'none';
                        }, 500); // Wait for transition to finish
                    }
                }, 1000); // 1 second delay before fading out
            </script>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <!-- Caterer Information -->
        <div class="card profile-card">
            <h3>Caterer Information</h3>
            <div class="profile-grid">
                <div class="profile-avatar">
                    <img src="<?php 
                        if (!empty($caterer['profile_image'])) {
                            echo '../' . htmlspecialchars($caterer['profile_image']);
                        } else {
                            echo '../uploads/logo.png';
                        }
                    ?>" alt="Caterer Logo">
                    <h3 style="margin-top: 0; margin-bottom: 5px;"><?php echo htmlspecialchars($caterer['business_name']); ?></h3>
                    <p style="color: var(--text-muted); font-size: 13px; margin-top: 5px;">Member since: <?php echo date('M d, Y', strtotime($caterer['created_at'])); ?></p>
                </div>

                <div class="profile-info-grid">
                    <div class="info-item">
                        <strong>Owner Name</strong>
                        <?php echo htmlspecialchars(trim($caterer['first_name'] . ' ' . $caterer['middle_name'] . ' ' . $caterer['last_name'] . ' ' . $caterer['suffix'])); ?>
                    </div>
                    <div class="info-item">
                        <strong>Phone Number</strong>
                        <?php echo htmlspecialchars($caterer['phone_number']); ?>
                    </div>
                    <div class="info-item">
                        <strong>Address</strong>
                        <span class="profile-address-line" tabindex="0" aria-label="Address"><?php echo htmlspecialchars($caterer['address']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>Username</strong>
                        <?php echo htmlspecialchars($caterer['username']); ?>
                    </div>
                    <div class="info-item">
                        <strong>Email Address</strong>
                        <?php echo !empty($caterer['email']) ? htmlspecialchars($caterer['email']) : '<em style="color: var(--text-muted);">Not set</em>'; ?>
                    </div>
                    <div class="info-item">
                        <strong>GCash Number</strong>
                        <?php echo !empty($caterer['gcash_number']) ? htmlspecialchars($caterer['gcash_number']) : 'Not set'; ?>
                    </div>
                    <div class="info-item">
                        <strong>Caterer Status</strong>
                        <?php $profile_store_is_available = isStoreAvailableNow($caterer['is_open'] ?? 0, $caterer['business_open_time'] ?? null, $caterer['business_close_time'] ?? null, $caterer['auto_schedule_enabled'] ?? 0); ?>
                        <span style="color: <?php echo $profile_store_is_available ? 'var(--success)' : 'var(--danger)'; ?>; font-weight: bold;">
                            <?php echo $profile_store_is_available ? 'OPEN' : 'CLOSED'; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <strong>Facebook Page</strong>
                        <?php if (!empty($caterer['facebook_link'])): ?>
                            <a href="<?php echo htmlspecialchars($caterer['facebook_link']); ?>" target="_blank" style="color: var(--accent-blue); text-decoration: none;">
                                Visit Page
                            </a>
                        <?php else: ?>
                            <span style="color: var(--text-muted);">Not set</span>
                        <?php endif; ?>
                    </div>
                    <div class="info-item">
                        <strong>Cover Photo</strong>
                        <?php if (!empty($caterer['cover_photo']) && file_exists('../' . $caterer['cover_photo'])): ?>
                            <span style="color: var(--success);">Uploaded</span>
                        <?php else: ?>
                            <span style="color: var(--text-muted);">Not set</span>
                        <?php endif; ?>
                    </div>
                    <div class="info-item">
                        <strong>Business Hours</strong>
                        <?php 
                        if (!empty($caterer['business_open_time']) && !empty($caterer['business_close_time'])) {
                            echo date('h:i A', strtotime($caterer['business_open_time'])) . ' - ' . date('h:i A', strtotime($caterer['business_close_time']));
                        } else {
                            echo '<span style="color: var(--text-muted);">Not set</span>';
                        }
                        ?>
                    </div>
                </div>
            </div>


            
            <!-- Cover Photo Preview -->
            <?php if (!empty($caterer['cover_photo']) && file_exists('../' . $caterer['cover_photo'])): ?>
            <div class="profile-cover-section" style="margin-top: 20px;">
                <div class="profile-cover-preview" style="border-radius: 12px; overflow: hidden; height: 150px; position: relative; border: 1px solid var(--border-color); cursor: pointer;" onclick="viewDocument('../<?php echo htmlspecialchars($caterer['cover_photo']); ?>', 'Cover Photo Preview')" title="Click to view full whole cover photo">
                    <img src="../<?php echo htmlspecialchars($caterer['cover_photo']); ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
                    <div style="position: absolute; bottom: 8px; right: 8px; background: rgba(0,0,0,0.7); color: #fff; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; display: flex; align-items: center; gap: 5px;">
                        <i class="fas fa-expand-alt"></i> View Full Cover Photo
                    </div>
                </div>
                <?php if (!$is_store_closed_by_portal): ?>
                <div class="profile-cover-actions">
                    <button type="button" class="profile-cover-delete" aria-label="Delete cover photo" title="Delete cover photo" onclick="confirmDeleteCover()"><i class="fas fa-trash-alt" aria-hidden="true"></i></button>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Caterer Documents -->
        <div class="profile-section-actions" aria-label="Profile sections">
            <label for="profile-section-select">Manage Profile</label>
            <select id="profile-section-select" class="profile-section-select">
                <option value="" hidden>Overview</option>
                <?php if (!$is_store_closed_by_portal): ?>
                <option value="profile-edit">Edit Profile Information</option>
                <?php endif; ?>

                <?php if (!$is_store_closed_by_portal): ?>
                <option value="profile-terms">Caterer Terms and Conditions</option>
                <?php endif; ?>
            </select>
        </div>
        <div class="card profile-section-panel" id="profile-documents" hidden>
            <h3>Caterer Documents</h3>
            <div class="document-list">
                <!-- Valid ID -->
                <div class="document-item">
                                        <div class="document-details">
                        <h4>Valid ID Document 
                            <?php if (!empty($caterer['valid_id_image']) && file_exists('../' . $caterer['valid_id_image'])): ?>
                            <?php else: ?>
                                <span class="status-badge status-missing">Missing</span>
                            <?php endif; ?>
                        </h4>
                                            </div>
                    <div class="document-actions">
                        <?php if (!empty($caterer['valid_id_image']) && file_exists('../' . $caterer['valid_id_image'])): ?>
                            <button class="btn btn-view" onclick="viewDocument('<?php echo '../' . htmlspecialchars($caterer['valid_id_image']); ?>', 'Valid ID')">
                                View
                            </button>
                            <button class="btn btn-view" style="margin-left: 5px;" onclick="openUploadModal('valid_id_image', 'Valid ID')">
                                Update
                            </button>
                        <?php else: ?>
                            <button class="btn btn-view" onclick="openUploadModal('valid_id_image', 'Valid ID')">
                                Upload
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Business Permit -->
                <div class="document-item">
                                        <div class="document-details">
                        <h4>Business Permit 
                            <?php if (!empty($caterer['business_permit_image']) && file_exists('../' . $caterer['business_permit_image'])): ?>
                            <?php else: ?>
                                <span class="status-badge status-missing">Missing</span>
                            <?php endif; ?>
                        </h4>
                                            </div>
                    <div class="document-actions">
                        <?php if (!empty($caterer['business_permit_image']) && file_exists('../' . $caterer['business_permit_image'])): ?>
                            <button class="btn btn-view" onclick="viewDocument('<?php echo '../' . htmlspecialchars($caterer['business_permit_image']); ?>', 'Business Permit')">
                                View
                            </button>
                            <button class="btn btn-view" style="margin-left: 5px;" onclick="openUploadModal('business_permit_image', 'Business Permit')">
                                Update
                            </button>
                        <?php else: ?>
                            <button class="btn btn-view" onclick="openUploadModal('business_permit_image', 'Business Permit')">
                                Upload
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- GCash QR -->
                <div class="document-item">
                                        <div class="document-details">
                        <h4>GCash QR Code 
                            <?php if (!empty($caterer['gcash_qr_image']) && file_exists('../' . $caterer['gcash_qr_image'])): ?>
                            <?php else: ?>
                                <span class="status-badge status-not-uploaded">Not Uploaded</span>
                            <?php endif; ?>
                        </h4>
                                            </div>
                    <div class="document-actions">
                        <?php if (!empty($caterer['gcash_qr_image']) && file_exists('../' . $caterer['gcash_qr_image'])): ?>
                            <button class="btn btn-view" onclick="viewDocument('<?php echo '../' . htmlspecialchars($caterer['gcash_qr_image']); ?>', 'GCash QR')">
                                View
                            </button>
                            <button class="btn btn-view" style="margin-left: 5px;" onclick="openUploadModal('gcash_qr_image', 'GCash QR')">
                                Update
                            </button>
                        <?php else: ?>
                            <button class="btn btn-view" onclick="openUploadModal('gcash_qr_image', 'GCash QR')">
                                Upload
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$is_store_closed_by_portal): ?>
        <div class="card profile-section-panel" id="profile-terms" hidden>
            <h3>Caterer Terms and Conditions</h3>
            <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                <div class="form-group">
                    <label>Custom Terms for Your Caterer</label>
                    <textarea name="store_terms_conditions" class="form-control" rows="10" placeholder="Type your own caterer rules here. Example: downpayment policy, cancellation policy, refund policy, rescheduling policy, damaged items policy, late payment policy, and other special conditions."><?php echo htmlspecialchars($caterer['store_terms_conditions'] ?? ''); ?></textarea>
                </div>

                <button type="submit" name="save_store_terms" class="btn" style="width: 100%; padding: 15px;">
                    <i class="fas fa-save"></i> Save Caterer Terms
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Edit Profile Form -->
        <?php if (!$is_store_closed_by_portal): ?>
        <div class="card profile-section-panel" id="profile-edit" hidden>
            <h3>Edit Profile Information</h3>
            <form method="POST" action="" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Business Name</label>
                        <input type="text" name="business_name" class="form-control" value="<?php echo htmlspecialchars($caterer['business_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email Address (Gmail)</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($caterer['email'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" maxlength="250" pattern="[\p{L}\p{M} .’\x27\-]{1,250}" title="Names cannot contain numbers." class="form-control" value="<?php echo htmlspecialchars($caterer['first_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" maxlength="250" pattern="[\p{L}\p{M} .’\x27\-]{1,250}" title="Names cannot contain numbers." class="form-control" value="<?php echo htmlspecialchars($caterer['middle_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" maxlength="250" pattern="[\p{L}\p{M} .’\x27\-]{1,250}" title="Names cannot contain numbers." class="form-control" value="<?php echo htmlspecialchars($caterer['last_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone_number" inputmode="numeric" minlength="11" maxlength="11" pattern="[0-9]{11}" title="Enter exactly 11 digits." class="form-control" value="<?php echo htmlspecialchars($caterer['phone_number']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>GCash Number</label>
                        <input type="tel" name="gcash_number" class="form-control" value="<?php echo htmlspecialchars($caterer['gcash_number']); ?>" placeholder="09XX-XXX-XXXX">
                    </div>
                    <div class="form-group">
                        <label>Business Hours</label>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="time" name="business_open_time" class="form-control" value="<?php echo htmlspecialchars($caterer['business_open_time'] ?? '08:00'); ?>" style="flex: 1;">
                            <span>to</span>
                            <input type="time" name="business_close_time" class="form-control" value="<?php echo htmlspecialchars($caterer['business_close_time'] ?? '17:00'); ?>" style="flex: 1;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Facebook Page Link</label>
                        <input type="url" name="facebook_link" class="form-control" value="<?php echo htmlspecialchars($caterer['facebook_link'] ?? ''); ?>" placeholder="https://facebook.com/yourpage">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Address</label>
                    <?php renderProfileAddressPicker((string) $caterer['address']); ?>
                </div>

                <div class="form-group">
                    <label>Profile Image</label>
                    <input type="file" name="profile_image" class="form-control" accept="image/*">
                </div>
                
                <div class="form-group">
                    <label>Cover Photo (Caterer Banner)</label>
                    <input type="file" name="cover_photo" class="form-control" accept="image/*">
                    
                    <?php if (!empty($caterer['cover_photo']) && file_exists('../' . $caterer['cover_photo'])): ?>
                        <div style="margin-top: 15px; border-radius: 8px; overflow: hidden; height: 120px; background: url('../<?php echo htmlspecialchars($caterer['cover_photo']); ?>') center/cover no-repeat; border: 1px solid var(--border-color); position: relative;">
                        </div>
                        <div style="margin-top: 10px; display: flex; align-items: center; justify-content: space-between; background: #c9d2c1; border: 1px solid #96a38e; border-radius: 8px; padding: 10px 14px;">
                            <label style="display: flex; align-items: center; gap: 8px; margin: 0; color: #20372b; font-size: 13px; cursor: pointer; font-weight: 500;">
                                <input type="checkbox" name="remove_cover_photo" value="1" style="accent-color: #173d32; width: 16px; height: 16px; cursor: pointer;">
                                <span>Remove current cover photo upon updating</span>
                            </label>
                            <button type="button" onclick="confirmDeleteCover()" aria-label="Delete cover photo now" title="Delete cover photo now" style="background: none; border: none; color: #20372b; cursor: pointer; font-size: 16px; padding: 0; width: 44px; height: 44px; flex-shrink: 0;">
                                <i class="fas fa-trash-alt" aria-hidden="true"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <button type="submit" name="update_profile" class="btn" style="width: 100%; padding: 15px;">
                    <i class="fas fa-save"></i> Update Profile
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- Hidden Form for Direct Cover Photo Deletion -->
    <form id="deleteCoverForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
        <input type="hidden" name="delete_cover_photo" value="1">
    </form>

    <!-- Document Viewer Modal -->
    <div id="documentModal" class="modal">
        <div class="modal-content">
            <button type="button" class="modal-close" onclick="closeModal()" aria-label="Close document preview">&times;</button>
            <h3 id="modalTitle" style="margin-top: 0;">Document Viewer</h3>
            <div style="text-align: center; margin-top: 20px;">
                <img id="modalImage" src="" alt="Document" style="max-width: 100%; max-height: 60vh; border-radius: 8px;">
            </div>
        </div>
    </div>

    <!-- Upload Document Modal -->
    <div id="uploadDocumentModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <button class="modal-close" onclick="closeUploadModal()">&times;</button>
            <h3 id="uploadModalTitle" style="margin-top: 0;">Upload Document</h3>
            <form method="POST" action="" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                <input type="hidden" name="document_type" id="documentTypeInput">
                <div class="form-group" style="margin-top: 20px;">
                    <label>Select File</label>
                    <input type="file" name="document_file" class="form-control" accept="image/*" required>
                </div>
                <button type="submit" name="upload_document" class="btn" style="width: 100%; padding: 15px; margin-top: 20px;">
                    <i class="fas fa-upload"></i> Upload Document
                </button>
            </form>
        </div>
    </div>

    <script>
        var profileSectionSelect = document.getElementById('profile-section-select');
        const profileModalKey = 'profile-sections-<?php echo (int) ($_SESSION['user_id'] ?? 0); ?>';
        let profileSaved = {};
        try { profileSaved = JSON.parse(sessionStorage.getItem(profileModalKey) || '{}'); } catch (_) {}
        const profilePanels = new Map();
        document.querySelectorAll('.profile-section-panel:not(#profile-documents)').forEach(panel => {
            const title = panel.querySelector('h3')?.textContent.trim() || 'Profile';
            const overlay = document.createElement('div');
            overlay.className = 'profile-section-overlay'; overlay.hidden = true;
            const frame = document.createElement('section');frame.className = 'profile-section-frame';
            frame.setAttribute('role','dialog');frame.setAttribute('aria-modal','true');frame.setAttribute('aria-label',title);
            const header = document.createElement('header');const heading = document.createElement('h2');heading.textContent = title;
            const close = document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Close '+title);
            close.addEventListener('click',()=>setProfileSection(''));
            header.append(heading,close);
            panel.hidden = false; panel.classList.add('profile-modal-body');
            const oldHeading = panel.querySelector(':scope > h3');if(oldHeading)oldHeading.remove();
            frame.append(header,panel);overlay.append(frame);document.body.append(overlay);
            profilePanels.set(panel.id,{overlay,panel,close});
            if(profileSaved.scrolls?.[panel.id])panel.scrollTop=profileSaved.scrolls[panel.id];
        });
        function saveProfileModal() {
            const scrolls={};profilePanels.forEach(({panel},id)=>scrolls[id]=panel.scrollTop);
            try {sessionStorage.setItem(profileModalKey,JSON.stringify({section:profileSectionSelect.value,scrolls}));}catch(_){}
        }
        function restoreProfileSection() {
            const requested = new URL(location.href).searchParams.get('section') || '';
            const id = profilePanels.has(requested) ? requested : '';
            profileSectionSelect.value=id;
            profilePanels.forEach(({overlay},key)=>overlay.hidden=key!==id);
            if(id)profilePanels.get(id).close.focus();
            saveProfileModal();
        }
        function setProfileSection(id) {
            const url=new URL(location.href);
            if(id)url.searchParams.set('section',id);else url.searchParams.delete('section');
            history.replaceState(null,'',url);restoreProfileSection();
            if(!id)profileSectionSelect.focus();
        }
        profileSectionSelect.addEventListener('change',()=>setProfileSection(profileSectionSelect.value));
        window.addEventListener('popstate',restoreProfileSection);
        window.addEventListener('pagehide',saveProfileModal);
        document.addEventListener('keydown',event=>{
            const current=profilePanels.get(profileSectionSelect.value);if(!current)return;
            if(event.key==='Escape'){event.preventDefault();return;}
            if(event.key==='Tab'){
                const controls=Array.from(current.overlay.querySelectorAll('button,input,select,textarea,a[href]')).filter(el=>!el.disabled&&el.getClientRects().length);
                const first=controls[0],last=controls[controls.length-1];
                if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
                else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
            }
        });
        if(!new URL(location.href).searchParams.has('section')&&profileSaved.section)setProfileSection(profileSaved.section);
        else restoreProfileSection();
        requestAnimationFrame(()=>profilePanels.forEach(({panel},id)=>panel.scrollTop=profileSaved.scrolls?.[id]||0));
        function confirmDeleteCover() {
            if (confirm("Are you sure you want to delete your cover photo? Your caterer page will use the default banner instead.")) {
                document.getElementById('deleteCoverForm').submit();
            }
        }

        function viewDocument(imageUrl, title) {
            document.getElementById('modalImage').src = imageUrl;
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('documentModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('documentModal').style.display = 'none';
        }
        
        function openUploadModal(docType, title) {
            document.getElementById('documentTypeInput').value = docType;
            document.getElementById('uploadModalTitle').textContent = 'Upload ' + title;
            document.getElementById('uploadDocumentModal').style.display = 'flex';
        }

        function closeUploadModal() {
            document.getElementById('uploadDocumentModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('uploadDocumentModal')) {
                closeUploadModal();
            }
        }
    </script>

<style>
@media screen {
    body.bounded-caterer-page .caterer-workspace .profile-card {overflow:visible;}
    body.bounded-caterer-page .caterer-workspace .profile-card > h3 {
        position:sticky;
        top:-16px;
        z-index:10;
        background:#d4dbcd;
        margin-top:0;
        padding:14px 0;
        border-bottom:1px solid #96a38e;
        box-shadow:0 -16px 0 0 #d4dbcd;
    }
}
@media screen and (max-width:992px) {body.bounded-caterer-page .caterer-workspace .profile-card > h3 {top:-12px;box-shadow:0 -12px 0 #d4dbcd;}}
@media screen and (max-width:600px) {body.bounded-caterer-page .caterer-workspace .profile-card > h3 {top:-10px;padding:12px 0;box-shadow:0 -10px 0 #d4dbcd;}}
</style>
<style>
@media screen {
    body.bounded-caterer-page .caterer-workspace:has(.profile-card) {display:flex;flex-direction:column;overflow:hidden;scrollbar-gutter:auto;min-height:0;}
    .profile-fixed-heading {flex:0 0 auto;margin:0 0 12px;padding:8px 0 14px;border-bottom:1px solid #96a38e;font-size:16px;color:#20372b;}
    .profile-content-scroll {flex:1 1 0;min-height:0;min-width:0;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#84977e #d4dbcd;padding-right:6px;}
    body.bounded-caterer-page .caterer-workspace .profile-content-scroll > .profile-card {padding-top:0;}
}
</style>
<script>
(() => {
    const workspace = document.querySelector('.caterer-workspace');
    const card = workspace?.querySelector('.profile-card');
    const heading = card?.querySelector(':scope > h3');
    if (!workspace || !heading) return;
    const scroll = document.createElement('div');
    scroll.className = 'profile-content-scroll';
    scroll.setAttribute('role','region');
    scroll.setAttribute('aria-label','Caterer profile details');
    scroll.tabIndex = 0;
    heading.className = 'profile-fixed-heading';
    const nodes = Array.from(workspace.children).filter(el => !el.matches('script, style, link, .modal'));
    workspace.prepend(heading, scroll);
    nodes.forEach(el => scroll.append(el));
    const select = document.getElementById('profile-section-select');
    if (select) {
        const oldActions = select.closest('.profile-section-actions');
        const toolbar = document.createElement('div');
        toolbar.className = 'profile-heading-toolbar';
        heading.before(toolbar);
        toolbar.append(heading, select);
        select.setAttribute('aria-label', 'Profile section');
        oldActions?.remove();
    }

})();
</script>
<style>
@media screen {
    .profile-heading-toolbar {display:flex;align-items:center;justify-content:space-between;gap:14px;flex:0 0 auto;padding:0 0 12px;margin-bottom:12px;border-bottom:1px solid #96a38e;min-width:0;}
    .profile-heading-toolbar .profile-fixed-heading {margin:0;padding:0;border:0;min-width:0;line-height:1.4;}
    .profile-heading-toolbar .profile-section-select {width:260px;max-width:45%;min-width:0;flex-shrink:0;min-height:42px;background:#c9d2c1;color:#20372b;color-scheme:light;}
}
@media screen and (max-width:600px) {
    .profile-heading-toolbar {gap:8px;padding-bottom:10px;margin-bottom:10px;}
    .profile-heading-toolbar .profile-fixed-heading {font-size:14px;}
    .profile-heading-toolbar .profile-section-select {width:46%;max-width:46%;font-size:12px;padding:8px 6px;}
}
</style>
<style>
@media screen {
    body.bounded-caterer-page > .main-content:has(.profile-content-scroll) {padding:6px !important;}
    body.bounded-caterer-page .caterer-workspace:has(.profile-content-scroll) {padding:6px !important;scrollbar-gutter:auto;}
    .profile-heading-toolbar {width:100%;box-sizing:border-box;margin-bottom:8px;padding-bottom:10px;}
    .profile-content-scroll {width:100%;box-sizing:border-box;padding-right:0;scrollbar-gutter:auto;}
    body.bounded-caterer-page .caterer-workspace .profile-content-scroll > .profile-card {
        width:100%;box-sizing:border-box;padding:0 0 8px;margin:0;
        border:0;border-radius:0;box-shadow:none;background:transparent;
    }
}
@media screen and (max-width:992px) {
    body.bounded-caterer-page > .main-content:has(.profile-content-scroll) {padding:68px 6px 6px !important;}
}
</style>
<style>
@media screen {
    body.bounded-caterer-page .caterer-workspace .profile-content-scroll > .profile-card {
        display:flex;flex-direction:column;min-height:100%;padding-bottom:0;
    }
    .profile-content-scroll .profile-card > .profile-grid {flex:0 0 auto;}
    .profile-cover-section {display:flex;flex-direction:column;flex:1 0 auto;min-height:210px;}
    .profile-cover-section .profile-cover-preview {flex:1 0 150px;height:auto !important;min-height:150px;}
    .profile-cover-preview > img {position:absolute;inset:0;}
    .profile-cover-section > div:last-child:not(.profile-cover-preview) {flex:0 0 auto;}
}
</style>
<style>
@media screen {
    .profile-cover-section .profile-cover-actions {order:-1;display:flex;justify-content:flex-end;margin:0 0 6px;flex:0 0 auto;}
    .profile-cover-actions .profile-cover-delete {display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border:0;background:transparent;color:#20372b;font-size:16px;cursor:pointer;padding:0;}
    .profile-cover-delete:focus-visible {outline:2px solid #708b6b;outline-offset:2px;}
}
</style>
<style>
.profile-section-overlay[hidden]{display:none!important;}
.profile-section-overlay {position:fixed;inset:0;z-index:9000;display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;background:rgb(12 32 25 / 65%);backdrop-filter:blur(4px);}
.profile-section-frame {display:flex;flex-direction:column;width:min(820px,100%);height:min(580px,calc(100dvh - 48px));min-width:0;overflow:hidden;background:#d4dbcd;color:#20372b;border:1px solid #96a38e;border-radius:16px;box-shadow:0 24px 64px #0c201940;}
.profile-section-frame > header {display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 18px;border-bottom:1px solid #96a38e;flex:0 0 auto;}
.profile-section-frame h2 {font-size:17px;margin:0;line-height:1.4;}
.profile-section-frame > header button {width:44px;height:44px;flex-shrink:0;border:0;background:transparent;color:#20372b;font-size:26px;cursor:pointer;}
.profile-section-frame .profile-modal-body {flex:1 1 0;min-height:0;overflow:auto;overscroll-behavior:contain;scrollbar-width:thin;padding:18px!important;margin:0;border:0;border-radius:0;box-shadow:none;background:#d4dbcd!important;box-sizing:border-box;}
.profile-section-frame .form-control {box-sizing:border-box;width:100%;max-width:100%;min-width:0;background:#c9d2c1;color:#20372b;border-color:#96a38e;}
.profile-section-frame .form-grid {min-width:0;}
#documentModal,#uploadDocumentModal {z-index:10000;}
@media(max-width:600px){.profile-section-overlay{padding:12px;}.profile-section-frame{height:min(580px,calc(100dvh - 24px));}.profile-section-frame>header{padding:10px 14px;}.profile-section-frame h2{font-size:15px;}.profile-section-frame .profile-modal-body{padding:14px!important;}.profile-section-frame .form-grid{grid-template-columns:minmax(0,1fr);}.profile-section-frame .form-control{font-size:16px;}}
</style>
<style>
@media screen {
    .profile-content-scroll .profile-card > .profile-grid.profile-overview-grid {display:grid;grid-template-columns:200px minmax(0,1fr);gap:14px;align-items:stretch;}
    .profile-overview-grid > .profile-avatar {grid-column:1;grid-row:1;margin:0;align-self:stretch;}
    .profile-overview-grid > .profile-cover-section {grid-column:2;grid-row:1;margin:0!important;min-height:200px;position:relative;}
    .profile-overview-grid > .profile-info-grid {grid-column:1 / -1;grid-row:2;}
    .profile-overview-grid .profile-cover-actions {position:absolute;top:6px;right:6px;z-index:2;margin:0;}
    .profile-overview-grid .profile-cover-delete {color:#f0f1e6;text-shadow:0 1px 4px #173d32;}
    .profile-overview-grid .profile-cover-preview {min-height:200px;}
}
@media screen and (max-width:600px) {
    .profile-content-scroll .profile-card > .profile-grid.profile-overview-grid {grid-template-columns:minmax(0,1fr);gap:12px;}
    .profile-overview-grid > .profile-avatar {grid-column:1;grid-row:1;}
    .profile-overview-grid > .profile-cover-section {grid-column:1;grid-row:2;min-height:180px;}
    .profile-overview-grid > .profile-info-grid {grid-column:1;grid-row:3;}
    .profile-overview-grid .profile-cover-preview {min-height:180px;}
}
</style>
<script>
(() => {
    const card=document.querySelector('.profile-content-scroll .profile-card');
    const grid=card?.querySelector(':scope > .profile-grid');
    const cover=card?.querySelector(':scope > .profile-cover-section');
    if(grid&&cover){grid.classList.add('profile-overview-grid');grid.append(cover);}
})();
</script>
<script>
(() => {
    const documents=document.getElementById('profile-documents');
    const info=document.querySelector('.profile-info-grid');
    if(documents&&info){documents.hidden=false;documents.classList.remove('profile-section-panel');documents.classList.add('profile-inline-documents');info.after(documents);}
})();
</script>
<style>
@media screen {
    body.bounded-caterer-page .caterer-workspace .profile-overview-grid > .profile-info-grid {padding:12px;gap:10px 20px;}
    body.bounded-caterer-page .caterer-workspace .profile-info-grid .info-item {padding:2px 0;font-size:13px;min-height:0;line-height:1.45;}
    body.bounded-caterer-page .caterer-workspace .profile-info-grid .info-item strong {font-size:11px;margin-bottom:3px;}
    .profile-overview-grid > .profile-inline-documents {grid-column:1 / -1;grid-row:3;}
    body.bounded-caterer-page .caterer-workspace #profile-documents.profile-inline-documents {padding:12px;margin:0;}
    #profile-documents.profile-inline-documents > h3 {font-size:15px;padding-bottom:8px;margin-bottom:4px;}
    #profile-documents.profile-inline-documents .document-item {padding:8px 0;gap:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:nowrap;}
    #profile-documents.profile-inline-documents .document-details {flex:1;min-width:0;}
    #profile-documents.profile-inline-documents .document-details h4 {margin:0;font-size:13px;line-height:1.4;}
    #profile-documents.profile-inline-documents .document-actions {display:flex;flex:0 0 auto;gap:6px;width:auto;}
    #profile-documents.profile-inline-documents .document-actions .btn {min-height:36px;font-size:12px;padding:6px 10px;margin:0!important;}
}
@media(max-width:600px){
    .profile-overview-grid > .profile-inline-documents {grid-row:4;}
    #profile-documents.profile-inline-documents .document-details {flex-basis:auto;}
    #profile-documents.profile-inline-documents .document-actions .btn {min-height:40px;}
}
</style>
<style>
@media screen {
    body.bounded-caterer-page .caterer-workspace #profile-documents.profile-inline-documents .document-actions {gap:8px;align-items:center;}
    body.bounded-caterer-page .caterer-workspace #profile-documents.profile-inline-documents .document-actions .btn {
        display:inline-flex;align-items:center;justify-content:center;
        width:76px;min-width:76px;height:38px;min-height:38px;
        padding:6px 12px;margin:0!important;
        background:#c9d2c1!important;color:#20372b!important;
        border:1px solid #96a38e!important;border-radius:8px;
        box-shadow:none;font-size:12px;font-weight:500;line-height:1.3;
    }
    body.bounded-caterer-page .caterer-workspace #profile-documents.profile-inline-documents .document-actions .btn:hover {background:#b7c8ae!important;}
}
@media(max-width:600px){body.bounded-caterer-page .caterer-workspace #profile-documents.profile-inline-documents .document-actions .btn {width:64px;min-width:64px;min-height:40px;height:40px;padding:6px 8px;}}
</style>
<script>
(() => {
    const info=document.querySelector('.profile-overview-grid > .profile-info-grid');
    const documents=document.getElementById('profile-documents');
    if(!info||!documents)return;
    const combined=document.createElement('div');combined.className='profile-details-documents';
    info.before(combined);combined.append(info,documents);
})();
</script>
<style>
@media screen {
    .profile-overview-grid > .profile-details-documents {grid-column:1 / -1;grid-row:2;min-width:0;border:1px solid #96a38e;border-radius:10px;background:#c9d2c1;padding:12px;box-sizing:border-box;}
    body.bounded-caterer-page .caterer-workspace .profile-details-documents > .profile-info-grid {padding:0 0 12px;border:0;border-radius:0;box-shadow:none;background:transparent!important;gap:10px 20px;}
    body.bounded-caterer-page .caterer-workspace .profile-details-documents > #profile-documents.profile-inline-documents {margin:0;padding:12px 0 0;border:0;border-top:1px solid #96a38e;border-radius:0;background:transparent!important;box-shadow:none;}
}
@media(max-width:600px){.profile-overview-grid > .profile-details-documents {grid-row:3;padding:10px;}}
</style>
<style>
/* Consistent sage typography for profile editing and caterer terms. */
@media screen {
    .profile-section-frame {color:#20372b; font-family:inherit;}
    .profile-section-frame > header {background:#c9d5c1;border-color:#96a38e;}
    .profile-section-frame > header h2 {color:#173d32;font-size:17px;font-weight:650;letter-spacing:-.2px;}
    .profile-section-frame .profile-modal-body label {display:block;color:#425642;font-size:13px;font-weight:600;line-height:1.5;margin-bottom:6px;}
    .profile-section-frame .profile-modal-body .form-group {margin-bottom:16px;min-width:0;}
    .profile-section-frame .profile-modal-body .form-grid {gap:0 16px;}
    .profile-section-frame .profile-modal-body .form-control {
        background:#e1e7db!important;color:#20372b!important;border:1px solid #a0af96;
        border-radius:9px;min-height:44px;padding:10px 12px;font:inherit;font-size:14px;line-height:1.5;color-scheme:light;
    }
    .profile-section-frame .profile-modal-body .form-control::placeholder {color:#64735d;opacity:1;font-size:13px;}
    .profile-section-frame .profile-modal-body .form-control:focus {outline:2px solid #708b6b;outline-offset:1px;border-color:#708b6b;box-shadow:none;}
    .profile-section-frame .profile-modal-body textarea.form-control {line-height:1.7;resize:vertical;}
    .profile-section-frame .profile-modal-body button[type="submit"] {
        background:#173d32!important;color:#f0f1e6!important;border:1px solid #426052!important;
        border-radius:9px;min-height:46px;font-size:14px;font-weight:600;box-shadow:none;
    }
    .profile-section-frame .profile-modal-body button[type="submit"] * {color:inherit!important;}
    .profile-section-frame .profile-modal-body button[type="submit"]:hover {background:#2b5544!important;}
}
@media(max-width:600px){
    .profile-section-frame > header h2 {font-size:15px;}
    .profile-section-frame .profile-modal-body label {font-size:12px;}
    .profile-section-frame .profile-modal-body .form-control {font-size:16px;}
    .profile-section-frame .profile-modal-body .form-group {margin-bottom:14px;}
}
</style>
<style>
@media screen {
    .profile-section-frame #profile-terms.profile-modal-body {display:flex;flex-direction:column;overflow:hidden;}
    .profile-section-frame #profile-terms > form {display:flex;flex-direction:column;flex:1 1 0;min-height:0;gap:14px;}
    .profile-section-frame #profile-terms .form-group {display:flex;flex-direction:column;flex:1 1 0;min-height:0;margin:0;}
    .profile-section-frame #profile-terms .form-group > label {flex:0 0 auto;}
    .profile-section-frame #profile-terms textarea.form-control {flex:1 1 0;min-height:80px;height:0;resize:none;overflow-y:auto;}
    .profile-section-frame #profile-terms button[name="save_store_terms"] {flex:0 0 auto;margin-top:auto;}
}
</style>
<script>
(() => {
    const form=document.querySelector('#profile-edit > form');
    const button=form?.querySelector('button[name="update_profile"]');
    if(!form||!button)return;
    const fields=document.createElement('div');fields.className='profile-edit-fields';
    const footer=document.createElement('div');footer.className='profile-edit-footer';
    Array.from(form.childNodes).forEach(node=>{if(node!==button)fields.append(node);});
    footer.append(button);form.append(fields,footer);
})();
</script>
<style>
@media screen {
    .profile-section-frame #profile-edit.profile-modal-body {display:flex;flex-direction:column;overflow:hidden;padding:0!important;}
    .profile-section-frame #profile-edit > form {display:flex;flex-direction:column;flex:1 1 0;min-height:0;margin:0;}
    .profile-edit-fields {flex:1 1 0;min-height:0;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;padding:18px;scrollbar-width:thin;scrollbar-color:#84977e #d4dbcd;}
    .profile-edit-footer {flex:0 0 auto;padding:12px 18px;border-top:1px solid #96a38e;background:#d4dbcd;}
    .profile-edit-footer button {margin:0!important;}
}
@media(max-width:600px){.profile-edit-fields{padding:14px;}.profile-edit-footer{padding:10px 14px;}}
</style>
<?php if (in_array($success, ['Profile updated successfully!', 'Caterer terms and conditions updated successfully!'], true)): ?>
<dialog id="profileSavedNotice" aria-labelledby="profileSavedTitle">
    <h2 id="profileSavedTitle"><?php echo $success === 'Profile updated successfully!' ? 'Profile updated' : 'Terms saved'; ?></h2>
    <p>Your changes have been saved successfully.</p>
    <button type="button" onclick="document.getElementById('profileSavedNotice').close()">OK</button>
</dialog>
<style>
#profileSavedNotice {width:min(340px,calc(100% - 32px));box-sizing:border-box;padding:24px;border:1px solid #96a38e;border-radius:14px;background:#d4dbcd;color:#20372b;text-align:center;box-shadow:0 16px 48px #0c201933;}
#profileSavedNotice::backdrop {background:rgb(12 32 25 / 55%);}
#profileSavedNotice h2 {margin:0 0 10px;font-size:18px;color:#173d32;}
#profileSavedNotice p {margin:0 0 20px;font-size:14px;line-height:1.5;color:#425642;}
#profileSavedNotice button {min-width:96px;min-height:44px;padding:8px 20px;background:#173d32;color:#f0f1e6;border:1px solid #426052;border-radius:8px;font:inherit;cursor:pointer;}
</style>
<script>
(() => {
    const notice=document.getElementById('profileSavedNotice');
    const button=document.querySelector('#profile-edit button[name="update_profile"]');
    setProfileSection('');
    notice.addEventListener('close',()=>document.getElementById('profile-section-select')?.focus({preventScroll:true}));
    notice.showModal();
})();
</script>
<?php endif; ?>

<link rel="stylesheet" href="../assets/css/profile-fields.css?v=20261027-1">
<script src="../assets/js/profile-fields.js?v=20261027-1"></script>
<script src="../assets/js/philippine-address.js?v=7"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
