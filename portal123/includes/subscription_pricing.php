<?php
// Included after portal authentication in subscription.php.
$pricing_error = '';
require_once __DIR__ . '/../../includes/subscription_pricing_helper.php';
$pricing_success = $_SESSION['subscription_price_saved'] ?? '';
unset($_SESSION['subscription_price_saved']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $value = trim((string) ($_POST['monthly_fee'] ?? ''));
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $pricing_error = 'Invalid security token. Please refresh and try again.';
    } elseif (isset($_POST['upload_gcash'])) {
        $file = $_FILES['gcash_qr'] ?? null;
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
            $pricing_error = 'Select a QR image up to 5 MB.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!isset($types[$mime]) || !getimagesize($file['tmp_name'])) {
                $pricing_error = 'Select a valid JPEG, PNG, GIF or WebP image.';
            } else {
                $directory = __DIR__ . '/../../uploads/qr_codes/';
                $relative = 'uploads/qr_codes/gcash_qr_' . bin2hex(random_bytes(12)) . '.' . $types[$mime];
                $target = __DIR__ . '/../../' . $relative;
                if ((!is_dir($directory) && !mkdir($directory, 0755, true)) || !move_uploaded_file($file['tmp_name'], $target)) {
                    $pricing_error = 'Unable to upload the QR image. Please try again.';
                } else {
                    $stmt = mysqli_prepare($conn, "INSERT INTO system_settings (setting_key, setting_value) VALUES ('portal_gcash_qr', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $saved = false;
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, 's', $relative);
                        $saved = mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                    if ($saved) $pricing_success = 'GCash QR code updated.';
                    else {
                        unlink($target);
                        $pricing_error = 'Unable to save the QR code. Please try again.';
                    }
                }
            }
        }
    } else {
        $amount = normalizeSubscriptionMonthlyFee($value);
        if ($amount === null) {
            $pricing_error = 'Enter a price from PHP 0.01 to PHP 9,999,999.99 with up to two decimal places.';
        } elseif (saveSubscriptionMonthlyFee($conn, $amount, $price_save_error)) {
            $_SESSION['subscription_price_saved'] = 'Subscription price saved: PHP ' . number_format((float)$amount, 2) . '.';
            header('Location: subscription.php', true, 303);
            exit();
        } else {
            $pricing_error = $price_save_error ?: 'Unable to save the subscription price. Please try again.';
        }
    }
}
$subscription_fee = getSubscriptionFee($conn);
$qr_result = mysqli_query($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'portal_gcash_qr' LIMIT 1");
$qr_path = $qr_result ? (mysqli_fetch_assoc($qr_result)['setting_value'] ?? '') : '';
// Match the subscription management counts without changing subscription records.
$pricing_today = date('Y-m-d');
$pricing_warning = date('Y-m-d', strtotime('+' . getSubscriptionWarningDays() . ' days'));
$pricing_trash_exists = mysqli_query($conn, "SHOW TABLES LIKE 'portal_account_trash'");
$pricing_visible = ($pricing_trash_exists && mysqli_num_rows($pricing_trash_exists) > 0)
    ? ' AND NOT EXISTS (SELECT 1 FROM portal_account_trash t WHERE t.user_id = u.id)' : '';
$pricing_stores = "FROM store_details sd JOIN users u ON u.id = sd.user_id WHERE u.role = 'store' $pricing_visible";
$pricing_stats_result = mysqli_query($conn, "SELECT
    (SELECT COUNT(*) $pricing_stores AND sd.subscription_status = 'active' AND sd.subscription_expiry >= '$pricing_today') AS active,
    (SELECT COUNT(*) FROM subscriptions s JOIN users u ON u.id = s.store_id WHERE u.role = 'store' AND s.payment_status = 'pending' $pricing_visible) AS pending,
    (SELECT COUNT(*) $pricing_stores AND sd.subscription_status = 'active' AND sd.subscription_expiry BETWEEN '$pricing_today' AND '$pricing_warning') AS expiring,
    (SELECT COUNT(*) $pricing_stores AND (sd.subscription_status = 'expired' OR (sd.subscription_status = 'active' AND sd.subscription_expiry < '$pricing_today'))) AS expired");
$pricing_stats = $pricing_stats_result ? mysqli_fetch_assoc($pricing_stats_result) : null;

$page_title = 'Subscription Pricing';
require __DIR__ . '/header.php';
require __DIR__ . '/sidebar.php';
?>
<style>
.subscription-pricing{width:100%;max-width:820px;margin:0 auto;box-sizing:border-box;color:var(--text-main)}
.subscription-pricing *{box-sizing:border-box}
.subscription-pricing h2{margin:0 0 28px;font-size:clamp(23px,3vw,30px);font-weight:650;letter-spacing:-.8px;line-height:1.2}
.pricing-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:start}
.pricing-card{min-width:0;padding:20px;background:linear-gradient(145deg,#23231f 0%,#191a18 70%);border:1px solid rgba(220,200,145,.15);border-radius:16px;box-shadow:0 12px 28px rgba(0,0,0,.14)}
.pricing-card h3{margin:0 0 18px;font-size:16px;font-weight:600;letter-spacing:-.3px}
.subscription-pricing label{display:block;margin:0 0 10px;color:#c9c8bf;font-size:13px;font-weight:500}
.subscription-pricing input:not([type=hidden]){display:block;width:100%;min-width:0;padding:15px 16px;border:1px solid rgba(255,255,255,.1);border-radius:11px;background:#121411;color:#f4f1e6;font:inherit;outline:none;transition:border-color .2s,box-shadow .2s}
.subscription-pricing input[type=number]{font-size:27px;font-weight:500;letter-spacing:-.5px;font-variant-numeric:tabular-nums}
.subscription-pricing input:focus-visible{border-color:#c9ad61;box-shadow:0 0 0 3px rgba(201,173,97,.12)}
.subscription-pricing button{display:block;width:100%;min-height:48px;margin-top:24px;padding:13px 16px;border:1px solid #e5cb83;border-radius:11px;background:linear-gradient(110deg,#ead38f,#c6a64e);color:#201b0e;font:inherit;font-size:13px;font-weight:650;cursor:pointer;transition:filter .2s,transform .2s;line-height:1.4}
.subscription-pricing button:hover{filter:brightness(1.08)}
.subscription-pricing button:active{transform:translateY(1px)}
.subscription-pricing button:focus-visible{outline:2px solid #f3dda5;outline-offset:4px}
.qr-preview{display:flex;justify-content:center;align-items:center;margin-bottom:16px;padding:12px;background:#141612;border:1px solid rgba(255,255,255,.06);border-radius:11px}
.qr-preview img{display:block;width:100%;max-width:150px;height:auto;border-radius:6px}
.subscription-pricing input[type=file]{padding:10px;font-size:12px;color:#a9aaa2;overflow:hidden}
.subscription-pricing input[type=file]::file-selector-button{margin-right:10px;padding:9px 11px;border:1px solid rgba(222,203,149,.2);border-radius:7px;background:#2a2c24;color:#e4d7b0;font:inherit;font-weight:600;cursor:pointer}
.pricing-message{padding:14px 16px;border-radius:11px;margin-bottom:22px;font-size:13px;line-height:1.5}
.pricing-card.subscription-price-card{padding:14px}
.subscription-price-card h3{margin-bottom:12px;font-size:14px}
.subscription-pricing .subscription-price-card input[type=number]{padding:10px 12px;font-size:20px;border-radius:8px}
.subscription-pricing .subscription-price-card button{min-height:38px;margin-top:12px;padding:9px 12px;font-size:12px;border-radius:8px}
.pricing-overview{display:none}

/* Desktop layout; mobile keeps its existing styles. */
@media(min-width:993px){
    .subscription-settings-page .pricing-overview{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:24px;padding:28px 0;border-bottom:1px solid #37382e}
    .pricing-overview-item{min-width:0;padding-right:20px;border-right:1px solid #37382e}
    .pricing-overview-item:last-child{border-right:0;padding-right:0}
    .pricing-overview-label{color:#d1cec2;font-size:12px;font-weight:500}
    .pricing-overview-value{display:block;margin:10px 0 6px;color:#f2e5b9;font-size:30px;font-weight:600;line-height:1.2;font-variant-numeric:tabular-nums}
    .pricing-overview-description{margin:0;color:#999e91;font-size:11px;line-height:1.6}
    html:has(.subscription-settings-page){overflow-y:auto !important}
    body.portal-layout:has(.subscription-settings-page){overflow-y:visible !important}
    body.portal-layout .main-content.subscription-settings-page{display:block;min-height:100vh;min-height:100dvh;padding:24px;align-self:stretch}
    .subscription-settings-page .subscription-pricing{width:100%;max-width:none;margin:0}
    .subscription-settings-page .pricing-grid{display:flex;flex-direction:column;min-height:calc(100vh - 48px);min-height:calc(100dvh - 48px);padding:0 32px;border:1px solid #38382f;border-radius:16px;background:linear-gradient(120deg,#20211d,#1a1c18);box-shadow:0 12px 32px #00000018}
    .subscription-settings-page .pricing-card{display:grid;grid-template-columns:minmax(150px,.65fr) minmax(200px,1fr) minmax(220px,1fr);gap:18px 28px;align-items:center;min-width:0;margin:0;padding:32px 0;border:0;border-radius:0;background:none;box-shadow:none}
    .subscription-settings-page .pricing-card + .pricing-card{flex:1;border-top:1px solid #37382e;align-content:center;padding:40px 0}
    .subscription-settings-page .pricing-card h3{grid-column:1;grid-row:1;margin:0;padding:0;border:0;font-size:15px;font-weight:600;line-height:1.5;color:#ece9df}
    .subscription-settings-page .subscription-price-card input[type=number]{grid-column:2;grid-row:1;width:100%;min-height:50px;padding:12px 14px;font-size:23px;border-radius:8px;background:#131510;border-color:#3a3b31}
    .subscription-settings-page .pricing-card button{grid-column:3;width:100%;min-height:46px;margin:0;padding:12px 16px;border-radius:8px;font-size:12px;font-weight:600;box-shadow:none}
    .subscription-settings-page .subscription-price-card button{grid-row:1}
    .subscription-settings-page .pricing-card:not(.subscription-price-card) h3{grid-row:1 / span 2;align-self:start;padding-top:10px}
    .subscription-settings-page .qr-preview{grid-column:2;grid-row:1 / span 2;justify-content:center;width:100%;min-height:240px;margin:0;padding:20px;background:#151711;border:1px solid #313429;border-radius:12px}
    .subscription-settings-page .qr-preview img{width:200px;max-width:100%;height:auto;border:8px solid #fff;border-radius:8px;background:#fff}
    .subscription-settings-page input[type=file]{grid-column:3;grid-row:1;align-self:end;min-height:46px;padding:8px;font-size:12px;border-radius:8px;background:#131510;border-color:#3a3b31}
    .subscription-settings-page input[type=file]::file-selector-button{padding:8px 10px;margin-right:10px}
    .subscription-settings-page .pricing-card:not(.subscription-price-card) button{grid-row:2;align-self:start}
}

@media(min-width:993px){
    .subscription-settings-page .pricing-card:not(.subscription-price-card){grid-template-columns:minmax(260px,340px) minmax(0,1fr);align-content:center}
    .subscription-settings-page .pricing-card:not(.subscription-price-card) h3{grid-column:1;grid-row:1;padding-top:0}
    .subscription-settings-page .pricing-card:not(.subscription-price-card) .qr-preview{grid-column:1;grid-row:2;min-height:240px}
    .subscription-settings-page .pricing-card:not(.subscription-price-card) input[type=file]{grid-column:1;grid-row:3;align-self:auto}
    .subscription-settings-page .pricing-card:not(.subscription-price-card) button{grid-column:1;grid-row:4;align-self:auto}
}

/* Place both settings sections on the same desktop row. */
@media(min-width:993px){
    .subscription-settings-page .pricing-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:40px;grid-template-rows:auto 1fr;align-items:start}
    .subscription-settings-page .pricing-overview{grid-column:1 / -1;grid-row:1}
    .subscription-settings-page .pricing-card.subscription-price-card,
    .subscription-settings-page .pricing-card:not(.subscription-price-card){display:flex;flex-direction:column;align-items:stretch;gap:18px;grid-row:2;margin:0;padding:28px 0;border:0;align-self:stretch}
    .subscription-settings-page .pricing-card.subscription-price-card{grid-column:1;padding-right:40px;border-right:1px solid #37382e}
    .subscription-settings-page .pricing-card:not(.subscription-price-card){grid-column:2}
    .subscription-settings-page .pricing-card h3,
    .subscription-settings-page .pricing-card:not(.subscription-price-card) h3{margin:0 0 6px;padding:0;min-height:23px}
    .subscription-settings-page .pricing-card .qr-preview{width:100%;max-width:340px;align-self:flex-start}
    .subscription-settings-page .pricing-card:not(.subscription-price-card) input[type=file],
    .subscription-settings-page .pricing-card:not(.subscription-price-card) button{width:100%;max-width:340px;align-self:flex-start}
    .subscription-settings-page .subscription-price-card button{width:auto;align-self:flex-end}
}

@media(max-width:700px){.subscription-pricing{max-width:440px}.pricing-grid{grid-template-columns:minmax(0,1fr);gap:12px}.pricing-card{padding:18px;border-radius:14px}.pricing-card h3{margin-bottom:16px}.subscription-pricing button{margin-top:16px;min-height:44px}.subscription-pricing input:not([type=hidden]){padding:12px}.subscription-pricing input[type=file]{padding:8px}.subscription-pricing input[type=number]{font-size:23px}}
@media(max-width:380px){.pricing-card{padding:16px}.qr-preview img{max-width:130px}}
</style>
<link rel="stylesheet" href="../assets/css/portal-subscription-mobile.css?v=20261030-1">
<div class="main-content subscription-settings-page">
    <div class="subscription-pricing">


        <?php if ($pricing_success): ?><div class="pricing-message" role="status" style="background:rgba(46,204,113,.15);color:#2ecc71"><?php echo htmlspecialchars($pricing_success); ?></div><?php endif; ?>
        <?php if ($pricing_error): ?><div class="pricing-message" role="alert" style="background:rgba(231,76,60,.15);color:#ff8f8f"><?php echo htmlspecialchars($pricing_error); ?></div><?php endif; ?>
        <div class="pricing-grid">
            <section class="pricing-overview" aria-label="Subscription overview">
                <?php foreach ([
                    'active' => ['Active subscriptions', 'Caterers with current coverage'],
                    'pending' => ['Pending payments', 'Payments awaiting verification'],
                    'expiring' => ['Expiring soon', 'Coverage ending within ' . getSubscriptionWarningDays() . ' days'],
                    'expired' => ['Expired subscriptions', 'Caterers with expired coverage']
                ] as $stat_key => [$stat_label, $stat_description]): ?>
                <div class="pricing-overview-item">
                    <span class="pricing-overview-label"><?php echo htmlspecialchars($stat_label); ?></span>
                    <strong class="pricing-overview-value"><?php echo $pricing_stats !== null ? number_format((int) $pricing_stats[$stat_key]) : '—'; ?></strong>
                    <p class="pricing-overview-description"><?php echo htmlspecialchars($stat_description); ?></p>
                </div>
                <?php endforeach; ?>
            </section>
        <form class="pricing-card subscription-price-card" method="post" action="subscription.php">
            <h3>Subscription pricing</h3>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES); ?>">
            <label for="monthly-fee">Monthly subscription price (PHP)</label>
            <input id="monthly-fee" inputmode="decimal" name="monthly_fee" type="number" aria-label="Monthly subscription price in PHP" min="0.01" max="9999999.99" step="0.01" required value="<?php echo number_format($subscription_fee, 2, '.', ''); ?>">
            <button type="submit">Save subscription price</button>
        </form>
        <form class="pricing-card" method="post" action="subscription.php" enctype="multipart/form-data">
            <h3>GCash QR code</h3>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES); ?>">
            <?php if ($qr_path): ?><div class="qr-preview"><img src="../<?php echo htmlspecialchars($qr_path, ENT_QUOTES); ?>" alt="Current GCash QR code" ></div><?php endif; ?>
            <input id="gcash-qr" type="file" name="gcash_qr" aria-label="GCash QR code image" accept="image/jpeg,image/png,image/gif,image/webp" required>
            <button type="submit" name="upload_gcash" value="1">Update GCash QR Code</button>
        </form>
        </div>
    </div>
</div>
<script>
    document.querySelectorAll('.subscription-pricing .pricing-message[role="status"]').forEach(function (notice) {
        window.setTimeout(function () {
            notice.remove();
        }, 3000);
    });
</script>

<?php require __DIR__ . '/footer.php'; ?>

