<?php
session_start();
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/financial_helper.php';
require_once __DIR__ . '/../includes/booking_pricing_helper.php';
require_once __DIR__ . '/../includes/booking_flow_helper.php';
require_once __DIR__ . '/../includes/order_reference_helper.php';
require_once __DIR__ . '/../includes/media_path_helper.php';

// Check Caterer Login
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['store', 'caterer'])) {
    header("Location: ../login.php");
    exit();
}

$store_id = $_SESSION['user_id'];
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
// Dashboard links explicitly retain their return destination after payment actions.
$payment_return_query = ($_GET['return_to'] ?? '') === 'payments' ? '&return_to=payments' : '';
if (($_GET['embed'] ?? '') === '1') $payment_return_query .= '&embed=1';
$payment_back_href = $payment_return_query !== '' ? 'payments.php?restore=1' : 'order-details.php?id=' . $order_id;
// Preserve the originating order-details page and its selected capacity date.
$capacity_return_date = (string) ($_GET['capacity_date'] ?? '');
$capacity_date_parts = explode('-', $capacity_return_date);
if ($payment_return_query === ''
    && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $capacity_return_date)
    && checkdate((int) ($capacity_date_parts[1] ?? 0), (int) ($capacity_date_parts[2] ?? 0), (int) ($capacity_date_parts[0] ?? 0))) {
    $payment_return_query = '&capacity_date=' . rawurlencode($capacity_return_date);
    $payment_back_href = 'order-details.php?id=' . $order_id . $payment_return_query;
}

function normalizePaymentReceiptPath($payment) {
    $path = $payment['proof_image'] ?? ($payment['receipt_image'] ?? '');
    return is_string($path) ? trim($path) : '';
}

function renderPaymentTimelineReceiptThumb(array $event): string {
    if (($event['type'] ?? '') !== 'payment') {
        return '';
    }

    $receipt_path = mediaUploadPath(normalizePaymentReceiptPath($event), '');
    if ($receipt_path === '') {
        return '';
    }

    $receipt_url = htmlspecialchars(mediaUploadUrl($receipt_path, '../', ''), ENT_QUOTES, 'UTF-8');
    $receipt_title = htmlspecialchars((string) ($event['title'] ?? 'Payment Receipt'), ENT_QUOTES, 'UTF-8');
    return '<button type="button" class="timeline-receipt-button" data-proof-url="' . $receipt_url . '" data-proof-title="' . $receipt_title . '" onclick="openProofModal(this.dataset.proofUrl, this.dataset.proofTitle)" aria-label="View payment receipt" title="Click to view payment receipt"><img src="' . $receipt_url . '" alt="Payment receipt thumbnail"></button>';
}

function getReceiptStatusLabel($status, $is_latest = false) {
    $status = strtolower(trim((string) $status));
    if ($status === 'verified') {
        return 'Verified';
    }
    if ($status === 'rejected') {
        return 'Rejected';
    }
    return $is_latest ? 'Newest Upload' : 'Pending';
}

function formatStorePaymentTypeLabel($payment_type) {
    $payment_type = strtolower(trim((string)$payment_type));
    $labels = [
        'downpayment' => 'Downpayment',
        'down_payment' => 'Downpayment',
        'installment' => 'Additional Payment',
        'additional' => 'Additional Payment',
        'additional_payment' => 'Additional Payment',
        'full_payment' => 'Full Payment'
    ];

    return $labels[$payment_type] ?? ucwords(str_replace('_', ' ', $payment_type));
}

function getStorePaymentMethodIcon($payment_method) {
    $payment_method = strtolower(trim((string)$payment_method));
    if ($payment_method === 'gcash') {
        return 'fa-mobile-alt';
    }
    if ($payment_method === 'bank') {
        return 'fa-university';
    }
    return 'fa-money-bill';
}

function getCombinedOrderPaymentHistory($order, $payments) {
    $combined = is_array($payments) ? $payments : [];
    $initial_receipt = trim((string)($order['payment_screenshot'] ?? ''));
    $has_real_receipt = false;

    foreach ($combined as $payment) {
        if (normalizePaymentReceiptPath($payment) !== '') {
            $has_real_receipt = true;
            break;
        }
    }

    if ($initial_receipt !== '' && !$has_real_receipt) {
        $already_exists = false;
        foreach ($combined as $payment) {
            if (normalizePaymentReceiptPath($payment) === $initial_receipt) {
                $already_exists = true;
                break;
            }
        }

        if (!$already_exists) {
            $initial_amount = 0;
            if (!empty($order['down_payment_required'])) {
                $initial_amount = floatval($order['down_payment_required']);
            } elseif (!empty($order['total_amount'])) {
                $initial_amount = floatval($order['total_amount']) * 0.5;
            }

            $combined[] = [
                'id' => 'initial-order-proof-' . intval($order['id'] ?? 0),
                'created_at' => $order['created_at'] ?? $order['order_date'] ?? date('Y-m-d H:i:s'),
                'payment_type' => 'down_payment',
                'payment_method' => $order['payment_method'] ?? 'gcash',
                'amount' => $initial_amount,
                'status' => 'pending',
                'receipt_image' => $initial_receipt,
                'proof_image' => $initial_receipt,
                'notes' => 'Initial booking downpayment proof',
                'reject_reason' => ''
            ];
        }
    }

    usort($combined, function($a, $b) {
        return strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? '');
    });

    return $combined;
}

