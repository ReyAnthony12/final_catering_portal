<?php
require_once __DIR__ . '/../includes/order_reference_helper.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];

function reportProfileUrl($path)
{
    $path = ltrim(str_replace('\\', '/', trim((string) $path)), '/');
    return strpos($path, 'uploads/') === 0 && strpos($path, '..') === false
        ? '../' . $path : '../uploads/logo.png';
}

if (isset($_GET['customer_profiles'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $result = mysqli_query($conn, "SELECT DISTINCT u.id, u.profile_image FROM users u JOIN bookings o ON o.customer_id = u.id WHERE o.store_id = $user_id");
    if (!$result) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load profiles']);
        exit;
    }
    $profiles = [];
    while ($profile = mysqli_fetch_assoc($result)) {
        $profiles[$profile['id']] = reportProfileUrl($profile['profile_image']);
    }
    echo json_encode((object) $profiles);
    exit;
}

function reportValidDate($value, $fallback)
{
    $raw = trim((string) $value);
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    return ($dt && $dt->format('Y-m-d') === $raw) ? $raw : $fallback;
}

function formatPeso($amount)
{
    return 'PHP ' . number_format((float) $amount, 2);
}

function reportRows(mysqli $conn, $sql)
{
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return [];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

function reportOne(mysqli $conn, $sql)
{
    $rows = reportRows($conn, $sql);
    return $rows[0] ?? [];
}

function statusLabel($status)
{
    $status = strtolower(trim((string) $status));
    $map = [
        'pending'    => 'Pending',
        'confirmed'  => 'Confirmed',
        'preparing'  => 'Preparing',
        'delivering' => 'On site / Delivering',
        'completed'  => 'Completed',
        'cancelled'  => 'Cancelled',
    ];
    return $map[$status] ?? ucfirst($status ?: 'Pending');
}

$start_date = reportValidDate($_GET['start_date'] ?? '', date('Y-01-01'));
$end_date   = reportValidDate($_GET['end_date'] ?? '', date('Y-m-t'));
if ($start_date > $end_date) {
    $tmp        = $start_date;
    $start_date = $end_date;
    $end_date   = $tmp;
}

$esc_start   = mysqli_real_escape_string($conn, $start_date);
$esc_end     = mysqli_real_escape_string($conn, $end_date);
$date_filter = "o.order_date BETWEEN '$esc_start' AND '$esc_end'";

$caterer      = reportOne($conn, "SELECT business_name FROM store_details WHERE user_id = $user_id LIMIT 1");
$store_name = $caterer['business_name'] ?? 'Catering Caterer';

$summary = reportOne($conn, "
    SELECT
        COUNT(*) as total_bookings,
        SUM(CASE WHEN o.order_status != 'cancelled' THEN 1 ELSE 0 END) as active_events,
        SUM(CASE WHEN o.order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_events,
        SUM(CASE WHEN o.order_status != 'cancelled' THEN COALESCE(o.number_of_pax, 0) ELSE 0 END) as total_pax,
        SUM(CASE WHEN o.order_status != 'cancelled' THEN o.total_amount ELSE 0 END) as contract_sales,
        SUM(CASE WHEN o.order_status = 'completed' THEN 1 ELSE 0 END) as completed_events,
        SUM(CASE WHEN o.order_status IN ('pending','confirmed','preparing','delivering') THEN 1 ELSE 0 END) as upcoming_events
    FROM bookings o
    WHERE o.store_id = $user_id
      AND $date_filter
");

$collections = reportOne($conn, "
    SELECT
        COALESCE(SUM(CASE WHEN o.order_status != 'cancelled' THEN pay.paid_amount ELSE 0 END), 0) as collected,
        COALESCE(SUM(CASE WHEN o.order_status != 'cancelled' THEN GREATEST(o.total_amount - pay.paid_amount, 0) ELSE 0 END), 0) as outstanding
    FROM bookings o
    LEFT JOIN (
        SELECT order_id, COALESCE(SUM(amount), 0) as paid_amount
        FROM payments
        WHERE status = 'verified'
        GROUP BY order_id
    ) pay ON pay.order_id = o.id
    WHERE o.store_id = $user_id
      AND $date_filter
");

$pending_proofs = reportOne($conn, "
    SELECT COUNT(*) as total
    FROM payments p
    JOIN bookings o ON p.order_id = o.id
    WHERE o.store_id = $user_id
      AND p.status = 'pending'
      AND $date_filter
");

$events = reportRows($conn, "
    SELECT
        o.id,
        o.customer_id,
        u.profile_image,
        o.order_number,
        o.order_date,
        o.order_time,
        o.full_name,
        o.address,
        o.contact_number,
        o.number_of_pax,
        o.order_status,
        o.total_amount,
        o.package_name,
        o.event_type,
        o.delivery_type,
        COALESCE(NULLIF(p.name, ''), NULLIF(o.package_name, ''), 'Catering package') as service_name,
        TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as customer_name,
        COALESCE(pay.paid_amount, 0) as paid_amount
    FROM bookings o
    LEFT JOIN products p ON o.product_id = p.id
    LEFT JOIN users u ON o.customer_id = u.id
    LEFT JOIN (
        SELECT order_id, COALESCE(SUM(amount), 0) as paid_amount
        FROM payments
        WHERE status = 'verified'
        GROUP BY order_id
    ) pay ON pay.order_id = o.id
    WHERE o.store_id = $user_id
      AND $date_filter
    ORDER BY o.order_date ASC, o.order_time ASC, o.id ASC
");

$packages = reportRows($conn, "
    SELECT
        COALESCE(NULLIF(p.name, ''), NULLIF(o.package_name, ''), 'Unnamed package') as package_name,
        COUNT(*) as bookings,
        SUM(CASE WHEN o.order_status != 'cancelled' THEN COALESCE(o.number_of_pax, 0) ELSE 0 END) as pax,
        SUM(CASE WHEN o.order_status != 'cancelled' THEN o.total_amount ELSE 0 END) as sales
    FROM bookings o
    LEFT JOIN products p ON o.product_id = p.id
    WHERE o.store_id = $user_id
      AND $date_filter
    GROUP BY COALESCE(NULLIF(p.name, ''), NULLIF(o.package_name, ''), 'Unnamed package')
    HAVING package_name != 'Set 1'
    ORDER BY sales DESC, bookings DESC
");

$payment_methods = reportRows($conn, "
    SELECT
        COALESCE(NULLIF(p.payment_method, ''), NULLIF(o.payment_method, ''), 'Unknown') as method_name,
        COUNT(*) as payment_count,
        SUM(p.amount) as total_amount
    FROM payments p
    JOIN bookings o ON p.order_id = o.id
    WHERE o.store_id = $user_id
      AND p.status = 'verified'
      AND $date_filter
    GROUP BY COALESCE(NULLIF(p.payment_method, ''), NULLIF(o.payment_method, ''), 'Unknown')
    ORDER BY total_amount DESC
");

$unpaid_events     = [];
$fully_paid_events = [];
foreach ($events as $event) {
    if (strtolower((string) $event['order_status']) === 'cancelled') {
        continue;
    }
    $balance = (float) $event['total_amount'] - (float) $event['paid_amount'];
    if ($balance > 0.009) {
        $event['balance'] = $balance;
        $unpaid_events[]  = $event;
    } else {
        $fully_paid_events[] = $event;
    }
}

$inventory = reportRows($conn, "
    SELECT item_name, item_type, available_stock, damaged_stock, low_stock_threshold
    FROM inventory_items
    WHERE store_id = $user_id
    ORDER BY item_type ASC, item_name ASC
");

$damages = reportRows($conn, "
    SELECT od.item_name, SUM(od.quantity) as qty, SUM(od.total) as total_cost
    FROM booking_damages od
    JOIN bookings o ON od.order_id = o.id
    WHERE o.store_id = $user_id
      AND DATE(od.created_at) BETWEEN '$esc_start' AND '$esc_end'
    GROUP BY od.item_name
    ORDER BY total_cost DESC
");

// Revenue Summary � per day
$revenue_daily = reportRows($conn, "
    SELECT
        o.order_date as period_label,
        COUNT(DISTINCT o.id) as bookings,
        SUM(CASE WHEN o.order_status != 'cancelled' THEN o.total_amount ELSE 0 END) as contract_total,
        COALESCE(SUM(pay.paid_amount), 0) as collected
    FROM bookings o
    LEFT JOIN (
        SELECT order_id, COALESCE(SUM(amount), 0) as paid_amount
        FROM payments
        WHERE status = 'verified'
        GROUP BY order_id
    ) pay ON pay.order_id = o.id
    WHERE o.store_id = $user_id
      AND $date_filter
    GROUP BY o.order_date
    ORDER BY o.order_date ASC
");

// Revenue Summary � per week (ISO week)
$revenue_weekly = reportRows($conn, "
    SELECT
        CONCAT('Week ', WEEK(o.order_date, 3), ', ', YEAR(o.order_date)) as period_label,
        YEARWEEK(o.order_date, 3) as sort_key,
        COUNT(DISTINCT o.id) as bookings,
        SUM(CASE WHEN o.order_status != 'cancelled' THEN o.total_amount ELSE 0 END) as contract_total,
        COALESCE(SUM(pay.paid_amount), 0) as collected
    FROM bookings o
    LEFT JOIN (
        SELECT order_id, COALESCE(SUM(amount), 0) as paid_amount
        FROM payments
        WHERE status = 'verified'
        GROUP BY order_id
    ) pay ON pay.order_id = o.id
    WHERE o.store_id = $user_id
      AND $date_filter
    GROUP BY YEARWEEK(o.order_date, 3)
    ORDER BY sort_key ASC
");

// Revenue Summary � per month
$revenue_monthly = reportRows($conn, "
    SELECT
        DATE_FORMAT(o.order_date, '%M %Y') as period_label,
        DATE_FORMAT(o.order_date, '%Y-%m') as sort_key,
        COUNT(DISTINCT o.id) as bookings,
        SUM(CASE WHEN o.order_status != 'cancelled' THEN o.total_amount ELSE 0 END) as contract_total,
        COALESCE(SUM(pay.paid_amount), 0) as collected
    FROM bookings o
    LEFT JOIN (
        SELECT order_id, COALESCE(SUM(amount), 0) as paid_amount
        FROM payments
        WHERE status = 'verified'
        GROUP BY order_id
    ) pay ON pay.order_id = o.id
    WHERE o.store_id = $user_id
      AND $date_filter
    GROUP BY DATE_FORMAT(o.order_date, '%Y-%m')
    ORDER BY sort_key ASC
");

$total_sales     = (float) ($summary['contract_sales'] ?? 0);
$total_collected = (float) ($collections['collected'] ?? 0);
$outstanding     = (float) ($collections['outstanding'] ?? 0);
$print_url       = 'detailed_report.php?start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date) . '&print=1';

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .reports-page h1 { margin: 0 0 6px; font-size: 26px; }
    .reports-page .sub { margin: 0 0 18px; color: var(--text-muted); }
    .filter-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 12px;
        margin-bottom: 20px;
    }
    .date-input {
        background: #111;
        color: #fff;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 8px 10px;
    }
    .report-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 14px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        border: none;
        cursor: pointer;
        color: #141414;
        background: #d4af37;
    }
    .report-btn.dark {
        background: #171717;
        color: #fff;
        border: 1px solid var(--border-color);
    }
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 12px;
        margin-bottom: 20px;
    }
    .summary-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 14px;
    }
    .summary-card span { display: block; color: var(--text-muted); font-size: 12px; }
    .summary-card strong { display: block; margin-top: 8px; font-size: 22px; }
    .report-section {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 16px;
    }
    .report-section h2 { margin: 0 0 12px; font-size: 17px; }
    .report-table { width: 100%; border-collapse: collapse; }
    .report-table th, .report-table td {
        padding: 9px 8px;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        text-align: left;
        vertical-align: top;
        font-size: 13px;
    }
    .report-table th { color: var(--text-muted); font-size: 11px; text-transform: uppercase; }
    .empty { text-align: center; color: var(--text-muted); padding: 18px; }
    .right { text-align: right; }
    .print-only { display: none; }

    @media print {
        .sidebar, .main-footer, .mobile-topbar, .sidebar-overlay, .filter-bar,
        .notification-btn, .no-print, .sidebar-utility, .summary-grid { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        body, .reports-page, .summary-card, .report-section {
            background: #fff !important;
            color: #000 !important;
            border: none !important;
            box-shadow: none !important;
            border-radius: 0 !important;
        }
        .print-only { display: block !important; margin-bottom: 16px; }
        .reports-page h1, .reports-page .sub, .summary-card span, .summary-card strong,
        .report-section h2, .report-table th, .report-table td, .empty { color: #000 !important; }
        .report-table th, .report-table td { border: 1px solid #000 !important; }
        .summary-grid { grid-template-columns: repeat(3, 1fr); }
        a { color: #000 !important; text-decoration: none !important; }
        /* Revenue panels - show all when printing */
        .rev-panel { display: block !important; }
        /* Sections hidden by JS before printing */
        .section-hidden-for-print { display: none !important; }
        .badge-paid { background:#16a34a !important; color:#fff !important; }
        .print-modal-overlay { display: none !important; }
        .rev-tabs { display: none !important; }
    }
    /* Revenue tabs */
    .rev-tabs { display:flex; gap:6px; margin-bottom:12px; flex-wrap:wrap; }
    .rev-tab { padding:6px 14px; border-radius:6px; border:1px solid var(--border-color); background:transparent; color:var(--text-muted); cursor:pointer; font-size:13px; font-weight:600; }
    .rev-tab.active { background:#d4af37; color:#141414; border-color:#d4af37; }
    .rev-panel { display:none; }
    .rev-panel.active { display:block; }
    /* Print modal */
    .print-modal-overlay { display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,.65); align-items:center; justify-content:center; }
    .print-modal-overlay.open { display:flex; }
    .print-modal { background:#1a1a1a; border:1px solid var(--border-color); border-radius:14px; padding:24px 28px; min-width:320px; max-width:480px; width:92%; box-shadow:0 8px 40px rgba(0,0,0,.6); }
    .print-modal h3 { margin:0 0 16px; font-size:18px; }
    .print-modal .check-list { display:flex; flex-direction:column; gap:10px; margin-bottom:20px; }
    .print-modal label { display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; }
    .print-modal input[type=checkbox] { width:16px; height:16px; accent-color:#d4af37; cursor:pointer; }
    .btn-do-print:disabled { opacity:.5; cursor:not-allowed; }
    .print-modal-actions { display:flex; gap:10px; justify-content:flex-end; }
    .btn-cancel-modal { padding:8px 18px; border-radius:8px; border:1px solid var(--border-color); background:transparent; color:#fff; cursor:pointer; font-weight:600; }
    .btn-do-print { padding:8px 22px; border-radius:8px; border:none; background:#d4af37; color:#141414; cursor:pointer; font-weight:600; }
    .select-all-row { display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:13px; color:var(--text-muted); }
    .badge-paid { background:#16a34a; color:#fff; font-size:11px; padding:2px 7px; border-radius:999px; font-weight:700; vertical-align:middle; }

    /* Compact reports; wide tables scroll without expanding the page. */
    .reports-page { width:100%; min-width:0; max-width:1440px; margin:0 auto; color:#eeeae1; }
    .reports-heading { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px 16px; margin:0 0 16px; padding:4px 0 14px; border-bottom:1px solid #373329; }
    .reports-page h1 { margin:0; font-size:clamp(21px,2.3vw,28px); font-weight:650; line-height:1.25; letter-spacing:-.7px; }
    .reports-period { color:#bcac7e; font-size:12px; line-height:1.5; font-variant-numeric:tabular-nums; }
    .reports-page .filter-bar { display:grid; grid-template-columns:repeat(2,minmax(125px,1fr)) repeat(4,auto); align-items:end; gap:8px; padding:12px; margin:0 0 14px; border:1px solid #34332e; border-radius:10px; background:#1c1c1a; }
    .date-field { display:flex; flex-direction:column; gap:5px; min-width:0; }
    .date-field label { color:#b9b5ac; font-size:11px; font-weight:600; }
    .reports-page .date-input { box-sizing:border-box; width:100%; min-width:0; max-width:100%; height:40px; padding:7px 9px; font:inherit; font-size:12px; color-scheme:dark; border-color:#3a3933; }
    .reports-page .report-btn { min-width:0; min-height:40px; padding:8px 12px; justify-content:center; font:inherit; font-size:12px; font-weight:600; line-height:1.25; border-radius:7px; white-space:nowrap; transition:background .15s; }
    .reports-page .report-btn:hover { filter:brightness(1.1); }
    .reports-page #btnOpenPrintModal { color:#dfc779; background:transparent; border:1px solid #5a4d2d; }
    .reports-page :is(button,a,input,[tabindex]):focus-visible { outline:2px solid #edce70; outline-offset:3px; }
    .reports-page .summary-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:6px; margin:0 0 12px; }
    .reports-page .summary-card { min-width:0; padding:8px 10px; border:1px solid #36352f; border-radius:7px; background:linear-gradient(120deg,#242420,#1c1c1a); }
    .reports-page .summary-card span { color:#b8b4aa; font-size:10px; line-height:1.3; }
    .report-customer-profile { display:flex; align-items:center; gap:10px; }
    .report-customer-avatar { width:36px; height:36px; flex:0 0 36px; border-radius:50%; object-fit:cover; border:1px solid #48463e; }
    .reports-page .summary-card strong { margin-top:4px; color:#f4efdf; font-size:clamp(14px,1.3vw,18px); font-weight:650; line-height:1.3; font-variant-numeric:tabular-nums; letter-spacing:-.4px; overflow-wrap:anywhere; }
    .reports-page .report-section { min-width:0; margin-bottom:12px; padding:14px; border:1px solid #34332e; border-radius:10px; background:#1c1c1a; }
    .reports-page .report-section h2 { margin:0 0 10px; font-size:14px; font-weight:600; line-height:1.4; letter-spacing:-.15px; }
    .report-table-scroll { width:100%; max-width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; border:1px solid #32322d; border-radius:7px; scrollbar-width:thin; scrollbar-color:#736334 #202020; }
    .reports-page .report-table th { padding:10px; background:#24241f; color:#bcb7a9; font-size:10px; letter-spacing:.5px; white-space:nowrap; }
    .reports-page .report-table td { padding:10px; font-size:12px; line-height:1.5; min-width:90px; }
    .reports-page .report-table .right { text-align:right; white-space:nowrap; }
    .reports-page .report-table .empty { text-align:center; padding:20px 12px; white-space:normal; }
    .reports-page .report-table tbody tr:last-child td { border-bottom:0; }
    .reports-page .rev-tabs { gap:6px; }
    .reports-page .rev-tab { min-height:40px; padding:7px 16px; font-size:12px; }
    .print-modal { box-sizing:border-box; min-width:0; max-height:calc(100dvh - 32px); overflow-y:auto; }
    .print-modal label { min-height:36px; }
    @media screen and (min-width:701px) {
        .reports-page .summary-card:nth-child(n+4) { container-type:inline-size; }
        .reports-page .summary-card:nth-child(n+4) strong {
            font-size:clamp(10px,8.4cqi,18px);
            white-space:nowrap;
            overflow-wrap:normal;
        }
    }
    @media (min-width:1200px) { .reports-page .summary-grid { grid-template-columns:repeat(6,minmax(0,1fr)); } }
    @media (max-width:1100px) {
        .reports-page .filter-bar { grid-template-columns:repeat(4,minmax(0,1fr)); }
        .date-field { grid-column:span 2; }
    }
    @media (max-width:600px) {
        .reports-heading { margin-bottom:12px; padding:2px 0 10px; gap:5px; }
        .reports-period { width:100%; font-size:11px; }
        .reports-page .filter-bar { padding:10px; gap:7px; margin-bottom:10px; }
        .reports-page .report-btn { padding:8px 4px; font-size:11px; white-space:normal; }
        .reports-page .summary-grid { grid-template-columns:repeat(2,minmax(0,1fr)); gap:5px; margin-bottom:10px; }
        .reports-page .summary-card { padding:7px 9px; border-radius:6px; }
        .reports-page .summary-card strong { font-size:clamp(12px,3.2vw,16px); letter-spacing:-.35px; }
        .reports-page .summary-card:nth-child(n+4) strong { font-size:clamp(10px,2.7vw,14px); }
        .reports-page .report-section { padding:11px; }
        .reports-page .rev-tab { flex:1; padding:7px; }
        .print-modal { padding:18px; }
    }
    @media (max-width:360px) { .reports-page .filter-bar { grid-template-columns:repeat(2,minmax(0,1fr)); } .date-field { grid-column:span 1; } }
    @media print {
        .reports-heading { background:none; padding:0; border:0; }
        .reports-period { display:none; }
        .report-table-scroll { overflow:visible; border:0; border-radius:0; }
        .reports-page .report-table td { min-width:0; }
    }
    .report-picker { display:flex; align-items:center; gap:12px; margin:0 0 12px; padding:10px 12px; background:#20201c; border:1px solid #3b3930; border-radius:8px; }
    .report-picker label { flex-shrink:0; color:#bdb7a7; font-size:12px; font-weight:600; }
    .report-picker select { box-sizing:border-box; width:100%; max-width:360px; min-width:0; min-height:42px; padding:9px 12px; border:1px solid #786534; border-radius:6px; background:#171713; color:#ead38e; color-scheme:dark; font:inherit; font-size:13px; font-weight:600; cursor:pointer; }
    .reports-page.sections-ready .report-section { display:none; }
    .reports-page.sections-ready .report-section.is-open { display:block; }
    @media (max-width:600px) { .report-picker { gap:8px; padding:9px 10px; } .report-picker label { font-size:11px; } .report-picker select { flex:1; max-width:none; font-size:12px; padding:8px; } }
    @media print {
        .report-picker { display:none !important; }
        .reports-page.sections-ready .report-section { display:block !important; }
        .reports-page.sections-ready .report-section.section-hidden-for-print,
        .reports-page .rev-panel[data-empty-print],
        .reports-page .customer-group-row { display:none !important; }
        .reports-page .report-table .booking-detail-row { display:table-row !important; }
    }
    @media screen and (max-width:700px) {
        .reports-page .report-table-scroll { overflow:visible; border:0; border-radius:0; }
        .reports-page .report-table, .reports-page .report-table tbody { display:block; width:100%; }
        .reports-page .report-table thead { display:none; }
        .reports-page .report-table tbody tr { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:9px 12px; padding:11px; margin-bottom:8px; background:#22221e; border:1px solid #34342d; border-radius:7px; }
        .reports-page .report-table tbody tr:last-child { margin-bottom:0; }
        .reports-page .report-table td { display:block; min-width:0; padding:0; border:0; font-size:12px; line-height:1.45; text-align:left; white-space:normal; overflow-wrap:anywhere; }
        .reports-page .report-table td::before { content:attr(data-label); display:block; margin-bottom:3px; color:#aaa697; font-size:10px; font-weight:500; line-height:1.3; }
        .reports-page .report-table .cell-value { display:block; min-width:0; }
        .reports-page .report-table td.cell-wide { grid-column:1 / -1; }
        .reports-page .report-table .right { text-align:left; white-space:normal; font-variant-numeric:tabular-nums; }
        .reports-page .report-table .right .cell-value { color:#e7d294; }
        .reports-page .report-table .customer-group-row { display:block; padding:10px; background:#2b291f !important; border-color:#55492b; }
        .reports-page .report-table .customer-group-row td::before, .reports-page .report-table .empty::before { display:none; }
        .reports-page .report-table .customer-group-row td { font-size:12px; }
        .reports-page .report-table .customer-group-row span { font-size:11px !important; }
        .reports-page .report-table .booking-detail-row.is-expanded { display:grid !important; }
        .reports-page .report-table .empty { grid-column:1 / -1; padding:8px 0; }
    }
    /* Keep date controls and actions compact at phone widths. */
    @media screen and (max-width:600px) {
        .reports-page .filter-bar { grid-template-columns:repeat(4,minmax(0,1fr)); gap:7px 6px; padding:9px; border-radius:8px; }
        .reports-page .filter-bar .date-field { grid-column:span 2; gap:4px; }
        .reports-page .filter-bar .date-field label { font-size:10px; line-height:1.2; }
        .reports-page .filter-bar .date-input { height:34px; min-height:34px; padding:5px 7px; font-size:11px; border-radius:5px; }
        .reports-page .filter-bar .report-btn { grid-column:span 1; min-height:34px; padding:6px 3px; font-size:10px; line-height:1.2; border-radius:5px; white-space:nowrap; }
    }
    @media screen and (max-width:380px) {
        .reports-page .filter-bar { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .reports-page .filter-bar .date-field { grid-column:span 1; }
        .reports-page .filter-bar .report-btn { padding:6px; }
    }
</style>

<div class="reports-page">

    <div class="print-only">
        <div><strong><?php echo htmlspecialchars($store_name); ?></strong></div>
        <div>Catering Operations Report</div>
        <div>Period: <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></div>
        <div>Printed: <?php echo date('M d, Y h:i A'); ?></div>
    </div>

    <form method="GET" class="filter-bar no-print">
        <div class="date-field"><label for="start_date">From</label>
        <input id="start_date" type="date" name="start_date" class="date-input" value="<?php echo htmlspecialchars($start_date); ?>">
        </div><div class="date-field"><label for="end_date">To</label>
        <input id="end_date" type="date" name="end_date" class="date-input" value="<?php echo htmlspecialchars($end_date); ?>">
        </div><button type="submit" class="report-btn apply-filter">Apply</button>
        <a class="report-btn dark" href="reports.php?start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-t'); ?>">This month</a>
        <a class="report-btn dark" href="reports.php?start_date=<?php echo date('Y-01-01'); ?>&end_date=<?php echo date('Y-m-t'); ?>">This year</a>
        <button type="button" class="report-btn" id="btnOpenPrintModal">Print</button>
    </form>

    <div class="summary-grid" id="sec-summary-grid">
        <div class="summary-card"><span>Events</span><strong><?php echo number_format((int) ($summary['active_events'] ?? 0)); ?></strong></div>
        <div class="summary-card"><span>Completed</span><strong><?php echo number_format((int) ($summary['completed_events'] ?? 0)); ?></strong></div>
        <div class="summary-card"><span>Cancelled</span><strong><?php echo number_format((int) ($summary['cancelled_events'] ?? 0)); ?></strong></div>
        <div class="summary-card"><span>Contract sales</span><strong><?php echo formatPeso($total_sales); ?></strong></div>
        <div class="summary-card"><span>Collected</span><strong><?php echo formatPeso($total_collected); ?></strong></div>
        <div class="summary-card"><span>Outstanding balance</span><strong><?php echo formatPeso($outstanding); ?></strong></div>
    </div>

    <div class="report-section" id="sec-event-bookings">
        <h2>Event bookings</h2>
        <div class="report-table-scroll" role="region" aria-label="Scrollable report table" tabindex="0"><table class="report-table">
            <thead>
                <tr>
                    <th>Event date</th>
                    <th>Customer / Booking</th>
                    <th>Venue</th>
                    <th>Package</th>
                    <th>Pax</th>
                    <th>Status</th>
                    <th class="right">Amount</th>
                    <th class="right">Paid</th>
                    <th class="right">Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$events): ?>
                    <tr><td colspan="9" class="empty">No catering bookings in this period.</td></tr>
                <?php else: ?>
                    <?php 
                    $grouped_events = [];
                    foreach ($events as $row) {
                        $customer = trim((string) $row['full_name']) !== '' ? $row['full_name'] : $row['customer_name'];
                        $customer = $customer ?: 'Unknown Customer';
                        $customerKey = !empty($row['customer_id']) ? 'user_' . $row['customer_id'] : 'guest_' . $customer;
                        $grouped_events[$customerKey][] = $row;
                    }
                    ?>
                    <?php foreach ($grouped_events as $customerKey => $customer_bookings): ?>
                        <?php
                        $profile = $customer_bookings[0];
                        $customer_name = trim((string) $profile['full_name']) !== '' ? $profile['full_name'] : ($profile['customer_name'] ?: 'Unknown Customer');
                        $groupId = md5($customerKey);
                        ?>
                        <tr class="customer-group-row" style="cursor: pointer; background: rgba(255,255,255,0.05);" onclick="toggleBookings('<?php echo htmlspecialchars($groupId); ?>', this)">
                            <td colspan="9">
                                <div class="report-customer-profile">
                                <img class="report-customer-avatar" data-customer-id="<?php echo (int) $profile['customer_id']; ?>" src="<?php echo htmlspecialchars(reportProfileUrl($profile['profile_image']), ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($customer_name, ENT_QUOTES); ?> profile" onerror="this.onerror=null;this.src='../uploads/logo.png';">
                                <div>
                                <strong style="color: #d4af37;"><?php echo htmlspecialchars($customer_name); ?></strong> 
                                <span style="font-size: 12px; color: var(--text-muted);"> (<?php echo count($customer_bookings); ?> booking<?php echo count($customer_bookings) > 1 ? 's' : ''; ?>)</span>
                                </div></div>
                            </td>
                        </tr>
                        <?php foreach ($customer_bookings as $row): ?>
                            <?php
                            $paid = (float) $row['paid_amount'];
                            $balance = (float) $row['total_amount'] - $paid;
                            ?>
                            <tr class="booking-detail-row booking-group-<?php echo htmlspecialchars($groupId); ?>" style="display: none; background: rgba(0,0,0,0.2);">
                                <td>
                                    <?php echo date('M d, Y', strtotime($row['order_date'])); ?><br>
                                    <?php echo $row['order_time'] ? date('g:i A', strtotime($row['order_time'])) : '-'; ?>
                                </td>
                                <td>
                                    <span style="color: var(--text-muted); font-size: 11px;">Booking:</span><br>
                                    <?php echo htmlspecialchars(formatCatererBookingNumber(0, $row['order_number'])); ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['address'] ?: '-'); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($row['service_name']); ?>
                                    <?php if (!empty($row['event_type'])): ?>
                                        <br><?php echo htmlspecialchars($row['event_type']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format((int) $row['number_of_pax']); ?></td>
                                <td><?php echo htmlspecialchars(statusLabel($row['order_status'])); ?></td>
                                <td class="right"><?php echo formatPeso($row['total_amount']); ?></td>
                                <td class="right"><?php echo formatPeso($paid); ?></td>
                                <td class="right"><?php echo formatPeso(max($balance, 0)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="report-section" id="sec-unpaid">
        <h2>Unpaid / partial collections</h2>
        <div class="report-table-scroll" role="region" aria-label="Scrollable report table" tabindex="0"><table class="report-table">
            <thead>
                <tr>
                    <th>Event date</th>
                    <th>Customer / Booking</th>
                    <th class="right">Amount</th>
                    <th class="right">Paid</th>
                    <th class="right">Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$unpaid_events): ?>
                    <tr><td colspan="5" class="empty">No outstanding balances in this period.</td></tr>
                <?php else: ?>
                    <?php 
                    $grouped_unpaid = [];
                    foreach ($unpaid_events as $row) {
                        $customer = trim((string) $row['full_name']) !== '' ? $row['full_name'] : $row['customer_name'];
                        $customer = $customer ?: 'Unknown Customer';
                        $grouped_unpaid[$customer][] = $row;
                    }
                    ?>
                    <?php foreach ($grouped_unpaid as $customer_name => $customer_bookings): ?>
                        <?php $groupId = md5('unpaid_' . $customer_name); ?>
                        <tr class="customer-group-row" style="cursor: pointer; background: rgba(255,255,255,0.05);" onclick="toggleBookings('<?php echo htmlspecialchars($groupId); ?>', this)">
                            <td colspan="5">
                                <strong style="color: #d4af37;"><?php echo htmlspecialchars($customer_name); ?></strong> 
                                <span style="font-size: 12px; color: var(--text-muted);"> (<?php echo count($customer_bookings); ?> booking<?php echo count($customer_bookings) > 1 ? 's' : ''; ?>)</span>
                            </td>
                        </tr>
                        <?php foreach ($customer_bookings as $row): ?>
                            <tr class="booking-detail-row booking-group-<?php echo htmlspecialchars($groupId); ?>" style="display: none; background: rgba(0,0,0,0.2);">
                                <td><?php echo date('M d, Y', strtotime($row['order_date'])); ?></td>
                                <td>
                                    <span style="color: var(--text-muted); font-size: 11px;">Booking:</span><br>
                                    <?php echo htmlspecialchars(formatCatererBookingNumber(0, $row['order_number'])); ?>
                                </td>
                                <td class="right"><?php echo formatPeso($row['total_amount']); ?></td>
                                <td class="right"><?php echo formatPeso($row['paid_amount']); ?></td>
                                <td class="right"><?php echo formatPeso($row['balance']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="report-section" id="sec-packages">
        <h2>Package / service report</h2>
        <div class="report-table-scroll" role="region" aria-label="Scrollable report table" tabindex="0"><table class="report-table">
            <thead>
                <tr>
                    <th>Package</th>
                    <th>Bookings</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$packages): ?>
                    <tr><td colspan="2" class="empty">No package bookings in this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($packages as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['package_name']); ?></td>
                            <td><?php echo number_format((int) $row['bookings']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="report-section" id="sec-payments">
        <h2>Verified payments by method</h2>
        <div class="report-table-scroll" role="region" aria-label="Scrollable report table" tabindex="0"><table class="report-table">
            <thead>
                <tr>
                    <th>Method</th>
                    <th>Count</th>
                    <th class="right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$payment_methods): ?>
                    <tr><td colspan="3" class="empty">No verified payments in this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($payment_methods as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['method_name']))); ?></td>
                            <td><?php echo number_format((int) $row['payment_count']); ?></td>
                            <td class="right"><?php echo formatPeso($row['total_amount']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="report-section" id="sec-inventory">
        <h2>Equipment / inventory</h2>
        <div class="report-table-scroll" role="region" aria-label="Scrollable report table" tabindex="0"><table class="report-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Type</th>
                    <th class="right">Available</th>
                    <th class="right">Damaged</th>
                    <th class="right">Low stock at</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$inventory): ?>
                    <tr><td colspan="5" class="empty">No inventory items yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($inventory as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($row['item_type'])); ?></td>
                            <td class="right"><?php echo number_format((float) $row['available_stock'], 0); ?></td>
                            <td class="right"><?php echo number_format((float) $row['damaged_stock'], 0); ?></td>
                            <td class="right"><?php echo number_format((float) $row['low_stock_threshold'], 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="report-section" id="sec-damages">
        <h2>Damage charges this period</h2>
        <div class="report-table-scroll" role="region" aria-label="Scrollable report table" tabindex="0"><table class="report-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th class="right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$damages): ?>
                    <tr><td colspan="3" class="empty">No damage charges in this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($damages as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                            <td><?php echo number_format((int) $row['qty']); ?></td>
                            <td class="right"><?php echo formatPeso($row['total_cost']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <!-- 7. Revenue Summary (Daily / Weekly / Monthly) -->
    <div class="report-section" id="sec-revenue-summary">
        <h2>Revenue Summary</h2>
        <div class="rev-tabs no-print">
            <button class="rev-tab active" data-target="rev-daily">Daily</button>
            <button class="rev-tab" data-target="rev-weekly">Weekly</button>
            <button class="rev-tab" data-target="rev-monthly">Monthly</button>
        </div>
        <div class="rev-panel active" id="rev-daily"<?php if (!$revenue_daily): ?> data-empty-print<?php endif; ?>>
            <h3 class="print-only" style="font-size:14px;margin:8px 0 6px;">Daily Revenue</h3>
            <div class="report-table-scroll" role="region" aria-label="Scrollable report table" tabindex="0"><table class="report-table">
                <thead><tr><th>Date</th><th>Bookings</th><th class="right">Contract Total</th><th class="right">Collected</th><th class="right">Outstanding</th></tr></thead>
                <tbody>
                    <?php if (!$revenue_daily): ?>
                        <tr><td colspan="5" class="empty">No data for this period.</td></tr>
                    <?php else: ?>
                        <?php $rv_dc=0;$rv_dl=0; foreach($revenue_daily as $row): $rv_do=max((float)$row['contract_total']-(float)$row['collected'],0);$rv_dc+=(float)$row['contract_total'];$rv_dl+=(float)$row['collected']; ?>
                        <tr>
                            <td><?php echo date('M d, Y (D)',strtotime($row['period_label']));?></td>
                            <td><?php echo number_format((int)$row['bookings']);?></td>
                            <td class="right"><?php echo formatPeso($row['contract_total']);?></td>
                            <td class="right"><?php echo formatPeso($row['collected']);?></td>
                            <td class="right"><?php echo formatPeso($rv_do);?></td>
                        </tr>
                        <?php endforeach;?>
                        <tr style="font-weight:700;border-top:2px solid rgba(255,255,255,.2)">
                            <td colspan="2">TOTAL</td>
                            <td class="right"><?php echo formatPeso($rv_dc);?></td>
                            <td class="right"><?php echo formatPeso($rv_dl);?></td>
                            <td class="right"><?php echo formatPeso(max($rv_dc-$rv_dl,0));?></td>
                        </tr>
                    <?php endif;?>
                </tbody>
            </table></div>
        </div>
        <div class="rev-panel" id="rev-weekly"<?php if (!$revenue_weekly): ?> data-empty-print<?php endif; ?>>
            <h3 class="print-only" style="font-size:14px;margin:8px 0 6px;">Weekly Revenue</h3>
            <div class="report-table-scroll" role="region" aria-label="Scrollable report table" tabindex="0"><table class="report-table">
                <thead><tr><th>Week</th><th>Bookings</th><th class="right">Contract Total</th><th class="right">Collected</th><th class="right">Outstanding</th></tr></thead>
                <tbody>
                    <?php if (!$revenue_weekly): ?>
                        <tr><td colspan="5" class="empty">No data for this period.</td></tr>
                    <?php else: ?>
                        <?php $rv_wc=0;$rv_wl=0; foreach($revenue_weekly as $row): $rv_wo=max((float)$row['contract_total']-(float)$row['collected'],0);$rv_wc+=(float)$row['contract_total'];$rv_wl+=(float)$row['collected']; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['period_label']);?></td>
                            <td><?php echo number_format((int)$row['bookings']);?></td>
                            <td class="right"><?php echo formatPeso($row['contract_total']);?></td>
                            <td class="right"><?php echo formatPeso($row['collected']);?></td>
                            <td class="right"><?php echo formatPeso($rv_wo);?></td>
                        </tr>
                        <?php endforeach;?>
                        <tr style="font-weight:700;border-top:2px solid rgba(255,255,255,.2)">
                            <td colspan="2">TOTAL</td>
                            <td class="right"><?php echo formatPeso($rv_wc);?></td>
                            <td class="right"><?php echo formatPeso($rv_wl);?></td>
                            <td class="right"><?php echo formatPeso(max($rv_wc-$rv_wl,0));?></td>
                        </tr>
                    <?php endif;?>
                </tbody>
            </table></div>
        </div>
        <div class="rev-panel" id="rev-monthly"<?php if (!$revenue_monthly): ?> data-empty-print<?php endif; ?>>
            <h3 class="print-only" style="font-size:14px;margin:8px 0 6px;">Monthly Revenue</h3>
            <div class="report-table-scroll" role="region" aria-label="Scrollable report table" tabindex="0"><table class="report-table">
                <thead><tr><th>Month</th><th>Bookings</th><th class="right">Contract Total</th><th class="right">Collected</th><th class="right">Outstanding</th></tr></thead>
                <tbody>
                    <?php if (!$revenue_monthly): ?>
                        <tr><td colspan="5" class="empty">No data for this period.</td></tr>
                    <?php else: ?>
                        <?php $rv_mc=0;$rv_ml=0; foreach($revenue_monthly as $row): $rv_mo=max((float)$row['contract_total']-(float)$row['collected'],0);$rv_mc+=(float)$row['contract_total'];$rv_ml+=(float)$row['collected']; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['period_label']);?></td>
                            <td><?php echo number_format((int)$row['bookings']);?></td>
                            <td class="right"><?php echo formatPeso($row['contract_total']);?></td>
                            <td class="right"><?php echo formatPeso($row['collected']);?></td>
                            <td class="right"><?php echo formatPeso($rv_mo);?></td>
                        </tr>
                        <?php endforeach;?>
                        <tr style="font-weight:700;border-top:2px solid rgba(255,255,255,.2)">
                            <td colspan="2">TOTAL</td>
                            <td class="right"><?php echo formatPeso($rv_mc);?></td>
                            <td class="right"><?php echo formatPeso($rv_ml);?></td>
                            <td class="right"><?php echo formatPeso(max($rv_mc-$rv_ml,0));?></td>
                        </tr>
                    <?php endif;?>
                </tbody>
            </table></div>
        </div>
    </div>

    <!-- 8. Fully Paid -->
    <div class="report-section" id="sec-fully-paid">
        <h2>Fully Paid &nbsp;<span class="badge-paid"><?php echo count($fully_paid_events); ?></span></h2>
        <div class="report-table-scroll" role="region" aria-label="Scrollable report table" tabindex="0"><table class="report-table">
            <thead><tr><th>Event date</th><th>Customer</th><th>Booking #</th><th>Package</th><th>Pax</th><th>Status</th><th class="right">Total</th><th class="right">Paid</th></tr></thead>
            <tbody>
                <?php if (!$fully_paid_events): ?>
                    <tr><td colspan="8" class="empty">No fully paid orders in this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($fully_paid_events as $row): ?>
                        <?php $cust = trim((string)$row['full_name'])!=='' ? $row['full_name'] : $row['customer_name']; ?>
                        <tr>
                            <td><?php echo date('M d, Y',strtotime($row['order_date']));?><br><?php echo $row['order_time']?date('g:i A',strtotime($row['order_time'])):'';?></td>
                            <td><?php echo htmlspecialchars($cust?:'-');?><br><small><?php echo htmlspecialchars($row['contact_number']?:'');?></small></td>
                            <td><?php echo htmlspecialchars(formatCatererBookingNumber(0, $row['order_number']));?></td>
                            <td><?php echo htmlspecialchars($row['service_name']);?></td>
                            <td><?php echo number_format((int)$row['number_of_pax']);?></td>
                            <td><?php echo htmlspecialchars(statusLabel($row['order_status']));?></td>
                            <td class="right"><?php echo formatPeso($row['total_amount']);?></td>
                            <td class="right"><?php echo formatPeso($row['paid_amount']);?></td>
                        </tr>
                    <?php endforeach;?>
                <?php endif;?>
            </tbody>
        </table></div>
    </div>
</div>

<!-- Print selector modal -->
<?php
$printable_sections = array_filter([
    'sec-event-bookings' => ['Event bookings', (bool) ($events)],
    'sec-unpaid' => ['Unpaid / partial collections', (bool) ($unpaid_events)],
    'sec-packages' => ['Package / service report', (bool) ($packages)],
    'sec-payments' => ['Verified payments by method', (bool) ($payment_methods)],
    'sec-inventory' => ['Equipment / inventory', (bool) ($inventory)],
    'sec-damages' => ['Damage charges this period', (bool) ($damages)],
    'sec-revenue-summary' => ['Revenue Summary', (bool) ($revenue_daily || $revenue_weekly || $revenue_monthly)],
    'sec-fully-paid' => ['Fully Paid', (bool) ($fully_paid_events)],
], static function (array $section): bool { return $section[1]; });
?>
<div class="print-modal-overlay no-print" id="printModalOverlay">
    <div class="print-modal">
        <h3>&#128438; Select sections to print</h3>
        <?php if ($printable_sections): ?>
        <div class="select-all-row"><input type="checkbox" id="chkAll" checked><label for="chkAll">Select / deselect all</label></div>
        <div class="check-list">
            <?php foreach ($printable_sections as $section_id => [$label]): ?>
                <label><input type="checkbox" class="sec-chk" value="<?php echo htmlspecialchars($section_id); ?>" checked> <?php echo htmlspecialchars($label); ?></label>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p>No report data to print for this date range.</p>
        <?php endif; ?>
        <div class="print-modal-actions">
            <button class="btn-cancel-modal" id="btnCancelPrintModal">Cancel</button>
            <button class="btn-do-print" id="btnConfirmPrint">&#128438; Print</button>
        </div>
    </div>
</div>

<script>
function toggleBookings(groupId, el) {
    var rows = document.querySelectorAll('.booking-group-' + groupId);
    var icon = el.querySelector('.toggle-icon');
    var isHidden = false;
    rows.forEach(function(row) {
        if (row.style.display === 'none' || row.style.display === '') {
            row.style.display = 'table-row';
            row.classList.add('is-expanded');
            isHidden = true;
        } else {
            row.style.display = 'none';
            row.classList.remove('is-expanded');
        }
    });
    if (icon) {
        icon.innerHTML = isHidden ? '&#9662;' : '&#9656;';
    }
}
(function(){
    document.querySelectorAll('.reports-page .report-table').forEach(function(table){
        var labels=Array.from(table.querySelectorAll('thead th')).map(function(th){return th.textContent.trim();});
        table.querySelectorAll('tbody tr').forEach(function(row){
            if(row.classList.contains('customer-group-row')) return;
            Array.from(row.cells).forEach(function(cell,index){
                if(cell.colSpan>1) return;
                var label=labels[index] || '';
                cell.setAttribute('data-label',label);
                if(/Customer|Booking|Package|Item|Venue/i.test(label)) cell.classList.add('cell-wide');
                var value=document.createElement('span');
                value.className='cell-value';
                while(cell.firstChild) value.appendChild(cell.firstChild);
                cell.appendChild(value);
            });
        });
    });
    var reportsPage=document.querySelector('.reports-page');
    var reportSections=Array.from(reportsPage.querySelectorAll('.report-section'));
    var picker=document.createElement('div');
    picker.className='report-picker no-print';
    var pickerLabel=document.createElement('label');
    pickerLabel.htmlFor='report-section-select';
    pickerLabel.textContent='View report';
    var reportSelect=document.createElement('select');
    reportSelect.id='report-section-select';
    reportSections.forEach(function(section){
        var heading=section.querySelector('h2');
        heading.id='heading-'+section.id;
        section.setAttribute('role','region');
        section.setAttribute('aria-labelledby',heading.id);
        var option=document.createElement('option');
        option.value=section.id;
        option.textContent=heading.firstChild.textContent.trim();
        reportSelect.appendChild(option);
    });
    function showSelectedReport(){
        reportSections.forEach(function(section){
            section.classList.toggle('is-open',section.id===reportSelect.value);
        });
        reportSelect.setAttribute('aria-controls',reportSelect.value);
    }
    reportSelect.addEventListener('change',showSelectedReport);
    picker.appendChild(pickerLabel);
    picker.appendChild(reportSelect);
    reportsPage.insertBefore(picker,reportSections[0]);
    showSelectedReport();
    reportsPage.classList.add('sections-ready');
    document.querySelectorAll('.rev-tab').forEach(function(tab){
        tab.addEventListener('click',function(){
            document.querySelectorAll('.rev-tab').forEach(function(t){t.classList.remove('active');});
            document.querySelectorAll('.rev-panel').forEach(function(p){p.classList.remove('active');});
            tab.classList.add('active');
            var t=document.getElementById(tab.getAttribute('data-target'));
            if(t){t.classList.add('active');}
        });
    });
    var overlay=document.getElementById('printModalOverlay');
    var chkAll = document.getElementById('chkAll');
    var sectionChecks = Array.from(overlay.querySelectorAll('.sec-chk'));
    var printButton = document.getElementById('btnConfirmPrint');
    var reportSections = Array.from(document.querySelectorAll('.reports-page .report-section'));
    var availableIds = new Set(sectionChecks.map(function (check) { return check.value; }));

    reportSections.forEach(function (section) {
        if (!availableIds.has(section.id)) section.classList.add('section-hidden-for-print');
    });

    function syncPrintSelection() {
        var count = sectionChecks.filter(function (check) { return check.checked; }).length;
        if (chkAll) {
            chkAll.checked = count === sectionChecks.length;
            chkAll.indeterminate = count > 0 && count < sectionChecks.length;
        }
        printButton.disabled = count === 0;
    }

    document.getElementById('btnOpenPrintModal').addEventListener('click', function () {
        syncPrintSelection();
        overlay.classList.add('open');
    });
    document.getElementById('btnCancelPrintModal').addEventListener('click', function () { overlay.classList.remove('open'); });
    overlay.addEventListener('click', function (event) { if (event.target === overlay) overlay.classList.remove('open'); });
    if (chkAll) chkAll.addEventListener('change', function () {
        sectionChecks.forEach(function (check) { check.checked = chkAll.checked; });
        syncPrintSelection();
    });
    sectionChecks.forEach(function (check) { check.addEventListener('change', syncPrintSelection); });
    syncPrintSelection();

    var printFrame;
    printButton.addEventListener('click', function () {
        var selected = new Set(sectionChecks.filter(function (check) { return check.checked; }).map(function (check) { return check.value; }));
        if (!selected.size) return;

        if (printFrame) printFrame.remove();
        printFrame = document.createElement('iframe');
        printFrame.title = 'Catering report print preview';
        printFrame.style.cssText = 'position:fixed;left:-10000px;top:0;width:900px;height:700px;border:0';
        document.body.appendChild(printFrame);

        // Use a separate document so dashboard scrolling and visibility rules cannot hide print content.
        var frame = printFrame;
        var printDocument = frame.contentDocument;
        printDocument.title = 'Catering Operations Report';
        var style = printDocument.createElement('style');
        style.textContent = `
            @page { size: A4 landscape; margin: 13mm; }
            body { margin: 0; font: 10pt/1.4 Arial, sans-serif; color: #172e25; }
            .print-header { border-bottom: 2px solid #254838; padding-bottom: 10px; margin-bottom: 16px; }
            .print-header > :first-child { font-size: 12pt; }
            .print-header > :nth-child(2) { font-size: 18pt; font-weight: bold; }
            .print-header > :nth-child(n+3) { font-size: 9pt; color: #46574d; }
            h2 { font-size: 13pt; margin: 18px 0 7px; break-after: avoid; }
            h3 { font-size: 11pt; break-after: avoid; }
            table { border-collapse: collapse; width: 100%; margin-bottom: 12px; }
            th, td { border: 1px solid #b3beb6; padding: 6px 7px; text-align: left; vertical-align: top; }
            th { background: #e6eee7; color: #172e25; print-color-adjust: exact; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; }
            td { overflow-wrap: break-word; }
            .right, #sec-event-bookings th:nth-last-child(-n+3) { text-align: right; white-space: nowrap; }
            #sec-event-bookings table { table-layout: fixed; }
            #sec-event-bookings th:nth-child(1) { width: 10%; }
            #sec-event-bookings th:nth-child(2) { width: 16%; }
            #sec-event-bookings th:nth-child(3) { width: 12%; }
            #sec-event-bookings th:nth-child(4) { width: 15%; }
            #sec-event-bookings th:nth-child(5) { width: 6%; }
            #sec-event-bookings th:nth-child(6) { width: 9%; }
            #sec-event-bookings th:nth-child(n+7) { width: 10%; }
            #sec-event-bookings td:nth-child(5) { white-space: nowrap; }
            .print-customer { display: block; font-weight: bold; }
            .print-booking { display: block; font-size: 9pt; color: #46574d; }
        `;
        printDocument.head.appendChild(style);

        var header = printDocument.importNode(document.querySelector('.reports-page > .print-only'), true);
        header.className = 'print-header';
        printDocument.body.appendChild(header);
        reportSections.filter(function (section) { return selected.has(section.id); }).forEach(function (section) {
            var copy = printDocument.importNode(section, true);
            copy.removeAttribute('hidden');
            copy.removeAttribute('style');
            copy.querySelectorAll('.no-print, [data-empty-print], script, img').forEach(function (element) { element.remove(); });
            var customerName = '';
            copy.querySelectorAll('.customer-group-row, .booking-detail-row').forEach(function (row) {
                if (row.classList.contains('customer-group-row')) {
                    customerName = row.querySelector('strong').textContent.trim();
                    row.remove();
                    return;
                }
                row.removeAttribute('style');
                var bookingCell = row.cells[1];
                var bookingNumber = bookingCell.textContent.replace(/^\s*Booking:\s*/, '').trim();
                var name = printDocument.createElement('span');
                name.className = 'print-customer';
                name.textContent = customerName;
                var booking = printDocument.createElement('span');
                booking.className = 'print-booking';
                booking.textContent = 'Booking ' + bookingNumber;
                bookingCell.replaceChildren(name, booking);
            });
            printDocument.body.appendChild(copy);
        });

        overlay.classList.remove('open');
        frame.contentWindow.addEventListener('afterprint', function () {
            frame.remove();
            if (printFrame === frame) printFrame = null;
        }, { once: true });
        requestAnimationFrame(function () {
            if (!frame.isConnected) return;
            frame.contentWindow.focus();
            frame.contentWindow.print();
        });
    });
})();
</script>

<script>
(function () {
    var refreshing = false;
    async function refreshCustomerProfiles() {
        if (document.hidden || refreshing) return;
        refreshing = true;
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('customer_profiles', '1');
            var response = await fetch(url, {cache: 'no-store', credentials: 'same-origin'});
            if (!response.ok) return;
            var profiles = await response.json();
            document.querySelectorAll('.report-customer-avatar').forEach(function (img) {
                var path = profiles[img.dataset.customerId];
                if (!path) return;
                var next = new URL(path, window.location.href);
                next.searchParams.set('profile_refresh', Date.now());
                var replacement = new Image();
                replacement.onload = function () { img.src = next.href; };
                replacement.onerror = function () { img.src = '../uploads/logo.png'; };
                replacement.src = next.href;
            });
        } catch (error) {
            // Keep the displayed photos when the connection is unavailable.
        } finally {
            refreshing = false;
        }
    }
    setInterval(refreshCustomerProfiles, 10000);
    document.addEventListener('visibilitychange', refreshCustomerProfiles);
    refreshCustomerProfiles();
})();
</script>



<style>
@media screen {
    .reports-page #sec-event-bookings { min-width:0; overflow:hidden; }
    .reports-page #sec-event-bookings .report-table-scroll {
        max-height: min(520px, 55dvh); overflow:auto; overscroll-behavior:contain;
        scrollbar-width:thin; scrollbar-color:#84977e #d4dbcd;
        scrollbar-gutter:stable; border:1px solid #96a38e; border-radius:10px;
    }
    .reports-page #sec-event-bookings .report-table { width:100%; table-layout:fixed; }
    .reports-page #sec-event-bookings th { position:sticky; top:0; z-index:1; background:#c9d2c1; }
    .reports-page #sec-event-bookings td { overflow-wrap:anywhere; white-space:normal; }
    .reports-page #sec-event-bookings .report-customer-profile { min-width:0; gap:10px; }
    .reports-page #sec-event-bookings .customer-group-row:hover { background:#bccdb4 !important; }
    .reports-page #sec-event-bookings .booking-detail-row td { padding:10px 8px; vertical-align:top; }
}
@media screen and (max-width:700px) {
    .reports-page #sec-event-bookings .report-table-scroll { padding:6px; max-height:55dvh; }
    .reports-page #sec-event-bookings .report-table { table-layout:auto; }
    .reports-page #sec-event-bookings .booking-detail-row.is-expanded { background:#d4dbcd; border-color:#a8b69d; }
    .reports-page #sec-event-bookings .booking-detail-row td { padding:0; }
    .reports-page #sec-event-bookings .report-customer-profile > div { min-width:0; }
}
</style>


<style>
@media screen {
    .caterer-sage-theme .reports-page #sec-event-bookings > .report-table-scroll {
        border: 0 !important;
        border-radius: 0;
        background: transparent !important;
        box-shadow: none;
    }
    .reports-page #sec-event-bookings > .report-table-scroll:focus {
        outline: none;
    }
    .reports-page #sec-event-bookings > .report-table-scroll:focus-visible {
        outline: 2px solid #708b6b;
        outline-offset: -2px;
    }
}
</style>
<link rel="stylesheet" href="../assets/css/report-pickers.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/report-pickers.css'); ?>">
<script src="../assets/js/report-pickers.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/report-pickers.js'); ?>"></script>
<style>
@media screen {
    body.bounded-caterer-page .caterer-workspace .reports-page .summary-grid { gap:6px; margin-bottom:8px; }
    body.bounded-caterer-page .caterer-workspace .reports-page .summary-card { padding:6px 8px; border-radius:7px; }
    .reports-page .summary-card strong { margin-top:3px; }
}
@media screen and (max-width:600px) {
    body.bounded-caterer-page .caterer-workspace .reports-page .summary-grid { grid-template-columns:repeat(3,minmax(0,1fr)); gap:5px; }
    body.bounded-caterer-page .caterer-workspace .reports-page .summary-card { padding:7px 5px; }
    .reports-page .summary-card span { font-size:10px; line-height:1.3; }
    .reports-page .summary-card strong { font-size:12px; line-height:1.4; letter-spacing:-.2px; }
}
</style>
<script>
(() => {
    const page = document.querySelector('.reports-page');
    const key = 'reports-view-' + location.pathname + location.search;
    let restoring = true;
    function save() {
        if (restoring) return;
        const expanded = Array.from(page.querySelectorAll('.customer-group-row')).filter(row => row.nextElementSibling?.classList.contains('is-expanded')).map(row => row.getAttribute('onclick'));
        const scrolls = Array.from(document.querySelectorAll('.caterer-workspace, .report-table-scroll')).map(el => ({id: el.closest('.report-section')?.id || 'workspace', top: el.scrollTop, left: el.scrollLeft}));
        try { sessionStorage.setItem(key, JSON.stringify({section: document.getElementById('report-section-select')?.value, expanded, scrolls, tab: page.querySelector('.rev-tab.active')?.getAttribute('data-target')})); } catch (_) {}
    }
    try {
        const state = JSON.parse(sessionStorage.getItem(key) || 'null');
        if (state) {
            const select = document.getElementById('report-section-select');
            if (select && Array.from(select.options).some(option => option.value === state.section)) {select.value = state.section; select.dispatchEvent(new Event('change'));}
            page.querySelectorAll('.customer-group-row').forEach(row => {
                if ((state.expanded || []).includes(row.getAttribute('onclick')) && !row.nextElementSibling?.classList.contains('is-expanded')) row.click();
            });
            page.querySelectorAll('.rev-tab').forEach(tab => {if (tab.getAttribute('data-target') === state.tab) tab.click();});
            requestAnimationFrame(() => {
                (state.scrolls || []).forEach(saved => {
                    const el = saved.id === 'workspace' ? document.querySelector('.caterer-workspace') : document.getElementById(saved.id)?.querySelector('.report-table-scroll');
                    if (el) {el.scrollTop = saved.top; el.scrollLeft = saved.left;}
                });
                restoring = false;
            });
        } else restoring = false;
    } catch (_) { restoring = false; }
    window.addEventListener('pagehide', save);
    page.addEventListener('change', save);
    page.addEventListener('click', () => requestAnimationFrame(save));
    document.querySelectorAll('.caterer-workspace, .report-table-scroll').forEach(el => el.addEventListener('scroll', save, {passive:true}));
})();
</script>
<style>
@media screen {
    body.bounded-caterer-page .caterer-workspace:has(.reports-page) {
        display:flex; flex-direction:column; min-height:0; overflow:hidden;
    }
    body.bounded-caterer-page .caterer-workspace .reports-page {
        display:flex; flex-direction:column; flex:1 1 0; min-height:0; overflow:hidden; width:100%;
    }
    .reports-page > :is(.filter-bar, .summary-grid, .report-picker) { flex:0 0 auto; }
    body.bounded-caterer-page .caterer-workspace .reports-page.sections-ready > .report-section.is-open {
        display:flex; flex-direction:column; flex:1 1 0; min-height:0; overflow:hidden; margin-bottom:0;
    }
    .reports-page .report-section > h2 { flex:0 0 auto; }
    body.bounded-caterer-page .reports-page .report-section > .report-table-scroll,
    body.bounded-caterer-page .reports-page #sec-event-bookings > .report-table-scroll {
        flex:1 1 0; min-height:0; max-height:none; overflow:auto;
        overscroll-behavior:contain; scrollbar-width:thin; scrollbar-color:#84977e #d4dbcd;
    }
}
</style>
<style>
@media screen {
    .reports-page .report-picker[hidden] { display:none !important; }
    .reports-page .filter-bar { grid-template-columns:minmax(0,1fr) minmax(0,1fr) repeat(5,auto); align-items:end; }
    .reports-page .filter-bar > .report-picker-control {
        width:auto; min-height:42px; max-width:none; white-space:nowrap;
        font-weight:600; padding:9px 12px; margin:0;
    }
}
@media screen and (max-width:900px) {
    .reports-page .filter-bar { grid-template-columns:repeat(6,minmax(0,1fr)); }
    .reports-page .filter-bar .date-field { grid-column:span 3; }
    .reports-page .filter-bar > :is(.report-btn,.report-picker-control) { grid-column:span 2; width:100%; min-width:0; }
}
@media screen and (max-width:600px) {
    .reports-page .filter-bar > .report-picker-control { font-size:12px; padding:8px 5px; }
}
</style>
<style>
@media screen {
    body.bounded-caterer-page > .main-content:has(.reports-page) { padding:6px !important; }
    body.bounded-caterer-page .caterer-workspace:has(.reports-page) { padding:8px; }
    body.bounded-caterer-page .caterer-workspace .reports-page { max-width:none; margin:0; padding:0; }
    body.bounded-caterer-page .caterer-workspace .reports-page .report-section { padding:8px; }
    .reports-page #sec-event-bookings > .report-table-scroll { width:100%; box-sizing:border-box; padding:0; scrollbar-gutter:auto; }
    .reports-page .report-section > h2 { margin-bottom:8px; }
}
@media screen and (max-width:992px) {
    body.bounded-caterer-page > .main-content:has(.reports-page) { padding:68px 6px 6px !important; }
}
@media screen and (max-width:600px) {
    body.bounded-caterer-page .caterer-workspace:has(.reports-page) { padding:6px; }
    body.bounded-caterer-page .caterer-workspace .reports-page .report-section { padding:6px; }
}
</style>
<style>
@media screen {
    body.bounded-caterer-page .caterer-workspace:has(.reports-page) {
        scrollbar-gutter:auto !important;
        padding:6px !important;
        overflow:hidden;
    }
    body.bounded-caterer-page .caterer-workspace .reports-page {
        align-self:stretch;
        width:100%;
        max-width:none;
        box-sizing:border-box;
    }
    body.bounded-caterer-page .caterer-workspace .reports-page .report-section {
        padding:6px;
        box-sizing:border-box;
        width:100%;
    }
}
</style>
<style>
@media screen {
    .reports-page #sec-event-bookings .report-table thead {
        position:sticky;
        top:0;
        z-index:4;
    }
    .reports-page #sec-event-bookings .customer-group-row {
        position:sticky;
        top:var(--booking-table-heading-height, 34px);
        z-index:3;
    }
    .caterer-sage-theme .reports-page #sec-event-bookings .customer-group-row td {
        background:#c0ceb7 !important;
        box-shadow:0 1px 0 #96a38e;
    }
}
@media screen and (max-width:700px) {
    .reports-page #sec-event-bookings .customer-group-row {
        top:0;
        background:#c0ceb7 !important;
        box-shadow:0 2px 4px #173d321a;
    }
}
</style>
<script>
(() => {
    const section = document.getElementById('sec-event-bookings');
    const heading = section?.querySelector('thead');
    if (!heading) return;
    const update = () => section.style.setProperty('--booking-table-heading-height', heading.getBoundingClientRect().height + 'px');
    new ResizeObserver(update).observe(heading);
    update();
})();
</script>
<style>
@media screen {
    .reports-page #sec-event-bookings .report-table {
        border-collapse:separate;
        border-spacing:0;
        isolation:isolate;
    }
    .reports-page #sec-event-bookings .report-table thead {
        background:#c9d2c1;
        z-index:6;
    }
    .caterer-sage-theme .reports-page #sec-event-bookings .report-table thead th {
        background:#c9d2c1 !important;
        top:0;
        z-index:6;
        box-shadow:0 1px 0 #96a38e;
    }
    .reports-page #sec-event-bookings .customer-group-row {
        background:#c0ceb7 !important;
        z-index:5;
    }
    .caterer-sage-theme .reports-page #sec-event-bookings .customer-group-row td {
        position:sticky;
        top:var(--booking-table-heading-height,34px);
        background:#c0ceb7 !important;
        z-index:5;
        box-shadow:0 -1px 0 1px #c0ceb7,0 1px 0 #96a38e;
    }
}
@media screen and (max-width:700px) {
    .caterer-sage-theme .reports-page #sec-event-bookings .customer-group-row td {
        position:static;
        box-shadow:none;
    }
}
</style>
<style>
@media screen and (max-width:700px) {
    .reports-page .filter-bar {
        display:grid;
        grid-template-columns:repeat(6,minmax(0,1fr));
        gap:8px;
        padding:10px;
        width:100%;
        box-sizing:border-box;
        min-width:0;
    }
    .reports-page .filter-bar .date-field {
        grid-column:span 3;
        display:flex;
        flex-direction:column;
        gap:5px;
        min-width:0;
    }
    .reports-page .filter-bar .date-field label {font-size:11px;margin:0;}
    .reports-page .filter-bar .date-field .report-picker-control {
        width:100%;min-width:0;height:42px;font-size:12px;padding:8px;
    }
    .reports-page .filter-bar > :is(.report-btn,.report-picker-control) {
        grid-column:span 2;
        width:100%;min-width:0;min-height:40px;
        padding:8px 4px;box-sizing:border-box;
        display:flex;align-items:center;justify-content:center;
        margin:0;font-size:12px;line-height:1.3;white-space:normal;text-align:center;
    }
    .reports-page .filter-bar > #btnOpenPrintModal,
    .reports-page .filter-bar > .report-picker-control {grid-column:span 3;}
}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
