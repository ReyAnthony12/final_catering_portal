<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/financial_helper.php';
require_once __DIR__ . '/../includes/order_reference_helper.php';
requireLogin();

if (getUserRole() !== 'customer') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$booking_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($booking_id <= 0) {
    header('Location: booking.php');
    exit();
}

$query = "
    SELECT 
        o.*,
        sd.business_name AS store_name,
        sd.is_open AS store_is_open,
        sd.business_open_time AS store_open_time,
        sd.business_close_time AS store_close_time,
        sd.auto_schedule_enabled AS store_auto_schedule_enabled,
        p.name AS product_name,
        p.category,
        u.first_name,
        u.last_name,
        u.email,
        u.phone_number
    FROM bookings o
    LEFT JOIN store_details sd ON o.store_id = sd.user_id
    LEFT JOIN products p ON o.product_id = p.id
    LEFT JOIN users u ON o.customer_id = u.id
    WHERE o.id = '$booking_id' AND o.customer_id = '$user_id'
    LIMIT 1
";
$result = mysqli_query($conn, $query);

if (!$result || mysqli_num_rows($result) === 0) {
    header('Location: booking.php');
    exit();
}

$booking = mysqli_fetch_assoc($result);

function normalizeReceiptBookingStatus($status) {
    $status = strtolower(trim((string) $status));
    if ($status === 'approved') {
        return 'confirmed';
    }
    if ($status === 'ready') {
        return 'delivering';
    }
    if (in_array($status, ['rejected', 'declined'], true)) {
        return 'cancelled';
    }
    return $status;
}

function extractReceiptMenuDetails($raw_menu) {
    $details = [
        'set_name' => '',
        'addons' => [],
        'swaps' => [],
        'build_menu' => [],
        'custom_request' => ''
    ];

    $parts = preg_split('/\s*\|\s*/', trim((string) $raw_menu), -1, PREG_SPLIT_NO_EMPTY);
    if (empty($parts)) {
        return $details;
    }

    $first_part = trim((string) $parts[0]);
    if (
        stripos($first_part, 'Added Items:') !== 0 &&
        stripos($first_part, 'Menu Swaps:') !== 0 &&
        stripos($first_part, 'Build Your Own Menu:') !== 0 &&
        stripos($first_part, 'Custom Add-on Request:') !== 0
    ) {
        $details['set_name'] = $first_part;
    }

    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '' || $part === $details['set_name']) {
            continue;
        }

        if (stripos($part, 'Added Items:') === 0) {
            $items_str = trim(substr($part, strlen('Added Items:')));
            if (preg_match_all('/(?:^|,\s*)(.+?)\s*(\(\+[^\)]*\))(?=,|$)/', $items_str, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $details['addons'][] = trim($match[1] . ' ' . $match[2]);
                }
            } elseif ($items_str !== '') {
                $details['addons'] = array_map('trim', explode(',', $items_str));
            }
        } elseif (stripos($part, 'Menu Swaps:') === 0) {
            $items_str = trim(substr($part, strlen('Menu Swaps:')));
            if (preg_match_all('/(?:^|,\s*)(.+?)\s*(\(\+[^\)]*\))(?=,|$)/', $items_str, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $details['swaps'][] = trim($match[1] . ' ' . $match[2]);
                }
            } elseif ($items_str !== '') {
                $details['swaps'] = array_map('trim', explode(',', $items_str));
            }
        } elseif (stripos($part, 'Build Your Own Menu:') === 0) {
            $items_str = trim(substr($part, strlen('Build Your Own Menu:')));
            if ($items_str !== '') {
                $details['build_menu'] = array_values(array_filter(array_map('trim', explode(',', $items_str)), 'strlen'));
            }
        } elseif (stripos($part, 'Custom Add-on Request:') === 0) {
            $details['custom_request'] = trim(substr($part, strlen('Custom Add-on Request:')));
        }
    }

    return $details;
}

