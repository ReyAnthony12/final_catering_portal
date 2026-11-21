<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
requireLogin();

if (getUserRole() !== 'portal') {
    header('Location: ../index.php');
    exit();
}

$start_raw = isset($_GET['start_date']) ? trim((string) $_GET['start_date']) : date('Y-m-01');
$end_raw = isset($_GET['end_date']) ? trim((string) $_GET['end_date']) : date('Y-m-t');

$start_obj = DateTime::createFromFormat('Y-m-d', $start_raw);
$end_obj = DateTime::createFromFormat('Y-m-d', $end_raw);

if (!$start_obj || $start_obj->format('Y-m-d') !== $start_raw) {
    $start_raw = date('Y-m-01');
}
if (!$end_obj || $end_obj->format('Y-m-d') !== $end_raw) {
    $end_raw = date('Y-m-t');
}
if ($start_raw > $end_raw) {
    $tmp = $start_raw;
    $start_raw = $end_raw;
    $end_raw = $tmp;
}

$start_date = mysqli_real_escape_string($conn, $start_raw);
$end_date = mysqli_real_escape_string($conn, $end_raw);

// Match Manage Users: accounts in Trash are excluded from current totals.
$trash_table = mysqli_query($conn, "SHOW TABLES LIKE 'portal_account_trash'");
$account_scope = $trash_table && mysqli_num_rows($trash_table) > 0
    ? ' AND NOT EXISTS (SELECT 1 FROM portal_account_trash t WHERE t.user_id = users.id)'
    : '';
