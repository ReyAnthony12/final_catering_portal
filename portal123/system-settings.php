<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
requireLogin();

if (getUserRole() !== 'portal') {
    header('Location: ../index.php');
    exit();
}

// Handle database backup export
if (isset($_GET['export_db']) && $_GET['export_db'] == '1') {
    $db_name = defined('DB_NAME') ? DB_NAME : 'catering_portal';
    $backup_filename = 'catering_portal_backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $backup_filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo "-- Catering Portal Database Backup\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Database: $db_name\n\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    
    $tables_result = mysqli_query($conn, "SHOW TABLES");
    while ($table_row = mysqli_fetch_row($tables_result)) {
        $table = $table_row[0];
        
        // Get CREATE TABLE statement
        $create_result = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
        $create_row = mysqli_fetch_row($create_result);
        echo "DROP TABLE IF EXISTS `$table`;\n";
        echo $create_row[1] . ";\n\n";
        
        // Get table data
        $data_result = mysqli_query($conn, "SELECT * FROM `$table`");
        if (mysqli_num_rows($data_result) > 0) {
            while ($row = mysqli_fetch_assoc($data_result)) {
                $values = array_map(function($val) use ($conn) {
                    if ($val === null) return 'NULL';
                    return "'" . mysqli_real_escape_string($conn, $val) . "'";
                }, array_values($row));
                $columns = array_map(function($col) { return "`$col`"; }, array_keys($row));
                echo "INSERT INTO `$table` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
            }
            echo "\n";
        }
    }
    
    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';
$csrf_token = generateCSRFToken();

// Get current settings
$settings_query = "SELECT * FROM system_settings";
$settings_result = mysqli_query($conn, $settings_query);
$settings = [];
if ($settings_result) {
    while ($row = mysqli_fetch_assoc($settings_result)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// Check and fix database schema (ensure setting_value is LONGTEXT)
$schema_check = mysqli_query($conn, "SHOW COLUMNS FROM system_settings LIKE 'setting_value'");
if ($schema_check && ($schema_row = mysqli_fetch_assoc($schema_check))) {
    if (stripos($schema_row['Type'], 'text') === false) {
        mysqli_query($conn, "ALTER TABLE system_settings MODIFY setting_value LONGTEXT");
    }
} elseif (!$schema_check) {
    $error = 'Unable to verify the system settings schema. Please check the database configuration.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'change_password') {
        $verification_email = is_string($_POST['verification_email'] ?? null) ? trim($_POST['verification_email']) : '';
        $new_password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirm_password = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        // Always compare against the saved contact email, never another submitted setting.
        $registered_email = trim((string) ($settings['contact_email'] ?? ''));

        if (!filter_var($registered_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Save a valid Contact Email in Contact Information before changing your password.';
        } elseif (!filter_var($verification_email, FILTER_VALIDATE_EMAIL) || strcasecmp($verification_email, $registered_email) !== 0) {
            $error = 'Contact Email must match the saved portal Contact Email.';

        } elseif (strlen($new_password) < 12 || strlen($new_password) > 72) {
            $error = 'Use a new password between 12 and 72 bytes long.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New password and confirmation do not match.';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ? AND role = 'portal' AND status = 'active' LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'i', $user_id);
            mysqli_stmt_execute($stmt);
            $admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$admin) {
                $error = 'Your active portal account could not be found.';
            } elseif (password_verify($new_password, $admin['password'])) {
                $error = 'Choose a password different from your current password.';
            } else {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $old_hash = $admin['password'];
                $stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ? AND role = 'portal' AND status = 'active' AND password = ?");
                mysqli_stmt_bind_param($stmt, 'sis', $password_hash, $user_id, $old_hash);
                $updated = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
                mysqli_stmt_close($stmt);

                if ($updated) {
                    session_regenerate_id(true);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['password_change_success'] = true;
                    header('Location: system-settings.php');
                    exit();
                }
                $error = 'Password could not be changed. Reload the page and try again.';
            }
        }
    } else {
        $contact_email = mysqli_real_escape_string($conn, trim((string) ($_POST['contact_email'] ?? '')));
        $contact_phone = mysqli_real_escape_string($conn, trim((string) ($_POST['contact_phone'] ?? '')));
        $location = mysqli_real_escape_string($conn, trim((string) ($_POST['location'] ?? '')));
        $footer_text = mysqli_real_escape_string($conn, trim((string) ($_POST['footer_text'] ?? '')));
        $terms_content = mysqli_real_escape_string($conn, (string) ($_POST['terms_content'] ?? ''));
        $customer_agreement = (string) ($_POST['customer_agreement'] ?? '');
        $caterer_subscription_agreement = (string) ($_POST['caterer_subscription_agreement'] ?? '');

        // Handle logo upload
        $site_logo = $settings['site_logo'] ?? 'uploads/logo.png'; // default fallback
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_extension = strtolower(pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $new_filename = 'logo_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $upload_path)) {
                    $site_logo = 'uploads/' . $new_filename;
                } else {
                    $error = 'Failed to move uploaded logo file.';
                }
            } else {
                $error = 'Invalid file type for logo. Allowed: JPG, PNG, GIF, SVG, WEBP.';
            }
        }

        $settings_to_update = [
            'contact_email' => $contact_email,
            'contact_phone' => $contact_phone,
            'location' => $location,
            'footer_text' => $footer_text,
            'terms_content' => $terms_content,
            'customer_agreement' => $customer_agreement,
            'caterer_subscription_agreement' => $caterer_subscription_agreement,
            'site_logo' => $site_logo
        ];

        $all_ok = true;
        foreach ($settings_to_update as $key => $value) {
            $key_escaped = mysqli_real_escape_string($conn, $key);
            $check_query = "SELECT setting_key FROM system_settings WHERE setting_key = '$key_escaped' LIMIT 1";
            $check_result = mysqli_query($conn, $check_query);

            if ($check_result && mysqli_num_rows($check_result) > 0) {
                $stmt = mysqli_prepare($conn, "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
                mysqli_stmt_bind_param($stmt, 'ss', $value, $key);
            } else {
                $stmt = mysqli_prepare($conn, "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
                mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
            }
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            if (!$ok) {
                $all_ok = false;
            }
        }

        if ($all_ok) {
            $success = 'System settings updated successfully!';
            // Refresh local settings cache
            foreach ($settings_to_update as $key => $value) {
                $settings[$key] = $value;
            }
        } else {
            $error = 'Some settings could not be saved. Please try again.';
        }
    }
}

if (!empty($_SESSION['password_change_success'])) {
    $success = 'Password changed successfully. Use your new password the next time you sign in.';
    unset($_SESSION['password_change_success']);
}

$page_title = 'System Settings - Portal Dashboard';
$extra_head = '
    <!-- Summernote CSS -->
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <style>
        .main-content {
            width: calc(100% - 260px);
            max-width: none;
            padding: 24px 30px 32px;
        }

        .content-body {
            padding: 0;
            background: transparent;
            border: 0;
            margin-bottom: 0;
        }

        .settings-hero {
            margin-bottom: 24px;
            padding: 28px;
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.14), rgba(33, 150, 243, 0.08));
            border: 1px solid rgba(212, 175, 55, 0.22);
            border-radius: 18px;
        }

        .settings-hero-copy {
            max-width: 720px;
        }

        .settings-hero-copy p {
            margin-top: 10px;
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.65;
        }

        .settings-layout {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .settings-form {
            display: block;
            width: 100%;
        }

        .settings-main,
        .settings-sidebar {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .settings-panel,
        .setting-category {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.18);
        }

        .setting-category {
            padding: 22px;
        }

        .settings-panel {
            padding: 20px;
        }

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 18px;
        }

        .section-heading h2,
        .panel-heading h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            font-size: 18px !important;
        }

        .section-heading p,
        .panel-heading p {
            margin: 6px 0 0;
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .panel-heading {
            margin-bottom: 14px;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .setting-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .setting-item-full {
            grid-column: 1 / -1;
        }

        .setting-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
        }

        .setting-description {
            font-size: 12px;
            line-height: 1.5;
            color: var(--text-muted);
        }

        .form-control {
            min-height: 44px;
            font-size: 13px;
            padding: 10px 12px;
        }

        textarea.form-control {
            min-height: 110px;
            resize: vertical;
        }

        .content-header h1 {
            font-size: 30px;
            line-height: 1.15;
        }

        .btn {
            padding: 10px 16px;
            font-size: 13px;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-main);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .preview-section {
            position: sticky;
            top: 24px;
        }

        .preview-label,
        .settings-list-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .preview-content {
            margin-top: 12px;
            background: linear-gradient(180deg, rgba(20, 20, 20, 0.95), rgba(30, 30, 30, 0.95));
            padding: 18px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .preview-top {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .preview-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            color: var(--text-main);
            font-size: 13px;
            line-height: 1.5;
        }

        .preview-item i {
            color: var(--info-color);
            margin-top: 2px;
        }

        .preview-footer {
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.6;
            margin-top: 12px;
            padding-top: 14px;
            border-top: 1px solid var(--border-color);
            text-align: center;
        }

        .settings-checklist {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }

        .settings-checklist div {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .settings-checklist i {
            color: var(--success-color);
        }

        .terms-note {
            margin-top: 12px;
            padding: 14px 16px;
            background: rgba(33, 150, 243, 0.08);
            border: 1px solid rgba(33, 150, 243, 0.18);
            border-radius: 12px;
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .settings-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 18px 22px;
            background: rgba(20, 20, 20, 0.9);
            border: 1px solid var(--border-color);
            border-radius: 18px;
        }

        .settings-actions h3 {
            margin: 0 0 6px;
            font-size: 16px;
        }

        .settings-actions p {
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .settings-actions-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
        }

        .editor-shell .note-editor {
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .editor-shell .note-toolbar {
            border-bottom: 1px solid var(--border-color);
        }

        @media (max-width: 1200px) {
            .settings-form {
                grid-template-columns: 1fr;
            }

            .preview-section {
                position: static;
            }
        }

        @media (max-width: 992px) {
            .main-content {
                width: 100%;
                padding: 84px 24px 28px;
            }
        }

        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }

            .settings-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .settings-actions-buttons {
                justify-content: stretch;
            }

            .settings-actions-buttons .btn {
                width: 100%;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding-left: 12px;
                padding-right: 12px;
            }

            .settings-hero,
            .setting-category,
            .settings-panel,
            .settings-actions {
                padding: 18px;
            }
        }
        .settings-password-field { position: relative; }
        .settings-password-field .form-control { padding-right: 54px; }
        .settings-password-toggle { position: absolute; right: 4px; top: 50%; transform: translateY(-50%); width: 44px; height: 44px; border: 0; border-radius: 8px; background: transparent; color: var(--text-muted); cursor: pointer; }
        .settings-password-toggle:hover { color: var(--accent-gold); }
        .settings-password-toggle:focus-visible { outline: 2px solid var(--accent-gold); }
    </style>
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

    <link rel="stylesheet" href="../assets/css/portal-system-settings.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/portal-system-settings.css'); ?>">
    <div class="main-content settings-page">

        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success settings-success-notice" role="status">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <div class="content-body">
            <div class="settings-layout">


                <div class="settings-preferences">
                <form method="POST" action="" id="system-settings-form" class="settings-form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="settings-main">
                        <div class="settings-section-buttons" aria-label="Settings sections"><button type="button" class="settings-section-button" id="settings-button-1" aria-expanded="false" aria-controls="settings-panel-1"><span class="settings-card-title">Brand Settings</span></button><button type="button" class="settings-section-button" id="settings-button-2" aria-expanded="false" aria-controls="settings-panel-2"><span class="settings-card-title">Contact Information</span></button><button type="button" class="settings-section-button" id="settings-button-3" aria-expanded="false" aria-controls="settings-panel-3"><span class="settings-card-title">Footer Settings</span></button><button type="button" class="settings-section-button" id="settings-button-4" aria-expanded="false" aria-controls="settings-panel-4"><span class="settings-card-title">Legal & Policies</span></button><button type="button" class="settings-section-button" id="settings-button-5" aria-expanded="false" aria-controls="settings-panel-5"><span class="settings-card-title">Registration Agreements</span></button></div>
                        <section class="setting-category settings-section-panel" id="settings-panel-1" aria-labelledby="settings-button-1" hidden><div class="settings-disclosure-body">

                            <div class="settings-grid">
                                <div class="setting-item setting-item-full">
                                    <label class="setting-label">
                                        Site Logo
                                    </label>
                                    <input type="file" name="site_logo" id="site_logo" class="form-control" accept="image/*">
                                    <?php if (!empty($settings['site_logo'])): ?>
                                        <div style="margin-top: 10px;">
                                            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 5px;">Current Logo:</p>
                                            <img src="<?php echo htmlspecialchars(portalLogoUrl($settings['site_logo']), ENT_QUOTES, 'UTF-8'); ?>" alt="Current Logo" style="max-width: 150px; max-height: 80px; object-fit: contain; border-radius: 8px; border: 1px solid var(--border-color); background: #1e1e1e; padding: 10px;">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div></section>

                        <section class="setting-category settings-section-panel" id="settings-panel-2" aria-labelledby="settings-button-2" hidden><div class="settings-disclosure-body">

                            <div class="settings-grid">
                                <div class="setting-item">
                                    <label class="setting-label">
                                        Contact Email
                                    </label>
                                    <input type="email" name="contact_email"
                                           value="<?php echo htmlspecialchars($settings['contact_email'] ?? 'info@cateringportal.com'); ?>"
                                           class="form-control" placeholder="Enter contact email">
                                </div>

                                <div class="setting-item">
                                    <label class="setting-label">
                                        Contact Phone Number
                                    </label>
                                    <input type="text" name="contact_phone"
                                           value="<?php echo htmlspecialchars($settings['contact_phone'] ?? '+63 912 345 6789'); ?>"
                                           class="form-control" placeholder="Enter contact phone">
                                </div>

                                <div class="setting-item setting-item-full">
                                    <label class="setting-label">
                                        Location Address
                                    </label>
                                    <textarea name="location" rows="3"
                                              class="form-control" placeholder="Enter business location"><?php echo htmlspecialchars($settings['location'] ?? 'Ipil, Zamboanga Sibugay'); ?></textarea>
                                </div>
                            </div>
                        </div></section>

                        <section class="setting-category settings-section-panel" id="settings-panel-3" aria-labelledby="settings-button-3" hidden><div class="settings-disclosure-body">

                            <div class="setting-item">
                                <label class="setting-label">
                                    Footer Text
                                </label>
                                <textarea name="footer_text" rows="4"
                                          class="form-control" placeholder="Enter footer text"><?php echo htmlspecialchars($settings['footer_text'] ?? '© 2024 Catering Services Portal System. All rights reserved.'); ?></textarea>
                            </div>
                        </div></section>

                        <section class="setting-category settings-section-panel" id="settings-panel-4" aria-labelledby="settings-button-4" hidden><div class="settings-disclosure-body">

                            <div class="setting-item editor-shell">
                                <label class="setting-label">
                                    Terms and Conditions Content
                                </label>
                                <textarea name="terms_content" id="terms_content" rows="12"
                                          class="form-control" spellcheck="false" placeholder="Enter Terms and Conditions HTML content"><?php
                        $default_terms = '
    <p>Welcome to our Catering Portal. By booking our services, you agree to comply with and be bound by the following terms and conditions. Please read them carefully.</p>

    <h2>1. Booking and Reservations</h2>
    <ul>
        <li>A non-refundable down payment of <strong>50%</strong> of the total estimated cost is required to secure your reservation date.</li>
        <li>The remaining balance must be paid in full on or before the day of the event, or as per the specific caterer\'s policy.</li>
        <li>Bookings are only confirmed upon receipt of the down payment and verification by the catering provider.</li>
        <li>We recommend booking at least 2 weeks in advance to ensure availability.</li>
    </ul>

    <h2>2. Cancellations and Refunds</h2>
    <ul>
        <li>Cancellations made <strong>7 days or more</strong> before the event date may be eligible for a refund of payments made, excluding the non-refundable down payment (subject to caterer discretion).</li>
        <li>Cancellations made <strong>less than 7 days</strong> before the event are non-refundable.</li>
        <li>In the event of a cancellation by the catering provider due to unforeseen circumstances, a full refund (including down payment) will be issued.</li>
    </ul>

    <h2>3. Menu Changes and Customizations</h2>
    <ul>
        <li>Final menu choices and guest counts must be confirmed at least <strong>5 days</strong> prior to the event.</li>
        <li>Any custom requests or add-ons submitted during booking cannot be removed once the order is confirmed, as preparations may have already begun.</li>
        <li>Additional charges may apply for last-minute changes or increases in guest count.</li>
    </ul>

    <h2>4. Event Duration and Overtime</h2>
    <ul>
        <li>The standard service duration is <strong>4 hours</strong> from the agreed start time.</li>
        <li>Overtime fees may apply for service exceeding the standard duration.</li>
        <li>Clients must ensure the venue allows ample time for setup (usually 2-3 hours before the event) and breakdown.</li>
    </ul>

    <h2>5. Liability and Damages</h2>
    <ul>
        <li>The client is responsible for any loss or damage to catering equipment (plates, utensils, warmers, tables, etc.) caused by guests during the event.</li>
        <li>Damaged or lost items will be charged to the client at replacement cost.</li>
        <li>The catering provider is not liable for food quality issues resulting from the client\'s failure to consume food within the recommended safe timeframe (usually 4 hours after serving).</li>
    </ul>

    <h2>6. Force Majeure</h2>
    <ul>
        <li>The catering provider shall not be liable for failure to perform obligations due to events beyond reasonable control, such as acts of God, extreme weather, strikes, or government restrictions.</li>
        <li>In such cases, both parties will work together to reschedule the event or negotiate a fair resolution.</li>
    </ul>

    <h2>7. Privacy Policy</h2>
    <ul>
        <li>We value your privacy. Your personal information (contact details, address) is collected solely for the purpose of fulfilling your order and will not be shared with third parties without consent.</li>
    </ul>';
                        echo htmlspecialchars($settings['terms_content'] ?? $default_terms);
                    ?></textarea>
                            </div>
                        </div></section>

                        <section class="setting-category settings-section-panel" id="settings-panel-5" aria-labelledby="settings-button-5" hidden><div class="settings-disclosure-body">

                            <div class="setting-item editor-shell">
                                <label class="setting-label">
                                    Customer Agreement
                                </label>
                                <textarea name="customer_agreement" id="customer_agreement" rows="10"
                                          class="form-control summernote-editor" spellcheck="false" placeholder="Enter Customer Agreement HTML content"><?php
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
                        echo htmlspecialchars($settings['customer_agreement'] ?? $default_customer_agreement);
                    ?></textarea>
                            </div>

                            <div class="setting-item editor-shell" style="margin-top: 20px;">
                                <label class="setting-label">
                                    Subscription Agreement for Caterer Owner
                                </label>
                                <textarea name="caterer_subscription_agreement" id="caterer_subscription_agreement" rows="10"
                                          class="form-control summernote-editor" spellcheck="false" placeholder="Enter Caterer Subscription Agreement HTML content"><?php
                        $default_caterer_agreement = '
    <p><strong>By registering a Caterer Owner, I agree to the following subscription terms:</strong></p>
    <ul>
        <li><strong>Approval Required:</strong> Your caterer registration needs manual approval.</li>
        <li><strong>Profile Display:</strong> Your caterer profile picture will be automatically displayed on the homepage after approval.</li>
        <li><strong>7-Day Free Trial:</strong> After approval, you will receive a 7-day free trial.</li>
        <li><strong>Automatic Subscription:</strong> After the 7-day free trial, you will automatically be subscribed to our monthly plan.</li>
        <li><strong>Monthly Fee:</strong> The subscription fee is <strong>₱500.00 per month</strong>.</li>
        <li><strong>Payment Method:</strong> Payments will be processed through the GCash QR code.</li>
        <li><strong>Service Access:</strong> Full access to caterer features will be available only with an active subscription.</li>
    </ul>
    <div class="highlight">
        <p><i class="fas fa-exclamation-circle"></i> IMPORTANT: Your caterer profile picture will be visible on the homepage automatically once approved. To keep using all features, pay your subscription on time. If payment is missed, you\'ll lose access to premium features.</p>
    </div>';
                        echo htmlspecialchars($settings['caterer_subscription_agreement'] ?? $default_caterer_agreement);
                    ?></textarea>
                            </div>
                        </div></section>

                    </div>


                </form>
                        <div class="settings-actions">
                            <div>
                                <h3>Save Changes</h3>
                            </div>

                            <div class="settings-actions-buttons">
                                <button form="system-settings-form" type="submit" class="btn btn-primary">
                                    Save Settings
                                </button>
                            </div>
                        </div>
                </div>
                <section class="setting-category" id="change-password">
                    <div class="section-heading">
                        <div>
                            <span class="settings-security-kicker">ACCOUNT SECURITY</span><h2 style="color: var(--accent-gold);">Change Password</h2><p class="settings-security-description">Keep your account protected with a strong, private password.</p>
                        </div>
                    </div>
                    <form method="POST" action="system-settings.php#change-password">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="change_password">
                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label" for="verification_email">Contact Email</label>
                                <input type="email" id="verification_email" name="verification_email" class="form-control" autocomplete="email" required placeholder="Enter the saved Contact Email">
                            </div>
                            <div class="setting-item">
                                <label class="setting-label" for="new_password">New Password</label>
                                <div class="settings-password-field">
                                    <input type="password" id="new_password" name="new_password" class="form-control" autocomplete="new-password" minlength="12" maxlength="72" required>
                                    <button type="button" class="settings-password-toggle" data-password-target="new_password" aria-controls="new_password" aria-label="Show new password" aria-pressed="false">Show</button>
                                </div>
                            </div>
                            <div class="setting-item">
                                <label class="setting-label" for="confirm_password">Confirm New Password</label>
                                <div class="settings-password-field">
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" autocomplete="new-password" minlength="12" maxlength="72" required>
                                    <button type="button" class="settings-password-toggle" data-password-target="confirm_password" aria-controls="confirm_password" aria-label="Show confirm password" aria-pressed="false">Show</button>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="margin-top: 18px;">Change Password</button>
                    </form>
                </section>

            </div>
        </div>
    </div>

<?php require __DIR__ . '/includes/footer-bar.php'; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <script>
        function openSettingsPanel(panelId) {
            document.querySelectorAll('.settings-section-button').forEach(function(button) {
                const active = button.getAttribute('aria-controls') === panelId;
                button.setAttribute('aria-expanded', String(active));
                document.getElementById(button.getAttribute('aria-controls')).hidden = !active;
            });
        }
        document.querySelectorAll('.settings-section-button').forEach(function(button) {
            button.addEventListener('click', function() {
                openSettingsPanel(button.getAttribute('aria-expanded') === 'true' ? null : button.getAttribute('aria-controls'));
            });
        });
        document.querySelector('.settings-form').addEventListener('invalid', function(event) {
            const panel = event.target.closest('.settings-section-panel');
            if (panel) openSettingsPanel(panel.id);
        }, true);
        $(document).ready(function() {
            var summernoteOptions = {
                tabsize: 2,
                height: 300,
                toolbar: [],
                callbacks: {
                    onInit: function() {
                        // Dark mode adjustments
                        $('.note-editor').css({
                            'background-color': '#2a2a2a',
                            'border-color': '#333',
                            'color': '#fff'
                        });
                        $('.note-editable').css({
                            'background-color': '#2a2a2a',
                            'color': '#fff'
                        });
                        $('.note-toolbar').css({
                            'background-color': '#1e1e1e',
                            'border-bottom': '1px solid #333'
                        });
                        $('.note-btn').css({
                            'background-color': '#333',
                            'border-color': '#444',
                            'color': '#fff'
                        });
                        $('.note-status-output').css({
                            'background-color': '#1e1e1e',
                            'border-top': '1px solid #333'
                        });
                    }
                }
            };
            
            var termsOptions = Object.assign({}, summernoteOptions, { placeholder: 'Enter your terms and conditions here...', height: 400 });
            $('#terms_content').summernote(termsOptions);
            
            var customerOptions = Object.assign({}, summernoteOptions, { placeholder: 'Enter Customer Agreement HTML content' });
            $('#customer_agreement').summernote(customerOptions);
            
            var catererOptions = Object.assign({}, summernoteOptions, { placeholder: 'Enter Caterer Subscription Agreement HTML content' });
            $('#caterer_subscription_agreement').summernote(catererOptions);
        });

        const inputs = document.querySelectorAll('input[name], textarea[name]');
        inputs.forEach(input => {
            input.addEventListener('input', updatePreview);
        });

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        
        function updatePreview() {
            const previewContent = document.querySelector('.preview-content');
            if (previewContent) {
                const email = document.querySelector('[name="contact_email"]').value;
                const phone = document.querySelector('[name="contact_phone"]').value;
                const location = document.querySelector('[name="location"]').value;
                const footer = document.querySelector('[name="footer_text"]').value;

                const previewHTML = `
                    <div class="preview-top">
                        <div class="preview-item">
                            <span>${escapeHtml(email || 'info@cateringportal.com')}</span>
                        </div>
                        <div class="preview-item">
                            <span>${escapeHtml(phone || '+63 912 345 6789')}</span>
                        </div>
                        <div class="preview-item">
                            <span>${escapeHtml(location || 'Ipil, Zamboanga Sibugay')}</span>
                        </div>
                    </div>
                    <div class="preview-footer">
                        ${escapeHtml(footer || '© 2024 Catering Services Portal System. All rights reserved.')}
                    </div>
                `;

                previewContent.innerHTML = previewHTML;
            }
        }
    </script>

<script>
    const settingsSuccessNotice = document.querySelector('.settings-success-notice');
    if (settingsSuccessNotice) {
        window.setTimeout(function() {
            settingsSuccessNotice.remove();
        }, 3000);
    }
    document.querySelectorAll('[data-password-target]').forEach(function(button) {
        button.addEventListener('click', function() {
            const input = document.getElementById(button.dataset.passwordTarget);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.textContent = show ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', String(show));
            button.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + input.labels[0].textContent.trim().toLowerCase());
        });
    });
</script>
</body>
</html>