function getStoreLatestReceiptUploads($conn, $store_id, $limit = 12) {
    $items = [];

    $payment_q = mysqli_query($conn, "SELECT p.*, o.customer_id, o.order_number, o.created_at as order_created_at, o.payment_screenshot, u.profile_image, u.first_name, u.last_name
        FROM payments p
        JOIN bookings o ON p.order_id = o.id
        JOIN users u ON o.customer_id = u.id
        WHERE o.store_id = '" . intval($store_id) . "'
        AND (
            (p.receipt_image IS NOT NULL AND p.receipt_image <> '')
            OR (p.proof_image IS NOT NULL AND p.proof_image <> '')
        )
        ORDER BY p.created_at DESC
        LIMIT " . intval(max($limit * 2, 20)));

    if ($payment_q) {
        while ($row = mysqli_fetch_assoc($payment_q)) {
            $items[] = $row;
        }
    }

    $initial_q = mysqli_query($conn, "SELECT o.id as order_id, o.customer_id, o.order_number, o.created_at, o.payment_screenshot as receipt_image, o.payment_method, o.down_payment_required, u.profile_image, u.first_name, u.last_name
        FROM bookings o
        JOIN users u ON o.customer_id = u.id
        WHERE o.store_id = '" . intval($store_id) . "'
        AND o.payment_screenshot IS NOT NULL
        AND o.payment_screenshot <> ''
        AND NOT EXISTS (
            SELECT 1
            FROM payments p
            WHERE p.order_id = o.id
              AND (
                    (p.receipt_image IS NOT NULL AND p.receipt_image <> '')
                    OR (p.proof_image IS NOT NULL AND p.proof_image <> '')
              )
        )
        ORDER BY o.created_at DESC
        LIMIT " . intval(max($limit * 2, 20)));

    if ($initial_q) {
        while ($row = mysqli_fetch_assoc($initial_q)) {
            $items[] = [
                'id' => 'initial-order-proof-' . intval($row['order_id']),
                'customer_id' => $row['customer_id'],
                'profile_image' => $row['profile_image'] ?? '',
                'order_id' => intval($row['order_id']),
                'order_number' => $row['order_number'],
                'created_at' => $row['created_at'],
                'payment_type' => 'down_payment',
                'payment_method' => $row['payment_method'] ?: 'gcash',
                'amount' => floatval($row['down_payment_required'] ?? 0),
                'status' => 'pending',
                'receipt_image' => $row['receipt_image'],
                'proof_image' => $row['receipt_image'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'notes' => 'Initial booking downpayment proof'
            ];
        }
    }

    $deduped = [];
    $seen = [];
    usort($items, function($a, $b) {
        return strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? '');
    });

    foreach ($items as $item) {
        $key = ($item['order_id'] ?? '') . '|' . normalizePaymentReceiptPath($item);
        if ($key === '|' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $item;
        if (count($deduped) >= $limit) {
            break;
        }
    }

    return $deduped;
}

function renderPaymentCustomerAvatar($customer) {
    $profile = trim((string)($customer['profile_image'] ?? ''));
    $name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
    ?>
    <span class="customer-avatar">
        <img src="<?php echo htmlspecialchars($profile !== '' ? '../' . ltrim($profile, '/') : '../uploads/logo.png', ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($profile !== '' ? $name . ' profile' : 'Catering Portal logo', ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" onerror="this.onerror = null; this.src = '../uploads/logo.png'; this.alt = 'Catering Portal logo';">
    </span>
    <?php
}

function renderCustomerActivity($rows, $section) {
    if ($rows instanceof mysqli_result) $rows = mysqli_fetch_all($rows, MYSQLI_ASSOC);
    $groups = [];
    foreach ($rows as $row) $groups[$row['customer_id']][] = $row;
    ob_start();
    if (!$groups): ?>
        <div class="activity-empty"><span><i class="fas <?php echo $section === 'today' ? 'fa-clock' : 'fa-receipt'; ?>"></i></span><strong><?php echo $section === 'today' ? 'No transactions today' : ($section === 'receipts' ? 'No uploaded receipts yet' : 'No transactions yet'); ?></strong><?php if ($section !== 'today'): ?><p>Customer payment activity will appear here.</p><?php endif; ?></div>
    <?php endif;
    foreach ($groups as $customerId => $items):
        $name = trim($items[0]['first_name'] . ' ' . $items[0]['last_name']);
        $total = array_sum(array_column($items, 'amount'));
        $bookingCount = count(array_unique(array_column($items, 'order_id')));
    ?>
        <details class="payment-customer activity-customer" data-customer-id="<?php echo (int) $customerId; ?>" data-activity-key="<?php echo htmlspecialchars($section . '-' . $customerId); ?>">
            <summary>
                <?php renderPaymentCustomerAvatar($items[0]); ?>
                <span class="customer-heading"><strong><?php echo htmlspecialchars($name); ?></strong><small><?php echo count($items); ?> <?php echo $section === 'receipts' ? 'receipt' : 'transaction'; ?><?php echo count($items) === 1 ? '' : 's'; ?> · <?php echo $bookingCount; ?> booking<?php echo $bookingCount === 1 ? '' : 's'; ?></small></span>
                <span class="customer-balance"><small><?php echo $section === 'receipts' ? 'Uploaded amount' : 'Transaction total'; ?></small><strong>&#8369;<?php echo number_format($total, 2); ?></strong></span>
            </summary>
            <div class="customer-bookings">
            <?php foreach ($items as $item):
                $status = strtolower($item['status'] ?? 'pending');
                if (!in_array($status, ['verified', 'pending', 'rejected'], true)) $status = 'pending';
                $receipt = normalizePaymentReceiptPath($item);
                $reference = formatOrderReference($item['order_id'], $item['order_number']);
            ?>
                <article class="payment-booking activity-item">
                    <div class="booking-top"><a href="payments.php?return_to=payments&amp;id=<?php echo intval($item['order_id']); ?>"><?php echo htmlspecialchars($reference); ?></a><span class="receipt-status-chip receipt-status-<?php echo $status; ?>"><?php echo ucfirst($status); ?></span></div>
                    <div class="booking-date"><i class="far fa-calendar"></i> <?php echo date('M d, Y · h:i A', strtotime($item['created_at'])); ?></div>
                    <div class="activity-payment"><div><small><?php echo htmlspecialchars(formatStorePaymentTypeLabel($item['payment_type'] ?? 'payment')); ?></small><strong>&#8369;<?php echo number_format($item['amount'], 2); ?></strong></div><span><i class="fas <?php echo getStorePaymentMethodIcon($item['payment_method'] ?? 'cash'); ?>"></i> <?php echo htmlspecialchars(ucfirst($item['payment_method'] ?? 'cash')); ?></span></div>
                    <div class="booking-actions">
                        <?php if ($receipt !== ''): ?>
                            <button type="button" class="proof-link-btn" data-proof-src="<?php echo htmlspecialchars('../' . $receipt, ENT_QUOTES); ?>" data-proof-title="<?php echo htmlspecialchars('Payment proof for ' . $reference, ENT_QUOTES); ?>" onclick="openProofModal(this.dataset.proofSrc, this.dataset.proofTitle)"><i class="fas fa-image"></i> View receipt</button>
                        <?php endif; ?>
                        <a class="action-btn btn-view" href="payments.php?return_to=payments&amp;id=<?php echo intval($item['order_id']); ?>"><i class="fas fa-eye"></i> Manage booking</a>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach;
    return ob_get_clean();
}
function renderRecentReceiptRows($payments) {
    ob_start();
    if (!empty($payments)):
        foreach ($payments as $index => $row):
            $receipt_path = normalizePaymentReceiptPath($row);
            $is_latest = ($index === 0);
            $status = strtolower($row['status'] ?? 'pending');
            $badge_class = 'receipt-status-' . ($status === 'verified' ? 'verified' : ($status === 'rejected' ? 'rejected' : 'pending'));
            ?>
            <tr class="<?php echo $is_latest ? 'new-receipt-row' : ''; ?>">
                <td style="color: var(--accent-gold); font-weight: 600;"><?php echo htmlspecialchars(formatOrderReference($row['order_id'], $row['order_number'])); ?></td>
                <td>
                    <div><a href="payments.php?return_to=payments&amp;id=<?php echo $row['order_id']; ?>" class="customer-link"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></a></div>
                    <div style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars(formatStorePaymentTypeLabel($row['payment_type'])); ?></div>
                </td>
                <td>
                    <div style="font-weight: 500;"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></div>
                    <div style="font-size: 11px; color: var(--text-muted);"><?php echo date('h:i A', strtotime($row['created_at'])); ?></div>
                </td>
                <td style="font-weight: 700;">&#8369;<?php echo number_format($row['amount'], 2); ?></td>
                <td>
                    <span class="receipt-status-chip <?php echo $badge_class; ?> <?php echo $is_latest ? 'receipt-newest-label' : ''; ?>">
                        <?php echo htmlspecialchars(getReceiptStatusLabel($status, $is_latest)); ?>
                    </span>
                </td>
                <td>
                    <?php if ($receipt_path !== ''): ?>
                        <button type="button" class="proof-link-btn" onclick="openProofModal('../<?php echo htmlspecialchars($receipt_path); ?>', 'Payment proof for <?php echo htmlspecialchars(formatOrderReference($row['order_id'], $row['order_number'])); ?>')">
                            <i class="fas fa-image"></i> View Receipt
                        </button>
                    <?php else: ?>
                        <span style="color: var(--text-muted); font-size: 12px;">No image</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="payments.php?return_to=payments&amp;id=<?php echo $row['order_id']; ?>" class="action-btn btn-view">
                        <i class="fas fa-eye"></i> Manage
                    </a>
                </td>
            </tr>
            <?php
        endforeach;
    else: ?>
        <tr>
            <td colspan="7" style="text-align: center; padding: 28px; color: var(--text-muted);">
                <i class="fas fa-image" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i><br>
                No uploaded receipts yet.
            </td>
        </tr>
    <?php
    endif;
    return ob_get_clean();
}

function renderOrderPaymentRows($order_id, $order_number, $payments) {
    ob_start();
    if (!empty($payments)):
        foreach ($payments as $index => $pay):
            $status = strtolower($pay['status'] ?? 'pending');
            $status_color = $status === 'verified' ? 'var(--success-color)' : ($status === 'pending' ? 'var(--warning-text)' : 'var(--error-color)');
            $receipt_path = normalizePaymentReceiptPath($pay);
            $is_latest = ($index === 0);
            $can_review = is_numeric($pay['id'] ?? null) && $status === 'pending';
            ?>
            <tr class="<?php echo $is_latest ? 'payment-row-highlight' : ''; ?>">
                <td data-label="Booking Ref" style="color: var(--accent-gold); font-weight: 600;"><?php echo htmlspecialchars(formatOrderReference($order_id, $order_number)); ?></td>
                <td data-label="Date & Time">
                    <div><?php echo date('M d, Y h:i A', strtotime($pay['created_at'])); ?></div>
                    <?php if ($is_latest): ?>
                        <div style="font-size: 11px; color: var(--accent-gold); margin-top: 4px;">Latest payment upload</div>
                    <?php endif; ?>
                </td>
                <td data-label="Type"><?php echo htmlspecialchars(formatStorePaymentTypeLabel($pay['payment_type'])); ?></td>
                <td data-label="Method">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <i class="fas <?php echo getStorePaymentMethodIcon($pay['payment_method']); ?>" style="color: var(--text-muted); font-size: 12px;"></i>
                        <?php echo htmlspecialchars(ucfirst($pay['payment_method'])); ?>
                    </div>
                </td>
                <td data-label="Amount" style="font-weight: bold;">&#8369;<?php echo number_format($pay['amount'], 2); ?></td>
                <td data-label="Status">
                    <span style="color: <?php echo $status_color; ?>; font-weight: bold; text-transform: uppercase;"><?php echo htmlspecialchars($pay['status']); ?></span>
                </td>
                <td data-label="Receipt">
                    <?php if ($receipt_path !== ''): ?>
                        <button type="button" class="proof-link-btn" onclick="openProofModal('../<?php echo htmlspecialchars($receipt_path); ?>', 'Payment proof for <?php echo htmlspecialchars(formatOrderReference($order_id, $order_number)); ?>')">
                            <i class="fas fa-image"></i> Receipt
                        </button>
                    <?php else: ?>
                        <span style="color: var(--text-muted); font-size: 12px;">No image</span>
                    <?php endif; ?>
                </td>
                <td data-label="Action">
                    <?php if ($can_review): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="payment_id" value="<?php echo $pay['id']; ?>">
                            <input type="hidden" name="reject_reason" value="">
                            <button type="submit" name="verify_payment" class="btn-xs btn-success" title="Verify payment" aria-label="Verify payment">Verify</button>
                            <button type="submit" name="reject_payment" class="btn-xs btn-danger" title="Reject payment" aria-label="Reject payment" onclick="return fillRejectReason(this.form);">Reject</button>
                        </form>
                    <?php elseif (!is_numeric($pay['id'] ?? null)): ?>
                        <span style="color: var(--text-muted); font-size: 12px;">Booking receipt</span>
                    <?php else: ?>
                        <span style="color: var(--text-muted); font-size: 12px;">Reviewed</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        endforeach;
    else: ?>
        <tr><td colspan="8" style="text-align: center; color: var(--text-muted);">No payments found.</td></tr>
    <?php
    endif;
    return ob_get_clean();
}

function renderOrderReceiptGallery($order_id, $order_number, $payments) {
    ob_start();
    $has_receipts = false;
    ?>
    <div class="receipt-gallery">
        <?php foreach ($payments as $index => $pay):
            $receipt_path = normalizePaymentReceiptPath($pay);
            if ($receipt_path === '') {
                continue;
            }
            $has_receipts = true;
            $status = strtolower($pay['status'] ?? 'pending');
            $card_class = $status === 'verified' ? 'receipt-card-verified' : ($status === 'rejected' ? 'receipt-card-rejected' : 'receipt-card-pending');
            $is_latest = ($index === 0);
            ?>
            <div class="receipt-card <?php echo $card_class; ?> <?php echo $is_latest ? 'receipt-card-latest' : ''; ?>">
                <div class="receipt-card-top">
                    <div>
                        <div class="receipt-card-title"><?php echo htmlspecialchars(formatStorePaymentTypeLabel($pay['payment_type'])); ?></div>
                        <div class="receipt-card-meta"><?php echo date('M d, Y h:i A', strtotime($pay['created_at'])); ?></div>
                    </div>
                    <span class="receipt-status-chip receipt-status-<?php echo $status === 'verified' ? 'verified' : ($status === 'rejected' ? 'rejected' : 'pending'); ?> <?php echo $is_latest ? 'receipt-newest-label' : ''; ?>">
                        <?php echo htmlspecialchars(getReceiptStatusLabel($status, $is_latest)); ?>
                    </span>
                </div>
                <div class="receipt-card-amount">&#8369;<?php echo number_format($pay['amount'], 2); ?></div>
                <button type="button" class="receipt-preview-btn" onclick="openProofModal('../<?php echo htmlspecialchars($receipt_path); ?>', 'Payment proof for <?php echo htmlspecialchars(formatOrderReference($order_id, $order_number)); ?>')">
                    <img src="../<?php echo htmlspecialchars($receipt_path); ?>" alt="Receipt preview">
                </button>
                <div class="receipt-card-footer">
                    <span><i class="fas <?php echo getStorePaymentMethodIcon($pay['payment_method']); ?>"></i> <?php echo htmlspecialchars(ucfirst($pay['payment_method'])); ?></span>
                    <?php if (!empty($pay['reject_reason']) && $status === 'rejected'): ?>
                        <span title="<?php echo htmlspecialchars($pay['reject_reason']); ?>">Rejected</span>
                    <?php elseif ($is_latest): ?>
                        <span>Latest uploaded receipt</span>
                    <?php else: ?>
                        <span>Saved in payment history</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$has_receipts): ?>
            <div class="empty-receipt-state">
                <i class="fas fa-image"></i>
                <span>No payment proof uploaded yet for this booking.</span>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function renderPaymentCustomers($result, $tab) {
    $groups = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $groups[$row['customer_id']][] = $row;
    }
    ob_start();
    if (!$groups) echo '<div class="payment-empty">No bookings in this category.</div>';
    foreach ($groups as $bookings) {
        $customer = $bookings[0];
        $name = trim($customer['first_name'] . ' ' . $customer['last_name']);
        $balanceTotal = 0;
        $pendingCount = 0;
        $pendingTokens = [];
        foreach ($bookings as $booking) {
            $pendingCount += (int) ($booking['pending_count'] ?? 0);
            if (!empty($booking['pending_count'])) $pendingTokens[] = $booking['id'] . ':' . ($booking['pending_signature'] ?? $booking['pending_count']);
        }
        sort($pendingTokens, SORT_STRING);
        $pendingToken = hash('sha256', implode('|', $pendingTokens));
        foreach ($bookings as $booking) $balanceTotal += max(0, $booking['total_amount'] + $booking['damage_total'] - $booking['paid_amount']);
        ?>
        <details class="payment-customer" data-payment-token="<?php echo $pendingToken; ?>" data-customer-id="<?php echo (int) $customer['customer_id']; ?>" data-activity-key="<?php echo htmlspecialchars($tab . '-' . $customer['customer_id']); ?>">
            <summary class="booking-customer-summary">
                <?php renderPaymentCustomerAvatar($customer); ?>
                <span class="customer-heading"><strong><?php echo htmlspecialchars($name); ?><?php if ($pendingCount > 0): ?><span class="payment-name-badge" aria-label="<?php echo $pendingCount; ?> pending payments"><?php echo $pendingCount === 1 ? 'New payment proof' : $pendingCount . ' new payment proofs'; ?></span><?php endif; ?></strong></span>
                <span class="customer-balance"><strong>&#8369;<?php echo number_format($balanceTotal, 2); ?></strong></span>
            </summary>
            <div class="customer-bookings">
            <?php foreach ($bookings as $booking):
                $total = $booking['total_amount'] + $booking['damage_total'];
                $balance = max(0, $total - $booking['paid_amount']);
                $pending = $booking['pending_amount'] ?? 0;
                $status = $balance <= 0 ? 'paid' : (($pending > 0 || ($booking['pending_count'] ?? 0) > 0) ? 'pending' : ($booking['paid_amount'] > 0 ? 'partial' : 'unpaid'));
            ?>
                <article class="payment-booking">
                    <div class="booking-top"><a href="payments.php?return_to=payments&amp;id=<?php echo intval($booking['id']); ?>"><?php echo htmlspecialchars(formatOrderReference($booking['id'], $booking['order_number'])); ?></a><span class="status-badge badge-<?php echo $status; ?>"><?php echo $status === 'pending' ? 'Pending verification' : ucfirst($status); ?></span></div>
                    <div class="booking-date"><i class="far fa-calendar"></i> <?php echo date('M d, Y', strtotime($booking['order_date'])); ?></div>
                    <dl class="booking-amounts">
                        <div><dt>Total due</dt><dd>&#8369;<?php echo number_format($total, 2); ?></dd></div>
                        <div><dt>Verified paid</dt><dd class="amount-paid">&#8369;<?php echo number_format($booking['paid_amount'], 2); ?></dd></div>
                        <div><dt>Pending</dt><dd>&#8369;<?php echo number_format($pending, 2); ?></dd></div>
                        <div><dt>Balance</dt><dd class="amount-balance">&#8369;<?php echo number_format($balance, 2); ?></dd></div>
                    </dl>
                    <div class="booking-actions">
                        <a class="action-btn btn-view" href="payments.php?return_to=payments&amp;id=<?php echo intval($booking['id']); ?>"><i class="fas fa-eye"></i> Manage booking</a>
                        <form method="POST" onsubmit="return confirmBookingAction(event)">
                            <input type="hidden" name="order_id" value="<?php echo intval($booking['id']); ?>">
                            <button class="action-btn booking-trash" type="submit" name="<?php echo $tab === 'trash' ? 'restore_order' : 'archive_order'; ?>"><i class="fas <?php echo $tab === 'trash' ? 'fa-undo' : 'fa-trash'; ?>"></i> <?php echo $tab === 'trash' ? 'Restore' : 'Trash'; ?></button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        </details>
        <?php
    }
    return ob_get_clean();
}
function syncStoreOrderFinancialSnapshots($conn, $store_id) {
    $store_id = intval($store_id);
    $orders_q = mysqli_query($conn, "
        SELECT id
        FROM bookings
        WHERE store_id = '$store_id'
          AND (order_status IS NULL OR order_status NOT IN ('cancelled'))
    ");

    if (!$orders_q) {
        return;
    }

    while ($row = mysqli_fetch_assoc($orders_q)) {
        getOrderFinancials(intval($row['id']));
    }
}

// ==========================================
// MODE 1: GENERAL PAYMENT DASHBOARD (No ID)
// ==========================================
if ($order_id == 0) {


    // ==========================================
    // HANDLE ARCHIVE/RESTORE ACTIONS
    // ==========================================
    if (isset($_POST['archive_order'])) {
        $archive_id = intval($_POST['order_id']);
        $archived = mysqli_query($conn, "UPDATE bookings SET is_archived_by_store = 1 WHERE id = '$archive_id' AND store_id = '$store_id' AND is_archived_by_store = 0");
        if ($archived && mysqli_affected_rows($conn) > 0) {
            $_SESSION['payment_action_success'] = 'Booking moved to trash successfully.';
        }
        header("Location: payments.php");
        exit();
    }

    if (isset($_POST['restore_order'])) {
        $restore_id = intval($_POST['order_id']);
        $restored = mysqli_query($conn, "UPDATE bookings SET is_archived_by_store = 0 WHERE id = '$restore_id' AND store_id = '$store_id' AND is_archived_by_store = 1");
        if ($restored && mysqli_affected_rows($conn) > 0) {
            $_SESSION['payment_action_success'] = 'Booking restored successfully.';
        }
        header("Location: payments.php");
        exit();
    }

    syncStoreOrderFinancialSnapshots($conn, $store_id);

    $page_title = "Payments";

    
    // Fetch Key Metrics
    $stats = [
        'total_revenue' => 0,
        'pending_count' => 0,
        'pending_amount' => 0,
        'today_income' => 0
    ];
    
    // Total Revenue (Verified Payments)
    $rev_q = mysqli_query($conn, "SELECT SUM(amount) as total FROM payments p JOIN bookings o ON p.order_id = o.id WHERE o.store_id = '$store_id' AND p.status = 'verified'");
    $stats['total_revenue'] = mysqli_fetch_assoc($rev_q)['total'] ?? 0;
    
    // Pending Verifications
    $pen_q = mysqli_query($conn, "SELECT COUNT(*) as count, SUM(amount) as total FROM payments p JOIN bookings o ON p.order_id = o.id WHERE o.store_id = '$store_id' AND p.status = 'pending'");
    $pen_res = mysqli_fetch_assoc($pen_q);
    $stats['pending_count'] = $pen_res['count'] ?? 0;
    $stats['pending_amount'] = $pen_res['total'] ?? 0;
    
    // Today's Income
    $today = date('Y-m-d');
    $tod_q = mysqli_query($conn, "SELECT SUM(amount) as total FROM payments p JOIN bookings o ON p.order_id = o.id WHERE o.store_id = '$store_id' AND p.status = 'verified' AND DATE(p.created_at) = '$today'");
    $stats['today_income'] = mysqli_fetch_assoc($tod_q)['total'] ?? 0;

    // Initial Fetch - Today's
    $today_q = "SELECT p.*, o.customer_id, o.order_number, u.profile_image, u.first_name, u.last_name 
                FROM payments p 
                JOIN bookings o ON p.order_id = o.id 
                JOIN users u ON o.customer_id = u.id 
                WHERE o.store_id = '$store_id' 
                AND DATE(p.created_at) = '$today'
                ORDER BY p.created_at DESC";
    $today_res = mysqli_query($conn, $today_q);

    // Initial Fetch - Recent Receipt Uploads
    $receipt_rows = getStoreLatestReceiptUploads($conn, $store_id, 12);

    // Initial Fetch - Recent History
    $trans_q = "SELECT p.*, o.customer_id, o.order_number, u.profile_image, u.first_name, u.last_name 
                FROM payments p 
                JOIN bookings o ON p.order_id = o.id 
                JOIN users u ON o.customer_id = u.id 
                WHERE o.store_id = '$store_id' 
                ORDER BY p.created_at DESC LIMIT 20";
    $trans_res = mysqli_query($conn, $trans_q);

    // Fetch Orders with Balance (Unpaid/Partial) - Not Archived
    // Use computed balances instead of trusting bookings.payment_status so zero-payment customers are always visible.
    $unpaid_q = "SELECT 
                    o.id, o.customer_id,
                    o.order_number,
                    o.order_date,
                    o.total_amount,
                    o.payment_status,
                    o.order_status,
                    u.profile_image, u.first_name,
                    u.last_name,
                    u.phone_number,
                    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = o.id AND status = 'verified') as paid_amount,
                    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = o.id AND status = 'pending') as pending_amount,
                    (SELECT COUNT(*) FROM payments WHERE order_id = o.id AND status = 'pending') as pending_count,
                    (SELECT GROUP_CONCAT(id ORDER BY id) FROM payments WHERE order_id = o.id AND status = 'pending') as pending_signature,
                    (SELECT COALESCE(SUM(total), 0) FROM booking_damages WHERE order_id = o.id) as damage_total
                FROM bookings o
                JOIN users u ON o.customer_id = u.id
                WHERE o.store_id = '$store_id' 
                AND o.is_archived_by_store = 0
                AND (o.order_status IS NULL OR o.order_status NOT IN ('cancelled'))
                HAVING ((total_amount + damage_total) - paid_amount) > 0
                ORDER BY pending_count DESC, order_date ASC"; // Show unpaid and pending-verification bookings first
    $unpaid_res = mysqli_query($conn, $unpaid_q);

    // Only customers whose active bookings are all fully paid belong in this tab.
    $paid_q = "SELECT 
                    o.id, o.customer_id, o.order_number, o.order_date, o.total_amount, o.payment_status, o.order_status,
                    u.profile_image, u.first_name, u.last_name,
                    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = o.id AND status = 'verified') as paid_amount,
                    (SELECT COUNT(*) FROM payments WHERE order_id = o.id AND status = 'pending') as pending_count,
                    (SELECT GROUP_CONCAT(id ORDER BY id) FROM payments WHERE order_id = o.id AND status = 'pending') as pending_signature,
                    (SELECT COALESCE(SUM(total), 0) FROM booking_damages WHERE order_id = o.id) as damage_total
                FROM bookings o
                JOIN users u ON o.customer_id = u.id
                WHERE o.store_id = '$store_id' 
                AND o.is_archived_by_store = 0
                AND (o.order_status IS NULL OR o.order_status NOT IN ('cancelled'))
                AND NOT EXISTS (
                    SELECT 1 FROM bookings outstanding
                    WHERE outstanding.customer_id = o.customer_id
                      AND outstanding.store_id = o.store_id
                      AND outstanding.is_archived_by_store = 0
                      AND (outstanding.order_status IS NULL OR outstanding.order_status NOT IN ('cancelled'))
                      AND (
                          outstanding.total_amount
                          + (SELECT COALESCE(SUM(d.total), 0) FROM booking_damages d WHERE d.order_id = outstanding.id)
                          - (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.order_id = outstanding.id AND p.status = 'verified')
                      ) > 0
                )
                HAVING ((total_amount + damage_total) - paid_amount) <= 0
                ORDER BY o.order_date DESC";
    $paid_res = mysqli_query($conn, $paid_q);
    // The paid tab groups bookings by customer, so count those same customers.
    $paid_customer_ids = [];
    while ($paid_customer = mysqli_fetch_assoc($paid_res)) {
        $paid_customer_ids[(int)$paid_customer['customer_id']] = true;
    }
    $paid_customer_count = count($paid_customer_ids);
    if (mysqli_num_rows($paid_res) > 0) mysqli_data_seek($paid_res, 0);


    // Fetch Archived Orders (Trash)
    $archived_q = "SELECT 
                    o.id, o.customer_id, o.order_number, o.order_date, o.total_amount, o.payment_status, o.order_status,
                    u.profile_image, u.first_name, u.last_name,
                    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = o.id AND status = 'verified') as paid_amount,
                    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = o.id AND status = 'pending') as pending_amount,
                    (SELECT COUNT(*) FROM payments WHERE order_id = o.id AND status = 'pending') as pending_count,
                    (SELECT GROUP_CONCAT(id ORDER BY id) FROM payments WHERE order_id = o.id AND status = 'pending') as pending_signature,
                    (SELECT COALESCE(SUM(total), 0) FROM booking_damages WHERE order_id = o.id) as damage_total
                FROM bookings o
                JOIN users u ON o.customer_id = u.id
                WHERE o.store_id = '$store_id' 
                AND o.is_archived_by_store = 1
                ORDER BY o.order_date DESC";
    $archived_res = mysqli_query($conn, $archived_q);
    if (isset($_GET['fetch_dashboard_data'])) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode([
            'total_revenue' => number_format($stats['total_revenue'], 2),
            'today_income' => number_format($stats['today_income'], 2),
            'pending_count' => $stats['pending_count'],
            'tab_counts' => [mysqli_num_rows($unpaid_res), $paid_customer_count, mysqli_num_rows($archived_res)],
            'unpaid_html' => renderPaymentCustomers($unpaid_res, 'unpaid'),
            'paid_html' => renderPaymentCustomers($paid_res, 'paid'),
            'trash_html' => renderPaymentCustomers($archived_res, 'trash')
        ]);
        exit();
    }
    require_once __DIR__ . '/includes/header.php';
    ?>

    <link rel="stylesheet" href="../assets/css/caterer-payments.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-payments.css'); ?>">