// Get system statistics
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users WHERE role = 'customer'$account_scope) as new_customers,
        (SELECT COUNT(*) FROM users WHERE role IN ('store', 'caterer')$account_scope) as new_stores,
        (SELECT COUNT(*) FROM subscriptions WHERE payment_status = 'paid' AND DATE(created_at) BETWEEN '$start_date' AND '$end_date') as new_subscriptions,
        (SELECT COALESCE(SUM(amount), 0) FROM subscriptions WHERE payment_status = 'paid' AND DATE(created_at) BETWEEN '$start_date' AND '$end_date') as subscription_revenue,
        (SELECT COUNT(*) FROM bookings WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date') as total_orders,
        (SELECT COALESCE(SUM(total_amount), 0) FROM bookings WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date') as total_sales
";
$stats_result = mysqli_query($conn, $stats_query);
$stats = $stats_result ? mysqli_fetch_assoc($stats_result) : [
    'new_customers' => 0,
    'new_stores' => 0,
    'new_subscriptions' => 0,
    'subscription_revenue' => 0,
    'total_orders' => 0,
    'total_sales' => 0,
];

// Refresh the same authorized, date-filtered summary without rendering the page.
if (isset($_GET['stats_refresh'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$stats_result) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load report statistics']);
    } else {
        echo json_encode(['stats' => $stats]);
    }
    exit();
}
// Get growth data
$growth_query = "
    SELECT 
        DATE_FORMAT(u.created_at, '%Y-%m') as month,
        COUNT(CASE WHEN role = 'customer' THEN 1 END) as customers,
        COUNT(CASE WHEN role IN ('store', 'caterer') THEN 1 END) as stores,
        COUNT(CASE WHEN role IN ('store', 'caterer') AND sd.subscription_status = 'active' THEN 1 END) as subscribed_stores
    FROM users u
    LEFT JOIN store_details sd ON u.id = sd.user_id
    WHERE u.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(u.created_at, '%Y-%m')
    ORDER BY month
";
$growth_result = mysqli_query($conn, $growth_query);

// Get top performing stores
$top_stores_query = "
    SELECT 
        sd.business_name,
        u.first_name,
        u.last_name,
        COUNT(o.id) as total_orders,
        SUM(o.total_amount) as total_sales
    FROM bookings o
    JOIN store_details sd ON o.store_id = sd.user_id
    JOIN users u ON sd.user_id = u.id
    WHERE DATE(o.created_at) BETWEEN '$start_date' AND '$end_date'
    GROUP BY o.store_id
    ORDER BY total_sales DESC
    LIMIT 10
";
$top_stores_result = mysqli_query($conn, $top_stores_query);

if (isset($_GET['output']) && in_array($_GET['output'], ['print', 'csv'], true)) {
    if (!$stats_result || !$top_stores_result) {
        http_response_code(503);
        exit('Unable to generate report. Please try again.');
    }
    require __DIR__ . '/includes/report-output.php';
    exit();
}
$page_title = 'Reports - Portal Dashboard';
$extra_head = '
    <link rel="stylesheet" href="reports-responsive.css?v=20261016-8">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Report Period Header */
        .report-period {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .report-actions {
            display: flex;
            gap: 10px;
        }
        
        /* Revenue Cards */
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .revenue-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 25px;
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--accent-gold);
        }

        /* Charts & Tables Layout */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin-top: 20px;
        }
        
        .top-caterer-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            background: rgba(212, 175, 55, 0.2);
            color: var(--accent-gold);
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 5px;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .grid-3 { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 768px) {
            .grid-3 { grid-template-columns: 1fr; }
            .report-period { flex-direction: column; gap: 15px; text-align: center; }
            .report-actions { width: 100%; justify-content: center; }
        }
    </style>
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

    <div class="main-content reports-page">
        
        <!-- Statistics -->
        <div class="reports-summary-grid">
        <div class="stats-grid">
            <div class="stat-card">
                <h3>TOTAL CUSTOMERS</h3>
                <p data-report-stat="new_customers" style="color: var(--info-color);"><?php echo $stats['new_customers']; ?></p>
            </div>
            
            <div class="stat-card">
                <h3>TOTAL CATERERS</h3>
                <p data-report-stat="new_stores" style="color: #f093fb;"><?php echo $stats['new_stores']; ?></p>
            </div>
            
        </div>
        
        <!-- Revenue Statistics -->
        <div class="grid-3">
            <div class="revenue-card" style="border-left-color: var(--info-color);">
                <h3>SUBSCRIPTIONS</h3>
                <p data-report-stat="new_subscriptions" style="font-size: 28px; font-weight: 800; color: var(--info-color);"><?php echo $stats['new_subscriptions']; ?></p>
            </div>
            
            <div class="revenue-card" style="border-left-color: var(--error-color);">
                <h3>SUBSCRIPTION REVENUE</h3>
                <p data-report-stat="subscription_revenue" style="font-size: 28px; font-weight: 800; color: var(--error-color);">₱<?php echo number_format($stats['subscription_revenue'], 2); ?></p>
            </div>
            
        </div>
        
        <!-- Date Filter -->
        </div><!-- Reports summary -->
        <div class="content-body reports-filter-panel">
            <div class="report-period">
                <div>
                    <h3 style="margin: 0; color: var(--text-main);">Report Period</h3>
                    <p style="margin: 5px 0 0 0; color: var(--text-muted);">
                        <?php echo date('F d, Y', strtotime($start_date)); ?> to <?php echo date('F d, Y', strtotime($end_date)); ?>
                    </p>
                </div>
                <div class="report-actions">
                    <a href="backup.php" class="btn btn-outline">Backup / Import</a>
                    <button type="button" onclick="printReport()" class="btn btn-primary">
                        Print
                    </button>
                    <form method="post" action="backup.php" style="margin:0">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                        <button type="submit" name="action" value="download" class="btn btn-primary" title="Export current website and database backup">Export</button>
                    </form>
                </div>
            </div>
            
            <h3>Filter Reports</h3>
            <form method="GET" action="" class="reports-filter-form">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>" required class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>" required class="form-control">
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-info">
                        Apply filter
                    </button>
                </div>
            </form>
        </div>
        
        <div class="grid-2">
            <!-- Growth Chart -->
            <div class="content-body">
                <h3>User Growth</h3>
                <div class="chart-container">
                    <canvas id="growthChart"></canvas>
                </div>
            </div>
            
            <!-- Top Stores -->
            <div class="content-body">
                <h3>Top Performing Caterers</h3>
                <?php if ($top_stores_result && mysqli_num_rows($top_stores_result) > 0): ?>
                    <div class="reports-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Caterer</th>
                                <th>Booked</th>
                                <th>Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($caterer = mysqli_fetch_assoc($top_stores_result)): ?>
                                <tr>
                                    <td data-label="Caterer">
                                        <strong><?php echo htmlspecialchars($caterer['business_name']); ?></strong><br>
                                        <small style="color: var(--text-muted);"><?php echo htmlspecialchars($caterer['first_name'] . ' ' . $caterer['last_name']); ?></small>
                                        <?php if ($caterer['total_orders'] > 10): ?>
                                            <br><span class="top-caterer-badge">Top Performer</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Booked" style="text-align: center;">
                                        <span style="font-size: 18px; font-weight: 800; color: var(--info-color);"><?php echo $caterer['total_orders']; ?></span>
                                    </td>
                                    <td data-label="Sales">
                                        <span style="font-size: 16px; font-weight: 800; color: var(--success-color);">₱<?php echo number_format($caterer['total_sales'], 2); ?></span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else: ?>
                    <div class="reports-empty">
                        <h3>No activity yet</h3>
                        <p>Caterer performance will appear here when orders are recorded for this period.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        let reportStats = <?php echo json_encode($stats); ?>;
        let refreshingStats = false;
        async function refreshReportStats() {
            if (document.hidden || refreshingStats) return;
            refreshingStats = true;
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('stats_refresh', '1');
                const response = await fetch(url, {cache: 'no-store', credentials: 'same-origin'});
                if (!response.ok) return;
                const payload = await response.json();
                if (!payload.stats) return;
                reportStats = payload.stats;
                document.querySelectorAll('[data-report-stat]').forEach(element => {
                    const key = element.dataset.reportStat;
                    const value = Number(reportStats[key]);
                    if (!Number.isFinite(value)) return;
                    element.textContent = key === 'subscription_revenue'
                        ? '₱' + value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})
                        : value.toLocaleString('en-US');
                });
            } catch (error) {
                // Retain the last successful values during a connection interruption.
            } finally {
                refreshingStats = false;
            }
        }
        setInterval(refreshReportStats, 5000);
        document.addEventListener('visibilitychange', refreshReportStats);
        window.addEventListener('focus', refreshReportStats);
    </script>
    <script>
        // Prepare growth chart data
        const growthData = {
            labels: [],
            datasets: [
                {
                    label: 'Customers',
                    data: [],
                    backgroundColor: 'rgba(33, 150, 243, 0.2)',
                    borderColor: 'rgba(33, 150, 243, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Caterers',
                    data: [],
                    backgroundColor: 'rgba(76, 175, 80, 0.2)',
                    borderColor: 'rgba(76, 175, 80, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Subscribed Caterers',
                    data: [],
                    backgroundColor: 'rgba(212, 175, 55, 0.2)',
                    borderColor: 'rgba(212, 175, 55, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                }
            ]
        };
        
        <?php
        if ($growth_result) mysqli_data_seek($growth_result, 0);
        $growth_labels = [];
        $customer_data = [];
        $store_data = [];
        $subscribed_data = [];
        while ($growth_result && ($growth = mysqli_fetch_assoc($growth_result))) {
            $growth_labels[] = date('M Y', strtotime($growth['month'] . '-01'));
            $customer_data[] = $growth['customers'];
            $store_data[] = $growth['stores'];
            $subscribed_data[] = $growth['subscribed_stores'];
        }
        ?>
        
        growthData.labels = <?php echo json_encode($growth_labels); ?>;
        growthData.datasets[0].data = <?php echo json_encode($customer_data); ?>;
        growthData.datasets[1].data = <?php echo json_encode($store_data); ?>;
        growthData.datasets[2].data = <?php echo json_encode($subscribed_data); ?>;
        
        // Create growth chart
        const growthCtx = document.getElementById('growthChart').getContext('2d');
        const growthChart = new Chart(growthCtx, {
            type: 'line',
            data: growthData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#a0a0a0',
                            font: {
                                size: 12,
                                family: "'Inter', sans-serif"
                            },
                            padding: 12,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 8,
                            boxHeight: 8
                        }
                    },
                    title: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        },
                        ticks: {
                            precision: 0,
                            color: '#a0a0a0',
                            font: {
                                size: 11
                            }
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        },
                        ticks: {
                            maxRotation: 0,
                            maxTicksLimit: 6,
                            color: '#a0a0a0',
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
        
        function reportOutputUrl(output) {
            const url = new URL(window.location.href);
            url.searchParams.delete('stats_refresh');
            url.searchParams.set('output', output);
            return url.href;
        }
        function printReport() {
            window.open(reportOutputUrl('print'), '_blank', 'noopener');
        }
        function exportReport() {
            window.location.assign(reportOutputUrl('csv'));
        }
    </script>
<?php require __DIR__ . '/includes/footer-bar.php'; ?>
</body>
</html>
