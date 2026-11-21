<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Must have gone through OTP verification first
if (!isset($_SESSION['reset_verified']) || $_SESSION['reset_verified'] !== true || empty($_SESSION['reset_user_id'])) {
    header('Location: forgot_password.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $user_id          = intval($_SESSION['reset_user_id']);

    if (empty($new_password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        
        $update_fields = ["password = '$hashed'"];
        if (columnExists($conn, 'users', 'status')) {
            // Only unlock if currently 'locked', keep 'closed' (admin banned) closed
            $status_check = mysqli_query($conn, "SELECT status FROM users WHERE id = '$user_id'");
            if ($status_check && $row = mysqli_fetch_assoc($status_check)) {
                if ($row['status'] === 'locked') {
                    $update_fields[] = "status = 'active'";
                }
            }
        }
        if (columnExists($conn, 'users', 'login_attempts')) {
            $update_fields[] = "login_attempts = 0";
        }
        if (columnExists($conn, 'users', 'locked_until')) {
            $update_fields[] = "locked_until = NULL";
        }
        
        $update_query = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE id = '$user_id'";
        $update = mysqli_query($conn, $update_query);

        if ($update && mysqli_affected_rows($conn) > 0) {
            // Clean up reset session
            unset($_SESSION['reset_verified'], $_SESSION['reset_user_id'], $_SESSION['reset_username'], $_SESSION['reset_email']);

            // Also clean password_resets table
            mysqli_query($conn, "DELETE FROM password_resets WHERE user_id = '$user_id'");

            $_SESSION['success'] = 'Your password has been reset successfully. You can now log in with your new password.';
            header('Location: login.php');
            exit();
        } else {
            $error = 'Failed to update password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="soft-sage-theme auth-sage-theme login-layout">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Catering Services Portal</title>
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
        body {
            background: url('uploads/background/login.png') no-repeat center center fixed;
            background-size: cover;
            font-family: 'Inter', 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text-main);
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
            border: 1px solid var(--border-color);
            animation: fadeIn 0.6s ease-out;
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
            isolation: isolate;
        }
        .logo-wrap::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.18) 0%, rgba(212, 175, 55, 0.08) 45%, rgba(212, 175, 55, 0) 72%);
            opacity: 0.55;
            animation: logoHaloPulse 4.6s ease-in-out infinite;
            z-index: -1;
            pointer-events: none;
        }
        @keyframes logoHaloPulse {
            0%, 100% { opacity: 0.42; transform: scale(0.96); }
            50% { opacity: 0.7; transform: scale(1.02); }
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
            animation: logoFloat 4.8s ease-in-out infinite;
            display: block;
        }
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }
        .login-header h2 {
            font-size: 30px;
            font-weight: 800;
            margin-bottom: 10px;
            letter-spacing: -0.02em;
            background: linear-gradient(135deg, #fff 0%, var(--accent-gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .login-header p { color: var(--text-muted); font-size: 15px; }
        .form-content { padding: 40px; }
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
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
        .step-box {
            background: rgba(212, 175, 55, 0.05);
            border: 1px solid rgba(212, 175, 55, 0.15);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 25px;
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.6;
        }
        .step-box strong { color: var(--accent-gold); }
        .form-group { margin-bottom: 22px; position: relative; }
        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: var(--text-main);
            font-weight: 600;
            font-size: 14px;
        }
        .form-group label i { color: var(--accent-gold); margin-right: 8px; }
        .form-group input {
            width: 100%;
            padding: 15px 48px 15px 18px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            font-size: 16px;
            background: var(--input-bg);
            color: var(--text-main);
            transition: all 0.3s ease;
        }
        .form-group input:focus {
            border-color: var(--accent-gold);
            outline: none;
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.15);
            transform: translateY(-1px);
        }
        .password-toggle {
            position: absolute;
            right: 16px;
            bottom: 16px;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 16px;
            padding: 0;
            transition: color 0.2s;
        }
        .password-toggle:hover { color: var(--accent-gold); }
        .strength-bar {
            height: 4px;
            border-radius: 4px;
            background: rgba(255,255,255,0.08);
            margin-top: 8px;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease, background 0.3s ease;
            width: 0%;
        }
        .strength-label {
            font-size: 12px;
            margin-top: 4px;
            color: var(--text-muted);
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
            width: 100%;
            margin-bottom: 15px;
            transition: all 0.3s ease;
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
        .form-links {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }
        .form-links a {
            color: var(--accent-gold);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-links a:hover { color: #fff; transform: translateY(-1px); }
    </style>
    <link rel="stylesheet" href="assets/css/soft-sage-theme.css?v=20261019-8">
    <link rel="stylesheet" href="assets/css/auth-sage-theme.css?v=20261021-refined-fields">
    <link rel="stylesheet" href="assets/css/public-footer.css?v=20261021-18">
</head>
<body class="login-page recovery-page">
    <div class="login-stage">
    <div class="login-container">
        <div class="login-header">
            <div class="logo-wrap">
                <img src="uploads/logo.png" alt="Logo">
            </div>
            <h2>Reset Password</h2>
            <p>Create a new secure password for your account</p>
        </div>

        <div class="form-content">
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="step-box">
                <strong>Step 3:</strong> Set your new password for account
                <strong><?php echo htmlspecialchars($_SESSION['reset_username'] ?? ''); ?></strong>.
            </div>

            <form method="POST" action="" id="resetForm">
                <div class="form-group">
                    <label for="new_password"><i class="fas fa-lock"></i> New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8"
                           placeholder="At least 8 characters" autocomplete="new-password">
                    <button type="button" class="password-toggle" onclick="togglePwd('new_password', this)" aria-label="Show password">
                        <i class="fas fa-eye"></i>
                    </button>
                    <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                    <div class="strength-label" id="strengthLabel"></div>
                </div>

                <div class="form-group">
                    <label for="confirm_password"><i class="fas fa-lock"></i> Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8"
                           placeholder="Repeat your new password" autocomplete="new-password">
                    <button type="button" class="password-toggle" onclick="togglePwd('confirm_password', this)" aria-label="Show password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>

                <button type="submit" name="reset_password" class="btn">
                    <i class="fas fa-key"></i> Set New Password
                </button>
            </form>

            <div class="form-links">
                <a href="login.php"><i class="fas fa-sign-in-alt"></i> Back to Login</a>
                <a href="index.php"><i class="fas fa-home"></i> Homepage</a>
            </div>
        </div>
    </div>

</div>
    <?php include __DIR__ . '/includes/public-footer.php'; ?>

<script>
function togglePwd(fieldId, btn) {
    const input = document.getElementById(fieldId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// Password strength meter
document.getElementById('new_password').addEventListener('input', function () {
    const val = this.value;
    const fill = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { pct: '0%',   color: 'transparent',  text: '' },
        { pct: '25%',  color: '#f44336',       text: 'Weak' },
        { pct: '50%',  color: '#ff9800',       text: 'Fair' },
        { pct: '75%',  color: '#2196f3',       text: 'Good' },
        { pct: '100%', color: '#4caf50',       text: 'Strong' },
    ];
    const lvl = levels[score];
    fill.style.width = lvl.pct;
    fill.style.background = lvl.color;
    label.textContent = lvl.text;
    label.style.color = lvl.color;
});

// Client-side validation
document.getElementById('resetForm').addEventListener('submit', function (e) {
    const pw = document.getElementById('new_password').value;
    const cp = document.getElementById('confirm_password').value;
    if (pw.length < 8) {
        e.preventDefault();
        alert('Password must be at least 8 characters.');
        return;
    }
    if (pw !== cp) {
        e.preventDefault();
        alert('Passwords do not match.');
    }
});
</script>
</body>
</html>

