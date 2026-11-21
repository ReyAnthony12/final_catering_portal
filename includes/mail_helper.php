<?php

require_once __DIR__ . '/../config.php';

function smtpReadResponse($socket, $expectedCode) {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    if ($expectedCode !== null && strpos($response, (string) $expectedCode) !== 0) {
        throw new Exception('SMTP error: ' . trim($response));
    }

    return $response;
}

function smtpSendCommand($socket, $command, $expectedCode) {
    fwrite($socket, $command . "\r\n");
    return smtpReadResponse($socket, $expectedCode);
}

function sendEmailViaSmtp($toEmail, $toName, $subject, $htmlBody, &$errorMessage = null) {
    if (MAIL_USERNAME === '' || MAIL_PASSWORD === '' || MAIL_FROM_EMAIL === '') {
        $errorMessage = 'Email sending is not configured yet. Add Gmail SMTP settings to the protected .env file in your hosting htdocs folder.';
        return false;
    }

    // Port 587 uses STARTTLS; port 465 starts with TLS immediately.
    $host = (MAIL_PORT === 465 ? 'ssl://' : 'tcp://') . MAIL_HOST;
    $socket = @stream_socket_client($host . ':' . MAIL_PORT, $errno, $errstr, 15);

    if (!$socket) {
        $errorMessage = 'Unable to connect to the mail server: ' . $errstr;
        return false;
    }

    // Set read/write timeout so the page never hangs forever
    stream_set_timeout($socket, 10);

    try {
        smtpReadResponse($socket, 220);
        smtpSendCommand($socket, 'EHLO localhost', 250);
        if (MAIL_PORT !== 465) {
            smtpSendCommand($socket, 'STARTTLS', 220);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new Exception('Unable to establish a secure connection to the mail server.');
            }
            smtpSendCommand($socket, 'EHLO localhost', 250);
        }
        smtpSendCommand($socket, 'AUTH LOGIN', 334);
        smtpSendCommand($socket, base64_encode(MAIL_USERNAME), 334);
        smtpSendCommand($socket, base64_encode(MAIL_PASSWORD), 235);
        smtpSendCommand($socket, 'MAIL FROM: <' . MAIL_FROM_EMAIL . '>', 250);
        smtpSendCommand($socket, 'RCPT TO: <' . $toEmail . '>', 250);
        smtpSendCommand($socket, 'DATA', 354);

        $headers = [];
        $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>';
        $headers[] = 'To: ' . $toName . ' <' . $toEmail . '>';
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.";
        fwrite($socket, $message . "\r\n");
        smtpReadResponse($socket, 250);
        smtpSendCommand($socket, 'QUIT', 221);
        fclose($socket);
        return true;
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        fclose($socket);
        return false;
    }
}

function sendPasswordResetOtpEmail($email, $username, $otp, &$errorMessage = null) {
    $subject = 'Your Catering Portal Reset Code';
    $htmlBody = '
        <div style="font-family: Arial, sans-serif; background: #0a0a0a; color: #f5f5f5; padding: 24px;">
            <div style="max-width: 520px; margin: 0 auto; background: #1e1e1e; border: 1px solid #333333; border-radius: 12px; overflow: hidden;">
                <div style="padding: 20px; border-bottom: 3px solid #d4af37; text-align: center;">
                    <h2 style="margin: 0; color: #d4af37;">Catering Portal</h2>
                    <p style="margin: 8px 0 0; color: #a0a0a0;">Password Reset Verification</p>
                </div>
                <div style="padding: 24px;">
                    <p>Hello <strong>' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
                    <p>Use this verification code to reset your password:</p>
                    <div style="margin: 24px 0; text-align: center;">
                        <span style="display: inline-block; letter-spacing: 8px; font-size: 28px; font-weight: bold; color: #111; background: #d4af37; padding: 14px 22px; border-radius: 10px;">' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <p style="color: #a0a0a0;">This code will expire in 10 minutes.</p>
                    <p style="color: #f44336; font-size: 13px;">If you did not request this, you can ignore this email.</p>
                </div>
            </div>
        </div>';

    return sendEmailViaSmtp($email, $username, $subject, $htmlBody, $errorMessage);
}

