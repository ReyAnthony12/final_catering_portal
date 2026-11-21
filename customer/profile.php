<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/profile_fields.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/payment_functions.php';
requireLogin();

if (getUserRole() !== 'customer') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';
$csrf_token = generateCSRFToken();

// Get customer details
$query = "SELECT * FROM users WHERE id = '$user_id'";
$result = mysqli_query($conn, $query);
$customer = mysqli_fetch_assoc($result);

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $validation = validateProfileFields($_POST, (string) $customer['address']);
        if (isset($validation['error'])) {
            $error = $validation['error'];
        } else {
        $first_name = mysqli_real_escape_string($conn, $_POST['first_name']);
    $last_name = mysqli_real_escape_string($conn, $_POST['last_name']);
    $middle_name = mysqli_real_escape_string($conn, $_POST['middle_name']);
    $suffix = isset($_POST['suffix']) ? mysqli_real_escape_string($conn, $_POST['suffix']) : '';
    $phone_number = mysqli_real_escape_string($conn, $_POST['phone_number']);
    $address = mysqli_real_escape_string($conn, $validation['address']);
    
    // Handle profile image upload
    $profile_image = $customer['profile_image'];
    if (!empty($_FILES['profile_image']['name'])) {
        $target_dir = "../uploads/profiles/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES["profile_image"]["name"], PATHINFO_EXTENSION);
        $new_filename = "profile_" . $user_id . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        // Check file size (max 2MB)
        if ($_FILES["profile_image"]["size"] > 2000000) {
            $error = "Sorry, your file is too large. Maximum size is 2MB.";
        } else {
            // Allow certain file formats
            $allowed_extensions = array("jpg", "jpeg", "png", "gif");
            if (in_array(strtolower($file_extension), $allowed_extensions)) {
                if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $target_file)) {
                    // Delete old profile image if exists and not the default
                    if ($profile_image && $profile_image != 'uploads/logo.png' && file_exists("../" . $profile_image)) {
                        unlink("../" . $profile_image);
                    }
                    $profile_image = "uploads/profiles/" . $new_filename;
                } else {
                    $error = "Sorry, there was an error uploading your file.";
                }
            } else {
                $error = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
            }
        }
    }
    
        if (!$error) {
            $update_query = "UPDATE users SET 
                            first_name = '$first_name',
                            last_name = '$last_name',
                            middle_name = '$middle_name',
                            suffix = '$suffix',
                            phone_number = '$phone_number',
                            address = '$address',
                            profile_image = '$profile_image'
                            WHERE id = '$user_id'";
            
            if (mysqli_query($conn, $update_query)) {
                $success = "Profile updated successfully!";
                // Refresh customer data
                $result = mysqli_query($conn, $query);
                $customer = mysqli_fetch_assoc($result);
            } else {
                appLogError('Customer profile update failed', mysqli_error($conn));
                $error = "Unable to update your profile right now. Please try again.";
            }
        }
    }
}

}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $current_password = mysqli_real_escape_string($conn, $_POST['current_password']);
        $new_password = mysqli_real_escape_string($conn, $_POST['new_password']);
        $confirm_password = mysqli_real_escape_string($conn, $_POST['confirm_password']);
        
        // Verify current password
        if (password_verify($current_password, $customer['password'])) {
        if ($new_password === $confirm_password) {
            if (strlen($new_password) >= 8) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $password_query = "UPDATE users SET password = '$hashed_password' WHERE id = '$user_id'";
                
                if (mysqli_query($conn, $password_query)) {
                    $success = "Password changed successfully!";
                } else {
                    appLogError('Customer profile password change failed', mysqli_error($conn));
                    $error = "Unable to change your password right now. Please try again.";
                }
            } else {
                $error = "New password must be at least 8 characters long.";
            }
        } else {
            $error = "New password and confirm password do not match.";
        }
        } else {
            $error = "Current password is incorrect.";
        }
    }
}

