<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/subscription_helper.php';
requireLogin();
if (getUserRole() !== 'portal') {
    header('Location: ../login.php');
    exit;
}
header('Cache-Control: no-store');
$page_title = 'Caterer Subscription Payments';
$fee = getSubscriptionFee($conn);
$today = date('Y-m-d');
$warning_days = getSubscriptionWarningDays();
$coverage_filter = (string) ($_GET['coverage'] ?? 'all');
if (!in_array($coverage_filter, ['all', 'expiring', 'expired', 'active'], true)) $coverage_filter = 'all';
$error_message = '';
$caterers = [];
$payments = [];
$selected_caterer_id = max(0, (int) ($_GET['caterer'] ?? 0));
$selected_caterer = null;
$received = 0.0;
$pending = 0.0;
$due_count = 0;
try {
    // Older installations may not yet have the shared account-trash table.
    $trash_check = mysqli_query($conn, "SHOW TABLES LIKE 'portal_account_trash'");
    $exclude_trash = $trash_check && mysqli_num_rows($trash_check)
        ? ' AND NOT EXISTS (SELECT 1 FROM portal_account_trash t WHERE t.user_id = u.id)' : '';
    $result = mysqli_query($conn, "SELECT sd.user_id, sd.business_name, sd.subscription_status, sd.subscription_expiry,
        u.email, u.profile_image, p.amount, p.reference_number, p.payment_status, p.created_at
        FROM store_details sd JOIN users u ON u.id = sd.user_id
        LEFT JOIN subscriptions p ON p.id = (SELECT MAX(s.id) FROM subscriptions s WHERE s.store_id = sd.user_id)
        WHERE u.role IN ('store', 'caterer') $exclude_trash
        ORDER BY sd.subscription_expiry ASC, sd.business_name ASC");
    if (!$result) throw new RuntimeException('Unable to load subscriptions');
    while ($row = mysqli_fetch_assoc($result)) {
        $row['renewal_due'] = empty($row['subscription_expiry']) || $row['subscription_expiry'] < $today;
        if ($row['renewal_due']) $due_count++;
        $row['coverage_state'] = payoutCoverage($row['subscription_expiry'], $row['subscription_status'], $today, $warning_days);
        if ((int) $row['user_id'] === $selected_caterer_id) $selected_caterer = $row;
        $caterers[] = $row;
    }
    $result = mysqli_query($conn, "SELECT s.*, sd.business_name, sd.subscription_expiry AS current_expiry, sd.subscription_status AS current_status, u.profile_image FROM subscriptions s
        JOIN users u ON u.id = s.store_id JOIN store_details sd ON sd.user_id = s.store_id
        WHERE u.role IN ('store', 'caterer') $exclude_trash ORDER BY s.created_at DESC, s.id DESC");
    if (!$result) throw new RuntimeException('Unable to load payments');
    while ($row = mysqli_fetch_assoc($result)) {
        if ($row['payment_status'] === 'paid') $received += (float) $row['amount'];
        if ($row['payment_status'] === 'pending') $pending += (float) $row['amount'];
        $row['coverage_state'] = payoutCoverage($row['current_expiry'], $row['current_status'], $today, $warning_days);
        $payments[] = $row;
    }
} catch (Throwable $e) {
    $error_message = 'Unable to load subscription records. Please try again.';
}
function payoutEscape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function payoutDate($value): string { return $value && strtotime($value) ? date('M d, Y', strtotime($value)) : 'Not set'; }
// Always use the caterer's current coverage, not the dates of an older payment.
function payoutCoverage($expiry, $status, string $today, int $warning_days): array {
    if (!$expiry || !strtotime($expiry)) return ['key' => 'expired', 'label' => 'No active coverage', 'note' => 'Renewal due'];
    $days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable(date('Y-m-d', strtotime($expiry))))->format('%r%a');
    if ($days < 0 || $status === 'expired') return ['key' => 'expired', 'label' => 'Expired', 'note' => 'Renewal due'];
    if ($status !== 'active') return ['key' => 'expired', 'label' => 'Inactive', 'note' => 'Renewal due'];
    if ($days <= $warning_days) return ['key' => 'expiring', 'label' => 'Expiring soon', 'note' => $days === 0 ? 'Expires today' : $days . ($days === 1 ? ' day left' : ' days left')];
    return ['key' => 'active', 'label' => 'Active', 'note' => $days . ' days left'];
}
function payoutIsExpired(array $caterer, string $today): bool {
    return $caterer['subscription_status'] === 'expired'
        || (!empty($caterer['subscription_expiry']) && substr($caterer['subscription_expiry'], 0, 10) < $today);
}
function payoutProfile(array $row): string {
    $name = (string) $row['business_name'];
    $initial = strtoupper(substr(trim($name), 0, 1));
    $path = str_replace('\\', '/', (string) ($row['profile_image'] ?? ''));
    $avatar = '<span class="finance-avatar" aria-hidden="true">' . payoutEscape($initial ?: '?') . '</span>';
    if (strpos($path, 'uploads/') === 0 && strpos($path, '..') === false && is_file(__DIR__ . '/../' . $path)) {
        $avatar = '<img class="finance-avatar" src="../' . payoutEscape($path) . '" alt="" width="40" height="40" loading="lazy">';
    }
    return '<div class="finance-caterer">' . $avatar . '<div class="finance-caterer-copy"><strong>' . payoutEscape($name) . '</strong>' . '</div></div>';
}
$expired_count = count(array_filter($caterers, fn($caterer) => payoutIsExpired($caterer, $today)));
$live_refresh = $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['live']);
if ($live_refresh) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    if ($error_message !== '') { http_response_code(503); exit; }
}
$extra_head = '<link rel="stylesheet" href="payouts-responsive.css?v=20261016-9">';
if (!$live_refresh) {
    require_once __DIR__ . '/includes/header.php';
    require_once __DIR__ . '/includes/sidebar.php';
}
?>
<div class="main-content payouts-page"><div class="finance-shell">
    <?php if ($error_message): ?><p role="alert"><?php echo payoutEscape($error_message); ?></p><?php endif; ?>
    <section class="finance-kpi-grid">
        <article class="finance-kpi-card"><div class="finance-kpi-label">Monthly Subscription</div><div class="finance-kpi-value">&#8369;<?php echo number_format($fee, 2); ?></div></article>
        <article class="finance-kpi-card"><div class="finance-kpi-label">Subscription Collections</div><div class="finance-kpi-value">&#8369;<?php echo number_format($received, 2); ?></div></article>
    </section>

    <section class="finance-section">
    <?php if ($selected_caterer_id > 0): ?>
        <div class="finance-history-header">
            <a class="finance-manage" href="payouts.php?coverage=<?php echo payoutEscape($coverage_filter); ?>#caterer-<?php echo $selected_caterer_id; ?>">&larr; Back</a>
            <?php if ($selected_caterer): ?><a id="manage-subscription" class="finance-manage" href="subscription.php?caterer_id=<?php echo $selected_caterer_id; ?>&amp;from=history&amp;coverage=<?php echo payoutEscape($coverage_filter); ?>">Manage subscription</a><?php endif; ?>
        </div>
        <?php if ($selected_caterer): ?>
            <div class="finance-detail-profile"><?php echo payoutProfile($selected_caterer); ?>
                <div><span class="finance-status coverage-<?php echo $selected_caterer['coverage_state']['key']; ?>"><?php echo payoutEscape($selected_caterer['coverage_state']['label']); ?></span>
                <small><?php echo payoutEscape($selected_caterer['coverage_state']['note']); ?> &middot; <?php echo payoutDate($selected_caterer['subscription_expiry']); ?></small></div>
            </div>
            <h3>Payment records</h3>
            <?php $history = array_values(array_filter($payments, fn($payment) => (int) $payment['store_id'] === $selected_caterer_id)); ?>
            <div class="finance-table-wrap"><table class="finance-table workspace-records finance-payment-records"><thead><tr><th>Amount</th><th>Reference</th><th>Submitted</th><th>Status</th><th>Payment Coverage</th></tr></thead><tbody>
            <?php foreach ($history as $row): ?><tr>
                <td data-label="Amount" class="finance-money">&#8369;<?php echo number_format((float) $row['amount'], 2); ?></td>
                <td data-label="Reference"><?php echo payoutEscape($row['reference_number'] ?: '—'); ?></td>
                <td data-label="Submitted"><?php echo payoutDate($row['created_at']); ?></td>
                <td data-label="Status"><span class="finance-status <?php echo payoutEscape($row['payment_status']); ?>"><?php echo payoutEscape(['paid'=>'Approved', 'pending'=>'Under review', 'rejected'=>'Rejected'][$row['payment_status']] ?? 'Unknown'); ?></span></td>
                <td data-label="Payment coverage"><?php echo !empty($row['start_date']) && !empty($row['end_date']) ? payoutDate($row['start_date']) . ' – ' . payoutDate($row['end_date']) : 'Not activated'; ?></td>
            </tr><?php endforeach; ?>
            <?php if (!$history): ?><tr><td colspan="5" class="finance-empty">No payment records yet.</td></tr><?php endif; ?>
            </tbody></table></div>
        <?php else: ?><p role="status">Caterer unavailable.</p><?php endif; ?>
    <?php else: ?>
        <div class="finance-history-header"><h3>Subscription Payment History</h3>
            <?php if ($expired_count > 0): ?><a class="finance-expired-link" href="payouts.php?coverage=expired">Expired <span class="finance-expired-badge" aria-label="<?php echo $expired_count; ?> expired subscriptions"><?php echo $expired_count; ?></span></a><?php endif; ?>
            <form method="get" class="finance-coverage-filter">
                <select name="coverage" id="coverageFilter" aria-label="Filter subscriptions">
                    <?php foreach (['all' => 'All subscriptions', 'expiring' => 'Expiring soon', 'expired' => 'Expired / Inactive', 'active' => 'Active'] as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo $coverage_filter === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline">Apply</button>
            </form>
        </div>
        <?php $visible_caterers = array_values(array_filter($caterers, fn($caterer) => $coverage_filter === 'all' || $caterer['coverage_state']['key'] === $coverage_filter)); ?>
        <div class="finance-profile-grid">
        <?php foreach ($visible_caterers as $row): ?>
            <?php $profile = $row; unset($profile['email']); ?>
            <a id="caterer-<?php echo (int) $row['user_id']; ?>" class="finance-profile-link finance-profile-card" href="payouts.php?caterer=<?php echo (int) $row['user_id']; ?>&amp;coverage=<?php echo payoutEscape($coverage_filter); ?>"><?php echo payoutProfile($profile); ?><?php if (payoutIsExpired($row, $today)): ?><span class="finance-expired-badge" title="Subscription expired" aria-label="1 expired subscription">1</span><?php endif; ?></a>
        <?php endforeach; ?>
        <?php if (!$visible_caterers): ?><p class="finance-empty"><?php echo $error_message ? 'Subscription records unavailable.' : 'No caterers match this filter.'; ?></p><?php endif; ?>
        </div>
    <?php endif; ?>
    </section>
</div></div>
<?php if ($live_refresh) exit; ?>
<script>
(() => {
    let refreshing = false;
    function restoreCatererTarget() {
        if (!/^#(?:caterer-\d+|manage-subscription)$/.test(location.hash)) return;
        const target = document.getElementById(location.hash.slice(1));
        if (!target) return;
        target.scrollIntoView({block: 'center', behavior: 'instant'});
        target.focus({preventScroll: true});
    }
    // A fresh Back navigation retains the filter and targets the selected profile.
    requestAnimationFrame(restoreCatererTarget);
    window.addEventListener('hashchange', restoreCatererTarget);
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            refreshSubscriptions();
            requestAnimationFrame(restoreCatererTarget);
        }
    });
    async function refreshSubscriptions() {
        if (refreshing || document.hidden) return;
        refreshing = true;
        try {
            const url = new URL(location.href);
            url.searchParams.set('live', '1');
            const response = await fetch(url, {cache: 'no-store', credentials: 'same-origin'});
            if (!response.ok || response.redirected) return;
            const incoming = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('.finance-shell');
            const current = document.querySelector('.finance-shell');
            if (!incoming || !current || current.contains(document.activeElement)) return;
            // Leave unchanged records in place so polling never resets the layout or selection.
            if (incoming.innerHTML === current.innerHTML || window.getSelection()?.toString()) return;
            const offsets = [...current.querySelectorAll('.finance-table-wrap')].map(el => el.scrollLeft);
            current.replaceWith(incoming);
            incoming.querySelectorAll('.finance-table-wrap').forEach((el, i) => el.scrollLeft = offsets[i] || 0);
        } catch (_) { /* Retry on the next interval. */ }
        finally { refreshing = false; }
    }
    setInterval(refreshSubscriptions, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshSubscriptions(); });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
