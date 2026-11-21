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

// Check if single event requested
$single_event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$where_clause = "WHERE e.store_id = '$user_id' AND e.status = 'Completed'";

if ($single_event_id > 0) {
    $where_clause .= " AND e.id = '$single_event_id'";
}

// Fetch Completed Events
$query = "
    SELECT e.*, o.order_number 
    FROM events e 
    JOIN bookings o ON e.order_id = o.id 
    $where_clause
    ORDER BY e.event_date DESC 
";
$result = mysqli_query($conn, $query);

if (mysqli_num_rows($result) == 0) {
    echo '<div style="padding: 30px; text-align: center; color: var(--text-muted);">
            <i class="fas fa-check-circle" style="font-size: 32px; margin-bottom: 10px; opacity: 0.5;"></i><br>
            No completed events found yet.
          </div>';
    exit();
}
?>

<div style="display: flex; flex-direction: column; gap: 15px;">
    <?php while ($event = mysqli_fetch_assoc($result)): ?>
        <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden;">
            <!-- Event Header -->
            <div style="padding: 12px 15px; background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong style="color: var(--accent-gold); font-size: 15px;">
                        Event #<?php echo $event['id']; ?> (<?php echo htmlspecialchars(formatCatererBookingNumber(0, $event['order_number'])); ?>)
                    </strong>
                    <div style="font-size: 12px; color: var(--text-muted);">
                        <?php echo date('F j, Y', strtotime($event['event_date'])); ?> | <?php echo htmlspecialchars($event['customer_name']); ?>
                    </div>
                </div>
                <span style="background: #27ae60; color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">
                    COMPLETED
                </span>
            </div>
            
            <!-- Inventory Table -->
            <div style="padding: 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead style="background: rgba(255,255,255,0.02); color: var(--text-muted);">
                        <tr>
                            <th style="text-align: left; padding: 8px 15px;">Item</th>
                            <th style="text-align: center; padding: 8px;">Borrowed</th>
                            <th style="text-align: center; padding: 8px;">Returned</th>
                            <th style="text-align: center; padding: 8px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $evt_id = $event['id'];
                        $inv_q = mysqli_query($conn, "SELECT ei.*, ii.item_type 
                                                      FROM event_inventory ei 
                                                      LEFT JOIN inventory_items ii ON ei.item_name = ii.item_name AND ii.store_id = '$user_id'
                                                      WHERE ei.event_id = '$evt_id'");
                        
                        while ($item = mysqli_fetch_assoc($inv_q)):
                            $borrowed = $item['quantity_required'];
                            $returned = $item['quantity_returned'];
                            $released = $item['quantity_released'];
                            $type = $item['item_type'];
                            
                            // Determine Status
                            $status = '';
                            $statusColor = '';
                            
                            if ($type === 'consumable') {
                                $status = 'Consumed';
                                $statusColor = '#95a5a6'; // Grey
                            } elseif ($returned >= $borrowed) {
                                $status = 'Returned';
                                $statusColor = '#27ae60'; // Green
                            } elseif ($returned > 0 && $returned < $borrowed) {
                                $missing = $borrowed - $returned;
                                $status = "Missing ($missing)";
                                $statusColor = '#e74c3c'; // Red
                            } else {
                                // If completed but 0 returned (and was released)
                                if ($released > 0) {
                                     $status = 'Not Returned';
                                     $statusColor = '#c0392b'; // Dark Red
                                } else {
                                     $status = '-'; // Maybe not released?
                                     $statusColor = '#7f8c8d';
                                }
                            }
                        ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td style="padding: 8px 15px;">
                                <?php echo htmlspecialchars($item['item_name']); ?>
                                <?php if ($type === 'consumable'): ?>
                                    <span style="font-size: 10px; color: #3498db; margin-left: 5px;">(Consumable)</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center; padding: 8px; color: var(--text-main); font-weight: bold;">
                                <?php echo $borrowed; ?>
                            </td>
                            <td style="text-align: center; padding: 8px; color: <?php echo ($returned < $borrowed && $type !== 'consumable') ? '#e74c3c' : '#27ae60'; ?>;">
                                <?php echo $type === 'consumable' ? '-' : $returned; ?>
                            </td>
                            <td style="text-align: center; padding: 8px;">
                                <span style="color: <?php echo $statusColor; ?>; font-weight: 500; font-size: 11px;">
                                    <?php echo $status; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endwhile; ?>
</div>