<style>
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background: var(--card-bg); padding: 25px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 20px; transition: transform 0.2s; }
        .kpi-card:hover { transform: translateY(-5px); border-color: var(--accent-gold); }

        .kpi-info h3 { margin: 0; font-size: 14px; color: var(--text-muted); font-weight: 500; }
        .kpi-info .value { font-size: 20px; font-weight: 700; color: var(--text-main); margin-top: 4px; }
        
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .section-title { font-size: 18px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 10px; }
        
        .trans-table { width: 100%; border-collapse: collapse; }
        .trans-table th { text-align: left; padding: 15px; color: var(--text-muted); font-size: 12px; text-transform: uppercase; border-bottom: 1px solid var(--border-color); }
        .trans-table td { padding: 15px; border-bottom: 1px solid var(--border-color); color: var(--text-main); font-size: 14px; }
        .trans-table tr:hover td { background: rgba(255,255,255,0.02); }
        
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge-verified { background: rgba(46, 204, 113, 0.15); color: #2ecc71; }
        .badge-pending { background: rgba(241, 196, 15, 0.15); color: #f1c40f; }
        .badge-rejected { background: rgba(231, 76, 60, 0.15); color: #e74c3c; }
        .badge-unpaid { background: rgba(239, 68, 68, 0.15); color: #f87171; }
        .badge-partial { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
        .badge-paid { background: rgba(16, 185, 129, 0.15); color: #34d399; }
        .receipt-status-chip { display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .receipt-status-pending { background: rgba(212, 175, 55, 0.18); color: #f6d76d; }
        .receipt-status-verified { background: rgba(46, 204, 113, 0.16); color: #6ee7a8; }
        .receipt-status-rejected { background: rgba(231, 76, 60, 0.16); color: #ff8d85; }
        .proof-link-btn { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); border-radius: 10px; padding: 8px 10px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .proof-link-btn:hover { border-color: rgba(212, 175, 55, 0.5); color: #fff; }
        .customer-link { color: var(--text-main); text-decoration: none; font-weight: 600; }
        .customer-link:hover { color: var(--accent-gold); }
        .new-receipt-row td { background: rgba(212, 175, 55, 0.08); }
        .proof-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.82); z-index: 3000; align-items: center; justify-content: center; padding: 24px; }
        .proof-modal-shell { position: relative; width: min(760px, 100%); background: #161616; border: 1px solid rgba(255,255,255,0.1); border-radius: 18px; padding: 20px; }
        .proof-modal-title { color: var(--text-main); font-size: 16px; font-weight: 700; margin-bottom: 14px; }
        .proof-modal-shell img { width: 100%; max-height: 78vh; object-fit: contain; border-radius: 12px; background: #0d0d0d; }
        .proof-modal-close { position: absolute; top: 12px; right: 14px; background: rgba(255,255,255,0.08); border: none; color: #fff; width: 34px; height: 34px; border-radius: 10px; font-size: 22px; cursor: pointer; }
        
        .action-btn { padding: 6px 12px; border-radius: 6px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-view { background: rgba(52, 152, 219, 0.15); color: #3498db; }
        .btn-view:hover { background: rgba(52, 152, 219, 0.25); }

        /* Tabs */
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .tab-btn { background: transparent; border: 1px solid var(--border-color); color: var(--text-muted); padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
        .tab-btn.active { background: var(--accent-gold); color: #000; border-color: var(--accent-gold); }
        .tab-btn:hover:not(.active) { border-color: var(--text-main); color: var(--text-main); }
        
        .tab-content { display: none; animation: fadeIn 0.3s; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .kpi-grid { grid-template-columns:repeat(3,minmax(0,1fr)); width:100%; gap:10px; margin-bottom:18px; }
        .kpi-card { min-width:0; width:100%; box-sizing:border-box; padding:12px 14px; gap:0; }

        .kpi-info { min-width:0; width:100%; }
        .kpi-info .value { overflow-wrap:anywhere; }
        .payment-status-card { padding:24px; }
        .payment-hint { color:var(--text-muted); font-size:13px; margin:8px 0 0; }
        .payment-tabs { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); background:rgba(128,128,128,.06); padding:5px; border-radius:12px; gap:5px; }
        .payment-tabs .tab-btn { padding:12px 6px; font-size:12px; min-width:0; }
        .payment-tabs .tab-btn span { display:inline-block; margin-left:4px; }
        .payment-customer { border:1px solid var(--border-color); border-radius:14px; margin-top:12px; overflow:hidden; }
        .payment-customer summary { display:flex; align-items:center; gap:12px; padding:18px; cursor:pointer; list-style:none; }
        .payment-customer summary::-webkit-details-marker { display:none; }
        .payment-customer summary:focus-visible { outline:2px solid var(--accent-gold); outline-offset:-3px; }
        .customer-avatar img { width:100%; height:100%; object-fit:cover; border-radius:inherit; display:block; }
        .customer-avatar img[hidden] { display:none; }
        .customer-avatar { overflow:hidden; width:40px; height:40px; flex-shrink:0; border-radius:12px; display:grid; place-items:center; color:var(--accent-gold); background:rgba(212,175,55,.12); }
        .customer-heading { flex:1; min-width:0; }
        .customer-heading strong { display:block; overflow-wrap:anywhere; font-size:15px; }
        .customer-heading small,.customer-balance small { display:block; color:var(--text-muted); font-size:11px; margin-top:4px; }
        .customer-balance { text-align:right; }
        .customer-balance strong { display:block; color:var(--accent-gold); font-size:15px; margin-top:4px; overflow-wrap:anywhere; }
        .customer-bookings { border-top:1px solid var(--border-color); padding:14px; display:grid; gap:12px; background:rgba(128,128,128,.03); }
        .payment-booking { border:1px solid var(--border-color); border-radius:10px; padding:16px; min-width:0; }
        .booking-top { display:flex; flex-wrap:wrap; justify-content:space-between; gap:10px; align-items:center; }
        .booking-top a { color:var(--accent-gold); font-size:13px; font-weight:600; overflow-wrap:anywhere; }
        .booking-date { color:var(--text-muted); font-size:12px; margin-top:10px; }
        .booking-amounts { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin:18px 0; }
        .booking-amounts dt { color:var(--text-muted); font-size:11px; margin-bottom:5px; }
        .booking-amounts dd { margin:0; font-size:14px; font-weight:600; overflow-wrap:anywhere; }
        .amount-paid { color:#34d399; } .amount-balance { color:var(--accent-gold); }
        .booking-actions { display:flex; flex-wrap:wrap; gap:8px; } .booking-actions form { margin:0; }
        .booking-actions .action-btn { padding:12px; } .booking-trash { background:transparent; color:var(--text-muted); border:1px solid var(--border-color); }
        .payment-empty { text-align:center; padding:30px 12px; color:var(--text-muted); }
        .payment-customer .booking-customer-summary { flex-wrap:nowrap; gap:8px; }
        .booking-customer-summary .customer-heading { flex:1 1 auto; min-width:0; }
        .booking-customer-summary .customer-heading strong { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .booking-customer-summary .customer-balance { flex:0 0 auto; margin-left:0; text-align:right; }
        .booking-customer-summary .customer-balance strong { white-space:nowrap; margin-top:0; font-size:clamp(11px,3vw,15px); }
        @media(max-width:600px) {
            .kpi-grid { gap:6px; margin-bottom:12px; }
            .kpi-card { padding:8px 7px; align-items:flex-start; gap:0; border-radius:8px; }

            .kpi-info { width:100%; } .kpi-info h3 { font-size:10px; line-height:1.3; min-height:26px; } .kpi-info .value { font-size:clamp(11px,3.2vw,16px); line-height:1.25; margin-top:3px; }
            .payment-status-card { padding:14px; }
            .payment-status-card .section-title { font-size:16px; }
            .payment-customer summary { padding:12px; gap:9px; flex-wrap:wrap; }
            .customer-avatar { width:32px; height:32px; }
            .customer-heading strong { font-size:14px; }
            .customer-balance { margin-left:41px; text-align:left; flex-basis:calc(100% - 65px); }
            .customer-bookings { padding:8px; }
            .payment-booking { padding:12px; }
            .booking-amounts { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .tabs.payment-tabs { padding:3px; gap:3px; border-radius:9px; margin-bottom:12px; }
            .payment-tabs .tab-btn { display:flex; align-items:center; justify-content:center; flex-wrap:wrap; gap:3px 5px; padding:7px 4px; font-size:10px; line-height:1.25; border-radius:6px; }
            .payment-tabs .tab-btn span { display:inline; margin:0; font-size:11px; line-height:1.25; }
        }
        .card.today-transactions-card { min-width:0; padding:16px; margin-bottom:18px; border-left:3px solid var(--accent-gold); }
        .today-transactions-card .section-header { margin-bottom:10px; }
        .today-transactions-card .section-title { font-size:15px; gap:8px; }
        .today-transactions-card .activity-empty { padding:14px 10px; gap:5px; border-radius:10px; }
        .today-transactions-card .activity-empty > span { width:28px; height:28px; font-size:12px; }
        .today-transactions-card .activity-empty strong { font-size:12px; }
        .today-transactions-card .activity-empty p { font-size:11px; line-height:1.4; }
        .today-transactions-card .activity-customer { margin-top:8px; border-radius:10px; }
        .today-transactions-card .activity-customer summary { display:grid; grid-template-columns:auto minmax(0,1fr) minmax(0,auto); padding:12px; gap:10px; }
        .today-transactions-card .customer-heading small,
        .today-transactions-card .customer-balance,
        .today-transactions-card .activity-payment > * { min-width:0; overflow-wrap:anywhere; }
        .today-transactions-card .customer-balance { margin-left:0; }
        .today-transactions-card .customer-heading strong,
        .today-transactions-card .customer-balance strong { font-size:14px; }
        .today-transactions-card .customer-bookings { padding:10px; gap:8px; }
        .today-transactions-card .activity-item { padding:12px; }
        .today-transactions-card .activity-payment { margin:10px 0; gap:8px; }
        .today-transactions-card .booking-actions > * { max-width:100%; white-space:normal; overflow-wrap:anywhere; }
        @media(max-width:600px) {
            .card.today-transactions-card { padding:12px; margin-bottom:14px; }
            .today-transactions-card .section-title { font-size:14px; }
            .today-transactions-card .activity-empty { padding:10px 8px; }
            .today-transactions-card .activity-customer summary { grid-template-columns:32px minmax(0,1fr); padding:12px; gap:10px; align-items:center; }
            .today-transactions-card .customer-heading strong { line-height:1.35; }
            .today-transactions-card .customer-heading small { line-height:1.5; margin-top:3px; }
            .today-transactions-card .customer-balance { grid-column:1 / -1; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:4px 10px; width:100%; padding-top:10px; border-top:1px solid var(--border-color); text-align:left; }
            .today-transactions-card .customer-balance small { margin-top:0; }
            .today-transactions-card .customer-balance strong { margin-top:0; margin-left:auto; text-align:right; }
            .today-transactions-card .customer-bookings { padding:8px; }
            .today-transactions-card .activity-item { padding:10px; }
            .today-transactions-card .activity-item .booking-actions { grid-template-columns:repeat(auto-fit,minmax(min(110px,100%),1fr)); }
        }
        .activity-list { min-width:0; }
        .activity-empty { display:flex; flex-direction:column; align-items:center; text-align:center; padding:30px 12px; gap:10px; border:1px dashed var(--border-color); border-radius:14px; }
        .activity-empty > span { display:grid; place-items:center; width:44px; height:44px; border-radius:50%; background:rgba(212,175,55,.1); color:var(--accent-gold); }
        .activity-empty strong { font-size:14px; color:var(--text-main); }
        .activity-empty p { margin:0; color:var(--text-muted); font-size:12px; line-height:1.6; }
        .activity-payment { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin:16px 0; }
        .activity-payment small { display:block; font-size:11px; color:var(--text-muted); margin-bottom:4px; }
        .activity-payment strong { color:var(--text-main); font-size:20px; overflow-wrap:anywhere; }
        .activity-payment > span { font-size:12px; color:var(--text-muted); }
        .activity-item .booking-actions > * { min-height:40px; box-sizing:border-box; }
        @media(max-width:600px) {
            .activity-list .customer-heading small { line-height:1.6; }
            .activity-payment strong { font-size:18px; }
            .activity-item .booking-actions { display:grid; grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); }
            .activity-item .booking-actions > * { text-align:center; justify-content:center; font-size:11px; padding:10px 6px; }
        }
    </style>

    <div class="container-fluid">
<!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-info">
                    <h3>Total Revenue</h3>
                    <div class="value" id="kpi-total-rev">&#8369;<?php echo number_format($stats['total_revenue'], 2); ?></div>
                </div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-info">
                    <h3>Pending Verification</h3>
                    <div class="value" id="kpi-pending-val">
                        <?php echo $stats['pending_count']; ?>
                    </div>
                </div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-info">
                    <h3>Today's Income</h3>
                    <div class="value" id="kpi-today-rev">&#8369;<?php echo number_format($stats['today_income'], 2); ?></div>
                </div>
            </div>
        </div>

        <?php if (false): ?>
        <div class="card">
            <div class="section-header">
                <div class="section-title"><i class="fas fa-file-invoice-dollar"></i> Booking Payment Status</div>
            </div>
            
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('unpaid', this)">Unpaid / With Balance (<?php echo mysqli_num_rows($unpaid_res); ?>)</button>
                <button class="tab-btn" onclick="switchTab('paid', this)">Fully Paid</button>
                <button class="tab-btn" onclick="switchTab('trash', this)" style="color: var(--error-color); border-color: var(--error-color);">
                    <i class="fas fa-trash"></i> Trash (<?php echo mysqli_num_rows($archived_res); ?>)
                </button>
            </div>
            
            <div id="tab-unpaid" class="tab-content active">
                <div style="overflow-x: auto;">
                    <table class="trans-table">
                        <thead>
                            <tr>
                                <th>Booking #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Total Due</th>
                                <th>Paid</th>
                                <th>Pending</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($unpaid_res) > 0): ?>
                                <?php while($row = mysqli_fetch_assoc($unpaid_res)): 
                                    $grand_total = $row['total_amount'] + $row['damage_total'];
                                    $paid = $row['paid_amount'];
                                    $pending = $row['pending_amount'];
                                    $balance = $grand_total - $paid;
                                    if ($pending > 0 || intval($row['pending_count']) > 0) {
                                        $status = 'pending';
                                    } else {
                                        $status = $paid > 0 ? 'partial' : 'unpaid';
                                    }
                                ?>
                                    <tr>
                                        <td><span style="color: var(--accent-gold);"><?php echo htmlspecialchars(formatCatererBookingNumber(0, $row['order_number'])); ?></span></td>
                                        <td><?php echo date('M d, Y', strtotime($row['order_date'])); ?></td>
                                        <td>
                                            <div><a href="payments.php?return_to=payments&amp;id=<?php echo $row['id']; ?>" class="customer-link"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></a></div>
                                            <?php if (!empty($row['phone_number'])): ?>
                                                <div style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($row['phone_number']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-weight: 600;">&#8369;<?php echo number_format($grand_total, 2); ?></td>
                                        <td style="color: var(--text-muted);">&#8369;<?php echo number_format($paid, 2); ?></td>
                                        <td style="color: var(--warning-text); font-weight: 600;">&#8369;<?php echo number_format($pending, 2); ?></td>
                                        <td style="color: var(--error-color); font-weight: 700;">&#8369;<?php echo number_format($balance, 2); ?></td>
                                        <td>
                                            <span class="status-badge badge-<?php echo strtolower($status); ?>">
                                                <?php echo $status === 'pending' ? 'Pending Verification' : ucfirst($status); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="payments.php?return_to=payments&amp;id=<?php echo $row['id']; ?>" class="action-btn btn-view" title="Manage Payments">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirmBookingAction(event)">
                                                <input type="hidden" name="order_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="archive_order" class="action-btn btn-danger" style="background: transparent; color: var(--error-color); border: 1px solid var(--border-color);" title="Move to Trash">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                        <i class="fas fa-check-circle" style="font-size: 24px; margin-bottom: 10px; color: var(--success-color);"></i><br>
                                        No unpaid customers or outstanding balances right now.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div id="tab-paid" class="tab-content">
                <div style="overflow-x: auto;">
                    <table class="trans-table">
                        <thead>
                            <tr>
                                <th>Booking #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Total Amount</th>
                                <th>Paid</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($paid_res) > 0): ?>
                                <?php while($row = mysqli_fetch_assoc($paid_res)): 
                                    $grand_total = $row['total_amount'] + $row['damage_total'];
                                    $paid = $row['paid_amount'];
                                ?>
                                    <tr>
                                        <td><span style="color: var(--accent-gold);"><?php echo htmlspecialchars(formatCatererBookingNumber(0, $row['order_number'])); ?></span></td>
                                        <td><?php echo date('M d, Y', strtotime($row['order_date'])); ?></td>
                                        <td><a href="payments.php?return_to=payments&amp;id=<?php echo $row['id']; ?>" class="customer-link"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></a></td>
                                        <td style="font-weight: 600;">&#8369;<?php echo number_format($grand_total, 2); ?></td>
                                        <td style="color: var(--success-color);">&#8369;<?php echo number_format($paid, 2); ?></td>
                                        <td>
                                            <span class="status-badge badge-paid">Paid</span>
                                        </td>
                                        <td>
                                            <a href="payments.php?return_to=payments&amp;id=<?php echo $row['id']; ?>" class="action-btn btn-view" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirmBookingAction(event)">
                                                <input type="hidden" name="order_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="archive_order" class="action-btn btn-danger" style="background: transparent; color: var(--error-color); border: 1px solid var(--border-color);" title="Move to Trash">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                        No paid bookings found yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="tab-trash" class="tab-content">
                <div style="overflow-x: auto;">
                    <table class="trans-table">
                        <thead>
                            <tr>
                                <th>Booking #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($archived_res) > 0): ?>
                                <?php while($row = mysqli_fetch_assoc($archived_res)): 
                                    $paid = $row['paid_amount'];
                                    $status = $row['payment_status'] ?: ($paid > 0 ? 'partial' : 'unpaid');
                                ?>
                                    <tr>
                                        <td style="opacity: 0.6;"><span style="color: var(--accent-gold);"><?php echo htmlspecialchars(formatCatererBookingNumber(0, $row['order_number'])); ?></span></td>
                                        <td style="opacity: 0.6;"><?php echo date('M d, Y', strtotime($row['order_date'])); ?></td>
                                        <td style="opacity: 0.6;"><a href="payments.php?return_to=payments&amp;id=<?php echo $row['id']; ?>" class="customer-link"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></a></td>
                                        <td style="opacity: 0.6;">
                                            <span class="status-badge badge-<?php echo strtolower($status); ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST" style="display:inline;" onsubmit="return confirmBookingAction(event)">
                                                <input type="hidden" name="order_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="restore_order" class="action-btn" style="background: rgba(16, 185, 129, 0.15); color: #34d399;" title="Restore Booking">
                                                    <i class="fas fa-undo"></i> Restore
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                        <i class="fas fa-trash-alt" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i><br>
                                        Trash is empty.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
         
        <div class="card payment-status-card">
            <div class="section-header">
                <div><div class="section-title"><i class="fas fa-file-invoice-dollar"></i> Booking Payment Status</div></div>
            </div>
            <div class="tabs payment-tabs" aria-label="Payment categories">
                <button type="button" class="tab-btn active" onclick="switchTab('unpaid', this)">With balance <span><?php echo mysqli_num_rows($unpaid_res); ?></span></button>
                <button type="button" class="tab-btn" onclick="switchTab('paid', this)">Fully paid <span><?php echo $paid_customer_count; ?></span></button>
                <button type="button" class="tab-btn" onclick="switchTab('trash', this)">Trash <span><?php echo mysqli_num_rows($archived_res); ?></span></button>
            </div>
            <div id="tab-unpaid" class="tab-content active"><?php echo renderPaymentCustomers($unpaid_res, 'unpaid'); ?></div>
            <div id="tab-paid" class="tab-content"><?php echo renderPaymentCustomers($paid_res, 'paid'); ?></div>
            <div id="tab-trash" class="tab-content"><?php echo renderPaymentCustomers($archived_res, 'trash'); ?></div>
        </div>


        <?php if (false): ?>
        <!-- Booking Payment Status (Receivables) -->
        <div class="card">
            <div class="section-header">
                <div class="section-title"><i class="fas fa-file-invoice-dollar"></i> Booking Payment Status</div>
            </div>
            
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('unpaid', this)">Unpaid / With Balance (<?php echo mysqli_num_rows($unpaid_res); ?>)</button>
                <button class="tab-btn" onclick="switchTab('paid', this)">Fully Paid</button>
                <button class="tab-btn" onclick="switchTab('trash', this)" style="color: var(--error-color); border-color: var(--error-color);">
                    <i class="fas fa-trash"></i> Trash (<?php echo mysqli_num_rows($archived_res); ?>)
                </button>
            </div>
            
            <!-- Unpaid / Partial Orders -->
            <div id="tab-unpaid" class="tab-content active">
                <div style="overflow-x: auto;">
                    <table class="trans-table">
                        <thead>
                            <tr>
                                <th>Booking #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Total Due</th>
                                <th>Paid</th>
                                <th>Pending</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($unpaid_res) > 0): ?>
                                <?php while($row = mysqli_fetch_assoc($unpaid_res)): 
                                    $grand_total = $row['total_amount'] + $row['damage_total'];
                                    $paid = $row['paid_amount'];
                                    $pending = $row['pending_amount'];
                                    $balance = $grand_total - $paid;
                                    if ($pending > 0 || intval($row['pending_count']) > 0) {
                                        $status = 'pending';
                                    } else {
                                        $status = $paid > 0 ? 'partial' : 'unpaid';
                                    }
                                ?>
                                    <tr>
                                        <td><span style="color: var(--accent-gold);"><?php echo htmlspecialchars(formatCatererBookingNumber(0, $row['order_number'])); ?></span></td>
                                        <td><?php echo date('M d, Y', strtotime($row['order_date'])); ?></td>
                                        <td>
                                            <div><a href="payments.php?return_to=payments&amp;id=<?php echo $row['id']; ?>" class="customer-link"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></a></div>
                                            <?php if (!empty($row['phone_number'])): ?>
                                                <div style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($row['phone_number']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-weight: 600;">&#8369;<?php echo number_format($grand_total, 2); ?></td>
                                        <td style="color: var(--text-muted);">&#8369;<?php echo number_format($paid, 2); ?></td>
                                        <td style="color: var(--warning-text); font-weight: 600;">&#8369;<?php echo number_format($pending, 2); ?></td>
                                        <td style="color: var(--error-color); font-weight: 700;">&#8369;<?php echo number_format($balance, 2); ?></td>
                                        <td>
                                            <span class="status-badge badge-<?php echo strtolower($status); ?>">
                                                <?php echo $status === 'pending' ? 'Pending Verification' : ucfirst($status); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="payments.php?return_to=payments&amp;id=<?php echo $row['id']; ?>" class="action-btn btn-view" title="Manage Payments">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirmBookingAction(event)">
                                                <input type="hidden" name="order_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="archive_order" class="action-btn btn-danger" style="background: transparent; color: var(--error-color); border: 1px solid var(--border-color);" title="Move to Trash">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                        <i class="fas fa-check-circle" style="font-size: 24px; margin-bottom: 10px; color: var(--success-color);"></i><br>
                                        No unpaid customers or outstanding balances right now.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Fully Paid Orders -->
            <div id="tab-paid" class="tab-content">
                <div style="overflow-x: auto;">
                    <table class="trans-table">
                        <thead>
                            <tr>
                                <th>Booking #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Total Amount</th>
                                <th>Paid</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($paid_res) > 0): ?>
                                <?php while($row = mysqli_fetch_assoc($paid_res)): 
                                    $grand_total = $row['total_amount'] + $row['damage_total'];
                                    $paid = $row['paid_amount'];
                                ?>
                                    <tr>
                                        <td><span style="color: var(--accent-gold);"><?php echo htmlspecialchars(formatCatererBookingNumber(0, $row['order_number'])); ?></span></td>
                                        <td><?php echo date('M d, Y', strtotime($row['order_date'])); ?></td>
                                        <td><a href="payments.php?return_to=payments&amp;id=<?php echo $row['id']; ?>" class="customer-link"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></a></td>
                                        <td style="font-weight: 600;">&#8369;<?php echo number_format($grand_total, 2); ?></td>
                                        <td style="color: var(--success-color);">&#8369;<?php echo number_format($paid, 2); ?></td>
                                        <td>
                                            <span class="status-badge badge-paid">Paid</span>
                                        </td>
                                        <td>
                                            <a href="payments.php?return_to=payments&amp;id=<?php echo $row['id']; ?>" class="action-btn btn-view" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirmBookingAction(event)">
                                                <input type="hidden" name="order_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="archive_order" class="action-btn btn-danger" style="background: transparent; color: var(--error-color); border: 1px solid var(--border-color);" title="Move to Trash">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                        No paid bookings found yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Trash (Archived) Orders -->
            <div id="tab-trash" class="tab-content">
                <div style="overflow-x: auto;">
                    <table class="trans-table">
                        <thead>
                            <tr>
                                <th>Booking #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($archived_res) > 0): ?>
                                <?php while($row = mysqli_fetch_assoc($archived_res)): 
                                    $paid = $row['paid_amount'];
                                    $status = $row['payment_status'] ?: ($paid > 0 ? 'partial' : 'unpaid');
                                ?>
                                    <tr>
                                        <td style="opacity: 0.6;"><span style="color: var(--accent-gold);"><?php echo htmlspecialchars(formatCatererBookingNumber(0, $row['order_number'])); ?></span></td>
                                        <td style="opacity: 0.6;"><?php echo date('M d, Y', strtotime($row['order_date'])); ?></td>
                                        <td style="opacity: 0.6;"><a href="payments.php?return_to=payments&amp;id=<?php echo $row['id']; ?>" class="customer-link"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></a></td>
                                        <td style="opacity: 0.6;">
                                            <span class="status-badge badge-<?php echo strtolower($status); ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST" style="display:inline;" onsubmit="return confirmBookingAction(event)">
                                                <input type="hidden" name="order_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="restore_order" class="action-btn" style="background: rgba(16, 185, 129, 0.15); color: #34d399;" title="Restore Booking">
                                                    <i class="fas fa-undo"></i> Restore
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                        <i class="fas fa-trash-alt" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i><br>
                                        Trash is empty.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>


    </div>
    <?php if (!empty($_SESSION['payment_action_success'])): ?>
    <dialog id="payment-action-success" aria-labelledby="payment-success-title" aria-describedby="payment-success-description">

        <span class="payment-success-check" aria-hidden="true">&#10003;</span>
        <h2 id="payment-success-title">Success!</h2>
        <p id="payment-success-description"><?php echo htmlspecialchars($_SESSION['payment_action_success'], ENT_QUOTES, 'UTF-8'); ?></p>
        <button type="button" class="payment-success-ok" onclick="document.getElementById('payment-action-success').close()">OK</button>
    </dialog>
    <?php unset($_SESSION['payment_action_success']); endif; ?>
    <dialog id="booking-action-confirm" aria-labelledby="booking-action-title" aria-describedby="booking-action-description">
<button type="button" class="booking-confirm-close" aria-label="Close" onclick="document.getElementById('booking-action-confirm').close()">&times;</button>
<h2 id="booking-action-title">Move booking to trash?</h2>
<p id="booking-action-description">You can restore this booking from Trash later.</p>
<div class="booking-confirm-actions"><button type="button" class="booking-confirm-cancel" onclick="document.getElementById('booking-action-confirm').close()">Cancel</button><button type="button" id="booking-action-proceed">Move to trash</button></div>
</dialog>
<script>
    const paymentActionSuccess = document.getElementById('payment-action-success');
    if (paymentActionSuccess) {
        paymentActionSuccess.addEventListener('cancel', event => event.preventDefault());
        paymentActionSuccess.showModal();
    }
    let pendingBookingForm = null;
    let pendingBookingSubmitter = null;
    let bookingActionConfirmed = false;
    const bookingActionDialog = document.getElementById('booking-action-confirm');
    function confirmBookingAction(event) {
        if (bookingActionConfirmed) return true;
        event.preventDefault();
        pendingBookingForm = event.target;
        pendingBookingSubmitter = event.submitter || event.target.querySelector('button[type="submit"]');
        const restore = pendingBookingSubmitter?.name === 'restore_order';
        document.getElementById('booking-action-title').textContent = restore ? 'Restore booking?' : 'Move booking to trash?';
        document.getElementById('booking-action-description').textContent = restore ? 'This booking will return to your payment list.' : 'You can restore this booking from Trash later.';
        document.getElementById('booking-action-proceed').textContent = restore ? 'Restore booking' : 'Move to trash';
        if (!bookingActionDialog.open) bookingActionDialog.showModal();
        savePaymentView();
        return false;
    }
    bookingActionDialog.addEventListener('close', () => { pendingBookingForm = null; pendingBookingSubmitter = null; savePaymentView(); });
    document.getElementById('booking-action-proceed').addEventListener('click', () => {
        if (!pendingBookingForm) return;
        bookingActionConfirmed = true;
        savePaymentView();
        pendingBookingForm.requestSubmit(pendingBookingSubmitter);
        bookingActionConfirmed = false;
        bookingActionDialog.close();
    });
    const paymentReadKey = 'payment-customer-read-<?php echo (int) $store_id; ?>';
    function readPaymentIndicators() {
        try { return JSON.parse(localStorage.getItem(paymentReadKey)) || {}; } catch (_) { return {}; }
    }
    function syncPaymentIndicators() {
        const read = readPaymentIndicators();
        document.querySelectorAll('.payment-customer[data-payment-token]').forEach(customer => {
            const badge = customer.querySelector('.payment-name-badge');
            if (badge) badge.hidden = read[customer.dataset.customerId] === customer.dataset.paymentToken;
        });
    }
    document.addEventListener('click', event => {
        const summary = event.target.closest('.booking-customer-summary');
        const customer = summary?.closest('.payment-customer[data-payment-token]');
        if (!customer) return;
        const read = readPaymentIndicators();
        read[customer.dataset.customerId] = customer.dataset.paymentToken;
        try { localStorage.setItem(paymentReadKey, JSON.stringify(read)); } catch (_) {}
        const badge = customer.querySelector('.payment-name-badge');
        if (badge) badge.hidden = true;
    });
    window.addEventListener('storage', event => { if (event.key === paymentReadKey) syncPaymentIndicators(); });
    syncPaymentIndicators();
    function updateActivityList(id, html) {
        const container = document.getElementById(id);
        if (bookingActionDialog.open) return;
        if (!container || typeof html !== 'string' || container.innerHTML === html || container.contains(document.activeElement)) return;
        const listScroll = container.scrollTop;
        const openKeys = new Set(Array.from(container.querySelectorAll('details[open]')).map(el => el.dataset.activityKey));
        container.innerHTML = html;
        container.querySelectorAll('details').forEach(el => { el.open = openKeys.has(el.dataset.activityKey); });
        container.scrollTop = listScroll;
        syncPaymentIndicators();
    }
    function switchTab(tabName, btn) {
        // Hide all contents
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        // Show target
        document.getElementById('tab-' + tabName).classList.add('active');
        
        // Update buttons
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        if(btn) btn.classList.add('active');
    }

    const paymentListStateKey = 'caterer-payment-list-<?php echo (int) $store_id; ?>';
    const paymentWorkspace = document.querySelector('.caterer-workspace');
    let restoringPaymentState = false;
    function savePaymentView() {
        if (restoringPaymentState) return;
        try {
            sessionStorage.setItem(paymentListStateKey, JSON.stringify({
                confirmation: bookingActionDialog.open && !bookingActionConfirmed && pendingBookingForm ? {orderId: pendingBookingForm.querySelector('input[name="order_id"]')?.value, action: pendingBookingSubmitter?.name} : null,
                tab: document.querySelector('.tab-content.active')?.id.replace('tab-', '') || 'unpaid',
                scroll: paymentWorkspace?.scrollTop || 0,
                lists: Object.fromEntries(Array.from(document.querySelectorAll('.payment-status-card > .tab-content')).map(el => [el.id, el.scrollTop])),
                open: Array.from(document.querySelectorAll('details.payment-customer[open]')).map(el => el.dataset.activityKey)
            }));
        } catch (_) {}
    }
    function restorePaymentView() {
        try {
            const state = JSON.parse(sessionStorage.getItem(paymentListStateKey) || 'null');
            if (!state) return;
            restoringPaymentState = true;
            const tab = ['unpaid', 'paid', 'trash'].includes(state.tab) ? state.tab : 'unpaid';
            const button = Array.from(document.querySelectorAll('.payment-tabs .tab-btn')).find(el => el.getAttribute('onclick').includes("'" + tab + "'"));
            switchTab(tab, button);
            document.querySelectorAll('details.payment-customer').forEach(el => { el.open = (state.open || []).includes(el.dataset.activityKey); });
            if (paymentWorkspace) paymentWorkspace.scrollTop = Number(state.scroll) || 0;
            if (state.confirmation && !paymentActionSuccess?.open && !bookingActionDialog.open) {
                const {orderId, action} = state.confirmation;
                if (['archive_order', 'restore_order'].includes(action)) {
                    const form = Array.from(document.querySelectorAll('.payment-status-card form')).find(el => el.querySelector('input[name="order_id"]')?.value === orderId && el.querySelector('button[name="' + action + '"]'));
                    if (form) confirmBookingAction({target: form, submitter: form.querySelector('button[name="' + action + '"]'), preventDefault() {}});
                }
            }
            document.querySelectorAll('.payment-status-card > .tab-content').forEach(el => { el.scrollTop = Number(state.lists?.[el.id]) || 0; });
        } catch (_) {} finally { restoringPaymentState = false; }
    }
    document.addEventListener('toggle', event => { if (event.target.matches('details.payment-customer')) savePaymentView(); }, true);
    document.querySelector('.payment-tabs')?.addEventListener('click', savePaymentView);
    paymentWorkspace?.addEventListener('scroll', savePaymentView, {passive: true});
    document.querySelectorAll('.payment-status-card > .tab-content').forEach(el => el.addEventListener('scroll', savePaymentView, {passive: true}));
    window.addEventListener('pagehide', savePaymentView);
    document.addEventListener('visibilitychange', () => { if (document.hidden) savePaymentView(); });
    window.addEventListener('pageshow', restorePaymentView);
    restorePaymentView();

    // Real-Time Dashboard Updates
    setInterval(function() {
        // Add timestamp to prevent caching
        if (document.hidden) return;
        fetch('payments.php?fetch_dashboard_data=1&_=' + new Date().getTime(), {cache: 'no-store'})
            .then(res => res.json())
            .then(data => {
                document.getElementById('kpi-total-rev').innerText = '\u20b1' + data.total_revenue;
                document.getElementById('kpi-today-rev').innerText = '\u20b1' + data.today_income;
                document.getElementById('kpi-pending-val').textContent = data.pending_count;
                document.querySelectorAll('.payment-tabs .tab-btn span').forEach((span, index) => { if (data.tab_counts) span.textContent = data.tab_counts[index]; });
                const position = paymentWorkspace?.scrollTop || 0;
                updateActivityList('tab-unpaid', data.unpaid_html);
                updateActivityList('tab-paid', data.paid_html);
                updateActivityList('tab-trash', data.trash_html);
                if (paymentWorkspace) paymentWorkspace.scrollTop = position;
            })
            .catch(err => console.error('Dashboard update error:', err));
    }, 20000);
    </script>
    <div id="proofModal" class="proof-modal" role="dialog" aria-modal="true" aria-labelledby="proofModalTitle">
        <div class="proof-modal-shell">
            <button type="button" class="proof-modal-close" aria-label="Close receipt preview" onclick="closeProofModal(event)">&times;</button>
            <div id="proofModalTitle" class="proof-modal-title">Payment Receipt</div>
            <img id="proofModalImage" src="" alt="Receipt preview">
        </div>
    </div>
    <script>
    let proofPreviewTrigger = null;
    function openProofModal(src, title) {
        proofPreviewTrigger = document.activeElement;
        const modal = document.getElementById('proofModal');
        const image = document.getElementById('proofModalImage');
        const heading = document.getElementById('proofModalTitle');
        if (!modal || !image || !heading) return;
        image.src = src;
        heading.textContent = title || 'Payment Receipt';
        modal.style.display = 'flex';
    }

    function closeProofModal(event) {
        if (!event || !event.currentTarget?.classList.contains('proof-modal-close')) return;
        const modal = document.getElementById('proofModal');
        const image = document.getElementById('proofModalImage');
        if (modal) modal.style.display = 'none';
        if (image) image.src = '';
        if (proofPreviewTrigger) proofPreviewTrigger.focus();
    }
    </script>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit();
}

// ==========================================
// MODE 2: SPECIFIC ORDER DETAILS (With ID)
// ==========================================
// 1. Fetch Order Basic Info
$order_query = "SELECT o.*, u.profile_image, u.first_name, u.last_name 
                FROM bookings o
                JOIN users u ON o.customer_id = u.id
                WHERE o.id = '$order_id' AND o.store_id = '$store_id'";
$order_res = mysqli_query($conn, $order_query);

if (!$order_res || mysqli_num_rows($order_res) == 0) {
    echo "Booking not found or access denied.";
    exit();
}

$order = mysqli_fetch_assoc($order_res);

// ==========================================
// HANDLE ACTIONS (POST)
// ==========================================

// 1. Add Payment
if (isset($_POST['add_payment'])) {
    $amount = floatval($_POST['amount']);
    $type = $_POST['payment_type'];
    $method = $_POST['payment_method'];
    $notes = mysqli_real_escape_string($conn, $_POST['notes']);
    
    if (is_finite($amount) && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO payments (order_id, customer_id, store_id, amount, payment_type, payment_method, status, notes, confirmed_by, confirmed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, 'verified', ?, ?, NOW(), NOW())");
        $customer_id_for_payment = intval($order['customer_id']);
        $store_id_for_payment = intval($store_id);
        $confirmed_by = intval($store_id);
        $stmt->bind_param("iiidsssi", $order_id, $customer_id_for_payment, $store_id_for_payment, $amount, $type, $method, $notes, $confirmed_by);
        if ($stmt->execute()) {
            // Recalculate financials is handled by getOrderFinancials on load
            header("Location: payments.php?id=$order_id$payment_return_query&success=payment_added");
            exit();
        }
    }
}

// 3. Add Damage Charge
if (isset($_POST['add_damage'])) {
    $item = mysqli_real_escape_string($conn, $_POST['item_name']);
    $desc = mysqli_real_escape_string($conn, $_POST['description']); // Using notes column
    $amount = floatval($_POST['amount']);
    
    if ($amount > 0) {
        // Insert into booking_damages
        // Assuming quantity 1 for simple charge, or we could add quantity field if needed. 
        // The prompt says "Item Name, Description, Amount". 
        // I'll map Description to 'notes' and Amount to 'total' (price=amount, qty=1)
        $stmt = $conn->prepare("INSERT INTO booking_damages (order_id, item_name, quantity, price, total, notes, damage_status, created_at) VALUES (?, ?, 1, ?, ?, ?, 'pending', NOW())");
        $stmt->bind_param("isdds", $order_id, $item, $amount, $amount, $desc);
        if ($stmt->execute()) {
            header("Location: payments.php?id=$order_id$payment_return_query&success=damage_added");
            exit();
        }
    }
}

// 4. Verify Payment (Optional but good for management)
if (isset($_POST['verify_payment'])) {
    $pay_id = intval($_POST['payment_id'] ?? 0);
    try {
        $updated = mysqli_query($conn, "UPDATE payments SET status = 'verified', confirmed_by = '$store_id', confirmed_at = NOW() WHERE id = '$pay_id' AND order_id = '$order_id' AND status = 'pending'");
        $changed = $updated && mysqli_affected_rows($conn) === 1;
    } catch (mysqli_sql_exception $e) {
        $changed = false;
        error_log('Payment verification failed: ' . $e->getMessage());
    }
    if (!$changed) {
        header("Location: payments.php?id=$order_id$payment_return_query&error=payment_review_failed");
        exit();
    }
    getOrderFinancials($order_id);
    header("Location: payments.php?id=$order_id$payment_return_query&success=payment_verified");
    exit();
}

// 5. Reject Payment
if (isset($_POST['reject_payment'])) {
    $pay_id = intval($_POST['payment_id'] ?? 0);
    $reject_reason = mysqli_real_escape_string($conn, trim($_POST['reject_reason'] ?? ''));
    if ($reject_reason === '') {
        header("Location: payments.php?id=$order_id$payment_return_query&error=reject_reason_required");
        exit();
    }
    try {
        $updated = mysqli_query($conn, "UPDATE payments SET status = 'rejected', reject_reason = '$reject_reason', confirmed_by = '$store_id', confirmed_at = NOW() WHERE id = '$pay_id' AND order_id = '$order_id' AND status = 'pending'");
        $changed = $updated && mysqli_affected_rows($conn) === 1;
    } catch (mysqli_sql_exception $e) {
        $changed = false;
        error_log('Payment rejection failed: ' . $e->getMessage());
    }
    if (!$changed) {
        header("Location: payments.php?id=$order_id$payment_return_query&error=payment_review_failed");
        exit();
    }
    header("Location: payments.php?id=$order_id$payment_return_query&success=payment_rejected");
    exit();
}


// ==========================================
// HELPER: Build Timeline & Data
// ==========================================
function getStoreFinancialDetails($conn, $order_id, $fin, $order) {
    $events = [];
    
    // 1. Payments
    foreach ($fin['history'] as $pay) {
        $payment_notes = preg_replace_callback('/(?:(?:Order|Booking)\s*#?\s*:?\s*)?\bORD-[A-Z0-9]+\b/i', function ($match) use ($order_id, $order) {
            return 'Booking #' . formatOrderReference($order_id, $order['order_number'] ?? '');
        }, (string) ($pay['notes'] ?? ''));

        $events[] = [
            'date' => $pay['created_at'],
            'type' => 'payment',
            'title' => (strtolower($pay['status'] ?? '') === 'verified' ? 'Payment Verified: ' : (strtolower($pay['status'] ?? '') === 'rejected' ? 'Payment Rejected: ' : 'Payment Pending: ')) . formatStorePaymentTypeLabel($pay['payment_type']),
            'desc' => 'Via ' . ucfirst($pay['payment_method']) . ($payment_notes !== '' ? ' - ' . $payment_notes : ''),
            'amount' => -$pay['amount'], // Negative because it reduces balance
            'status' => $pay['status'],
            'id' => $pay['id'], // For verification actions
            'proof_image' => normalizePaymentReceiptPath($pay)
        ];
    }

    // 2. Damages
    $damages = [];
    $dmg_query = mysqli_query($conn, "SELECT * FROM booking_damages WHERE order_id = '$order_id'");
    while ($dmg = mysqli_fetch_assoc($dmg_query)) {
        $damages[] = $dmg;
        $events[] = [
            'date' => $dmg['created_at'] ?? $order['order_date'],
            'type' => 'damage',
            'title' => 'Damage Charge',
            'desc' => $dmg['item_name'] . ($dmg['notes'] ? " ({$dmg['notes']})" : ''),
            'amount' => $dmg['total'],
            'status' => 'charged'
        ];
    }

    // 3. Extra Fees (Logs)
    $log_query = mysqli_query($conn, "SELECT * FROM booking_logs WHERE order_id = '$order_id' AND action LIKE '%Extra Fee%'");
    $logged_fee_total = 0;
    while ($log = mysqli_fetch_assoc($log_query)) {
        $amount = 0;
        if (preg_match('/([\d,]+(?:\.\d{1,2})?)/', $log['details'], $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }
        $logged_fee_total += $amount;
        $events[] = [
            'date' => $log['created_at'],
            'type' => 'fee',
            'title' => 'Historical Adjustment',
            'desc' => $log['details'],
            'amount' => $amount,
            'status' => 'charged'
        ];
    }

    // Legacy/Initial Fee Check
    $total_extra = $fin['breakdown']['extra_fee'];
    if ($total_extra > $logged_fee_total + 0.01) {
        $diff = $total_extra - $logged_fee_total;
        $events[] = [
            'date' => $order['order_date'],
            'type' => 'fee',
            'title' => 'Historical Adjustment',
            'desc' => 'Service/Delivery Adjustments',
            'amount' => $diff,
            'status' => 'charged'
        ];
    }

    // 4. Order Created (Base)
    $events[] = [
        'date' => $order['created_at'] ?? $order['order_date'],
        'type' => 'order',
        'title' => !empty($order['created_at']) ? 'Booking Created' : 'Scheduled Event',
        'desc' => 'Base Package + Add-ons',
        'amount' => $fin['breakdown']['base_price'] + $fin['breakdown']['addons'],
        'status' => 'billed'
    ];

    // Sort by Date Descending
    usort($events, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    return ['events' => $events, 'damages' => $damages];
}

// ==========================================
// AJAX POLLING HANDLER
// ==========================================
function renderCatererPriceCalculation($conn, $order, $fin) {
    $pricing = bookingPricingCalculateStoredOrder($conn, $order);
    $money = static fn($amount) => '&#8369;' . number_format((float) $amount, 2);
    $lines = [];
    $guests = max(1, (int) ($order['number_of_pax'] ?? 1));
    $lines[] = 'Guest count: ' . number_format($guests);
    // Only show a rate formula when it reconciles with the saved booking price.
    if ($pricing && abs($pricing['base_price'] - $fin['breakdown']['base_price']) < 0.01) {
        if ($pricing['pricing_mode'] === 'package') {
            $lines[] = 'Package (' . (int) $pricing['selected_set_pax'] . ' guests included): ' . $money($pricing['selected_set_price']);
            if ($pricing['extra_guest_count'] > 0) {
                $lines[] = 'Additional guests: ' . (int) $pricing['extra_guest_count'] . ' &times; ' . $money($pricing['extra_guest_rate']) . ' = ' . $money($pricing['extra_guest_total']);
            }
        } else {
            $lines[] = (int) $pricing['quantity'] . ' guests &times; ' . $money($pricing['price_per_unit']) . ' per guest = ' . $money($pricing['quantity'] * $pricing['price_per_unit']);
        }
        if ($pricing['swaps_per_unit'] > 0) $lines[] = 'Menu swaps: +' . $money($pricing['swaps_per_unit']);
    } else {
        $lines[] = 'Saved package amount: ' . $money($fin['breakdown']['base_price']);
    }
    $formula = $money($fin['breakdown']['base_price']) . ' package + ' . $money($fin['breakdown']['addons']) . ' add-ons + ' . $money($fin['breakdown']['damages']) . ' damages';
    if ($fin['breakdown']['extra_fee'] != 0) $formula .= ' + ' . $money($fin['breakdown']['extra_fee']) . ' historical adjustment';
    $lines[] = $formula . ' = ' . $money($fin['totals']['grand_total']);
    $lines[] = $money($fin['totals']['grand_total']) . ' total &minus; ' . $money($fin['totals']['total_paid']) . ' verified payments = ' . $money($fin['totals']['balance']) . ' balance';
    return '<div class="caterer-price-calculation">' . implode('', array_map(static fn($line) => '<div>' . $line . '</div>', $lines)) . '</div>';
}

if (isset($_GET['fetch_data'])) {
    $fin = getOrderFinancials($order_id);
    $details = getStoreFinancialDetails($conn, $order_id, $fin, $order);
    $combined_history = getCombinedOrderPaymentHistory($order, $fin['history']);
    
    // Generate HTML for Summary Cards (Values only)
    $summary = [
        'calculation_html' => renderCatererPriceCalculation($conn, $order, $fin),
        'base' => number_format($fin['breakdown']['base_price'], 2),
        'addons' => number_format($fin['breakdown']['addons'], 2),
        'fees' => number_format($fin['breakdown']['extra_fee'], 2),
        'damages' => number_format($fin['breakdown']['damages'], 2),
        'grand_total' => number_format($fin['totals']['grand_total'], 2),
        'paid' => number_format($fin['totals']['total_paid'], 2),
        'balance' => number_format($fin['totals']['balance'], 2)
    ];

    // Generate HTML for Payment Table
    ob_start();
    foreach ($fin['history'] as $pay) {
        $status_color = $pay['status'] == 'verified' ? 'var(--success-color)' : ($pay['status'] == 'pending' ? 'var(--warning-text)' : 'var(--error-color)');
        echo '<tr>
                <td style="color: var(--accent-gold); font-weight: 600;">' . htmlspecialchars(formatOrderReference($order_id, $order['order_number'])) . '</td>
                <td>' . date('M d, Y h:i A', strtotime($pay['created_at'])) . '</td>
                <td>' . ucfirst($pay['payment_type']) . '</td>
                <td>' . ucfirst($pay['payment_method']) . '</td>
                <td style="font-weight: bold;">?' . number_format($pay['amount'], 2) . '</td>
                <td><span style="color: ' . $status_color . '; font-weight: bold; text-transform: uppercase;">' . $pay['status'] . '</span></td>
                <td>';
        if ($pay['status'] == 'pending') {
            echo '<form method="POST" style="display:inline;">
                    <input type="hidden" name="payment_id" value="'.$pay['id'].'">
                    <input type="hidden" name="reject_reason" value="">
                    <button type="submit" name="verify_payment" class="btn-xs btn-success" title="Verify payment" aria-label="Verify payment"><span class="payment-action-symbol" aria-hidden="true">&#10003;</span></button>
                    <button type="submit" name="reject_payment" class="btn-xs btn-danger" title="Reject payment" aria-label="Reject payment" onclick="return fillRejectReason(this.form);"><span class="payment-action-symbol" aria-hidden="true">&times;</span></button>
                  </form>';
        }
        echo '  </td>
              </tr>';
    }
    $payment_table_html = ob_get_clean();
    $payment_table_html = renderOrderPaymentRows($order_id, $order['order_number'], $combined_history);
    $receipt_gallery_html = renderOrderReceiptGallery($order_id, $order['order_number'], $combined_history);

    // Generate HTML for Timeline
    ob_start();
    foreach ($details['events'] as $event) {
        $color = $event['amount'] > 0 ? 'var(--error-color)' : 'var(--success-color)';
        $icon = 'fa-money-bill-wave';
        if ($event['type'] == 'damage') $icon = 'fa-exclamation-triangle';
        if ($event['type'] == 'fee') $icon = 'fa-tag';
        if ($event['type'] == 'order') $icon = 'fa-shopping-cart';

        echo '<div class="timeline-item">
                <div class="timeline-icon" style="background: '.$color.'"><i class="fas '.$icon.'"></i></div>
                <div class="timeline-content">
                    <div class="timeline-header">
                        <span class="timeline-title">'.$event['title'].'</span>
                        <span class="timeline-date">'.date('M d, Y h:i A', strtotime($event['date'])).'</span>
                    </div>
                    <div class="timeline-desc">'.$event['desc'].'</div>
                    '.renderPaymentTimelineReceiptThumb($event).'
                </div>
                <div class="timeline-amount" style="color: '.$color.'">
                    '.($event['amount'] > 0 ? '+' : '').'&#8369;'.number_format(abs($event['amount']), 2).'
                </div>
              </div>';
    }
    $timeline_html = ob_get_clean();

    echo json_encode([
        'summary' => $summary,
        'payment_table' => $payment_table_html,
        'receipt_gallery' => $receipt_gallery_html,
        'timeline' => $timeline_html
    ]);
    exit();
}

// ==========================================
// INITIAL PAGE LOAD
// ==========================================
$fin = getOrderFinancials($order_id);
$combined_history = getCombinedOrderPaymentHistory($order, $fin['history']);
$details = getStoreFinancialDetails($conn, $order_id, $fin, $order);

$page_title = "Payment Management - Booking #" . formatCatererBookingNumber($order_id, $order['order_number']);
require_once __DIR__ . '/includes/header.php';
?>

<?php if (($_GET['error'] ?? '') === 'payment_review_failed'): ?>
    <div role="alert" style="margin: 20px; padding: 15px; border: 1px solid #f44336; color: #f44336;">
        Payment was not updated. It may have already been reviewed or could not be found. Refresh the page and check its status before trying again.
    </div>
<?php elseif (($_GET['error'] ?? '') === 'reject_reason_required'): ?>
    <div role="alert" style="margin: 20px; padding: 15px; border: 1px solid #f44336; color: #f44336;">
        Please provide a reason for rejecting this payment.
    </div>
<?php endif; ?>

<style>
    :root {
        --primary-color: #d4af37;
        --secondary-color: #141414;
        --accent-gold: #d4af37;
        --text-main: #f3f4f6;
        --text-muted: #a0a0a0;
        --border-color: #333333;
        --card-bg: #1e1e1e;
        --page-bg: #0a0a0a;
        --success-color: #4caf50;
        --error-color: #f44336;
        --warning-text: #fbbf24;
    }
    
    body { background-color: var(--page-bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    
    .container-fluid { max-width: 1400px; margin: 0 auto; padding: 20px; }
    
    .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; }
    .header-title h1 { font-size: 24px; margin: 0; color: var(--accent-gold); }
    .header-title p { margin: 5px 0 0; color: var(--text-muted); font-size: 14px; }
    
    .grid-container { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 24px; align-items: start; }
    @media(max-width: 1000px) { .grid-container { grid-template-columns: 1fr; } }
    
    .card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
    .card h3 { margin-top: 0; font-size: 16px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 15px; }
    
    .summary-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; }
    .summary-row.total { border-top: 1px dashed var(--border-color); padding-top: 10px; margin-top: 10px; font-weight: bold; font-size: 16px; color: var(--text-main); }
    .summary-row.balance { font-size: 20px; color: var(--error-color); font-weight: bold; }
    
    .btn { padding: 10px 15px; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; font-size: 14px; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
    .btn-primary { background: linear-gradient(135deg, #111111, #262626); border: 1px solid var(--accent-gold); color: var(--text-main); }
    .btn-primary:hover { opacity: 0.9; }
    .btn-outline { background: transparent; border: 1px solid var(--border-color); color: var(--text-muted); }
    .btn-outline:hover { border-color: var(--text-main); color: var(--text-main); }
    .btn-danger { background: rgba(239, 68, 68, 0.2); color: var(--error-color); }
    .btn-success { background: rgba(52, 211, 153, 0.2); color: var(--success-color); }
    .btn-xs { padding: 4px 8px; font-size: 12px; }
    .receipt-status-chip { display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .receipt-status-pending { background: rgba(212, 175, 55, 0.18); color: #f6d76d; }
    .receipt-status-verified { background: rgba(46, 204, 113, 0.16); color: #6ee7a8; }
    .receipt-status-rejected { background: rgba(231, 76, 60, 0.16); color: #ff8d85; }
    .proof-link-btn { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); border-radius: 10px; padding: 8px 10px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
    .proof-link-btn:hover { border-color: rgba(212, 175, 55, 0.5); color: #fff; }
    
    .action-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 20px; }
    
    .data-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .data-table th { text-align: left; padding: 12px; color: var(--text-muted); border-bottom: 1px solid var(--border-color); }
    .data-table td { padding: 12px; border-bottom: 1px solid var(--border-color); }
    .payment-row-highlight td { background: rgba(212, 175, 55, 0.08); }
    .receipt-gallery { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
    .receipt-card { border: 1px solid var(--border-color); border-radius: 14px; padding: 14px; background: rgba(255,255,255,0.02); }
    .receipt-card-latest { border-color: rgba(212, 175, 55, 0.55); box-shadow: 0 0 0 1px rgba(212, 175, 55, 0.2) inset; }
    .receipt-card-pending { background: linear-gradient(180deg, rgba(212, 175, 55, 0.08), rgba(255,255,255,0.02)); }
    .receipt-card-verified { background: linear-gradient(180deg, rgba(46, 204, 113, 0.08), rgba(255,255,255,0.02)); }
    .receipt-card-rejected { background: linear-gradient(180deg, rgba(231, 76, 60, 0.08), rgba(255,255,255,0.02)); }
    .receipt-card-top { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; margin-bottom: 10px; }
    .receipt-card-title { font-size: 14px; font-weight: 700; color: var(--text-main); }
    .receipt-card-meta { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
    .receipt-card-amount { font-size: 18px; font-weight: 700; color: var(--accent-gold); margin-bottom: 12px; }
    .receipt-preview-btn { width: 100%; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 0; overflow: hidden; background: #101010; cursor: pointer; }
    .receipt-preview-btn img { width: 100%; height: 120px; object-fit: cover; display: block; }
    .receipt-card-footer { margin-top: 10px; display: flex; justify-content: space-between; gap: 10px; font-size: 12px; color: var(--text-muted); }
    .empty-receipt-state { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 18px; border: 1px dashed rgba(255,255,255,0.15); border-radius: 14px; color: var(--text-muted); }
    .section-subtext { margin: -6px 0 14px; color: var(--text-muted); font-size: 13px; }
    .page-stack { margin-top: 24px; }
    
    /* Timeline */
    .timeline { position: relative; padding-left: 20px; }
    .timeline-item { position: relative; padding-left: 30px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; }
    .timeline-item:before { content: ''; position: absolute; left: 0; top: 0; bottom: -20px; width: 2px; background: var(--border-color); }
    .timeline-item:last-child:before { display: none; }
    
    .timeline-icon { position: absolute; left: -9px; top: 0; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; color: white; z-index: 2; }
    
    .timeline-content { flex: 1; margin-right: 15px; }
    .timeline-receipt-button { flex: 0 0 58px; width: 58px; height: 52px; margin: 0 14px 0 0; padding: 2px; overflow: hidden; border: 1px solid rgba(212,175,55,.65); border-radius: 8px; background: rgba(255,255,255,.04); cursor: pointer; }
    .timeline-receipt-button:hover, .timeline-receipt-button:focus-visible { border-color: var(--accent-gold); box-shadow: 0 0 0 2px rgba(212,175,55,.2); outline: none; }
    .timeline-receipt-button img { display: block; width: 100%; height: 100%; object-fit: cover; border-radius: 5px; }
    .timeline-header { display: flex; flex-direction: column; }
    .timeline-title { font-weight: bold; font-size: 14px; }
    .timeline-date { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
    .timeline-desc { font-size: 13px; color: var(--text-muted); margin-top: 5px; }
    .timeline-amount { font-weight: bold; font-family: monospace; font-size: 14px; }
    @media (max-width: 520px) { .timeline-receipt-button { flex-basis: 48px; width: 48px; height: 46px; margin-right: 8px; } .timeline-content { margin-right: 8px; } }
    
    /* Modal */
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center; }
    .modal-content { background: var(--card-bg); padding: 25px; border-radius: 12px; width: 100%; max-width: 450px; border: 1px solid var(--border-color); }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .modal-header h2 { margin: 0; font-size: 18px; color: var(--text-main); }
    .close-btn { background: none; border: none; color: var(--text-muted); font-size: 24px; cursor: pointer; }
    .proof-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.82); z-index: 3000; align-items: center; justify-content: center; padding: 24px; }
    .proof-modal-shell { position: relative; width: min(760px, 100%); background: #161616; border: 1px solid rgba(255,255,255,0.1); border-radius: 18px; padding: 20px; }
    .proof-modal-title { color: var(--text-main); font-size: 16px; font-weight: 700; margin-bottom: 14px; }
    .proof-modal-shell img { width: 100%; max-height: 78vh; object-fit: contain; border-radius: 12px; background: #0d0d0d; }
    .proof-modal-close { position: absolute; top: 12px; right: 14px; background: rgba(255,255,255,0.08); border: none; color: #fff; width: 34px; height: 34px; border-radius: 10px; font-size: 22px; cursor: pointer; }
    
    .form-group { margin-bottom: 15px; }
    .form-label { display: block; margin-bottom: 5px; font-size: 13px; color: var(--text-muted); }
    .form-control { width: 100%; padding: 10px; background: #111827; border: 1px solid var(--border-color); color: white; border-radius: 6px; box-sizing: border-box; }
    .form-control:focus { outline: none; border-color: var(--primary-color); }
</style>

<link rel="stylesheet" href="../assets/css/caterer-payment-detail.css">
<link rel="stylesheet" href="../assets/css/caterer-payments.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-payments.css'); ?>">
<?php if (false): ?>
<div class="container-fluid">
    <a href="<?php echo htmlspecialchars($payment_back_href); ?>" class="payment-back-symbol" aria-label="Back to Booking" title="Back to Booking"><span aria-hidden="true">&#8592;</span></a>
    
    <div class="grid-container">
        <!-- LEFT COLUMN: Summary & Actions -->
        <div>
            <!-- Financial Summary -->
            <div class="card">
                <h3><i class="fas fa-calculator"></i> Financial Summary</h3>
<div id="summary-calculation"><?php echo renderCatererPriceCalculation($conn, $order, $fin); ?></div>
                <div class="summary-row">
                    <span>Base Package Price</span>
                    <span id="sum-base">&#8369;<?php echo number_format($fin['breakdown']['base_price'], 2); ?></span>
                </div>
                <div class="summary-row">
                    <span>Add-ons Total</span>
                    <span id="sum-addons">&#8369;<?php echo number_format($fin['breakdown']['addons'], 2); ?></span>
                </div>
                <div class="summary-row" style="color: var(--error-color);">
                    <span>Damage Charges</span>
                    <span id="sum-damages">&#8369;<?php echo number_format($fin['breakdown']['damages'], 2); ?></span>
                </div>
                <div class="summary-row total">
                    <span>Grand Total</span>
                    <span id="sum-grand">&#8369;<?php echo number_format($fin['totals']['grand_total'], 2); ?></span>
                </div>
                
                <div class="summary-row" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-color);">
                    <span>Total Paid (Verified)</span>
                    <span id="sum-paid" style="color: var(--success-color); font-weight: bold;">&#8369;<?php echo number_format($fin['totals']['total_paid'], 2); ?></span>
                </div>
                <div class="summary-row balance">
                    <span>Balance Due</span>
                    <span id="sum-balance">&#8369;<?php echo number_format($fin['totals']['balance'], 2); ?></span>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="action-grid">
                <button onclick="openModal('paymentModal')" class="btn btn-primary" style="justify-content: center; flex-direction: column; gap: 5px; padding: 15px;">
                    <i class="fas fa-wallet" style="font-size: 20px;"></i>
                    <span>Payment</span>
                </button>
                
                
            </div>
            
            <?php if (false): ?>
            <!-- Transaction History Timeline -->
            <div class="card">
                <h3><i class="fas fa-history"></i> Transaction History</h3>
                <div class="timeline" id="timeline-container">
                    <?php foreach ($details['events'] as $event): 
                        $color = $event['amount'] > 0 ? 'var(--error-color)' : 'var(--success-color)';
                        $icon = 'fa-money-bill-wave';
                        if ($event['type'] == 'damage') $icon = 'fa-exclamation-triangle';
                        if ($event['type'] == 'fee') $icon = 'fa-tag';
                        if ($event['type'] == 'order') $icon = 'fa-shopping-cart';
                    ?>
                        <div class="timeline-item">
                            <div class="timeline-icon" style="background: <?php echo $color; ?>"><i class="fas <?php echo $icon; ?>"></i></div>
                            <div class="timeline-content">
                                <div class="timeline-header">
                                    <span class="timeline-title"><?php echo htmlspecialchars($event['title']); ?></span>
                                    <span class="timeline-date"><?php echo date('M d, Y h:i A', strtotime($event['date'])); ?></span>
                                </div>
                                <div class="timeline-desc"><?php echo htmlspecialchars($event['desc']); ?></div>
<?php echo renderPaymentTimelineReceiptThumb($event); ?>
                            </div>
                            <div class="timeline-amount" style="color: <?php echo $color; ?>">
                                <?php echo ($event['amount'] > 0 ? '+' : '') . '&#8369;' . number_format(abs($event['amount']), 2); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- RIGHT COLUMN: Payment Table -->
        <div>
            <div class="card">
                <h3><i class="fas fa-image"></i> Customer Receipt History</h3>
                <div id="receipt-gallery-container">
                    <?php echo renderOrderReceiptGallery($order_id, $order['order_number'], $combined_history); ?>
                </div>
            </div>

            <div class="card">
                <h3><i class="fas fa-list"></i> Payment Transactions</h3>
                <div class="section-subtext">Review and verify uploaded payment transactions here.</div>
                <div style="overflow-x: auto;">
                    <table class="data-table payment-transactions-table">
                        <thead>
                            <tr>
                                <th>Booking Ref</th>
                                <th>Date & Time</th>
                                <th>Type</th>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Receipt</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="payment-table-body">
                            <?php if (false): foreach ($combined_history as $pay): ?>
                                <tr>
                                    <td style="color: var(--accent-gold); font-weight: 600;"><?php echo htmlspecialchars(formatOrderReference($order_id, $order['order_number'])); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($pay['created_at'])); ?></td>
                                    <td><?php echo ucfirst($pay['payment_type']); ?></td>
                                    <td><?php echo ucfirst($pay['payment_method']); ?></td>
                                    <td style="font-weight: bold;">&#8369;<?php echo number_format($pay['amount'], 2); ?></td>
                                    <td>
                                        <?php 
                                            $status_color = $pay['status'] == 'verified' ? 'var(--success-color)' : ($pay['status'] == 'pending' ? 'var(--warning-text)' : 'var(--error-color)');
                                        ?>
                                        <span style="color: <?php echo $status_color; ?>; font-weight: bold; text-transform: uppercase;"><?php echo $pay['status']; ?></span>
                                    </td>
                                    <td>
                                        <?php if ($pay['status'] == 'pending'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="payment_id" value="<?php echo $pay['id']; ?>">
                                                <input type="hidden" name="reject_reason" value="">
                                                <button type="submit" name="verify_payment" class="btn-xs btn-success" title="Verify payment" aria-label="Verify payment"><span class="payment-action-symbol" aria-hidden="true">&#10003;</span></button>
                                                <button type="submit" name="reject_payment" class="btn-xs btn-danger" title="Reject payment" aria-label="Reject payment" onclick="return fillRejectReason(this.form);"><span class="payment-action-symbol" aria-hidden="true">&times;</span></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($combined_history)): ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No payments found.</td></tr>
                            <?php endif; endif; ?>
                            <?php echo renderOrderPaymentRows($order_id, $order['order_number'], $combined_history); ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="page-stack">
        <div class="card">
            <h3><i class="fas fa-history"></i> Transaction History</h3>
            
            <div class="timeline" id="timeline-container">
                <?php foreach ($details['events'] as $event): 
                    $color = $event['amount'] > 0 ? 'var(--error-color)' : 'var(--success-color)';
                    $icon = 'fa-money-bill-wave';
                    if ($event['type'] == 'damage') $icon = 'fa-exclamation-triangle';
                    if ($event['type'] == 'fee') $icon = 'fa-tag';
                    if ($event['type'] == 'order') $icon = 'fa-shopping-cart';
                ?>
                    <div class="timeline-item">
                        <div class="timeline-icon" style="background: <?php echo $color; ?>"><i class="fas <?php echo $icon; ?>"></i></div>
                        <div class="timeline-content">
                            <div class="timeline-header">
                                <span class="timeline-title"><?php echo htmlspecialchars($event['title']); ?></span>
                                <span class="timeline-date"><?php echo date('M d, Y h:i A', strtotime($event['date'])); ?></span>
                            </div>
                            <div class="timeline-desc"><?php echo htmlspecialchars($event['desc']); ?></div>
<?php echo renderPaymentTimelineReceiptThumb($event); ?>
                        </div>
                        <div class="timeline-amount" style="color: <?php echo $color; ?>">
                            <?php echo ($event['amount'] > 0 ? '+' : '') . '&#8369;' . number_format(abs($event['amount']), 2); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<div class="container-fluid">
    <a href="<?php echo htmlspecialchars($payment_back_href); ?>" class="payment-back-symbol" aria-label="Back to Booking" title="Back to Booking"><span aria-hidden="true">&#8592;</span></a>

    <div class="grid-container">
        <div>
            <div class="card">
                <h3><i class="fas fa-calculator"></i> Financial Summary</h3>
<div id="summary-calculation"><?php echo renderCatererPriceCalculation($conn, $order, $fin); ?></div>
                <div class="summary-row">
                    <span>Base Package Price</span>
                    <span id="sum-base">&#8369;<?php echo number_format($fin['breakdown']['base_price'], 2); ?></span>
                </div>
                <div class="summary-row">
                    <span>Add-ons Total</span>
                    <span id="sum-addons">&#8369;<?php echo number_format($fin['breakdown']['addons'], 2); ?></span>
                </div>
                <div class="summary-row" style="color: var(--error-color);">
                    <span>Damage Charges</span>
                    <span id="sum-damages">&#8369;<?php echo number_format($fin['breakdown']['damages'], 2); ?></span>
                </div>
                <div class="summary-row total">
                    <span>Grand Total</span>
                    <span id="sum-grand">&#8369;<?php echo number_format($fin['totals']['grand_total'], 2); ?></span>
                </div>
                <div class="summary-row" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-color);">
                    <span>Total Paid (Verified)</span>
                    <span id="sum-paid" style="color: var(--success-color); font-weight: bold;">&#8369;<?php echo number_format($fin['totals']['total_paid'], 2); ?></span>
                </div>
                <div class="summary-row balance">
                    <span>Balance Due</span>
                    <span id="sum-balance">&#8369;<?php echo number_format($fin['totals']['balance'], 2); ?></span>
                </div>
            </div>

            <div class="card">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                <div class="section-subtext">Add payments for this booking.</div>
                <div class="action-grid" style="margin-bottom: 0;">
                    <button onclick="openModal('paymentModal')" class="btn btn-primary" style="justify-content: center; flex-direction: column; gap: 5px; padding: 15px;">
                        <i class="fas fa-wallet" style="font-size: 20px;"></i>
                        <span>Payment</span>
                    </button>
                    
                    
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <h3><i class="fas fa-image"></i> Customer Receipt History</h3>
                <div id="receipt-gallery-container">
                    <?php echo renderOrderReceiptGallery($order_id, $order['order_number'], $combined_history); ?>
                </div>
            </div>

            <div class="card">
                <h3><i class="fas fa-list"></i> Payment Transactions</h3>
                <div class="section-subtext">Review and verify uploaded payment transactions here.</div>
                <div style="overflow-x: auto;">
                    <table class="data-table payment-transactions-table">
                        <thead>
                            <tr>
                                <th>Booking Ref</th>
                                <th>Date & Time</th>
                                <th>Type</th>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Receipt</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="payment-table-body">
                            <?php echo renderOrderPaymentRows($order_id, $order['order_number'], $combined_history); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="page-stack">
        <div class="card">
            <h3><i class="fas fa-history"></i> Transaction History</h3>
            
            <div class="timeline" id="timeline-container">
                <?php foreach ($details['events'] as $event): 
                    $color = $event['amount'] > 0 ? 'var(--error-color)' : 'var(--success-color)';
                    $icon = 'fa-money-bill-wave';
                    if ($event['type'] == 'damage') $icon = 'fa-exclamation-triangle';
                    if ($event['type'] == 'fee') $icon = 'fa-tag';
                    if ($event['type'] == 'order') $icon = 'fa-shopping-cart';
                ?>
                    <div class="timeline-item">
                        <div class="timeline-icon" style="background: <?php echo $color; ?>"><i class="fas <?php echo $icon; ?>"></i></div>
                        <div class="timeline-content">
                            <div class="timeline-header">
                                <span class="timeline-title"><?php echo htmlspecialchars($event['title']); ?></span>
                                <span class="timeline-date"><?php echo date('M d, Y h:i A', strtotime($event['date'])); ?></span>
                            </div>
                            <div class="timeline-desc"><?php echo htmlspecialchars($event['desc']); ?></div>
<?php echo renderPaymentTimelineReceiptThumb($event); ?>
                        </div>
                        <div class="timeline-amount" style="color: <?php echo $color; ?>">
                            <?php echo ($event['amount'] > 0 ? '+' : '') . '&#8369;' . number_format(abs($event['amount']), 2); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- MODALS -->

<!-- Add Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Pay</h2>
            <button class="close-btn" onclick="closeModal('paymentModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Payment Type</label>
                <select name="payment_type" class="form-control">
                    <option value="down_payment">Down Payment</option>
                    <option value="installment">Additional Payment</option>
                    <option value="full_payment">Full Payment</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Payment Method</label>
                <select name="payment_method" class="form-control">
                    <option value="cash">Cash</option>
                    <option value="gcash">GCash</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Amount</label>
                <input type="number" min="0.01" step="0.01" name="amount" class="form-control" required placeholder="0.00" id="paymentAmount">
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Ref No, Payer Name, etc."></textarea>
            </div>
            <button type="submit" name="add_payment" class="btn btn-primary" style="width: 100%; justify-content: center;">Save Payment</button>
        </form>
    </div>
</div>

<!-- Add Damage Modal -->
<div id="damageModal" class="modal">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="damageModal-title">
        <div class="modal-header">
            <h2 id="damageModal-title">Record Damage Charge</h2>
            <button type="button" class="close-btn" aria-label="Close" onclick="closeModal('damageModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Item Name</label>
                <input type="text" name="item_name" class="form-control" required placeholder="e.g. Broken Plate">
            </div>
            <div class="form-group">
                <label class="form-label">Description / Notes</label>
                <textarea name="description" class="form-control" rows="2" placeholder="Details of damage..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Amount Charge</label>
                <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0.00">
            </div>
            <button type="submit" name="add_damage" class="btn btn-danger" style="width: 100%; justify-content: center; color: white;">Record Charge</button>
        </form>
    </div>
</div>

<div id="proofModal" class="proof-modal">
    <div class="proof-modal-shell">
        <button type="button" class="proof-modal-close" aria-label="Close receipt preview" onclick="closeProofModal(event)">&times;</button>
        <div id="proofModalTitle" class="proof-modal-title">Payment Receipt</div>
        <img id="proofModalImage" src="" alt="Receipt preview">
    </div>
</div>

<script>
    function fillRejectReason(form) {
        const reason = prompt('Please enter the reason for rejecting this payment proof:');
        if (reason === null) {
            return false;
        }

        if (!reason.trim()) {
            alert('Reject reason is required.');
            return false;
        }

        const input = form.querySelector('input[name="reject_reason"]');
        if (input) {
            input.value = reason.trim();
        }
        return true;
    }

    function openModal(id) {
        document.getElementById(id).style.display = 'flex';
    }
    
    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    const paymentAmount = document.getElementById('paymentAmount');
    paymentAmount.addEventListener('keydown', function(event) {
        if (event.key === '-') event.preventDefault();
    });
    paymentAmount.addEventListener('input', function() {
        if (this.value.includes('-') || this.valueAsNumber < 0) {
            this.value = '';
        }
    });

    let proofOrigin = null;
    let proofReturnTarget = null;
    function openProofModal(src, title) {
        proofReturnTarget = document.activeElement;
        const modal = document.getElementById('proofModal');
        const image = document.getElementById('proofModalImage');
        const heading = document.getElementById('proofModalTitle');
        if (!modal || !image || !heading) return;
        image.src = src;
        heading.textContent = title || 'Payment Receipt';
        modal.style.display = 'flex';
    }

    function closeProofModal(event) {
        if (!event || !event.currentTarget?.classList.contains('proof-modal-close')) return;
        const modal = document.getElementById('proofModal');
        const image = document.getElementById('proofModalImage');
        if (modal) modal.style.display = 'none';
        if (image) image.src = '';
        if (proofOrigin) {
            const origin = document.getElementById(proofOrigin.id);
            if (origin && !origin.open) origin.showModal();
            const panel = origin?.querySelector('.payment-detail-panel');
            if (panel) panel.scrollTop = proofOrigin.scroll || 0;
        }
        if (proofReturnTarget?.isConnected) proofReturnTarget.focus({preventScroll: true});
        proofOrigin = null;
        saveDetailView();
    }
    
    // Close on outside click
    window.onclick = function(event) {
        // Payment, fee, and damage forms close through their X buttons only.
    }
    
    document.addEventListener('keydown', event => {
        const preview = document.getElementById('proofModal');
        if (preview.style.display !== 'flex') return;
        if (event.key === 'Escape' || event.key === 'Tab') {
            event.preventDefault();
            event.stopImmediatePropagation();
            preview.querySelector('.proof-modal-close').focus();
        }
    }, true);
    const historyCard = Array.from(document.querySelectorAll('.container-fluid .card')).find(card => card.querySelector(':scope > h3')?.textContent.trim() === 'Transaction History');
    const sectionToolbar = document.createElement('div');
    sectionToolbar.className = 'payment-history-tools';
    sectionToolbar.setAttribute('role', 'toolbar');
    sectionToolbar.setAttribute('aria-label', 'Booking tools');
    if (historyCard) {
        historyCard.classList.add('payment-history-card');
        const header = document.createElement('div');
        header.className = 'payment-history-heading';
        header.append(historyCard.querySelector(':scope > h3'), sectionToolbar);
        historyCard.prepend(header);
    }
    const quickActionsCard = Array.from(document.querySelectorAll('.container-fluid .card')).find(card => card.querySelector(':scope > h3')?.textContent.trim() === 'Quick Actions');
    const backLink = document.querySelector('.container-fluid > .payment-back-symbol');
    if (quickActionsCard && backLink) {
        const toolbar = document.createElement('div');
        toolbar.className = 'payment-direct-actions';
        backLink.before(toolbar);
        toolbar.append(backLink);
        const actions = quickActionsCard.querySelector('.action-grid');
        if (actions) toolbar.append(actions);
        quickActionsCard.remove();
    }
    const financialSummaryCard = Array.from(document.querySelectorAll('.container-fluid .card')).find(card => card.querySelector(':scope > h3')?.textContent.trim() === 'Financial Summary');
    if (historyCard && financialSummaryCard) {
        const layout = document.createElement('div');
        layout.className = 'payment-history-summary-layout';
        historyCard.before(layout);
        financialSummaryCard.classList.add('payment-inline-summary');
        if (new URLSearchParams(location.search).get('embed') === '1') {
            const balanceRow = financialSummaryCard.querySelector('#sum-balance').closest('.summary-row');
            const paidRow = financialSummaryCard.querySelector('#sum-paid').closest('.summary-row');
            const totalRow = financialSummaryCard.querySelector('#sum-grand').closest('.summary-row');
            const calculation = financialSummaryCard.querySelector('#summary-calculation');
            const breakdown = document.createElement('details');
            breakdown.className = 'payment-cost-details';
            const label = document.createElement('summary');
            label.textContent = 'View cost breakdown';
            breakdown.append(label, ...financialSummaryCard.querySelectorAll('.summary-row:not(.total, .balance)'));
            breakdown.append(calculation);
            financialSummaryCard.append(balanceRow, totalRow, paidRow, breakdown);
            layout.append(financialSummaryCard, historyCard);
        } else {
            layout.append(historyCard, financialSummaryCard);
        }
    }
    const sectionSymbols = {'Customer Receipt History': '≡', 'Payment Transactions': '⇄'};
    document.querySelectorAll('.container-fluid .card').forEach((card, index) => {
        const heading = card.querySelector(':scope > h3');
        const title = heading?.textContent.trim();
        if (!sectionSymbols[title]) return;
        const dialog = document.createElement('dialog');
        dialog.className = 'payment-section-dialog';
        dialog.dataset.section = title;
        dialog.addEventListener('cancel', event => event.preventDefault());
        dialog.id = 'payment-detail-panel-' + index;
        const header = document.createElement('div');
        header.className = 'payment-section-dialog-header';
        const modalTitle = document.createElement('h2');
        modalTitle.id = dialog.id + '-title';
        modalTitle.textContent = title;
        dialog.setAttribute('aria-labelledby', modalTitle.id);
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'payment-section-close';
        close.textContent = '×';
        close.setAttribute('aria-label', 'Close');
        close.addEventListener('click', () => dialog.close());
        header.append(modalTitle, close);
        const panel = document.createElement('div');
        panel.className = 'payment-detail-panel';
        Array.from(card.childNodes).forEach(node => { if (node !== heading) panel.append(node); });
        dialog.append(header, panel);
        document.body.append(dialog);
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'payment-detail-toggle';
        const symbol = document.createElement('span');
        symbol.textContent = sectionSymbols[title];
        symbol.setAttribute('aria-hidden', 'true');
        const label = document.createElement('span');
        label.textContent = title;
        toggle.append(symbol, label);
        toggle.title = title;
        toggle.setAttribute('aria-label', title);
        toggle.setAttribute('aria-haspopup', 'dialog');
        toggle.setAttribute('aria-controls', dialog.id);
        toggle.addEventListener('click', () => { dialog.showModal(); saveDetailView(); });
        dialog.addEventListener('close', saveDetailView);
        dialog.addEventListener('click', event => {
            if (event.target.closest('[onclick*="openProofModal"]')) {
                proofOrigin = {id: dialog.id, scroll: panel.scrollTop};
                dialog.close();
            } else if (event.target.closest('[onclick*="openModal"]')) dialog.close();
        }, true);
        if (title === 'Payment Transactions') {
            window.openPaymentTransactions = () => {
                if (!dialog.open) dialog.showModal();
                saveDetailView();
            };
            if (new URLSearchParams(location.search).get('transactions_only') === '1') {
                dialog.classList.add('transactions-only-panel');
                dialog.addEventListener('close', () => window.parent.postMessage('close-payment-transactions', location.origin));
                window.addEventListener('load', () => window.openPaymentTransactions());
            }
        }
        if (title !== 'Payment Transactions') {
            sectionToolbar.append(toggle);
        }
        card.remove();
    });
    if (historyCard) {
        const page = historyCard.closest('.container-fluid');
        if (page?.querySelector('.payment-history-summary-layout .payment-inline-summary')) {
            page.querySelector(':scope > .grid-container')?.remove();
        }
    }
    const detailWorkspace = document.querySelector('.caterer-workspace');
    const detailMode = new URLSearchParams(location.search);
    const detailStateKey = 'payment-detail-<?php echo (int) $store_id; ?>-<?php echo (int) $order_id; ?>-' + (detailMode.get('transactions_only') === '1' ? 'transactions' : detailMode.get('embed') === '1' ? 'embedded' : 'page');
    let detailRestoring = false;
    function saveDetailView() {
        if (detailRestoring) return;
        try { sessionStorage.setItem(detailStateKey, JSON.stringify({
            proof: document.getElementById('proofModal').style.display === 'flex' ? {
                src: document.getElementById('proofModalImage').getAttribute('src'),
                title: document.getElementById('proofModalTitle').textContent,
                origin: proofOrigin
            } : null,
            scroll: detailWorkspace?.scrollTop || 0,
            timelineScroll: document.getElementById('timeline-container')?.scrollTop || 0,
            panels: Array.from(document.querySelectorAll('dialog.payment-section-dialog[open]')).map(el => el.id),
            expanded: Array.from(document.querySelectorAll('details[open][id]')).map(el => el.id)
        })); } catch (_) {}
    }
    function restoreDetailView() {
        try {
            const state = JSON.parse(sessionStorage.getItem(detailStateKey) || 'null');
            if (!state) return;
            detailRestoring = true;
            if (state.proof?.src) {
                const url = new URL(state.proof.src, location.href);
                if (url.origin === location.origin) {
                    proofOrigin = state.proof.origin || null;
                    openProofModal(state.proof.src, state.proof.title);
                }
            }
            (state.panels || []).forEach(id => {
                const panel = document.getElementById(id);
                if (!panel?.classList.contains('payment-section-dialog')) return;
                if (!panel.open) panel.showModal();
            });
            (state.expanded || []).forEach(id => { const el = document.getElementById(id); if (el?.tagName === 'DETAILS') el.open = true; });
            if (detailWorkspace) detailWorkspace.scrollTop = Number(state.scroll) || 0;
            const timeline = document.getElementById('timeline-container');
            if (timeline) timeline.scrollTop = Number(state.timelineScroll) || 0;
        } catch (_) {} finally { detailRestoring = false; }
    }
    detailWorkspace?.addEventListener('scroll', saveDetailView, {passive:true});
    document.getElementById('timeline-container')?.addEventListener('scroll', saveDetailView, {passive:true});
    window.addEventListener('pagehide', saveDetailView);
    window.addEventListener('pageshow', restoreDetailView);
    restoreDetailView();
    // Real-Time Polling
    setInterval(function() {
        if (document.hidden || document.activeElement?.matches('input, textarea, select') || Array.from(document.querySelectorAll('.modal')).some(el => el.style.display === 'flex') || document.querySelector('dialog.payment-section-dialog[open]')) return;
        fetch('payments.php?id=<?php echo $order_id; ?>&fetch_data=1', {cache: 'no-store'})
            .then(res => res.json())
            .then(data => {
                // Update Summary
                document.getElementById('sum-base').innerText = '\u20b1' + data.summary.base;
                const calculation = document.getElementById('summary-calculation');
                if (calculation) calculation.innerHTML = data.summary.calculation_html;
                document.getElementById('sum-addons').innerText = '\u20b1' + data.summary.addons;
                document.getElementById('sum-damages').innerText = '\u20b1' + data.summary.damages;
                document.getElementById('sum-grand').innerText = '\u20b1' + data.summary.grand_total;
                document.getElementById('sum-paid').innerText = '\u20b1' + data.summary.paid;
                document.getElementById('sum-balance').innerText = '\u20b1' + data.summary.balance;
                
                // Update HTML Sections
                const detailScroll = detailWorkspace?.scrollTop || 0;
                const timelineScroll = document.getElementById('timeline-container').scrollTop;
                document.getElementById('timeline-container').innerHTML = data.timeline;
                document.getElementById('receipt-gallery-container').innerHTML = data.receipt_gallery;
                document.getElementById('payment-table-body').innerHTML = data.payment_table;
                if (detailWorkspace) detailWorkspace.scrollTop = detailScroll;
                document.getElementById('timeline-container').scrollTop = timelineScroll;
            });
    }, 20000);
</script>

<?php if (($_GET['embed'] ?? '') === '1'): ?>
<style>
body.bounded-caterer-page > .sidebar,body.bounded-caterer-page > .main-footer,.mobile-topbar,.sidebar-toggle,.payment-back-symbol{display:none!important;}
body.bounded-caterer-page > .main-content{margin-left:0!important;padding:6px!important;width:100%;}
body.bounded-caterer-page > .main-content:has(.payment-history-summary-layout){padding:0!important;margin:0!important;height:100dvh!important;min-height:0!important;box-sizing:border-box;}
.caterer-workspace{border:0!important;box-shadow:none!important;padding:6px!important;}
body.bounded-caterer-page .caterer-workspace:has(.payment-history-summary-layout){margin:0!important;padding:12px!important;gap:0!important;}
body.bounded-caterer-page .caterer-workspace > .container-fluid:has(.payment-history-summary-layout){margin:0!important;padding:0!important;gap:0!important;width:100%;max-width:none;}
body.bounded-caterer-page .container-fluid:has(.payment-history-summary-layout) > .page-stack{margin:0!important;padding:0!important;}
body.bounded-caterer-page .caterer-workspace > .page-header,
body.bounded-caterer-page .container-fluid:has(.payment-history-summary-layout) > :is(.page-header,.payment-direct-actions){display:none!important;}
body.bounded-caterer-page .payment-history-summary-layout{gap:18px;}
body.bounded-caterer-page .payment-history-summary-layout .payment-history-heading{margin-top:0;padding-top:0;}
.payment-direct-actions,.action-grid{display:none!important;}
@media(max-width:600px){
    html,body.bounded-caterer-page{height:auto!important;min-height:100%;overflow:auto!important;}
    body.bounded-caterer-page > .main-content,
    body.bounded-caterer-page .caterer-workspace,
    body.bounded-caterer-page .caterer-workspace > .container-fluid,
    body.bounded-caterer-page .container-fluid > .page-stack{height:auto!important;max-height:none!important;overflow:visible!important;flex:0 0 auto!important;min-width:0;}
    body.bounded-caterer-page .payment-history-summary-layout{display:flex!important;flex-direction:column!important;height:auto!important;min-height:0!important;overflow:visible!important;gap:16px!important;}
    body.bounded-caterer-page .caterer-workspace .payment-history-summary-layout :is(.payment-history-card,.payment-inline-summary){width:100%!important;min-width:0!important;height:auto!important;max-height:none!important;flex:0 0 auto!important;box-sizing:border-box;}
    body.bounded-caterer-page .payment-history-summary-layout .payment-history-card > .timeline{height:auto!important;min-height:100px!important;max-height:220px!important;flex:0 0 auto!important;overflow-y:auto!important;}
    .payment-history-heading{flex-wrap:wrap;gap:8px;}
    .summary-row{gap:12px;overflow-wrap:anywhere;}
}
html:has(.payment-history-summary-layout), body.bounded-caterer-page:has(.payment-history-summary-layout) {
    height: auto !important; overflow-y: auto !important;
}
body.bounded-caterer-page .main-content:has(.payment-history-summary-layout),
body.bounded-caterer-page .caterer-workspace:has(.payment-history-summary-layout),
body.bounded-caterer-page .container-fluid:has(.payment-history-summary-layout),
body.bounded-caterer-page .container-fluid:has(.payment-history-summary-layout) > .page-stack,
body.bounded-caterer-page .caterer-workspace .payment-history-summary-layout,
body.bounded-caterer-page .caterer-workspace .payment-history-summary-layout :is(.payment-history-card,.payment-inline-summary),
body.bounded-caterer-page .caterer-workspace .payment-history-summary-layout .payment-history-card > .timeline {
    height: auto !important; min-height: 0 !important; max-height: none !important; overflow: visible !important;
}
body.bounded-caterer-page .caterer-workspace .payment-history-summary-layout {
    display: grid !important; grid-template-columns: minmax(0, 1fr) !important; gap: 10px !important;
}
body.bounded-caterer-page .caterer-workspace .payment-history-summary-layout :is(.payment-inline-summary,.payment-history-card) {
    width: 100% !important; min-width: 0; box-sizing: border-box; padding: 12px !important;
    background: #cbd7c5 !important; border: 1px solid #a2b19b !important; border-radius: 10px;
}
.payment-inline-summary .summary-row { gap: 12px; padding-block: 6px !important; margin: 0 !important; }
.payment-inline-summary .summary-row span:last-child { text-align: right; }
.payment-inline-summary .summary-row.balance { background: #b8cdb1 !important; padding: 10px !important; }
.payment-cost-details { margin-top: 8px; border-top: 1px solid #a2b19b; }
.payment-cost-details summary { padding: 10px 0; cursor: pointer; color: #173d32; font-weight: 600; }
.payment-history-heading { flex-wrap: wrap; gap: 8px; }
body.bounded-caterer-page:has(.payment-history-summary-layout) {
    min-height: 100dvh !important;
}
body.bounded-caterer-page > .main-content:has(.payment-history-summary-layout) {
    height: auto !important;
    min-height: 100dvh !important;
    max-height: none !important;
    overflow: visible !important;
}
body.bounded-caterer-page .caterer-workspace:has(.payment-history-summary-layout),
body.bounded-caterer-page .caterer-workspace > .container-fluid:has(.payment-history-summary-layout),
body.bounded-caterer-page .container-fluid:has(.payment-history-summary-layout) > .page-stack,
body.bounded-caterer-page .caterer-workspace .payment-history-summary-layout {
    flex: 0 0 auto !important;
    height: auto !important;
    min-height: min-content !important;
    max-height: none !important;
    overflow: visible !important;
}
body.bounded-caterer-page .payment-history-summary-layout::after { content: none; }
body.bounded-caterer-page .caterer-workspace .payment-history-summary-layout :is(.payment-inline-summary, .payment-history-card) {
    display: block !important;
    margin: 0;
    border-radius: 10px !important;
}
body.bounded-caterer-page .payment-history-summary-layout :is(.payment-history-heading, .payment-inline-summary > h3) {
    height: auto !important;
    min-height: 0 !important;
    margin: 0 0 8px !important;
    padding: 0 0 8px !important;
    border-bottom: 1px solid #a2b19b !important;
    background: transparent !important;
}
body.bounded-caterer-page .caterer-workspace .payment-history-summary-layout .payment-history-card > .timeline {
    position: relative !important;
    inset: auto !important;
    display: flow-root !important;
    margin: 0;
    padding: 4px 8px 0 14px;
}
body.bounded-caterer-page .payment-history-summary-layout .timeline-item:last-child { margin-bottom: 0; }
</style>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php if (($_GET['transactions_only'] ?? '') === '1'): ?>
<style>
html,html body.bounded-caterer-page{background:transparent!important;background-image:none!important;margin:0!important;padding:0!important;}
body > .main-content{display:none!important;}
.transactions-only-panel{visibility:visible!important;width:100%!important;max-width:none!important;max-height:calc(100dvh - 4px)!important;margin:0 auto!important;box-sizing:border-box;border-radius:12px!important;box-shadow:none!important;}
.transactions-only-panel .payment-section-dialog-header{padding:10px 16px;}
.transactions-only-panel .payment-section-dialog-header h2{font-size:16px;}
.transactions-only-panel .payment-section-close{outline:none!important;border:0!important;background:transparent!important;box-shadow:none!important;min-width:44px;min-height:44px;}
html body.bounded-caterer-page dialog.transactions-only-panel .payment-section-dialog-header button.payment-section-close,
html body.bounded-caterer-page dialog.transactions-only-panel .payment-section-dialog-header button.payment-section-close:is(:hover,:active){outline:0!important;border:0!important;background:transparent!important;box-shadow:none!important;border-radius:0!important;}
.transactions-only-panel .payment-detail-panel{padding:12px!important;}
@media(max-width:600px){
 .transactions-only-panel .data-table,.transactions-only-panel .data-table tbody{display:block!important;width:100%!important;min-width:0!important;}
 .transactions-only-panel .data-table thead{display:none!important;}
 .transactions-only-panel .data-table tr{display:block!important;padding:8px 0!important;border-bottom:1px solid #a9b6a1;}
 .transactions-only-panel .data-table td{display:grid!important;grid-template-columns:minmax(95px, .8fr) minmax(0, 1.2fr)!important;gap:12px!important;text-align:left!important;padding:8px!important;white-space:normal!important;overflow-wrap:anywhere;}
 .transactions-only-panel .data-table td::before{content:attr(data-label);font-weight:600;color:#425642;}
 .transactions-only-panel .payment-detail-panel{overflow-x:hidden!important;}
}
html body.bounded-caterer-page dialog.transactions-only-panel[open] {
    display: flex !important; flex-direction: column;
    inset: 0 0 auto; overflow: hidden !important;
    background: #cbd7c5 !important;
}
html body.bounded-caterer-page dialog.transactions-only-panel .payment-section-dialog-header {
    position: static; flex: 0 0 auto;
}
html body.bounded-caterer-page dialog.transactions-only-panel .payment-detail-panel {
    flex: 0 1 auto; min-height: 0; max-height: none !important;
    overflow: auto !important;
}
html body.bounded-caterer-page dialog.transactions-only-panel .payment-transactions-table thead th {
    position: static;
}
html body.bounded-caterer-page dialog.transactions-only-panel td[data-label="Status"] > span {
    display: inline-block; width: fit-content; padding: 3px 7px;
    border-radius: 5px; background: #bdcdb6;
    text-transform: capitalize !important;
}
html body.bounded-caterer-page dialog.transactions-only-panel .payment-section-close:focus-visible { outline: 2px solid #173d32 !important; outline-offset: 2px; }
.transactions-only-panel::backdrop{background:transparent!important;backdrop-filter:none!important;}
@media(max-width:768px){
 html body.bounded-caterer-page dialog.transactions-only-panel .payment-detail-panel{padding:12px 16px!important;}
 html body.bounded-caterer-page dialog.transactions-only-panel .payment-transactions-table tr{
    display:block!important;margin:0 0 16px!important;padding:0!important;background:transparent!important;border:0!important;border-radius:0!important;
 }
 html body.bounded-caterer-page dialog.transactions-only-panel .payment-transactions-table td{
    display:grid!important;grid-area:auto!important;grid-template-columns:minmax(90px,.8fr) minmax(0,1.4fr)!important;
    align-items:start!important;gap:4px 16px!important;padding:11px 0!important;border:0!important;border-bottom:1px solid #b4bfac!important;
    background:transparent!important;font-size:14px!important;line-height:1.5!important;min-width:0!important;text-align:left!important;
 }
 html body.bounded-caterer-page dialog.transactions-only-panel .payment-transactions-table td::before{
    display:block!important;content:attr(data-label)!important;grid-column:1!important;grid-row:1 / span 2!important;
    font-size:12px!important;font-weight:500!important;line-height:1.75!important;color:#52634d!important;text-transform:none!important;
 }
 html body.bounded-caterer-page dialog.transactions-only-panel .payment-transactions-table td > *{grid-column:2!important;min-width:0;}
 html body.bounded-caterer-page dialog.transactions-only-panel .payment-transactions-table td[data-label="Amount"]{font-size:18px!important;color:#173d32!important;}
 html body.bounded-caterer-page dialog.transactions-only-panel .payment-transactions-table .proof-link-btn{width:auto!important;min-height:44px!important;padding:0!important;justify-self:start!important;text-align:left!important;}
 html body.bounded-caterer-page dialog.transactions-only-panel .payment-transactions-table td[data-label="Status"] > span{font-size:13px!important;text-transform:capitalize!important;}
}
</style>
<script>
window.addEventListener('load', () => {
    const panel = document.querySelector('.transactions-only-panel');
    if (!panel) return;
    const header = panel.querySelector('.payment-section-dialog-header');
    const content = panel.querySelector('.payment-detail-panel');
    const resize = () => {
        if (!panel.open) return;
        const borders = panel.offsetHeight - panel.clientHeight;
        const height = header.offsetHeight + content.scrollHeight + borders;
        window.parent.postMessage({type: 'payment-transactions-size', height}, location.origin);
    };
    const observer = new ResizeObserver(resize);
    observer.observe(panel);
    observer.observe(content.querySelector('table') || content);
    resize();
});
</script>
<?php endif; ?>