$page_title = 'Profile';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .customer-profile-page .profile-form-section .form-control { padding: 7px 10px; min-height: 34px; }
    .customer-profile-page .profile-form-section input.form-control { height: 34px; }
    .customer-profile-page .profile-form-section textarea.form-control { height: 54px; }
    .customer-profile-page .profile-form-section .form-group { margin-bottom: 12px; }
    .customer-profile-page .profile-form-section h3 { padding-bottom: 10px; margin-bottom: 12px; }
    .customer-profile-page .profile-actions .btn { min-height: 34px; padding: 7px 12px; }
    .customer-profile-page .profile-actions .btn { font-size: 11px; }
    #profile-password-modal #password-modal-title { font-size: 16px; }
    #profile-password-modal .form-label { font-size: 11px; }
    #profile-password-modal :is(.form-control, .btn) { font-size: 12px; }
    .customer-profile-page .profile-summary h2 { font-size: 20px; }
    .customer-profile-page .profile-summary p { font-size: 13px; }
    .customer-profile-page .profile-form-section h3 { font-size: 17px; }
    .customer-profile-page .form-label { font-size: 12px; }
    .customer-profile-page :is(.form-control, .btn) { font-size: 13px; }
    .customer-page-shell:has(.customer-profile-page) {
        height: 100dvh;
    }

    .customer-page-shell > .container:has(.customer-profile-page) {
        display: flex;
        flex-direction: column;
        flex: 1 1 0;
        min-height: 0;
        margin-bottom: 12px;
    }

    .customer-profile-page {
        display: flex;
        flex-direction: column;
        flex: 1 1 0;
        min-height: 0;
    }

    .customer-profile-page > .alert { flex-shrink: 0; }

    .customer-profile-page > .profile-main-grid {
        flex: 1 1 0;
        min-height: 0;
        margin-bottom: 0;
        overflow-y: scroll;
        align-content: start;
        scrollbar-gutter: stable;
        scrollbar-width: thin;
        scrollbar-color: #708b6b #c9d2c1;
    }

    .customer-profile-page > .profile-main-grid::-webkit-scrollbar { width: 8px; }
    .customer-profile-page > .profile-main-grid::-webkit-scrollbar-track { background: #c9d2c1; border-radius: 8px; }
    .customer-profile-page > .profile-main-grid::-webkit-scrollbar-thumb { background: #708b6b; border-radius: 8px; }

    .profile-main-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
        gap: 20px;
    }

    .profile-main-grid.card {
        padding: 20px;
    }

    .profile-summary {
        padding-right: 20px;
        border-right: 1px solid var(--border-color);
        align-self: stretch;
    }

    .profile-form-section {
        padding: 0;
    }

    .profile-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 18px;
    }

    .profile-actions .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 44px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
    }

    .profile-actions .profile-password-button {
        background: var(--sage-gold);
        color: var(--text-main);
        border: 1px solid var(--accent-gold);
        box-shadow: none;
    }

    .profile-actions .profile-password-button:hover {
        background: var(--accent-gold);
        color: var(--card-bg);
        border-color: var(--accent-gold-hover);
    }

    .profile-actions .profile-password-button:focus-visible {
        outline: 2px solid var(--accent-gold);
        outline-offset: 3px;
    }

    @media (max-width: 480px) {
        .profile-actions {
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
        }

        .profile-actions .btn { width: 100%; }
    }

    .profile-password-modal {
        box-sizing: border-box;
        width: min(520px, calc(100% - 24px));
        max-width: calc(100% - 24px);
        max-height: calc(100dvh - 32px);
        margin: auto;
        padding: 20px;
        overflow-y: auto;
        background: var(--card-bg, #d4dbcd);
        color: var(--text-main);
        border: 1px solid var(--border-color);
        border-radius: 12px;
    }

    .profile-password-modal::backdrop {
        background: rgba(0, 0, 0, 0.5);
    }

    .profile-password-modal .form-control { box-sizing: border-box; }

    .profile-modal-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 16px;
        padding-bottom: 8px;
        border-bottom: 1px solid var(--border-color);
    }

    .profile-modal-close {
        flex: 0 0 44px;
        height: 44px;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: var(--text-main);
        font-size: 28px;
        cursor: pointer;
    }

    .profile-modal-close:focus-visible {
        outline: 2px solid var(--accent-gold);
    }

    .profile-form-section > h3 {
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-main);
        font-size: 18px;
    }

    .profile-form-section .form-group {
        margin-bottom: 12px;
    }

    .profile-form-section .form-label {
        margin-bottom: 5px;
        font-size: 13px;
    }

    .profile-form-section .form-control {
        padding: 9px 12px;
        min-height: 40px;
    }

    .profile-form-section textarea.form-control {
        min-height: 64px;
        resize: vertical;
    }

    .profile-form-section .btn {
        padding: 10px 14px;
    }

    .profile-basic-grid > .form-group,
    .profile-password-grid > .form-group {
        margin-bottom: 0;
    }

    .profile-basic-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 12px;
    }

    .profile-main-grid > * { min-width: 0; }

    .profile-summary {
        overflow-wrap: anywhere;
    }

    .profile-address-row {
        display: flex;
        align-items: baseline;
    }

    .profile-address-row > i { flex: 0 0 25px; }

    .profile-address-line {
        display: block;
        min-width: 0;
        max-width: 100%;
        flex: 1;
        white-space: normal;
        overflow-wrap: anywhere;
        line-height: 1.5;
    }

    .profile-password-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 12px;
    }

    @media (max-width: 992px) {
        .profile-main-grid {
            grid-template-columns: 1fr;
        }

        .profile-summary {
            padding-right: 0;
            padding-bottom: 24px;
            border-right: 0;
            border-bottom: 1px solid var(--border-color);
        }
    }

    @media (max-width: 768px) {
        .profile-main-grid.card {
            padding: 18px;
            gap: 24px;
        }

        .profile-basic-grid,
        .profile-password-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
<link rel="stylesheet" href="../assets/css/customer-profile-desktop.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/customer-profile-desktop.css'); ?>">

<div class="customer-profile-page">

    <?php if ($error): ?>
        <div class="alert alert-error" style="background: rgba(244, 67, 54, 0.1); color: #f44336; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #f44336;">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>
    
    <?php if (in_array($success, ['Profile updated successfully!', 'Password changed successfully!'], true)): ?>
        <dialog class="sage-notice-dialog" data-auto-notice aria-labelledby="customerProfileSavedTitle">
            <h2 id="customerProfileSavedTitle"><?php echo $success === 'Password changed successfully!' ? 'Password changed' : 'Profile updated'; ?></h2>
            <p><?php echo htmlspecialchars($success); ?></p>
            <div class="sage-notice-actions"><button type="button" data-notice-close>OK</button></div>
        </dialog>
    <?php elseif ($success): ?>
        <div class="alert alert-success" style="background: rgba(76, 175, 80, 0.1); color: #4caf50; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #4caf50;">
            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <div class="card profile-main-grid">
        <!-- Left Column: Profile Card -->
        <div class="profile-summary" style="text-align: center;">
            <div style="position: relative; width: 150px; height: 150px; margin: 0 auto 20px;">
                <img src="<?php echo !empty($customer['profile_image']) ? '../' . $customer['profile_image'] : '../uploads/logo.png'; ?>" 
                     alt="Profile" 
                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%; border: 4px solid var(--accent-gold);">
                <label for="profile_image_upload" style="position: absolute; bottom: 0; right: 0; background: var(--accent-gold); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #000;">
                    <i class="fas fa-camera"></i>
                </label>
            </div>
            
            <h2 style="color: var(--text-main); margin-bottom: 5px;"><?php echo htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['middle_name'] . ' ' . $customer['last_name'] . ' ' . $customer['suffix'])); ?></h2>
            <p style="color: var(--text-muted); margin-bottom: 20px;">Customer Account</p>
            
            <div style="text-align: left; border-top: 1px solid var(--border-color); padding-top: 20px;">
                <p style="margin-bottom: 10px; color: var(--text-muted);"><i class="fas fa-user" style="width: 25px; color: var(--accent-gold);"></i> <?php echo htmlspecialchars($customer['username']); ?></p>
                <p style="margin-bottom: 10px; color: var(--text-muted);"><i class="fas fa-envelope" style="width: 25px; color: var(--accent-gold);"></i> <?php echo $customer['email'] ? htmlspecialchars($customer['email']) : 'Not set'; ?></p>
                <p style="margin-bottom: 10px; color: var(--text-muted);"><i class="fas fa-phone" style="width: 25px; color: var(--accent-gold);"></i> <?php echo htmlspecialchars($customer['phone_number']); ?></p>
                <p class="profile-address-row" style="margin-bottom: 10px; color: var(--text-muted);"><i class="fas fa-map-marker-alt" style="width: 25px; color: var(--accent-gold);"></i> <span class="profile-address-line" aria-label="Address"><?php echo htmlspecialchars($customer['address']); ?></span></p>
                <p style="margin-bottom: 10px; color: var(--text-muted);"><i class="fas fa-calendar" style="width: 25px; color: var(--accent-gold);"></i> Joined <?php echo date('M Y', strtotime($customer['created_at'])); ?></p>
            </div>
        </div>

        <!-- Right Column: Edit Forms -->
        <div>
            <!-- Edit Profile -->
            <div class="profile-form-section">
                <h3>Edit Profile</h3>
                
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="file" id="profile_image_upload" name="profile_image" accept="image/*" style="display: none;" onchange="document.getElementById('profile_preview').src = window.URL.createObjectURL(this.files[0])">
                    
                    <div class="profile-basic-grid">
                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" maxlength="250" pattern="[\p{L}\p{M} .â€™\x27\-]{1,250}" title="Names cannot contain numbers." class="form-control" value="<?php echo htmlspecialchars($customer['first_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" maxlength="250" pattern="[\p{L}\p{M} .â€™\x27\-]{1,250}" title="Names cannot contain numbers." class="form-control" value="<?php echo htmlspecialchars($customer['last_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" maxlength="250" pattern="[\p{L}\p{M} .â€™\x27\-]{1,250}" title="Names cannot contain numbers." class="form-control" value="<?php echo htmlspecialchars($customer['middle_name']); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone_number" inputmode="numeric" minlength="11" maxlength="11" pattern="[0-9]{11}" title="Enter exactly 11 digits." class="form-control" value="<?php echo htmlspecialchars($customer['phone_number']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <?php renderProfileAddressPicker((string) $customer['address']); ?>
                    </div>

                    <div class="profile-actions">
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save" aria-hidden="true"></i> Update Profile
                        </button>
                        <button type="button" class="btn profile-password-button" id="open-password-modal">
                            <i class="fas fa-key" aria-hidden="true"></i> Change Password
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

            <!-- Password settings open in a dialog; only the X dismisses it. -->
            <dialog class="profile-password-modal" id="profile-password-modal" aria-labelledby="password-modal-title">
            <div class="profile-form-section">
                <div class="profile-modal-heading">
                    <h3 id="password-modal-title">Change Password</h3>
                    <button type="button" class="profile-modal-close" id="close-password-modal" aria-label="Close change password">&times;</button>
                </div>
                <?php if (isset($_POST['change_password']) && ($error || $success)): ?>
                    <p role="status" style="margin-bottom: 12px;"><?php echo htmlspecialchars($error ?: $success); ?></p>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <div style="position: relative;">
                            <input type="password" name="current_password" id="current_password" class="form-control" required style="padding-right: 46px;">
                            <button type="button" class="password-toggle" data-toggle-password="current_password" aria-label="Show password" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #888; cursor: pointer;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="profile-password-grid">
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <div style="position: relative;">
                                <input type="password" name="new_password" id="new_password" class="form-control" required style="padding-right: 46px;">
                                <button type="button" class="password-toggle" data-toggle-password="new_password" aria-label="Show password" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #888; cursor: pointer;">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div id="strengthIndicator" style="font-size: 12px; margin-top: 5px;"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm Password</label>
                            <div style="position: relative;">
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required style="padding-right: 46px;">
                                <button type="button" class="password-toggle" data-toggle-password="confirm_password" aria-label="Show password" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #888; cursor: pointer;">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div id="matchIndicator" style="font-size: 12px; margin-top: 5px;"></div>
                        </div>
                    </div>

                    <button type="submit" name="change_password" class="btn btn-primary">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </form>
            </div>
            </dialog>

<script>
    const passwordModal = document.getElementById('profile-password-modal');
    const openPasswordModal = document.getElementById('open-password-modal');
    const closePasswordModal = document.getElementById('close-password-modal');
    let profileScrollPosition = '';

    function showPasswordModal() {
        if (passwordModal.open) return;
        profileScrollPosition = document.body.style.overflow;
        passwordModal.showModal();
        document.body.style.overflow = 'hidden';
    }

    openPasswordModal.addEventListener('click', showPasswordModal);
    closePasswordModal.addEventListener('click', function() {
        passwordModal.close();
        document.body.style.overflow = profileScrollPosition;
        openPasswordModal.focus();
    });
    passwordModal.addEventListener('cancel', function(event) {
        event.preventDefault();
    });
    <?php if (isset($_POST['change_password']) && $error): ?>
    showPasswordModal();
    <?php endif; ?>

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

    // Password strength indicator
    const passwordInput = document.getElementById('new_password');
    const strengthIndicator = document.getElementById('strengthIndicator');
    
    passwordInput.addEventListener('input', function() {
        const password = this.value;
        let strength = 0;
        let message = '';
        let color = '#f44336';
        
        if (password.length >= 8) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;
        
        switch(strength) {
            case 0:
            case 1:
                message = 'Very Weak';
                color = '#f44336';
                break;
            case 2:
                message = 'Weak';
                color = '#ff9800';
                break;
            case 3:
                message = 'Good';
                color = '#2196f3';
                break;
            case 4:
                message = 'Strong';
                color = '#4caf50';
                break;
        }
        
        strengthIndicator.textContent = `Strength: ${message}`;
        strengthIndicator.style.color = color;
    });
    
    // Confirm password validation
    const confirmInput = document.getElementById('confirm_password');
    const matchIndicator = document.getElementById('matchIndicator');
    
    function validatePasswords() {
        const password = document.getElementById('new_password').value;
        const confirm = document.getElementById('confirm_password').value;
        
        if (confirm === '') {
            matchIndicator.textContent = '';
            return;
        }
        
        if (password === confirm) {
            matchIndicator.textContent = 'Passwords match.';
            matchIndicator.style.color = '#245539';
        } else {
            matchIndicator.textContent = 'Passwords do not match.';
            matchIndicator.style.color = '#8c3030';
        }
    }
    
    document.getElementById('new_password').addEventListener('input', validatePasswords);
    document.getElementById('confirm_password').addEventListener('input', validatePasswords);
</script>


<link rel="stylesheet" href="../assets/css/profile-fields.css?v=20261027-1">
<script src="../assets/js/profile-fields.js?v=20261027-1"></script>
<script src="../assets/js/philippine-address.js?v=7"></script>
<link rel="stylesheet" href="../assets/css/sage-notices.css?v=20261027-2">
<script src="../assets/js/sage-notices.js?v=20261027-2"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
