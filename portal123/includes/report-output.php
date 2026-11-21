<?php
// Loaded only after authorization and report queries in reports.php.
if (!isset($stats, $top_stores_result, $start_raw, $end_raw)) {
    http_response_code(404);
    exit();
}
header('Cache-Control: no-store');
$summary = [
    'Total Customers' => (int) $stats['new_customers'],
    'Total Caterers' => (int) $stats['new_stores'],
    'Paid Subscriptions' => (int) $stats['new_subscriptions'],
    'Subscription Revenue (PHP)' => number_format((float) $stats['subscription_revenue'], 2, '.', ''),
    'Total Orders' => (int) $stats['total_orders'],
    'Total System Sales (PHP)' => number_format((float) $stats['total_sales'], 2, '.', ''),
];
$rows = mysqli_fetch_all($top_stores_result, MYSQLI_ASSOC);
if ($_GET['output'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="system-report-' . $start_raw . '-to-' . $end_raw . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    // Prevent spreadsheet formulas in user-provided business and owner names.
    $csvText = static function ($value) {
        $text = (string) $value;
        return preg_match('/^[\s]*[=+@-]/u', $text) ? "'" . $text : $text;
    };
    fputcsv($out, ['System Report']);
    fputcsv($out, ['Report Period', $start_raw, $end_raw]);
    fputcsv($out, ['Account totals exclude Trash and cover all registration dates.']);
    fputcsv($out, ['Subscriptions, revenue, orders and sales use the report period.']);
    fputcsv($out, []);
    fputcsv($out, ['Metric', 'Value']);
    foreach ($summary as $label => $value) fputcsv($out, [$label, $value]);
    fputcsv($out, []);
    fputcsv($out, ['Top Performing Caterers']);
    fputcsv($out, ['Caterer', 'Owner', 'Orders', 'Sales (PHP)']);
    foreach ($rows as $row) {
        fputcsv($out, [$csvText($row['business_name']), $csvText(trim($row['first_name'] . ' ' . $row['last_name'])), (int) $row['total_orders'], number_format((float) $row['total_sales'], 2, '.', '')]);
    }
    fclose($out);
    exit();
}
header('Content-Type: text/html; charset=utf-8');
$escape = static function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>System Report</title>
<style>
body { font: 14px Arial, sans-serif; color: #111; max-width: 1000px; margin: 24px auto; padding: 0 16px; }
h1 { font-size: 24px; } h2 { margin-top: 28px; font-size: 18px; }
table { border-collapse: collapse; width: 100%; margin: 16px 0; }
th, td { border: 1px solid #ccc; padding: 10px; text-align: left; overflow-wrap: anywhere; }
th { background: #f3f3f3; } tr { break-inside: avoid; }
button { padding: 10px 18px; cursor: pointer; }
@media print { body { margin: 0; padding: 0; } button { display: none; } thead { display: table-header-group; } }
</style>
</head>
<body>
<button type="button" onclick="window.print()">Print / Save as PDF</button>
<h1>Catering Portal System Report</h1>
<p>Period: <?= $escape($start_raw) ?> to <?= $escape($end_raw) ?></p>
<table><thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody>
<?php foreach ($summary as $label => $value): ?>
<tr><td><?= $escape($label) ?></td><td><?= $escape($value) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<h2>Top Performing Caterers</h2>
<table><thead><tr><th>Caterer</th><th>Owner</th><th>Orders</th><th>Sales (PHP)</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><td><?= $escape($row['business_name']) ?></td><td><?= $escape(trim($row['first_name'] . ' ' . $row['last_name'])) ?></td><td><?= (int) $row['total_orders'] ?></td><td><?= number_format((float) $row['total_sales'], 2) ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4">No orders recorded for this period.</td></tr><?php endif; ?>
</tbody></table>
<script>window.addEventListener('load', () => window.print());</script>
</body></html>