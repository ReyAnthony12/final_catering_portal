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

function reportValidDate($value, $fallback)
{
    $raw = trim((string) $value);
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    return ($dt && $dt->format('Y-m-d') === $raw) ? $raw : $fallback;
}

function peso($amount)
{
    return number_format((float) $amount, 2);
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
    return ucfirst(strtolower(trim((string) $status)));
}

$start_date = reportValidDate($_GET['start_date'] ?? '', date('Y-01-01'));
$end_date = reportValidDate($_GET['end_date'] ?? '', date('Y-m-t'));
if ($start_date > $end_date) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

$esc_start = mysqli_real_escape_string($conn, $start_date);
$esc_end = mysqli_real_escape_string($conn, $end_date);
$date_filter = "o.order_date BETWEEN '$esc_start' AND '$esc_end'";
$do_print = isset($_GET['print']) && $_GET['print'] === '1';
$export_mode = $_GET['export'] ?? '';

$caterer = reportOne($conn, "SELECT business_name FROM store_details WHERE user_id = $user_id LIMIT 1");
$store_name = $caterer['business_name'] ?? 'Catering Caterer';

$summary = reportOne($conn, "
    SELECT
        SUM(CASE WHEN o.order_status != 'cancelled' THEN 1 ELSE 0 END) as active_events,
        SUM(CASE WHEN o.order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_events,
        SUM(CASE WHEN o.order_status != 'cancelled' THEN COALESCE(o.number_of_pax, 0) ELSE 0 END) as total_pax,
        SUM(CASE WHEN o.order_status != 'cancelled' THEN o.total_amount ELSE 0 END) as contract_sales
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

$events = reportRows($conn, "
    SELECT
        o.order_number,
        o.order_date,
        o.order_time,
        o.full_name,
        o.address,
        o.number_of_pax,
        o.order_status,
        o.total_amount,
        o.package_name,
        o.event_type,
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
    ORDER BY sales DESC
");

if ($export_mode === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="catering_report_' . $start_date . '_to_' . $end_date . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Event Date', 'Time', 'Order', 'Customer', 'Venue', 'Package', 'Pax', 'Status', 'Amount', 'Paid', 'Balance']);
    foreach ($events as $row) {
        $customer = trim((string) $row['full_name']) !== '' ? $row['full_name'] : $row['customer_name'];
        $paid = (float) $row['paid_amount'];
        fputcsv($output, [
            $row['order_date'],
            $row['order_time'],
            formatCatererBookingNumber(0, $row['order_number']),
            $customer,
            $row['address'],
            $row['service_name'],
            (int) $row['number_of_pax'],
            statusLabel($row['order_status']),
            $row['total_amount'],
            $paid,
            max((float) $row['total_amount'] - $paid, 0),
        ]);
    }
    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Catering Report</title>
    <style>
        body {
            margin: 20px;
            background: #fff;
            color: #000;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            line-height: 1.4;
        }
        h1, h2, p { margin: 0 0 8px; font-weight: normal; }
        h1 { font-size: 18px; font-weight: bold; }
        h2 { font-size: 14px; font-weight: bold; margin-top: 18px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #000; padding: 5px; text-align: left; vertical-align: top; }
        th { font-weight: bold; }
        .right { text-align: right; }
        .toolbar { margin-bottom: 16px; }
        .toolbar a, .toolbar button {
            margin-right: 8px;
            padding: 6px 10px;
            font-size: 12px;
            background: #fff;
            color: #000;
            border: 1px solid #000;
            cursor: pointer;
            text-decoration: none;
        }
        @media print {
            .toolbar { display: none; }
            body { margin: 0; }
        }
    </style>
</head>
<body<?php echo $do_print ? ' onload="window.print()"' : ''; ?>>
    <div class="toolbar">
        <a href="reports.php?start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">Back</a>
        <button type="button" onclick="window.print()">Print</button>
        <a href="?export=csv&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">CSV</a>
    </div>

    <h1><?php echo htmlspecialchars($store_name); ?></h1>
    <p>Catering Operations Report</p>
    <p>Period: <?php echo date('F d, Y', strtotime($start_date)); ?> to <?php echo date('F d, Y', strtotime($end_date)); ?></p>
    <p>Printed: <?php echo date('F d, Y h:i A'); ?></p>

    <h2>Summary</h2>
    <table>
        <tr><th>Events</th><td><?php echo (int) ($summary['active_events'] ?? 0); ?></td></tr>
        <tr><th>Cancelled</th><td><?php echo (int) ($summary['cancelled_events'] ?? 0); ?></td></tr>
        <tr><th>Pax</th><td><?php echo (int) ($summary['total_pax'] ?? 0); ?></td></tr>
        <tr><th>Contract sales</th><td class="right"><?php echo peso($summary['contract_sales'] ?? 0); ?></td></tr>
        <tr><th>Collected</th><td class="right"><?php echo peso($collections['collected'] ?? 0); ?></td></tr>
        <tr><th>Outstanding</th><td class="right"><?php echo peso($collections['outstanding'] ?? 0); ?></td></tr>
    </table>

    <h2>Event bookings</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Customer</th>
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
                <tr><td colspan="9">No bookings in this period.</td></tr>
            <?php else: ?>
                <?php foreach ($events as $row): ?>
                    <?php
                    $customer = trim((string) $row['full_name']) !== '' ? $row['full_name'] : $row['customer_name'];
                    $paid = (float) $row['paid_amount'];
                    $balance = max((float) $row['total_amount'] - $paid, 0);
                    ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($row['order_date'])); ?> <?php echo $row['order_time'] ? date('g:i A', strtotime($row['order_time'])) : ''; ?></td>
                        <td><?php echo htmlspecialchars(($customer ?: '-') . ' / Booking #' . formatCatererBookingNumber(0, $row['order_number'])); ?></td>
                        <td><?php echo htmlspecialchars($row['address'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($row['service_name']); ?></td>
                        <td><?php echo (int) $row['number_of_pax']; ?></td>
                        <td><?php echo htmlspecialchars(statusLabel($row['order_status'])); ?></td>
                        <td class="right"><?php echo peso($row['total_amount']); ?></td>
                        <td class="right"><?php echo peso($paid); ?></td>
                        <td class="right"><?php echo peso($balance); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h2>Package report</h2>
    <table>
        <thead>
            <tr>
                <th>Package</th>
                <th>Bookings</th>
                <th>Pax</th>
                <th class="right">Sales</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$packages): ?>
                <tr><td colspan="4">No package data.</td></tr>
            <?php else: ?>
                <?php foreach ($packages as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['package_name']); ?></td>
                        <td><?php echo (int) $row['bookings']; ?></td>
                        <td><?php echo (int) $row['pax']; ?></td>
                        <td class="right"><?php echo peso($row['sales']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>

