<?php
require_once __DIR__ . '/../includes/order_reference_helper.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

// Check login
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['store', 'caterer'])) {
    echo '<div style="color:red; padding:20px;">Unauthorized access.</div>';
    exit();
}

$user_id = $_SESSION['user_id'];
$inventory_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($inventory_id <= 0) {
    echo '<div style="color:red; padding:20px;">Invalid item ID.</div>';
    exit();
}

// Get item details
$item_q = mysqli_query($conn, "SELECT item_name, total_stock, unit, item_type FROM inventory_items WHERE id = '$inventory_id' AND store_id = '$user_id'");
$item = mysqli_fetch_assoc($item_q);

if (!$item) {
    echo '<div style="color:red; padding:20px;">Item not found.</div>';
    exit();
}

// Get usage history (future and past 30 days)
// We also want to see 'consumed' status for consumables
// LINK with Event Inventory to get actual Returned/Damaged counts
$query = "
    SELECT iu.*, o.order_number, u.id AS customer_id, u.first_name, u.last_name, u.profile_image,
           e.id as event_id, e.status as event_status,
           ei.quantity_required, ei.quantity_returned, ei.quantity_released
    FROM inventory_usage iu
    JOIN bookings o ON iu.order_id = o.id
    JOIN users u ON o.customer_id = u.id
    LEFT JOIN events e ON o.id = e.order_id
    LEFT JOIN event_inventory ei ON e.id = ei.event_id AND ei.item_name = '{$item['item_name']}'
    WHERE iu.inventory_item_id = '$inventory_id'
    AND (iu.event_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) OR iu.status = 'active' OR e.status = 'Completed')
    ORDER BY iu.event_date DESC
";
$usage_q = mysqli_query($conn, $query);

$usage_data = [];
while ($row = mysqli_fetch_assoc($usage_q)) {
    // Calculate Damaged/Missing if event is completed
    if ($row['event_status'] === 'Completed' && $row['quantity_released'] > 0) {
        $row['damaged_missing'] = $row['quantity_released'] - $row['quantity_returned'];
    } else {
        $row['damaged_missing'] = 0;
    }
    $usage_data[] = $row;
}
?>

<div class="usage-summary">
    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
        <span style="color: var(--text-muted);">Total Stock:</span>
        <strong style="color: var(--text-main);"><?php echo floatval($item['total_stock']) . ' ' . $item['unit']; ?></strong>
    </div>
    <div style="display: flex; justify-content: space-between;">
        <span style="color: var(--text-muted);">Type:</span>
        <strong style="color: <?php echo $item['item_type'] == 'consumable' ? '#3498db' : '#9b59b6'; ?>;">
            <?php echo ucfirst($item['item_type']); ?>
        </strong>
    </div>
</div>

<?php if (empty($usage_data)): ?>
    <div style="padding: 30px; text-align: center; color: var(--text-muted);">
        
        No usage records found for this item recently.
    </div>
<?php else: ?>
    <?php
    $customer_groups = [];
    foreach ($usage_data as $record) $customer_groups[$record['customer_id']][] = $record;
    foreach ($customer_groups as $customer_id => $customer_records):
        $customer = $customer_records[0];
        $customer_name = trim($customer['first_name'] . ' ' . $customer['last_name']);
        $avatar_path = str_replace('\\', '/', trim((string) $customer['profile_image']));
        $avatar_url = preg_match('~^uploads/[a-zA-Z0-9_./ -]+$~', $avatar_path) && !str_contains($avatar_path, '..') ? '../' . $avatar_path : '';
    ?>
    <details class="usage-customer" data-customer-id="<?php echo (int) $customer_id; ?>">
        <summary>
            <span class="usage-customer-avatar" aria-hidden="true">
                <?php if ($avatar_url): ?><img src="<?php echo htmlspecialchars($avatar_url); ?>" alt=""><?php else: ?>
                <?php echo htmlspecialchars(mb_substr($customer_name, 0, 1)); ?>
                <?php endif; ?>
            </span>
            <span class="usage-customer-name"><?php echo htmlspecialchars($customer_name); ?></span>
            <span class="usage-customer-count"><?php echo count($customer_records); ?> records</span>
        </summary>
    <div class="usage-records">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-color); text-align: left; color: var(--text-muted);">
                    <th style="padding: 10px;">Date</th>
                    <th style="padding: 10px;">Order</th>
                    <th style="padding: 10px;">Qty Used</th>
                    <th style="padding: 10px;">Returned</th>
                    <th style="padding: 10px;">Missing/Damaged</th>
                    <th style="padding: 10px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customer_records as $row): ?>
                    <?php 
                        $event_date = strtotime($row['event_date']);
                        $today = strtotime(date('Y-m-d'));
                        $is_today = ($event_date == $today);
                        $is_future = ($event_date > $today);
                        
                        $row_style = $is_today ? 'background: rgba(33, 150, 243, 0.1);' : '';
                        $date_style = $is_today ? 'color: #2196f3; font-weight: bold;' : ($is_future ? 'color: var(--text-main);' : 'color: var(--text-muted);');
                    ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); <?php echo $row_style; ?>">
                        <td data-label="Date" style="padding: 10px; <?php echo $date_style; ?>">
                            <?php echo date('M d, Y', $event_date); ?>
                            <?php if ($is_today): ?>
                                <span style="font-size:9px; margin-left:5px; background:#2196f3; color:#fff; padding:2px 4px; border-radius:3px;">TODAY</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Order" style="padding: 10px; color: var(--text-main);">
                            <div style="font-weight: 500;"><?php echo htmlspecialchars(formatCatererBookingNumber(0, $row['order_number'])); ?></div>
                        </td>
                        <td data-label="Qty used" style="padding: 10px; font-weight: bold; color: var(--accent-gold);">
                            <?php echo floatval($row['quantity_used']) . ' ' . $item['unit']; ?>
                        </td>
                        <td data-label="Returned" style="padding: 10px; color: #4caf50;">
                            <?php 
                            if ($row['quantity_returned'] !== null) {
                                echo floatval($row['quantity_returned']) . ' ' . $item['unit'];
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td data-label="Missing / damaged" style="padding: 10px; color: #f44336; font-weight: bold;">
                            <?php 
                            if ($row['damaged_missing'] > 0) {
                                echo floatval($row['damaged_missing']) . ' ' . $item['unit'];
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td data-label="Status" style="padding: 10px;">
                            <?php if ($row['status'] == 'returned'): ?>
                                <span style="color: #4caf50; font-size: 11px; background: rgba(76, 175, 80, 0.1); padding: 2px 6px; border-radius: 4px;">Returned</span>
                            <?php elseif ($row['status'] == 'consumed'): ?>
                                <span style="color: #e74c3c; font-size: 11px; background: rgba(231, 76, 60, 0.1); padding: 2px 6px; border-radius: 4px;">Consumed</span>
                            <?php elseif ($row['status'] == 'active'): ?>
                                <span style="color: #f39c12; font-size: 11px; background: rgba(243, 156, 18, 0.1); padding: 2px 6px; border-radius: 4px;">Reserved</span>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 11px;"><?php echo ucfirst($row['status']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </details>
    <?php endforeach; ?>
<?php endif; ?>


