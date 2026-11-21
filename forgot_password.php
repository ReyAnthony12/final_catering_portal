<?php
require_once 'config.php';
require_once 'includes/mail_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$step = 'request';

if (isset($_SESSION['password_reset_pending']) && !empty($_SESSION['password_reset_pending']['user_id'])) {
    $step = 'verify';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_code'])) {
        $email = mysqli_real_escape_string($conn, trim($_POST['email']));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $user_query = "SELECT id, username, email, role, status,
                                  COALESCE(NULLIF(email, ''), NULLIF(username, '')) AS recovery_email
                           FROM users
                           WHERE (email = '$email' OR username = '$email')
                           AND (role = 'caterer' OR role = 'customer' OR role = 'portal')
                           LIMIT 1";
            $user_result = mysqli_query($conn, $user_query);

            if ($user_result && mysqli_num_rows($user_result) > 0) {
                $user = mysqli_fetch_assoc($user_result);
                $delivery_email = $user['recovery_email'] ?? '';

                if (!filter_var($delivery_email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'This account does not have a valid email address saved for password recovery.';
                } else {
                $otp = (string) random_int(100000, 999999);
                $otp_hash = password_hash($otp, PASSWORD_DEFAULT);
                $expires_at = date('Y-m-d H:i:s', time() + 600);

                mysqli_query($conn, "DELETE FROM password_resets WHERE user_id = '" . intval($user['id']) . "'");

                $insert_query = "INSERT INTO password_resets (user_id, email, otp_hash, attempts, expires_at, created_at)
                                 VALUES ('" . intval($user['id']) . "', '" . mysqli_real_escape_string($conn, $delivery_email) . "', '$otp_hash', 0, '$expires_at', NOW())";

                if (mysqli_query($conn, $insert_query)) {
                    $mail_error = '';
                    if (sendPasswordResetOtpEmail($delivery_email, $user['username'], $otp, $mail_error)) {
                        $_SESSION['password_reset_pending'] = [
                            'request_id' => mysqli_insert_id($conn),
                            'user_id' => intval($user['id']),
                            'username' => $user['username'],
                            'email' => $delivery_email
                        ];
                        $step = 'verify';
                    } else {
                        mysqli_query($conn, "DELETE FROM password_resets WHERE user_id = '" . intval($user['id']) . "'");
                        $error = $mail_error ?: 'Failed to send the verification code.';
                    }
                } else {
                    $error = 'Failed to create a password reset request.';
                }
                }
            } else {
                $error = 'No account was found for that email address.';
            }
        }
    } elseif (isset($_POST['verify_code'])) {
        if (!isset($_SESSION['password_reset_pending'])) {
            $error = 'Your reset session expired. Please request a new code.';
            $step = 'request';
        } else {
            $otp_code = trim($_POST['otp_code']);
            $pending = $_SESSION['password_reset_pending'];
            $request_id = intval($pending['request_id']);
            $user_id = intval($pending['user_id']);

            $reset_query = "SELECT * FROM password_resets WHERE id = '$request_id' AND user_id = '$user_id' LIMIT 1";
            $reset_result = mysqli_query($conn, $reset_query);

            if ($reset_result && mysqli_num_rows($reset_result) > 0) {
                $reset_row = mysqli_fetch_assoc($reset_result);

                if (!empty($reset_row['verified_at'])) {
                    $error = 'This verification code was already used. Please request a new one.';
                    unset($_SESSION['password_reset_pending']);
                    $step = 'request';
                } elseif (strtotime($reset_row['expires_at']) < time()) {
                    mysqli_query($conn, "DELETE FROM password_resets WHERE id = '$request_id'");
                    unset($_SESSION['password_reset_pending']);
                    $error = 'Your verification code has expired. Please request a new one.';
                    $step = 'request';
                } elseif (intval($reset_row['attempts']) >= 5) {
                    mysqli_query($conn, "DELETE FROM password_resets WHERE id = '$request_id'");
                    unset($_SESSION['password_reset_pending']);
                    $error = 'Too many invalid attempts. Please request a new code.';
                    $step = 'request';
                } elseif (!preg_match('/^\d{6}$/', $otp_code)) {
                    $error = 'Please enter a valid 6-digit code.';
                    $step = 'verify';
                } elseif (password_verify($otp_code, $reset_row['otp_hash'])) {
                    mysqli_query($conn, "UPDATE password_resets SET verified_at = NOW() WHERE id = '$request_id'");
                    $_SESSION['reset_user_id'] = $user_id;
                    $_SESSION['reset_username'] = $pending['username'];
                    $_SESSION['reset_email'] = $pending['email'];
                    $_SESSION['reset_verified'] = true;
                    unset($_SESSION['password_reset_pending']);
                    header('Location: reset_password.php');
                    exit();
                } else {
                    mysqli_query($conn, "UPDATE password_resets SET attempts = attempts + 1 WHERE id = '$request_id'");
                    $error = 'Incorrect verification code.';
                    $step = 'verify';
                }
            } else {
                unset($_SESSION['password_reset_pending']);
                $error = 'Reset request not found. Please request a new code.';
                $step = 'request';
            }
        }
    } elseif (isset($_POST['start_over'])) {
        unset($_SESSION['password_reset_pending']);
        $step = 'request';
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="soft-sage-theme auth-sage-theme login-layout">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Forgot Password - Catering Services Portal</title>
    <link rel="icon" type="image/png" href="uploads/logo.png">
    <link rel="shortcut icon" href="uploads/logo.png">
    <link rel="apple-touch-icon" href="uploads/logo.png">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/password-recovery.css?v=20261019-2">
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
            <span class="eyebrow">ACCOUNT RECOVERY</span>
            <h1>Forgot password?</h1>
            <p><?php echo $step === 'verify' ? 'Verify your email code' : 'Let’s get you back to planning great events.'; ?></p>
        </div>

        <div class="form-content">
            <?php if ($error): ?>
                <div class="alert alert-error" role="alert"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($step === 'verify' && isset($_SESSION['password_reset_pending'])): ?>
                <div class="step-box" id="code-help">
                    <strong>Step 2:</strong> Enter the 6-digit code sent to
                    <strong><?php echo htmlspecialchars($_SESSION['password_reset_pending']['email']); ?></strong>.
                </div>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="otp_code"><i class="fas fa-shield-alt"></i> Verification Code</label>
                        <input type="text" id="otp_code" name="otp_code" maxlength="6" inputmode="numeric" pattern="\d{6}" required autocomplete="one-time-code" placeholder="000000" aria-describedby="code-help">
                    </div>
                    <button type="submit" name="verify_code" class="btn"><i class="fas fa-check"></i> Verify Code</button>
                    <button type="submit" name="start_over" class="btn btn-secondary" formnovalidate><i class="fas fa-rotate-left"></i> Use Another Email</button>
                </form>
            <?php else: ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Registered Email Address</label>
                        <input type="email" id="email" name="email" required autocomplete="email" inputmode="email" placeholder="you@example.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                    <button type="submit" name="send_code" class="btn" id="sendCodeButton"><i class="fas fa-paper-plane" aria-hidden="true"></i> <span role="status" aria-live="polite">Send Verification Code</span></button>
                </form>
            <?php endif; ?>

            <div class="form-links">
                <a href="login.php"><i class="fas fa-sign-in-alt"></i> Back to Login</a>
                <a href="index.php"><i class="fas fa-home"></i> Back to Homepage</a>
            </div>
        </div>
    </div>
    </div>
    <?php include __DIR__ . '/includes/public-footer.php'; ?>

<script>
        const sendCodeButton = document.getElementById('sendCodeButton');
        if (sendCodeButton) {
            const sendCodeForm = sendCodeButton.form;
            const resetSendButton = () => {
                sendCodeButton.removeAttribute('aria-disabled');
                sendCodeForm.removeAttribute('aria-busy');
                sendCodeButton.querySelector('i').className = 'fas fa-paper-plane';
                sendCodeButton.querySelector('span').textContent = 'Send Verification Code';
            };
            sendCodeForm.addEventListener('submit', (event) => {
                if (sendCodeButton.getAttribute('aria-disabled') === 'true') {
                    event.preventDefault();
                    return;
                }
                // Keep the named submit button enabled so PHP receives send_code.
                sendCodeButton.setAttribute('aria-disabled', 'true');
                sendCodeForm.setAttribute('aria-busy', 'true');
                sendCodeButton.querySelector('i').className = 'fas fa-spinner fa-spin';
                sendCodeButton.querySelector('span').textContent = 'Sending…';
            });
            window.addEventListener('pageshow', resetSendButton);
        }
    </script>
</body>
</html>