$fin = getOrderFinancials($booking_id);
$grand_total = $fin['totals']['grand_total'] ?? 0;
$total_paid = $fin['totals']['total_paid'] ?? 0;
$remaining = $fin['totals']['balance'] ?? 0;
$addons_total = $fin['breakdown']['addons'] ?? 0;
$extra_fee = $fin['breakdown']['extra_fee'] ?? 0;
$damages_total = $fin['breakdown']['damages'] ?? 0;

$menu_details = extractReceiptMenuDetails($booking['additional_menu'] ?? '');
$set_name = !empty($booking['package_name']) ? $booking['package_name'] : $menu_details['set_name'];
$receipt_addons = $menu_details['addons'];
$receipt_swaps = $menu_details['swaps'];
$receipt_build_menu = $menu_details['build_menu'];
$receipt_custom_request = $menu_details['custom_request'];
$event_time = !empty($booking['delivery_time']) ? $booking['delivery_time'] : ($booking['order_time'] ?? '');
$booking_created_at = !empty($booking['created_at']) ? strtotime($booking['created_at']) : null;
$print_mode = isset($_GET['print']) && $_GET['print'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Receipt - <?php echo htmlspecialchars(formatOrderReference($booking['id'])); ?></title>
    <link rel="icon" type="image/png" href="../uploads/logo.png">
    <link rel="shortcut icon" href="../uploads/logo.png">
    <link rel="apple-touch-icon" href="../uploads/logo.png">
    <style>
        :root {
            --bg: #c3cbbd;
            --card: #d4dbcd;
            --border: #96a38e;
            --text: #20372b;
            --muted: #425642;
            --accent: #70501e;
            --success: #245539;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 24px;
        }

        .receipt-shell {
            max-width: 900px;
            margin: 0 auto;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #173d32;
            color: var(--text);
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-primary {
            background: #173d32;
            color: #f0f1e6;
            border-color: #426052;
        }

        .receipt-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            overflow: hidden;
        }

        .receipt-header {
            padding: 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .receipt-title h1 {
            margin: 0 0 8px;
            color: var(--accent);
            font-size: 30px;
        }

        .receipt-title p {
            margin: 0;
            color: var(--muted);
        }

        .status-pill {
            align-self: flex-start;
            padding: 10px 14px;
            border-radius: 999px;
            background: #ddcba4;
            color: var(--accent);
            font-weight: 700;
            text-transform: none;
            letter-spacing: normal;
            font-size: 12px;
        }

        .receipt-body {
            padding: 18px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 4px 16px;
            margin-bottom: 8px;
        }

        .info-card {
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }

        .info-card.full {
            grid-column: 1 / -1;
        }

        .label {
            display: block;
            margin-bottom: 3px;
            color: var(--muted);
            font-size: 12px;
            text-transform: none;
            letter-spacing: normal;
        }

        .value {
            font-size: 15px;
            line-height: 1.4;
        }

        .totals {
            border-top: 1px solid var(--border);
            margin-top: 10px;
            padding-top: 18px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
            color: var(--muted);
        }

        .total-row strong {
            color: var(--text);
        }

        .total-row.grand strong,
        .total-row.grand span {
            color: var(--accent);
            font-size: 20px;
        }

        .total-row.paid strong,
        .total-row.paid span {
            color: var(--success);
        }

        .receipt-note {
            margin-top: 24px;
            padding: 16px;
            border-radius: 12px;
            border: 1px dashed var(--border);
            color: var(--muted);
            line-height: 1.7;
        }

        .selection-block {
            margin-top: 10px;
            padding: 10px 0;
            border-top: 1px solid var(--border);
        }

        .selection-block h3 {
            margin: 0 0 6px;
            color: var(--accent);
            font-size: 16px;
        }

        .selection-list {
            margin: 0;
            padding-left: 18px;
            color: var(--text);
            line-height: 1.8;
        }

        .selection-empty {
            color: var(--muted);
            font-size: 14px;
        }

        @media (max-width: 700px) {
            body {
                padding: 14px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media screen {
            .receipt-card { border-radius: 16px; }
            .toolbar {
                background: #173d32;
                border-bottom: 1px solid #426052;
                padding: 12px 22px;
                align-items: center;
            }
            .toolbar-actions { display: flex; flex-wrap: wrap; gap: 8px; }
            .toolbar .btn {
                padding: 9px 13px;
                font-size: 13px;
                line-height: 1.4;
                border-radius: 8px;
                background: #20483b;
            }
            .toolbar .btn-primary {
                background: #ba9b62;
                border-color: #ba9b62;
                color: #173d32;
            }
            .toolbar .btn-primary:hover { background: #d4b778; }
            .toolbar .btn:focus-visible { outline-color: #dcc389; }
            .btn { color: #f0f1e6; border-color: #426052; }
            .btn:hover { background: #2b5544; }
            .btn:focus-visible { outline: 2px solid #70501e; outline-offset: 3px; }
            .btn:disabled:hover { background: #dfc6bc; }
            .receipt-card { box-shadow: 0 8px 24px rgb(35 56 46 / 9%); }
            .receipt-header {
                background: #173d32;
                border-bottom: 3px solid #ba9b62;
                padding: 20px 22px;
                align-items: center;
            }
            .receipt-title h1 { color: #f0f1e6; font-size: 26px; letter-spacing: -0.5px; }
            .receipt-title p { color: #dcc389; font-size: 13px; font-weight: 600; }
            .status-pill {
                align-self: center;
                display: inline-flex;
                align-items: center;
                gap: 7px;
                padding: 8px 12px;
                border: 1px solid #ba9b62;
                font-size: 11px;
            }
            .status-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
            .receipt-note { background: #c9d2c1; }
        }
        @media screen and (max-width: 480px) {
            body { padding: 10px; }
            .toolbar { padding: 10px 14px; gap: 8px; }
            .toolbar .btn { padding: 8px 10px; font-size: 12px; }
            .receipt-header { padding: 16px 14px; gap: 12px; }
            .receipt-title h1 { font-size: 23px; }
            .receipt-body { padding: 14px; }
        }
        @media print {
            body {
                background: #fff;
                color: #000;
                padding: 0;
            }

            .toolbar {
                display: none !important;
            }

            .receipt-card,
            .info-card,
            .receipt-note {
                border-color: #d0d0d0;
                background: #fff;
                color: #000;
                box-shadow: none;
            }

            .receipt-title h1,
            .status-pill,
            .total-row.grand strong,
            .total-row.grand span {
                color: #000 !important;
            }

            .label,
            .total-row {
                color: #555 !important;
            }

            .value,
            .total-row strong,
            .receipt-note,
            .total-row.paid strong,
            .total-row.paid span {
                color: #000 !important;
            }
        }
        @media screen {
            body { padding: 16px; font-size: 12px; }
            .receipt-shell { max-width: 720px; }
            .toolbar { padding: 8px 14px; }
            .toolbar .btn { padding: 6px 10px; font-size: 11px; }
            .receipt-header { padding: 12px 16px; }
            .receipt-title h1 { font-size: 21px; }
            .receipt-title p { font-size: 11px; }
            .status-pill { padding: 6px 9px; font-size: 10px; }
            .receipt-body { padding: 12px 16px; }
            .info-grid { gap: 2px 12px; }
            .info-card { padding: 5px 0; }
            .label { font-size: 10px; margin-bottom: 2px; }
            .value { font-size: 12px; }
            .meta, .total-row, .selection-list, .selection-empty { font-size: 11px; }
            .selection-block { padding: 7px 0; margin-top: 7px; }
            .selection-block h3 { font-size: 13px; }
            .totals { padding-top: 10px; }
            .total-row.grand :is(strong, span) { font-size: 16px; }
            .receipt-note { margin-top: 14px; padding: 10px; font-size: 11px; }
        }
        @media screen {
            .receipt-card {
                border: 1px solid #91a18a;
                border-radius: 16px;
                overflow: hidden;
                box-shadow: 0 12px 30px rgb(23 61 50 / 14%);
            }
            .toolbar {
                padding: 12px 16px;
                gap: 12px;
                background: #20483b;
                border-bottom: 1px solid #496957;
            }
            .toolbar .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 32px;
                padding: 6px 12px;
                border-radius: 9px;
                box-shadow: 0 2px 4px rgb(0 0 0 / 8%);
                transition: background .15s ease, box-shadow .15s ease;
            }
            .toolbar .btn:hover { box-shadow: 0 3px 8px rgb(0 0 0 / 15%); }
            .receipt-header {
                padding: 10px 16px;
                gap: 16px;
                background: #173d32;
                border-bottom: 2px solid #ba9b62;
            }
            .receipt-title { border-left: 3px solid #ba9b62; padding-left: 12px; }
            .receipt-title h1 { margin: 0 0 3px; font-size: 18px; line-height: 1.2; }
            .receipt-title p { margin: 0; font-size: 11px; letter-spacing: .02em; }
            .status-pill { padding: 5px 9px; font-size: 10px; box-shadow: 0 2px 5px rgb(0 0 0 / 8%); }
        }
        @media screen and (max-width: 480px) {
            .toolbar { padding: 10px 12px; gap: 8px; }
            .toolbar-actions { gap: 6px; }
            .toolbar .btn { padding: 6px 9px; }
            .receipt-header { padding: 9px 12px; }
            .receipt-title h1 { font-size: 17px; }
        }
        @media screen {
            .receipt-header {
                background: var(--card-bg, #d4dbcd);
                border-bottom: 1px solid #96a38e;
            }
            .receipt-title { border-left: 0; padding-left: 0; }
            .receipt-title h1 { color: #20372b; }
            .receipt-title p { color: #425642; }
        }
        @page { size: auto; margin: 8mm; }
        @media print {
            html, body { margin: 0; padding: 0; height: 245mm; overflow: hidden; }
            .receipt-shell {
                max-width: 194mm; width: 100%; margin: 0;
                position: relative;
                height: 245mm;
                overflow: hidden;
            }
            .receipt-card {
                border: 0; border-radius: 0; overflow: visible;
                width: 100%;
                transform-origin: top left;
                transform: scale(var(--receipt-print-scale, 1));
            }
            .receipt-header { padding: 6px 0; border-bottom: 1px solid #999; }
            .receipt-title h1 { font-size: 16pt; margin: 0 0 3px; }
            .receipt-title p { font-size: 8pt; margin: 0; }
            .status-pill { font-size: 8pt; padding: 3px 6px; }
            .receipt-body { padding: 6px 0; }
            .info-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 2px 12px; margin-bottom: 4px; }
            .info-card { padding: 4px 0; }
            .label { font-size: 7pt; margin-bottom: 1px; }
            .value, .meta, .selection-list, .selection-empty, .total-row { font-size: 8pt; line-height: 1.25; }
            .selection-block { margin-top: 4px; padding: 4px 0; }
            .selection-block h3 { font-size: 9pt; margin: 0 0 3px; }
            .selection-list { padding-left: 14px; }
            .totals { margin-top: 4px; padding-top: 5px; }
            .total-row {
                display: grid;
                grid-template-columns: minmax(0, 1fr) minmax(95px, auto);
                width: 100%;
                max-width: 100%;
                gap: 8px;
                margin-bottom: 3px;
            }
            .total-row strong {
                display: block;
                text-align: right;
                color: #000 !important;
                -webkit-text-fill-color: #000 !important;
                white-space: nowrap;
                visibility: visible !important;
            }
            .total-row.grand :is(strong, span) { font-size: 11pt; }
            .receipt-note { margin-top: 6px; padding: 6px; font-size: 8pt; line-height: 1.25; }
            .receipt-header, .info-card, .total-row { break-inside: avoid; }
        }
        @media screen {
            html { height: 100%; }
            body { padding: 12px; }
            .receipt-shell { width: 100%; max-width: none; height: calc(100dvh - 12px); margin: 0; }
            .receipt-card { display: flex; flex-direction: column; height: 100%; min-height: 0; }
            .toolbar, .receipt-header { flex-shrink: 0; }
            .receipt-body {
                flex: 1 1 0;
                min-height: 0;
                overflow-y: scroll;
                scrollbar-gutter: stable;
                scrollbar-width: thin;
                scrollbar-color: #708b6b #c9d2c1;
            }
            .receipt-body::-webkit-scrollbar { width: 8px; }
            .receipt-body::-webkit-scrollbar-track { background: #c9d2c1; }
            .receipt-body::-webkit-scrollbar-thumb { background: #708b6b; border-radius: 8px; }
        }
        .receipt-header .status-pill {
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
            padding: 0;
            font-size: 10px;
            font-weight: 400;
            text-transform: none;
            letter-spacing: normal;
            color: #425642;
        }
        .receipt-header .status-pill::before { display: none; }
        .receipt-header .status-pill[data-status="pending"] { color: #b42318; }
        @media screen {
            *, *::before, *::after { box-sizing: border-box; }
            .receipt-shell, .receipt-card, .receipt-body, .info-card { min-width: 0; }
            .receipt-body { overflow-x: hidden; }
            .value, .meta, .selection-list, .total-row { overflow-wrap: anywhere; }
        }
        @media screen and (max-width: 600px) {
            body { padding: 6px; }
            .receipt-card { border-radius: 10px; }
            .toolbar, .receipt-header { padding: 9px 10px; gap: 8px; }
            .toolbar-actions { min-width: 0; flex-wrap: wrap; gap: 5px; }
            .toolbar .btn { padding: 7px 9px; font-size: 11px; }
            .receipt-body { padding: 10px; }
            .info-grid { grid-template-columns: minmax(0, 1fr); gap: 5px; }
            .receipt-title h1 { font-size: 17px; }
            .total-row { gap: 8px; }
            .total-row > span { min-width: 0; }
            .total-row > strong { flex-shrink: 0; }
        }
    </style>
</head>
<body<?php echo $print_mode ? ' onload="window.print()"' : ''; ?>>
    <div class="receipt-shell">
        <div class="receipt-card">
        <div class="toolbar">
            <a href="booking-details.php?id=<?php echo $booking_id; ?>" class="btn" id="receipt-back" aria-label="Back to Booking" title="Back to Booking"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 19-7-7 7-7M5 12h14"/></svg></a>
            <div class="toolbar-actions">
                <?php if (isStoreAvailableNow($booking['store_is_open'] ?? 0, $booking['store_open_time'] ?? null, $booking['store_close_time'] ?? null, $booking['store_auto_schedule_enabled'] ?? 0)): ?>
                    <a href="book-service.php?product_id=<?php echo intval($booking['product_id']); ?>&store_id=<?php echo intval($booking['store_id']); ?>&amp;cancel_from=receipt&amp;receipt_id=<?php echo (int) $booking_id; ?>&amp;return_url=<?php echo urlencode('booking-receipt.php?id=' . $booking_id . (($_GET['return_to'] ?? '') === 'details' ? '&return_to=details' : '')); ?>" class="btn">Book Again</a>
                <?php else: ?>
                    <button type="button" class="btn" style="background: #dfc6bc; color: #84352f; border-color: #b7897b; cursor: not-allowed;" disabled>Caterer Closed</button>
                <?php endif; ?>
                <a href="booking-receipt.php?id=<?php echo $booking_id; ?>&amp;print=1<?php echo ($_GET['return_to'] ?? '') === 'details' ? '&amp;return_to=details' : ''; ?>" class="btn btn-primary">Print Receipt</a>
            </div>
        </div>

            <div class="receipt-header">
                <div class="receipt-title">
                    <h1>Booking Receipt</h1>
                    <p>Booking #<?php echo htmlspecialchars(formatOrderReference($booking_id, $booking['order_number'] ?? '')); ?></p>
                </div>
                <div class="status-pill" data-status="<?php echo htmlspecialchars(strtolower(normalizeReceiptBookingStatus($booking['order_status'])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst(normalizeReceiptBookingStatus($booking['order_status']))); ?></div>
            </div>

            <div class="receipt-body">
                <div class="info-grid">
                    <div class="info-card">
                        <span class="label">Customer</span>
                        <div class="value"><?php echo htmlspecialchars(trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? '')) ?: 'N/A'); ?></div>
                    </div>
                    <div class="info-card">
                        <span class="label">Caterer</span>
                        <div class="value"><?php echo htmlspecialchars($booking['store_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-card">
                        <span class="label">Service</span>
                        <div class="value"><?php echo htmlspecialchars(($booking['product_name'] ?? '') ?: 'Catering Service'); ?></div>
                    </div>
                    <div class="info-card">
                        <span class="label">Booking Date</span>
                        <div class="value"><?php echo date('F d, Y', strtotime($booking['order_date'])); ?></div>
                    </div>
                    <div class="info-card">
                        <span class="label">Booking Time</span>
                        <div class="value"><?php echo !empty($event_time) ? date('h:i A', strtotime($event_time)) : 'N/A'; ?></div>
                    </div>
                    <div class="info-card">
                        <span class="label">Payment Method</span>
                        <div class="value"><?php echo htmlspecialchars($booking['payment_method'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-card">
                        <span class="label">Number of Person</span>
                        <div class="value"><?php echo intval(($booking['number_of_pax'] ?? 0) ?: ($booking['quantity'] ?? 0)); ?></div>
                    </div>
                    <div class="info-card full">
                        <span class="label">Venue / Address</span>
                        <div class="value"><?php echo htmlspecialchars(($booking['delivery_address'] ?? '') ?: ($booking['address'] ?? 'N/A')); ?></div>
                    </div>
                    <?php if ($set_name !== ''): ?>
                    <div class="info-card full">
                        <span class="label">Selected Package / Set</span>
                        <div class="value"><?php echo htmlspecialchars($set_name); ?></div>
                        <?php if (!empty($booking['package_price'])): ?>
                            <div class="meta">Package Price: &#8369;<?php echo number_format((float) $booking['package_price'], 2); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($booking_created_at): ?>
                    <div class="info-card full">
                        <span class="label">Booked On</span>
                        <div class="value"><?php echo date('F d, Y', $booking_created_at); ?></div>
                        <div class="meta"><?php echo date('h:i A', $booking_created_at); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="selection-block">
                    <h3>Add-ons</h3>
                    <?php if (!empty($receipt_addons)): ?>
                        <ul class="selection-list">
                            <?php foreach ($receipt_addons as $addon): ?>
                                <li><?php echo htmlspecialchars($addon); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="selection-empty">No add-ons selected.</div>
                    <?php endif; ?>
                </div>

                <div class="selection-block">
                    <h3>Swaps</h3>
                    <?php if (!empty($receipt_swaps)): ?>
                        <ul class="selection-list">
                            <?php foreach ($receipt_swaps as $swap): ?>
                                <li><?php echo htmlspecialchars($swap); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="selection-empty">No swaps selected.</div>
                    <?php endif; ?>
                </div>

                <div class="selection-block">
                    <h3>Build Your Own Menu</h3>
                    <?php if (!empty($receipt_build_menu)): ?>
                        <ul class="selection-list">
                            <?php foreach ($receipt_build_menu as $build_item): ?>
                                <li><?php echo htmlspecialchars($build_item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="selection-empty">No custom menu items selected.</div>
                    <?php endif; ?>
                </div>

                <div class="selection-block">
                    <h3>Custom Request</h3>
                    <div class="value"><?php echo htmlspecialchars($receipt_custom_request !== '' ? $receipt_custom_request : 'No custom request added.'); ?></div>
                </div>

                <div class="totals">
                    <div class="total-row"><span>Base / Package Total</span><strong>&#8369;<?php echo number_format($fin['breakdown']['base_price'] ?? 0, 2); ?></strong></div>
                    <div class="total-row"><span>Add-ons Total</span><strong>&#8369;<?php echo number_format($addons_total, 2); ?></strong></div>
                    <?php if ($damages_total > 0): ?>
                    <div class="total-row"><span>Damage Charges</span><strong>&#8369;<?php echo number_format($damages_total, 2); ?></strong></div>
                    <?php endif; ?>
                    <div class="total-row grand"><span>Grand Total</span><strong>&#8369;<?php echo number_format($grand_total, 2); ?></strong></div>
                    <div class="total-row"><span>Required Down Payment</span><strong>&#8369;<?php echo number_format($fin['down_payment_required'] ?? 0, 2); ?></strong></div>
                    <div class="total-row paid"><span>Total Paid</span><strong>&#8369;<?php echo number_format($total_paid, 2); ?></strong></div>
                    <div class="total-row"><span>Remaining Balance</span><strong><?php echo $remaining > 0 ? '&#8369;' . number_format($remaining, 2) : 'Fully Paid'; ?></strong></div>
                </div>
            </div>
        </div>
    </div>
<script>
window.addEventListener('beforeprint', () => {
    const receipt = document.querySelector('.receipt-shell');
    const probe = document.createElement('iframe');
    probe.style.cssText = 'position:fixed;left:-10000px;top:0;width:194mm;height:245mm;border:0;visibility:hidden;';
    document.body.appendChild(probe);
    const printStyles = [...document.querySelectorAll('style')].map(style => style.textContent
        .replace(/@media screen/g, '@media not all')
        .replace(/@media print/g, '@media all')).join('\n');
    const doc = probe.contentDocument;
    doc.open();
    doc.write('<!doctype html><html><head><style>' + printStyles +
        '.receipt-shell{position:static!important;height:auto!important;overflow:visible!important;width:194mm!important}.receipt-card{transform:none!important}body{margin:0!important;padding:0!important;height:auto!important;overflow:visible!important}</style></head><body>' +
        receipt.outerHTML + '</body></html>');
    doc.close();
    const measuredCard = doc.querySelector('.receipt-card');
    const height = Math.max(measuredCard.scrollHeight, measuredCard.getBoundingClientRect().height);
    const scale = Math.min(1, (probe.getBoundingClientRect().height - 24) / height);
    receipt.style.setProperty('--receipt-print-scale', String(scale));
    probe.remove();
});
window.addEventListener('afterprint', () => {
    const receipt = document.querySelector('.receipt-shell');
    receipt.style.removeProperty('--receipt-print-scale');
});
(() => {
    // Details printing has its own return route; keep list printing unchanged.
    if (new URLSearchParams(location.search).get('return_to') === 'details') {
        const destination = new URL('booking-details.php', location.href);
        destination.searchParams.set('id', <?php echo json_encode((string) $booking_id); ?>);
        document.getElementById('receipt-back').href = destination.href;
        return;
    }
    const raw = sessionStorage.getItem('bookingReceiptReturn');
    if (!raw) return;
    try {
        const saved = JSON.parse(raw);
        const destination = new URL(saved.url);
        if (destination.origin !== location.origin || !destination.pathname.endsWith('/customer/booking.php')) return;
        document.getElementById('receipt-back').href = destination.href;
    } catch (_) { /* Keep the normal booking link if no saved return location exists. */ }
})();
</script>
<?php if (!$print_mode) { require __DIR__ . '/includes/legal-footer.php'; } ?>
</body>
</html>
