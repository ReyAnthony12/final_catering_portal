<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
requireLogin();

if (getUserRole() !== 'customer') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get customer details
$customer_query = "SELECT * FROM users WHERE id = '$user_id'";
$customer_result = mysqli_query($conn, $customer_query);
$customer = mysqli_fetch_assoc($customer_result);

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Get order statistics
$orders_query = "
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as order_count,
        SUM(total_amount) as total_spent
    FROM bookings 
    WHERE customer_id = '$user_id' 
    AND DATE(created_at) BETWEEN '$start_date' AND '$end_date'
    GROUP BY DATE(created_at)
    ORDER BY date DESC
";
$orders_result = mysqli_query($conn, $orders_query);

// Get top caterers
$top_stores_query = "
    SELECT 
        sd.business_name,
        u.first_name,
        u.last_name,
        COUNT(o.id) as order_count,
        SUM(o.total_amount) as total_spent,
        MAX(o.created_at) as last_order
    FROM bookings o
    JOIN store_details sd ON o.store_id = sd.user_id
    JOIN users u ON sd.user_id = u.id
    WHERE o.customer_id = '$user_id'
    AND DATE(o.created_at) BETWEEN '$start_date' AND '$end_date'
    GROUP BY o.store_id
    ORDER BY total_spent DESC
    LIMIT 5
";
$top_stores_result = mysqli_query($conn, $top_stores_query);

$page_title = 'Reports';
require_once __DIR__ . '/includes/header.php';
?>

<div>
    <div class="section-header">
        <h1 class="section-title"><i class="fas fa-chart-bar" style="color: var(--accent-gold);"></i> Reports</h1>
    </div>

    <div class="card no-print">
        <h3 style="margin-bottom: 20px; color: var(--accent-gold);">Filter Reports</h3>
        <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 200px;">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>" required>
            </div>
            
            <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 200px;">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>" required>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">Apply Filter</button>
                <a href="reports.php" class="btn btn-outline">Reset</a>
                <button type="button" onclick="window.print()" class="btn btn-primary" style="background: var(--success-color); color: white;">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </form>
    </div>

    <!-- Top Caterers -->
    <div class="card" style="margin-top: 30px;">
        <h3 style="margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Top Caterers</h3>
        <?php if (mysqli_num_rows($top_stores_result) > 0): ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Caterer</th>
                            <th>Orders</th>
                            <th>Total Spent</th>
                            <th>Last Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Reset pointer for second use if needed, though we only use once here
                        // mysqli_data_seek($top_stores_result, 0); 
                        while ($caterer = mysqli_fetch_assoc($top_stores_result)): 
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($caterer['business_name']); ?></strong><br>
                                    <small style="color: var(--text-muted);"><?php echo htmlspecialchars($caterer['first_name'] . ' ' . $caterer['last_name']); ?></small>
                                </td>
                                <td><?php echo $caterer['order_count']; ?></td>
                                <td class="price">₱<?php echo number_format($caterer['total_spent'], 2); ?></td>
                                <td><?php echo date('M d, Y', strtotime($caterer['last_order'])); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-caterer-slash"></i>
                <p>No caterer data found for this period.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Detailed Order Report -->
    <div class="card" style="margin-top: 30px;">
        <h3 style="margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Detailed Order Report</h3>
        <?php if (mysqli_num_rows($orders_result) > 0): ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Orders Count</th>
                            <th>Total Spent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_orders = 0;
                        $total_spent = 0;
                        while ($order = mysqli_fetch_assoc($orders_result)): 
                            $total_orders += $order['order_count'];
                            $total_spent += $order['total_spent'];
                        ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($order['date'])); ?></td>
                                <td><?php echo $order['order_count']; ?></td>
                                <td class="price">₱<?php echo number_format($order['total_spent'], 2); ?></td>
                            </tr>
                        <?php endwhile; ?>
                        <tr style="font-weight: 700; background: rgba(212, 175, 55, 0.05);">
                            <td>TOTAL</td>
                            <td><?php echo $total_orders; ?></td>
                            <td class="price">₱<?php echo number_format($total_spent, 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-chart-line"></i>
                <p>No order data found for this period.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    @media print {
        /* Hide navbar and sidebar */
        .navbar, .no-print, .section-header {
            display: none !important;
        }
        
        body {
            background: white;
            color: black;
        }
        
        .card {
            background: white;
            border: 1px solid #ddd;
            color: black;
            box-shadow: none;
        }
        
        .main-content {
            margin: 0;
            padding: 0;
        }
        
        table {
            border-collapse: collapse;
            width: 100%;
        }
        
        th, td {
            border: 1px solid #ddd;
            color: black !important;
        }
        
        th {
            background-color: #f8f9fa !important;
        }
        
        .price {
            color: black !important;
        }
        
        /* Force background graphics for colors if needed, but usually better to stick to B&W friendly */
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