function sendStoreApprovalEmail($email, $storeName, $ownerName = '', &$errorMessage = null) {
    $subject = 'Your Caterer Account Has Been Approved - Catering Portal';
    $loginUrl = defined('BASE_URL') ? BASE_URL . 'login.php' : 'login.php';
    $displayName = !empty($ownerName) ? $ownerName : $storeName;
    
    $htmlBody = '
        <div style="font-family: Arial, sans-serif; background: #0a0a0a; color: #f5f5f5; padding: 24px;">
            <div style="max-width: 560px; margin: 0 auto; background: #1e1e1e; border: 1px solid #333333; border-radius: 12px; overflow: hidden;">
                <div style="padding: 24px; border-bottom: 3px solid #4caf50; text-align: center; background: #141414;">
                    <h2 style="margin: 0; color: #d4af37; font-size: 24px;">Catering Portal</h2>
                    <p style="margin: 8px 0 0; color: #4caf50; font-weight: bold; font-size: 16px;">✓ Caterer Account Approved</p>
                </div>
                <div style="padding: 28px; line-height: 1.6;">
                    <p style="font-size: 16px; margin-top: 0;">Hello <strong>' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
                    <p>Congratulations! Your caterer registration for <strong style="color: #d4af37;">' . htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') . '</strong> has been reviewed and <strong style="color: #4caf50;">APPROVED</strong> by the Portal Administrator.</p>
                    
                    <div style="background: rgba(212, 175, 55, 0.1); border-left: 4px solid #d4af37; padding: 14px 18px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin: 0; font-size: 14px;"><strong>Account Details:</strong></p>
                        <ul style="margin: 8px 0 0; padding-left: 20px; font-size: 14px; color: #e0e0e0;">
                            <li>Caterer Name: <strong>' . htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') . '</strong></li>
                            <li>Registered Email: <strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong></li>
                            <li>Status: <span style="color: #4caf50; font-weight: bold;">Active</span></li>
                            <li>Free Trial: <span style="color: #d4af37; font-weight: bold;">7-Day Free Trial Activated</span></li>
                        </ul>
                    </div>
                    
                    <p>You can now log in to manage your menu, set business hours, configure catering packages, and accept customer bookings.</p>
                    
                    <div style="margin: 28px 0; text-align: center;">
                        <a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" style="display: inline-block; background: #d4af37; color: #111; font-weight: bold; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-size: 15px;">Login to Your Caterer</a>
                    </div>
                    
                    <p style="color: #a0a0a0; font-size: 13px; border-top: 1px solid #2a2a2a; padding-top: 16px; margin-bottom: 0;">If you have questions or need assistance, feel free to contact the Catering Portal support team.</p>
                </div>
            </div>
        </div>';

    return sendEmailViaSmtp($email, $displayName, $subject, $htmlBody, $errorMessage);
}

function sendStoreRejectionEmail($email, $storeName, $reason, $ownerName = '', &$errorMessage = null) {
    $subject = 'Update on Your Caterer Application - Catering Portal';
    $registerUrl = defined('BASE_URL') ? BASE_URL . 'register.php' : 'register.php';
    $displayName = !empty($ownerName) ? $ownerName : $storeName;
    
    $htmlBody = '
        <div style="font-family: Arial, sans-serif; background: #0a0a0a; color: #f5f5f5; padding: 24px;">
            <div style="max-width: 560px; margin: 0 auto; background: #1e1e1e; border: 1px solid #333333; border-radius: 12px; overflow: hidden;">
                <div style="padding: 24px; border-bottom: 3px solid #f44336; text-align: center; background: #141414;">
                    <h2 style="margin: 0; color: #d4af37; font-size: 24px;">Catering Portal</h2>
                    <p style="margin: 8px 0 0; color: #f44336; font-weight: bold; font-size: 16px;">Caterer Application Update</p>
                </div>
                <div style="padding: 28px; line-height: 1.6;">
                    <p style="font-size: 16px; margin-top: 0;">Hello <strong>' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
                    <p>Thank you for submitting your caterer registration for <strong style="color: #d4af37;">' . htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>
                    <p>After reviewing your submitted information and documents, our administrator found issues that prevented your application from being approved:</p>
                    
                    <div style="background: rgba(244, 67, 54, 0.1); border-left: 4px solid #f44336; padding: 14px 18px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin: 0 0 6px; font-weight: bold; color: #f44336; font-size: 14px;">Reason for Disapproval:</p>
                        <p style="margin: 0; color: #ffffff; font-size: 14px; white-space: pre-wrap;">' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</p>
                    </div>
                    
                    <p>Please review and correct the required information or documents. You may submit a new application through our registration page.</p>
                    
                    <div style="margin: 28px 0; text-align: center;">
                        <a href="' . htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8') . '" style="display: inline-block; background: #d4af37; color: #111; font-weight: bold; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-size: 15px;">Register Again with Corrections</a>
                    </div>
                    
                    <p style="color: #a0a0a0; font-size: 13px; border-top: 1px solid #2a2a2a; padding-top: 16px; margin-bottom: 0;">If you believe this decision was made in error or have any questions, please contact our support team.</p>
                </div>
            </div>
        </div>';

    return sendEmailViaSmtp($email, $displayName, $subject, $htmlBody, $errorMessage);
}
?>
